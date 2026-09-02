<?php declare(strict_types=1);

namespace Emz\Monitorio\Tests\Stock;

use Doctrine\DBAL\Connection;
use Emz\Monitorio\Stock\DedicatedTransportWatchdog;
use Emz\Monitorio\Stock\StockPushConfig;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class DedicatedTransportWatchdogTest extends StockDbTestCase
{
    private const NOW = '2026-01-01 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $connection = self::connection();
        \assert($connection !== null);

        // Minimal-Schema des Doctrine-Transports: nur die Spalten, die der
        // Watchdog liest, plus die NOT-NULL-Pflichtfelder.
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `messenger_messages` (
                `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                `body` LONGTEXT NOT NULL,
                `headers` LONGTEXT NOT NULL,
                `queue_name` VARCHAR(190) NOT NULL,
                `created_at` DATETIME NOT NULL,
                `available_at` DATETIME NOT NULL,
                `delivered_at` DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
        $connection->executeStatement('DELETE FROM messenger_messages');
    }

    public function testStaysSilentWhenDisabledEvenWithStaleMessages(): void
    {
        $this->insertMessage(StockPushConfig::DEDICATED_TRANSPORT_NAME, ageSeconds: 5000);
        $logger = $this->loggerSpy();

        $this->watchdog(enabled: false, logger: $logger)->check($this->now());

        self::assertSame([], $logger->records);
    }

    public function testStaysSilentOnEmptyTransport(): void
    {
        $logger = $this->loggerSpy();

        $this->watchdog(enabled: true, logger: $logger)->check($this->now());

        self::assertSame([], $logger->records);
    }

    public function testStaysSilentForFreshMessages(): void
    {
        $this->insertMessage(StockPushConfig::DEDICATED_TRANSPORT_NAME, ageSeconds: 60);
        $logger = $this->loggerSpy();

        $this->watchdog(enabled: true, logger: $logger)->check($this->now());

        self::assertSame([], $logger->records);
    }

    public function testWarnsAndNotifiesWhenOldestMessageJustTurnedStale(): void
    {
        $this->insertMessage(StockPushConfig::DEDICATED_TRANSPORT_NAME, ageSeconds: 700);
        $logger = $this->loggerSpy();
        $notifications = $this->createMock(EntityRepository::class);
        $notifications->expects(self::once())->method('create');

        $this->watchdog(enabled: true, logger: $logger, notifications: $notifications)->check($this->now());

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertStringContainsString('emz_monitorio', $logger->records[0]['message']);
    }

    public function testKeepsWarningButStopsNotifyingLongAfterThreshold(): void
    {
        $this->insertMessage(StockPushConfig::DEDICATED_TRANSPORT_NAME, ageSeconds: 2000);
        $logger = $this->loggerSpy();
        $notifications = $this->createMock(EntityRepository::class);
        $notifications->expects(self::never())->method('create');

        $this->watchdog(enabled: true, logger: $logger, notifications: $notifications)->check($this->now());

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
    }

    public function testIgnoresDeliveredMessages(): void
    {
        $this->insertMessage(StockPushConfig::DEDICATED_TRANSPORT_NAME, ageSeconds: 700, delivered: true);
        $logger = $this->loggerSpy();

        $this->watchdog(enabled: true, logger: $logger)->check($this->now());

        self::assertSame([], $logger->records);
    }

    public function testIgnoresOtherQueues(): void
    {
        $this->insertMessage('async', ageSeconds: 700);
        $logger = $this->loggerSpy();

        $this->watchdog(enabled: true, logger: $logger)->check($this->now());

        self::assertSame([], $logger->records);
    }

    /**
     * Ein per ENV auf einen anderen Broker umgebogener Transport hat keine
     * messenger_messages-Zeilen bzw. im Extremfall keine Tabelle - der
     * Watchdog darf den ScheduledTask deshalb nie brechen.
     */
    public function testSurvivesFailingQuery(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('table gone'));
        $logger = $this->loggerSpy();

        $watchdog = new DedicatedTransportWatchdog($connection, $this->config(true), $logger);
        $watchdog->check($this->now());

        self::assertCount(1, $logger->records);
        self::assertSame('debug', $logger->records[0]['level']);
    }

    private function insertMessage(string $queue, int $ageSeconds, bool $delivered = false): void
    {
        $connection = self::connection();
        \assert($connection !== null);

        $createdAt = $this->now()->modify('-' . $ageSeconds . ' seconds')->format('Y-m-d H:i:s');

        $connection->executeStatement(
            'INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at, delivered_at)
             VALUES (:body, :headers, :queue, :createdAt, :createdAt, :deliveredAt)',
            [
                'body' => '{}',
                'headers' => '[]',
                'queue' => $queue,
                'createdAt' => $createdAt,
                'deliveredAt' => $delivered ? $createdAt : null,
            ]
        );
    }

    private function watchdog(
        bool $enabled,
        AbstractLogger $logger = new NullLogger(),
        ?EntityRepository $notifications = null,
    ): DedicatedTransportWatchdog {
        $connection = self::connection();
        \assert($connection !== null);

        return new DedicatedTransportWatchdog($connection, $this->config($enabled), $logger, $notifications);
    }

    private function config(bool $enabled): StockPushConfig
    {
        /** @var SystemConfigService&MockObject $systemConfig */
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key): mixed => $key === 'EmzMonitorio.config.useDedicatedTransport'
                ? $enabled
                : null
        );

        return new StockPushConfig($systemConfig);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    /**
     * @return AbstractLogger&object{records: list<array{level: string, message: string}>}
     */
    private function loggerSpy(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: string, message: string}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
            }
        };
    }
}
