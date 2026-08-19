<?php declare(strict_types=1);

namespace Emz\Monitorio\Stock;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Zugriff auf die Zustandstabelle `emz_monitorio_stock_state` und die dazu
 * passenden Produkt-Reads (Leaf-Produkte der Live-Version).
 *
 * Bestand lebt in Shopware auf den Leaf-Produkten (Varianten bzw. variantenlose
 * Produkte, `child_count` 0/NULL) - genau diese werden gemeldet. Inaktive und
 * Abverkauf-Produkte werden bewusst NICHT gefiltert (Spec Abschnitt 3/8).
 */
final class StockStateStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Aktueller Produkt-Stand plus zuletzt gemeldeter Stand fuer gegebene IDs.
     * Nicht existente IDs und Parent-Produkte fallen stillschweigend raus.
     *
     * @param list<string> $productIdsHex
     *
     * @return list<array<string, mixed>>
     */
    public function fetchCurrentProducts(array $productIdsHex): array
    {
        $productIdsHex = array_values(array_filter($productIdsHex, Uuid::isValid(...)));

        if ($productIdsHex === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            self::baseSelect() . ' AND p.id IN (:ids)',
            self::baseParams() + ['ids' => Uuid::fromHexToBytesList($productIdsHex)],
            ['ids' => ArrayParameterType::BINARY]
        );

        return array_map(self::normalizeRow(...), $rows);
    }

    /**
     * Reconciliation-Diff: Produkte, deren aktueller Stand vom zuletzt
     * gemeldeten abweicht - inklusive Produkte ganz ohne Zustandszeile
     * (die werden als source=baseline gemeldet).
     *
     * @return list<array<string, mixed>>
     */
    public function findDiffRows(int $limit): array
    {
        $sql = self::baseSelect() . '
            AND (
                s.product_id IS NULL
                OR s.stock != p.stock
                OR s.available_stock != COALESCE(p.available_stock, 0)
            )
            ORDER BY p.id
            LIMIT ' . $limit;

        return array_map(self::normalizeRow(...), $this->connection->fetchAllAssociative($sql, self::baseParams()));
    }

    /**
     * Alle Leaf-Produkte in Chunks (Keyset-Pagination) - fuer den Baseline-Vollimport.
     *
     * @return \Generator<list<array<string, mixed>>>
     */
    public function iterateLeafProducts(int $chunkSize): \Generator
    {
        $lastIdBytes = null;

        while (true) {
            $sql = self::baseSelect()
                . ($lastIdBytes !== null ? ' AND p.id > :lastId' : '')
                . ' ORDER BY p.id LIMIT ' . $chunkSize;
            $params = self::baseParams() + ($lastIdBytes !== null ? ['lastId' => $lastIdBytes] : []);

            $rows = $this->connection->fetchAllAssociative($sql, $params);

            if ($rows === []) {
                return;
            }

            $lastIdBytes = Uuid::fromHexToBytes((string) end($rows)['id']);

            yield array_map(self::normalizeRow(...), $rows);
        }
    }

    /**
     * Schreibt die Zustandstabelle mit den Werten aus einem BESTAETIGTEN Batch
     * fort (harte Anforderung: erst nach Response 204 aufrufen). Die Werte
     * kommen aus dem Batch-Payload, nicht aus der product-Tabelle - nur so
     * bleibt die previous*-Kette der Folge-Events exakt.
     *
     * @param list<array<string, mixed>> $events dekodierte Events des bestaetigten Batches
     */
    public function applyConfirmedEvents(array $events, ?\DateTimeImmutable $now = null): void
    {
        if ($events === []) {
            return;
        }

        $now ??= new \DateTimeImmutable();
        $updatedAt = $now->format('Y-m-d H:i:s.v');

        $this->connection->transactional(function (Connection $connection) use ($events, $updatedAt): void {
            foreach ($events as $event) {
                $productId = $event['productId'] ?? null;
                if (!\is_string($productId) || !Uuid::isValid($productId)) {
                    continue;
                }

                $connection->executeStatement(
                    'INSERT INTO emz_monitorio_stock_state (product_id, stock, available_stock, updated_at)
                     VALUES (:id, :stock, :availableStock, :updatedAt)
                     ON DUPLICATE KEY UPDATE
                        stock = VALUES(stock),
                        available_stock = VALUES(available_stock),
                        updated_at = VALUES(updated_at)',
                    [
                        'id' => Uuid::fromHexToBytes($productId),
                        'stock' => (int) ($event['stock'] ?? 0),
                        'availableStock' => (int) ($event['availableStock'] ?? 0),
                        'updatedAt' => $updatedAt,
                    ]
                );
            }
        });
    }

    /**
     * Raeumt Zustandszeilen geloeschter Produkte lokal auf - ohne Events zu
     * senden (Spec Abschnitt 7: v1 kennt keine Delete-Signale).
     */
    public function deleteOrphans(): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE s FROM emz_monitorio_stock_state s
             LEFT JOIN product p ON p.id = s.product_id AND p.version_id = :liveVersion
             WHERE p.id IS NULL',
            ['liveVersion' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );
    }

    private static function baseSelect(): string
    {
        return '
            SELECT
                LOWER(HEX(p.id)) AS id,
                COALESCE(p.product_number, \'\') AS product_number,
                COALESCE(t.name, tp.name) AS name,
                p.stock AS stock,
                COALESCE(p.available_stock, 0) AS available_stock,
                COALESCE(p.is_closeout, parent.is_closeout, 0) AS is_closeout,
                s.stock AS state_stock,
                s.available_stock AS state_available_stock,
                (s.product_id IS NOT NULL) AS has_state
            FROM product p
            LEFT JOIN product parent
                ON parent.id = p.parent_id AND parent.version_id = p.version_id
            LEFT JOIN emz_monitorio_stock_state s
                ON s.product_id = p.id
            LEFT JOIN product_translation t
                ON t.product_id = p.id AND t.product_version_id = p.version_id AND t.language_id = :languageId
            LEFT JOIN product_translation tp
                ON tp.product_id = parent.id AND tp.product_version_id = parent.version_id AND tp.language_id = :languageId
            WHERE p.version_id = :liveVersion
              AND (p.child_count = 0 OR p.child_count IS NULL)';
    }

    /**
     * @return array<string, string>
     */
    private static function baseParams(): array
    {
        return [
            'liveVersion' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            'languageId' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row): array
    {
        $hasState = (bool) $row['has_state'];

        return [
            'id' => (string) $row['id'],
            'product_number' => (string) $row['product_number'],
            'name' => $row['name'] === null ? null : (string) $row['name'],
            'stock' => (int) $row['stock'],
            'available_stock' => (int) $row['available_stock'],
            'is_closeout' => (bool) $row['is_closeout'],
            'has_state' => $hasState,
            'state_stock' => $hasState ? (int) $row['state_stock'] : null,
            'state_available_stock' => $hasState ? (int) $row['state_available_stock'] : null,
        ];
    }
}
