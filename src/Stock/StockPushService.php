<?php declare(strict_types=1);

namespace Emz\Monitorio\Stock;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Orchestriert den Stock-Push: Diffs bilden, Batches bauen und persistieren,
 * Outbox versenden und die Antworten exakt nach der Contract-Tabelle
 * (Spec Abschnitt 3) behandeln.
 *
 * Harte Regeln, die diese Klasse garantiert:
 * - Die Zustandstabelle wird erst NACH Response 204 fortgeschrieben.
 * - Retries senden den identischen persistierten Payload, also dieselbe batchId.
 * - Nach 413 entstehen kleinere NEUE Batches mit NEUEN batchIds.
 */
final class StockPushService
{
    private const MAX_BATCHES_PER_FLUSH = 100;

    /**
     * Outbox-Retention (Konzept, offener Punkt 3): Verlustfrei im Endzustand,
     * weil die Zustandstabelle fuer verworfene Batches nie fortgeschrieben
     * wurde - die Reconciliation meldet offene Differenzen erneut.
     */
    private const RETENTION_SECONDS = 259200; // 72 h
    private const MAX_OPEN_BATCHES = 500;

    private const AUTH_PAUSE_SECONDS = 3600;

    /**
     * Schwelle fuer die Uebergangs-Erkennung in den Pause-Zustand; bewusst
     * groesser als die Claim-Lease der Outbox (120 s), damit geclaimte Batches
     * nicht als "pausiert" zaehlen.
     */
    private const PAUSE_NOTIFY_THRESHOLD_SECONDS = 300;

    private const BACKOFF_BASE_SECONDS = 30;
    private const BACKOFF_MAX_SECONDS = 1800;

    private const DIFF_CHUNK_SIZE = 500;
    private const MAX_DIFF_CHUNKS_PER_RUN = 100;

    public function __construct(
        private readonly StockPushConfig $config,
        private readonly StockStateStore $stateStore,
        private readonly StockOutbox $outbox,
        private readonly StockEventBuilder $eventBuilder,
        private readonly MonitorioStockClient $client,
        private readonly LoggerInterface $logger,
        private readonly ?EntityRepository $notificationRepository = null,
    ) {
    }

    /**
     * Subscriber-Pfad (source=subscriber): meldet den aktuellen Stand der
     * uebergebenen Produkte, wenn er vom zuletzt gemeldeten abweicht.
     *
     * @param list<string> $productIdsHex
     */
    public function pushForProducts(array $productIdsHex, \DateTimeImmutable $occurredAt): void
    {
        if (!$this->config->isConfigured()) {
            return;
        }

        $rows = $this->stateStore->fetchCurrentProducts($productIdsHex);
        $events = $this->eventBuilder->buildEvents($rows, StockEventBuilder::SOURCE_SUBSCRIBER, $occurredAt);

        if ($events === []) {
            return;
        }

        $this->enqueueBatches($events);
        $this->flushOutbox();
    }

    /**
     * Reconciliation-Pfad (source=reconciliation): liefert zuerst offene
     * Batches nach; nur wenn die Outbox danach leer ist, wird der Diff
     * (Zustandstabelle gegen product) gemeldet. Das verhindert, dass ein
     * laengerer Monitorio-Ausfall inhaltsgleiche Batches anhaeuft.
     */
    public function runReconciliation(): void
    {
        if (!$this->config->isConfigured()) {
            return;
        }

        $purged = $this->outbox->purgeExpired(self::RETENTION_SECONDS, self::MAX_OPEN_BATCHES);
        if ($purged > 0) {
            $this->logger->warning(
                'Monitorio stock push: discarded ' . $purged . ' undeliverable outbox batches (retention). '
                . 'Reconciliation will report still-open differences again.'
            );
        }

        if (!$this->flushOutbox()) {
            $this->logger->info('Monitorio stock push: outbox not fully delivered, skipping diff phase this run.');
            $this->cleanupOrphans();

            return;
        }

        for ($i = 0; $i < self::MAX_DIFF_CHUNKS_PER_RUN; ++$i) {
            $rows = $this->stateStore->findDiffRows(self::DIFF_CHUNK_SIZE);
            if ($rows === []) {
                break;
            }

            $events = $this->eventBuilder->buildEvents($rows, StockEventBuilder::SOURCE_RECONCILIATION, new \DateTimeImmutable());
            if ($events === []) {
                break;
            }

            $this->enqueueBatches($events);

            if (!$this->flushOutbox()) {
                break;
            }
        }

        $this->cleanupOrphans();
    }

