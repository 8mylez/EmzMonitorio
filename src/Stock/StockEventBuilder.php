<?php declare(strict_types=1);

namespace Emz\Monitorio\Stock;

/**
 * Baut aus Produkt-Zustandszeilen (StockStateStore) contract-konforme Events
 * und schneidet sie in Batches (Spec Abschnitt 3: max. 500 Events UND
 * max. 256 KiB pro Request - hier mit Sicherheitsmarge).
 */
final class StockEventBuilder
{
    public const SOURCE_SUBSCRIBER = 'subscriber';
    public const SOURCE_RECONCILIATION = 'reconciliation';
    public const SOURCE_BASELINE = 'baseline';

    private const MAX_EVENTS_PER_BATCH = 500;
    private const MAX_BODY_BYTES = 240 * 1024;
    private const MAX_NAME_LENGTH = 255;

    private const JSON_FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;

    /**
     * previous*-Regeln (Spec Abschnitt 3/4):
     * - source=baseline: alle Produkte, previous* = null.
     * - source=subscriber|reconciliation: nur echte Abweichungen vom zuletzt
     *   gemeldeten Stand; previous* aus der Zustandstabelle. Produkte OHNE
     *   Zustandszeile (neu angelegt oder Baseline nie gelaufen) werden als
     *   source=baseline mit previous* = null gemeldet - der einzige erlaubte
     *   null-Fall, und serverseitig alarmfrei.
     *
     * @param list<array<string, mixed>> $rows normalisierte Zeilen aus dem StockStateStore
     *
     * @return list<array<string, mixed>>
     */
    public function buildEvents(array $rows, string $source, \DateTimeImmutable $occurredAt): array
    {
        $events = [];

        foreach ($rows as $row) {
            if ($source === self::SOURCE_BASELINE || !$row['has_state']) {
                $events[] = $this->buildEvent($row, self::SOURCE_BASELINE, $occurredAt, null, null);

                continue;
            }

            if ($row['stock'] === $row['state_stock'] && $row['available_stock'] === $row['state_available_stock']) {
                continue;
            }

            $events[] = $this->buildEvent(
                $row,
                $source,
                $occurredAt,
                (int) $row['state_stock'],
                (int) $row['state_available_stock']
            );
        }

        return $events;
    }

    /**
     * Schneidet Events in Batches: erst nach Event-Anzahl, dann werden zu grosse
     * Chunks am fertigen JSON gemessen und halbiert. Jeder Batch bekommt eine
     * frische ULID-batchId.
     *
     * @param list<array<string, mixed>> $events
     *
     * @return list<array{batchId: string, json: string, eventCount: int}>
     */
    public function buildBatches(array $events): array
    {
        if ($events === []) {
            return [];
        }

        $batches = [];
        foreach (array_chunk($events, self::MAX_EVENTS_PER_BATCH) as $chunk) {
            foreach ($this->packChunk($chunk) as $batch) {
                $batches[] = $batch;
            }
        }

        return $batches;
    }

    /**
     * 413-Behandlung (Contract-Tabelle): Events des abgelehnten Batches halbieren.
     * Die Haelften sind NEUE Batches mit NEUEN batchIds - sonst wuerde der zweite
     * Teil serverseitig als Duplikat des ersten verworfen. Ein nicht weiter
     * teilbarer Batch (1 Event) liefert [].
     *
     * @return list<array{batchId: string, json: string, eventCount: int}>
     */
    public function splitForRetry(string $payloadJson): array
    {
        $decoded = json_decode($payloadJson, true, 512, \JSON_THROW_ON_ERROR);
        $events = \is_array($decoded['events'] ?? null) ? array_values($decoded['events']) : [];

        if (\count($events) <= 1) {
            return [];
        }

        $half = (int) ceil(\count($events) / 2);

        return [
            $this->encodeBatch(\array_slice($events, 0, $half)),
            $this->encodeBatch(\array_slice($events, $half)),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function buildEvent(
        array $row,
        string $source,
        \DateTimeImmutable $occurredAt,
        ?int $previousStock,
        ?int $previousAvailableStock,
    ): array {
        $event = [
            'eventId' => Ulid::generate(),
            'occurredAt' => $occurredAt->format(\DATE_ATOM),
            'source' => $source,
            'productId' => (string) $row['id'],
            'productNumber' => (string) $row['product_number'],
        ];

        if ($row['name'] !== null && $row['name'] !== '') {
            $event['name'] = mb_substr((string) $row['name'], 0, self::MAX_NAME_LENGTH);
        }

        return $event + [
            'stock' => (int) $row['stock'],
            'availableStock' => (int) $row['available_stock'],
            'previousStock' => $previousStock,
            'previousAvailableStock' => $previousAvailableStock,
            'isCloseout' => (bool) $row['is_closeout'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $chunk
     *
     * @return list<array{batchId: string, json: string, eventCount: int}>
     */
    private function packChunk(array $chunk): array
    {
        $batch = $this->encodeBatch($chunk);

        if (\strlen($batch['json']) <= self::MAX_BODY_BYTES || \count($chunk) <= 1) {
            return [$batch];
        }

        $half = (int) ceil(\count($chunk) / 2);

        return [
            ...$this->packChunk(\array_slice($chunk, 0, $half)),
            ...$this->packChunk(\array_slice($chunk, $half)),
        ];
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return array{batchId: string, json: string, eventCount: int}
     */
    private function encodeBatch(array $events): array
    {
        $batchId = Ulid::generate();

        return [
            'batchId' => $batchId,
            'json' => json_encode(['batchId' => $batchId, 'events' => $events], self::JSON_FLAGS),
            'eventCount' => \count($events),
        ];
    }
}
