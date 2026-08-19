<?php declare(strict_types=1);

namespace Emz\Monitorio\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Tabellen fuer den Stock-Push (docs/stock_push_konzept.md, Abschnitt 3).
 *
 * Beide sind reine Infrastruktur-Tabellen ohne DAL-Entity: kein Admin-CRUD,
 * keine Associations, keine API-Exposition - Zugriff ausschliesslich per DBAL.
 */
class Migration1787178130StockPushTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1787178130;
    }

    public function update(Connection $connection): void
    {
        // Zuletzt ERFOLGREICH an Monitorio gemeldeter Stand je Leaf-Produkt.
        // Wird nur nach Response 204 fortgeschrieben, mit den Werten aus dem
        // bestaetigten Batch - so bleibt die previous*-Kette der Events exakt.
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `emz_monitorio_stock_state` (
                `product_id` BINARY(16) NOT NULL,
                `stock` BIGINT NOT NULL,
                `available_stock` BIGINT NOT NULL,
                `updated_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        // Persistierte Batches: Fallback-Puffer und Retry-Speicher in einem.
        // Der Payload ist der fertige JSON-Request-Body inklusive batchId -
        // Retries senden dadurch garantiert identische Bytes und dieselbe batchId,
        // auch ueber Prozess-Neustarts hinweg.
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `emz_monitorio_stock_outbox` (
                `batch_id` VARCHAR(26) NOT NULL,
                `payload` LONGTEXT NOT NULL,
                `event_count` INT NOT NULL,
                `attempts` INT NOT NULL DEFAULT 0,
                `next_retry_at` DATETIME(3) NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`batch_id`),
                KEY `idx.emz_monitorio_stock_outbox.due` (`next_retry_at`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }
}
