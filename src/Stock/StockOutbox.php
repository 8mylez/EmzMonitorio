<?php declare(strict_types=1);

namespace Emz\Monitorio\Stock;

use Doctrine\DBAL\Connection;

/**
 * Persistierte Batches (`emz_monitorio_stock_outbox`): Fallback-Puffer und
 * Retry-Speicher in einem. Die batchId bleibt ueber Retries und Prozess-Neustarts
 * identisch, weil der fertige JSON-Payload mitsamt batchId gespeichert wird.
 *
 * `next_retry_at` dient doppelt: als Backoff-Zeitpunkt und als kurzlebige
 * Claim-Lease gegen parallele Messenger-Worker (kein DB-Lock ueber HTTP-Calls).
 */
final class StockOutbox
{
    private const TIME_FORMAT = 'Y-m-d H:i:s.v';
    private const CLAIM_LEASE_SECONDS = 120;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function insert(string $batchId, string $payload, int $eventCount, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();

        $this->connection->executeStatement(
            'INSERT INTO emz_monitorio_stock_outbox (batch_id, payload, event_count, attempts, next_retry_at, created_at)
             VALUES (:batchId, :payload, :eventCount, 0, NULL, :createdAt)',
            [
                'batchId' => $batchId,
                'payload' => $payload,
                'eventCount' => $eventCount,
                'createdAt' => $now->format(self::TIME_FORMAT),
            ]
        );
    }

    /**
     * Claimt den aeltesten faelligen Batch (Lease statt Lock). Liefert null,
     * wenn nichts faellig ist oder parallele Worker schneller waren.
     *
     * @return array{batch_id: string, payload: string, event_count: int, attempts: int}|null
     */
    public function claimNextDue(?\DateTimeImmutable $now = null): ?array
    {
        $now ??= new \DateTimeImmutable();
        $nowFormatted = $now->format(self::TIME_FORMAT);
        $lease = $now->modify('+' . self::CLAIM_LEASE_SECONDS . ' seconds')->format(self::TIME_FORMAT);

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $row = $this->connection->fetchAssociative(
                'SELECT batch_id, payload, event_count, attempts
                 FROM emz_monitorio_stock_outbox
                 WHERE next_retry_at IS NULL OR next_retry_at <= :now
                 ORDER BY created_at, batch_id
                 LIMIT 1',
                ['now' => $nowFormatted]
            );

            if ($row === false) {
                return null;
            }

            $claimed = $this->connection->executeStatement(
                'UPDATE emz_monitorio_stock_outbox
                 SET next_retry_at = :lease
                 WHERE batch_id = :batchId AND (next_retry_at IS NULL OR next_retry_at <= :now)',
                ['lease' => $lease, 'batchId' => $row['batch_id'], 'now' => $nowFormatted]
            );

            if ($claimed === 1) {
                return [
                    'batch_id' => (string) $row['batch_id'],
                    'payload' => (string) $row['payload'],
                    'event_count' => (int) $row['event_count'],
                    'attempts' => (int) $row['attempts'],
                ];
            }
        }

        return null;
    }

    public function delete(string $batchId): void
    {
        $this->connection->executeStatement(
            'DELETE FROM emz_monitorio_stock_outbox WHERE batch_id = :batchId',
            ['batchId' => $batchId]
        );
    }

    public function scheduleRetry(string $batchId, int $delaySeconds, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();

        $this->connection->executeStatement(
            'UPDATE emz_monitorio_stock_outbox
             SET attempts = attempts + 1, next_retry_at = :nextRetryAt
             WHERE batch_id = :batchId',
            [
                'batchId' => $batchId,
                'nextRetryAt' => $now->modify('+' . $delaySeconds . ' seconds')->format(self::TIME_FORMAT),
            ]
        );
    }

    /**
     * Pausiert den kompletten Versand (401/403: Konfig-/Token-Fehler).
     */
    public function pauseAll(int $delaySeconds, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();

        $this->connection->executeStatement(
            'UPDATE emz_monitorio_stock_outbox SET next_retry_at = :nextRetryAt',
            ['nextRetryAt' => $now->modify('+' . $delaySeconds . ' seconds')->format(self::TIME_FORMAT)]
        );
    }

    /**
     * Anzahl Batches, deren naechster Versuch nach dem Schwellzeitpunkt liegt.
     * Dient der Uebergangs-Erkennung in den Pause-Zustand (Claim-Leases sind
     * kuerzer als die Schwelle und zaehlen damit nicht mit).
     */
    public function countScheduledAfter(\DateTimeImmutable $threshold): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM emz_monitorio_stock_outbox WHERE next_retry_at > :threshold',
            ['threshold' => $threshold->format(self::TIME_FORMAT)]
        );
    }

    public function countOpen(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM emz_monitorio_stock_outbox');
    }

    public function hasDue(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        return (bool) $this->connection->fetchOne(
            'SELECT EXISTS(
                SELECT 1 FROM emz_monitorio_stock_outbox
                WHERE next_retry_at IS NULL OR next_retry_at <= :now
            )',
            ['now' => $now->format(self::TIME_FORMAT)]
        );
    }

    /**
     * Sekunden bis zum naechsten faelligen Batch: 0 = sofort faellig,
     * null = Outbox leer.
     */
    public function secondsUntilNextDue(?\DateTimeImmutable $now = null): ?int
    {
        $now ??= new \DateTimeImmutable();

        if ($this->countOpen() === 0) {
            return null;
        }

        if ($this->hasDue($now)) {
            return 0;
        }

        $next = $this->connection->fetchOne('SELECT MIN(next_retry_at) FROM emz_monitorio_stock_outbox');

        if (!\is_string($next)) {
            return 0;
        }

        $nextAt = new \DateTimeImmutable($next);

        return max(0, $nextAt->getTimestamp() - $now->getTimestamp());
    }

    /**
     * Retention (Spec Abschnitt 10, Punkt 3): alte und ueberzaehlige Batches
     * verwerfen. Verlustfrei im Endzustand - die Zustandstabelle wurde fuer
     * verworfene Batches nie fortgeschrieben, also meldet die Reconciliation
     * jede noch offene Differenz erneut.
     *
     * @return int Anzahl verworfener Batches
     */
    public function purgeExpired(int $maxAgeSeconds, int $maxCount, ?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();

        $purged = (int) $this->connection->executeStatement(
            'DELETE FROM emz_monitorio_stock_outbox WHERE created_at < :threshold',
            ['threshold' => $now->modify('-' . $maxAgeSeconds . ' seconds')->format(self::TIME_FORMAT)]
        );

        $excess = $this->countOpen() - $maxCount;
        if ($excess > 0) {
            $purged += (int) $this->connection->executeStatement(
                'DELETE FROM emz_monitorio_stock_outbox ORDER BY created_at, batch_id LIMIT ' . $excess
            );
        }

        return $purged;
    }
}