    /**
     * Baseline-Vollimport (source=baseline): kompletter Leaf-Produktbestand,
     * previous* = null, in Chunks a max. 500 Events mit je eigener batchId.
     * Persistiert nur in die Outbox - den Versand steuert der Aufrufer
     * (Command: blockierend mit Fortschritt; sonst flushOutbox()).
     *
     * @param callable(int $products, int $batches): void|null $onProgress
     *
     * @return array{products: int, batches: int}
     */
    public function enqueueBaseline(?callable $onProgress = null): array
    {
        $products = 0;
        $batches = 0;

        foreach ($this->stateStore->iterateLeafProducts(self::DIFF_CHUNK_SIZE) as $rows) {
            $events = $this->eventBuilder->buildEvents($rows, StockEventBuilder::SOURCE_BASELINE, new \DateTimeImmutable());
            $products += \count($rows);
            $batches += $this->enqueueBatches($events);

            if ($onProgress !== null) {
                $onProgress($products, $batches);
            }
        }

        return ['products' => $products, 'batches' => $batches];
    }

    /**
     * Sendet faellige Outbox-Batches in Erstellungs-Reihenfolge.
     *
     * @return bool true, wenn alle verarbeiteten Batches bestaetigt wurden und
     *              die Outbox leer ist. Auch NICHT faellige Batches (Backoff)
     *              zaehlen als offen - die Diff-Phase der Reconciliation darf
     *              erst laufen, wenn wirklich nichts mehr aussteht, sonst
     *              entstehen inhaltsgleiche Duplikat-Batches.
     */
    public function flushOutbox(): bool
    {
        if (!$this->config->isConfigured()) {
            return false;
        }

        $allConfirmed = true;

        for ($i = 0; $i < self::MAX_BATCHES_PER_FLUSH; ++$i) {
            $batch = $this->outbox->claimNextDue();
            if ($batch === null) {
                break;
            }

            $result = $this->client->sendBatch($batch['payload']);

            if ($result->isTransportError()) {
                $this->scheduleTransientRetry($batch, null, 'transport error: ' . ($result->errorMessage ?? 'unknown'));

                return false;
            }

            switch (true) {
                case $result->statusCode === 204:
                    // Harte Anforderung (Spec Abschnitt 4): Zustandstabelle erst
                    // nach bestaetigtem Versand fortschreiben.
                    $this->confirmBatch($batch);
                    break;

                case $result->statusCode === 400:
                    $this->outbox->delete($batch['batch_id']);
                    $this->logger->error(
                        'Monitorio stock push: batch rejected with 400 and discarded (contract violation, plugin bug?). '
                        . 'Open differences will be reported again by reconciliation.',
                        ['batchId' => $batch['batch_id'], 'payloadExcerpt' => mb_substr($batch['payload'], 0, 2048)]
                    );
                    $allConfirmed = false;
                    break;

                case $result->statusCode === 413:
                    if (!$this->splitOversizedBatch($batch)) {
                        $allConfirmed = false;
                    }
                    break;

                case $result->statusCode === 429 || $result->statusCode >= 500:
                    $this->scheduleTransientRetry($batch, $result->retryAfterSeconds, 'HTTP ' . $result->statusCode);

                    return false;

                default:
                    // 401/403 laut Contract-Tabelle; alle uebrigen unerwarteten
                    // Status ebenfalls konservativ: nichts verwerfen, nicht haemmern.
                    $this->pauseSending((int) $result->statusCode);

                    return false;
            }
        }

        return $allConfirmed && $this->outbox->countOpen() === 0;
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function enqueueBatches(array $events): int
    {
        $batches = $this->eventBuilder->buildBatches($events);

        foreach ($batches as $batch) {
            $this->outbox->insert($batch['batchId'], $batch['json'], $batch['eventCount']);
        }

        return \count($batches);
    }

    /**
     * @param array{batch_id: string, payload: string, event_count: int, attempts: int} $batch
     */
    private function confirmBatch(array $batch): void
    {
        $decoded = json_decode($batch['payload'], true);
        $events = \is_array($decoded['events'] ?? null) ? $decoded['events'] : [];

        // Erst den Zustand fortschreiben, dann die Outbox-Zeile loeschen: crasht
        // es dazwischen, wird der Batch erneut gesendet und vom idempotenten
        // Server erneut mit 204 beantwortet - kein Schaden.
        $this->stateStore->applyConfirmedEvents($events);
        $this->outbox->delete($batch['batch_id']);
    }

    /**
     * 429/503/Netzwerkfehler: Retry mit IDENTISCHER batchId - die Zeile bleibt
     * mitsamt Payload liegen, nur der Faelligkeitszeitpunkt wandert nach hinten.
     *
     * @param array{batch_id: string, payload: string, event_count: int, attempts: int} $batch
     */
    private function scheduleTransientRetry(array $batch, ?int $retryAfterSeconds, string $reason): void
    {
        $delay = $retryAfterSeconds ?? $this->backoffDelay($batch['attempts']);

        $this->outbox->scheduleRetry($batch['batch_id'], $delay);
        $this->logger->warning(
            'Monitorio stock push: ' . $reason . ', retrying batch with identical batchId in ' . $delay . 's.',
            ['batchId' => $batch['batch_id'], 'attempts' => $batch['attempts'] + 1]
        );
    }

    /**
     * @param array{batch_id: string, payload: string, event_count: int, attempts: int} $batch
     */
    private function splitOversizedBatch(array $batch): bool
    {
        $parts = $this->eventBuilder->splitForRetry($batch['payload']);

        $this->outbox->delete($batch['batch_id']);

        if ($parts === []) {
            $this->logger->error(
                'Monitorio stock push: 413 for a single-event batch, discarded.',
                ['batchId' => $batch['batch_id']]
            );

            return false;
        }

        foreach ($parts as $part) {
            $this->outbox->insert($part['batchId'], $part['json'], $part['eventCount']);
        }

        $this->logger->warning(
            'Monitorio stock push: 413, split batch into two new batches with new batchIds.',
            ['batchId' => $batch['batch_id']]
        );

        return true;
    }

    private function pauseSending(int $statusCode): void
    {
        $threshold = (new \DateTimeImmutable())->modify('+' . self::PAUSE_NOTIFY_THRESHOLD_SECONDS . ' seconds');
        $alreadyPaused = $this->outbox->countScheduledAfter($threshold) > 0;

        $this->outbox->pauseAll(self::AUTH_PAUSE_SECONDS);

        $this->logger->error(
            'Monitorio stock push paused for ' . (self::AUTH_PAUSE_SECONDS / 60) . ' min: ingest responded with HTTP '
            . $statusCode . '. Check ingest token, project ID and base URL in the plugin configuration.'
        );

        if (!$alreadyPaused) {
            $this->notifyAdmin($statusCode);
        }
    }

    private function notifyAdmin(int $statusCode): void
    {
        if ($this->notificationRepository === null) {
            return;
        }

        try {
            $this->notificationRepository->create([[
                'id' => Uuid::randomHex(),
                'status' => 'error',
                'message' => \sprintf(
                    'Monitorio-Lagerbestand-Push pausiert: Der Ingest-Endpunkt antwortet mit HTTP %d. '
                    . 'Bitte Ingest-Token und Projekt-ID in der EmzMonitorio-Konfiguration pruefen.',
                    $statusCode
                ),
                'adminOnly' => false,
                'requiredPrivileges' => [],
            ]], Context::createCLIContext());
        } catch (\Throwable $e) {
            $this->logger->warning('Monitorio stock push: could not create admin notification: ' . $e->getMessage());
        }
    }

    private function backoffDelay(int $previousAttempts): int
    {
        return min(self::BACKOFF_BASE_SECONDS * (2 ** min($previousAttempts, 10)), self::BACKOFF_MAX_SECONDS);
    }

    private function cleanupOrphans(): void
    {
        $orphans = $this->stateStore->deleteOrphans();
        if ($orphans > 0) {
            $this->logger->info('Monitorio stock push: removed ' . $orphans . ' state rows of deleted products.');
        }
    }
}
