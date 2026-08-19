<?php declare(strict_types=1);

namespace Emz\Monitorio\Tests\Stock;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Emz\Monitorio\Migration\Migration1787178130StockPushTables;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Basis fuer DB-nahe Stock-Tests: eigene Test-Datenbank (aus DATABASE_URL
 * abgeleitet) mit Minimal-Schema von product/product_translation - nur die
 * Spalten, die die Stock-Queries lesen - plus die Plugin-Tabellen ueber die
 * ECHTE Migration. Kein Shopware-Kernel noetig.
 */
abstract class StockDbTestCase extends TestCase
{
    private const TEST_DATABASE = 'emz_monitorio_stock_test';

    protected static ?Connection $connection = null;

    private static bool $initialized = false;

    private static ?string $skipReason = null;

    protected function setUp(): void
    {
        $connection = self::connection();

        if ($connection === null) {
            self::markTestSkipped(self::$skipReason ?? 'Keine Test-Datenbank verfuegbar.');
        }

        foreach (['product_translation', 'product', 'emz_monitorio_stock_state', 'emz_monitorio_stock_outbox'] as $table) {
            $connection->executeStatement('DELETE FROM ' . $table);
        }
    }

    protected static function connection(): ?Connection
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        if (self::$initialized) {
            return null;
        }

        self::$initialized = true;

        // Expliziter Override (z. B. CI): kompletter DSN inklusive dbname.
        $explicitDsn = $_SERVER['EMZ_MONITORIO_TEST_DATABASE_URL'] ?? getenv('EMZ_MONITORIO_TEST_DATABASE_URL');
        $dsn = \is_string($explicitDsn) && $explicitDsn !== ''
            ? $explicitDsn
            : ($_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL'));

        if (!\is_string($dsn) || $dsn === '') {
            self::$skipReason = 'Weder EMZ_MONITORIO_TEST_DATABASE_URL noch DATABASE_URL gesetzt.';

            return null;
        }

        $parser = new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql']);
        $params = $parser->parse($dsn);

        try {
            if (\is_string($explicitDsn) && $explicitDsn !== '') {
                $testDbName = (string) ($params['dbname'] ?? '');
            } else {
                $testDbName = self::TEST_DATABASE;
                $bootstrap = DriverManager::getConnection($params);
                $bootstrap->executeStatement(
                    'CREATE DATABASE IF NOT EXISTS ' . $testDbName
                    . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
                );
                $params['dbname'] = $testDbName;
            }

            // Die Tests leeren product & Co. - niemals gegen eine Nicht-Test-DB laufen.
            if (!str_contains($testDbName, 'test')) {
                self::$skipReason = 'Test-Datenbankname "' . $testDbName . '" enthaelt kein "test" - abgebrochen.';

                return null;
            }

            $connection = DriverManager::getConnection($params);
            self::createSchema($connection);
        } catch (\Throwable $e) {
            self::$skipReason = 'Test-Datenbank nicht nutzbar: ' . $e->getMessage()
                . ' (Abhilfe: GRANT ALL ON ' . self::TEST_DATABASE . ".* TO '<user>'@'%' "
                . 'oder EMZ_MONITORIO_TEST_DATABASE_URL setzen.)';

            return null;
        }

        return self::$connection = $connection;
    }

    private static function createSchema(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `product` (
                `id` BINARY(16) NOT NULL,
                `version_id` BINARY(16) NOT NULL,
                `parent_id` BINARY(16) NULL,
                `product_number` VARCHAR(64) NULL,
                `stock` INT NOT NULL,
                `available_stock` INT NULL,
                `is_closeout` TINYINT(1) NULL,
                `child_count` INT NULL,
                PRIMARY KEY (`id`, `version_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `product_translation` (
                `product_id` BINARY(16) NOT NULL,
                `product_version_id` BINARY(16) NOT NULL,
                `language_id` BINARY(16) NOT NULL,
                `name` VARCHAR(255) NULL,
                PRIMARY KEY (`product_id`, `product_version_id`, `language_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        (new Migration1787178130StockPushTables())->update($connection);
    }

    protected static function insertProduct(
        string $idHex,
        int $stock,
        ?int $availableStock,
        string $productNumber = 'SW-1001',
        ?string $name = 'Beispielprodukt',
        ?bool $isCloseout = false,
        ?string $parentIdHex = null,
        ?int $childCount = 0,
    ): void {
        $connection = self::connection();
        \assert($connection !== null);

        $connection->executeStatement(
            'INSERT INTO product (id, version_id, parent_id, product_number, stock, available_stock, is_closeout, child_count)
             VALUES (:id, :versionId, :parentId, :productNumber, :stock, :availableStock, :isCloseout, :childCount)',
            [
                'id' => Uuid::fromHexToBytes($idHex),
                'versionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                'parentId' => $parentIdHex === null ? null : Uuid::fromHexToBytes($parentIdHex),
                'productNumber' => $productNumber,
                'stock' => $stock,
                'availableStock' => $availableStock,
                'isCloseout' => $isCloseout === null ? null : (int) $isCloseout,
                'childCount' => $childCount,
            ]
        );

        if ($name !== null) {
            $connection->executeStatement(
                'INSERT INTO product_translation (product_id, product_version_id, language_id, name)
                 VALUES (:id, :versionId, :languageId, :name)',
                [
                    'id' => Uuid::fromHexToBytes($idHex),
                    'versionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                    'languageId' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
                    'name' => $name,
                ]
            );
        }
    }

    protected static function insertState(string $idHex, int $stock, int $availableStock): void
    {
        $connection = self::connection();
        \assert($connection !== null);

        $connection->executeStatement(
            'INSERT INTO emz_monitorio_stock_state (product_id, stock, available_stock, updated_at)
             VALUES (:id, :stock, :availableStock, NOW(3))',
            [
                'id' => Uuid::fromHexToBytes($idHex),
                'stock' => $stock,
                'availableStock' => $availableStock,
            ]
        );
    }

    /**
     * @return array{stock: int, available_stock: int}|null
     */
    protected static function fetchState(string $idHex): ?array
    {
        $connection = self::connection();
        \assert($connection !== null);

        $row = $connection->fetchAssociative(
            'SELECT stock, available_stock FROM emz_monitorio_stock_state WHERE product_id = :id',
            ['id' => Uuid::fromHexToBytes($idHex)]
        );

        return $row === false
            ? null
            : ['stock' => (int) $row['stock'], 'available_stock' => (int) $row['available_stock']];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function fetchOutboxRows(): array
    {
        $connection = self::connection();
        \assert($connection !== null);

        return $connection->fetchAllAssociative(
            'SELECT batch_id, payload, event_count, attempts, next_retry_at, created_at
             FROM emz_monitorio_stock_outbox ORDER BY created_at, batch_id'
        );
    }

    protected static function makeOutboxDue(): void
    {
        $connection = self::connection();
        \assert($connection !== null);

        $connection->executeStatement('UPDATE emz_monitorio_stock_outbox SET next_retry_at = NULL');
    }

    protected static function productId(int $number): string
    {
        return str_pad(dechex($number + 1), 32, '0', \STR_PAD_LEFT);
    }
}
