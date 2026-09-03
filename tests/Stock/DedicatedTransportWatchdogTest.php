<?php declare(strict_types=1);

namespace Emz\Monitorio\Tests\Stock;

use Doctrine\DBAL\Connection;
use Emz\Monitorio\Stock\DedicatedTransportWatchdog;
use Emz\Monitorio\Stock\StockPushConfig;
use Psr\Log\AbstractLogger;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class DedicatedTransportWatchdogTest extends StockDbTestCase
{
    /**
     * Fester UTC-Bezugspunkt: der echte Doctrine-Transport schreibt
     * created_at in UTC - die Fixtures bilden genau das nach.
     */
    private const NOW = '2026-01-01 12:00:00';

    private const MARKER_KEY = DedicatedTransportWatchdog::NOTIFIED_AT_KEY;

    private \ArrayObject $configStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configStore = new \ArrayObject();

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

    public function testWarnsAndNotifiesOnFirstStaleDetection(): void
    {
        $this->insertMessage(StockPushConfig::DEDICATED_TRANSPORT_NAME, ageSeconds: 700);
        $logger = $this->loggerSpy();
        $notifications = $this->createMock(EntityRepository::class);
        $notifications->expects(self::once())->method('create');

        $this->watchdog(enabled: true, logger: $logger, notifications: $notifications)->check($this->now());

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertStringContainsString('emz_monitorio', $logger->records[0]['message']);
        self::assertSame($this->now()->getTimestamp(), $this->configStore[self::MARKER_KEY] ?? null);
    }

    /**
     * Regressionsschutz gegen das fruehere Zeitfenster: auch wenn der erste
     * Check erst WEIT nach dem Schwellwert laeuft (langes Task-Intervall,
     * verzoegerter Scheduler - genau der Staufall), muss die Notification
     * kommen.
     */
    public function testNotifiesEvenWhenFirstDetectionIsLate(): void
    {
        $this->insertMessage(StockPushConfig::DEDICATED_TRANSPORT_NAME, ageSeconds: 5000);
        $notifications = $this->createMock(EntityRepository::class);
        $notifications->expects(self::once())->method('create');

        $this->watchdog(enabled: true, notifications: $notifications)->check($this->now());
    }

    public function testDoesNotNotifyAgainWhileMarkerIsFresh(): void
    {
        $this->insertMessage(StockPushConfig::DEDICATED_TRANSPORT_NAME, ageSeconds: 700);
        $logger = $this->loggerSpy();
        $notifications = $this->createMock(EntityRepository::class);
        $notifications->expects(self::once())->method('create');

        $watchdog = $this->watchdog(enabled: true, logger: $logger, notifications: $notifications);
        $watchdog->check($this->now());
        $watchdog->check($this->now()->modify('+300 seconds'));

        // Die Log-Warnung kommt weiterhin bei jedem Lauf.
        self::assertCount(2, $logger->records);
    }

    public function testRenotifiesWhenTheConditionPersistsPastADay(): void
    {
        $this->insertMessage(StockPushConfig::DEDICATED_TRANSPORT_NAME, ageSeconds: 90700);
        $this->configStore[self::MARKER_KEY] = $this->now()->getTimestamp() - 90000;
        $notifications = $this->createMock(EntityRepository::class);
        $notifications->expects(self::once())->method('create');

        $this->watchdog(enabled: true, notifications: $notifications)->check($this->now());
    }

    public function testClearsMarkerOnceTransportIsHealthyAgain(): void
    {
        $this->configStore[self::MARKER_KEY] = $this->now()->getTimestamp() - 300;

        $this->watchdog(enabled: true)->check($this->now());

        self::assertArrayNotHasKey(self::MARKER_KEY, $this->configStore->getArrayCopy());
    }

    public function testClearsMarkerWellBelowTheThreshold(): void
    {
        $this->insertMessage(StockPushConfig::DEDICATED_TRANSPORT_NAME, ageSeconds: 200);
        $this->configStore[self::MARKER_KEY] = $this->now()->getTimestamp() - 300;

        $this->watchdog(enabled: true)->check($this->now());

        self::assertArrayNotHasKey(self::MARKER_KEY, $this->configStore->getArrayCopy());
    }

    /**
     * Hysterese: Pendelt das Alter um die Warnschwelle, darf der Marker im
     * Band dazwischen nicht fallen - sonst gaebe es je Pendelzyklus eine neue
     * Notification.
     */
    public function testKeepsMarkerInsideTheHysteresisBand(): void
    {
        $this->insertMessage(StockPushConfig::DEDICATED_TRANSPORT_NAME, ageSeconds: 400);
        $marker = $this->now()->getTimestamp() - 300;
        $this->configStore[self::MARKER_KEY] = $marker;
        $logger = $this->loggerSpy();

        $this->watchdog(enabled: true, logger: $logger)->check($this->now());

        self::assertSame($marker, $this->configStore[self::MARKER_KEY] ?? null);
        self::assertSame([], $logger->records);
    }

    public function testDoesNotRenotifyAfterABriefDipIntoTheHysteresisBand(): void
    {
        $this->insertMessage(StockPushConfig::DEDICATED_TRANSPORT_NAME, ageSeconds: 700);
        // Vorherige Notification liegt kurz zurueck; dazwischen fiel das Alter
        // einmal ins Hysterese-Band (kein eigener Check noetig - der Marker
        // bleibt dort ohnehin stehen, siehe Test darueber).
        $this->configStore[self::MARKER_KEY] = $this->now()->getTimestamp() - 900;
        $notifications = $this->createMock(EntityRepository::class);
        $notifications->expects(self::never())->method('create');

        $this->watchdog(enabled: true, notifications: $notifications)->check($this->now());
    }

    /**
     * Der Doctrine-Transport schreibt created_at in UTC; der Watchdog muss den
     * TZ-losen String auch als UTC parsen. Mit einer PHP-Default-TZ westlich
     * von UTC waere das Alter sonst um Stunden unterschaetzt und der Watchdog
     * bliebe genau im Fehlerfall stumm.
     */
    public function testAgeIsComputedInUtcRegardlessOfPhpTimezone(): void
    {
        $this->insertMessage(StockPushConfig::DEDICATED_TRANSPORT_NAME, ageSeconds: 700);
        $logger = $this->loggerSpy();

        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Pago_Pago');

        try {
            $this->watchdog(enabled: true, logger: $logger)->check($this->now());
        } finally {
            date_default_timezone_set($previousTimezone);
        }

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

        $watchdog = new DedicatedTransportWatchdog(
            $connection,
            new StockPushConfig($this->systemConfigFake(true)),
            $this->systemConfigFake(true),
            $logger
        );
        $watchdog->check($this->now());

        self::assertCount(1, $logger->records);
        self::assertSame('debug', $logger->records[0]['level']);
    }

    private function insertMessage(string $queue, int $ageSeconds, bool $delivered = false): void
    {
        $connection = self::connection();
        \assert($connection !== null);

        // UTC-Wandzeit, wie der echte Doctrine-Transport sie ablegt.
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
        ?AbstractLogger $logger = null,
        ?EntityRepository $notifications = null,
    ): DedicatedTransportWatchdog {
        $connection = self::connection();
        \assert($connection !== null);

        $systemConfig = $this->systemConfigFake($enabled);

        return new DedicatedTransportWatchdog(
            $connection,
            new StockPushConfig($systemConfig),
            $systemConfig,
            $logger ?? $this->loggerSpy(),
            $notifications
        );
    }

    /**
     * Stateful Fake: der Schalter ist fix, der Notification-Marker lebt im
     * geteilten $configStore, damit Tests gesetzte/geloeschte Marker pruefen
     * koennen.
     */
    private function systemConfigFake(bool $enabled): SystemConfigService
    {
        $store = $this->configStore ?? new \ArrayObject();

        $mock = $this->createMock(SystemConfigService::class);
        $mock->method('get')->willReturnCallback(
            static fn (string $key): mixed => $key === 'EmzMonitorio.config.useDedicatedTransport'
                ? $enabled
                : ($store[$key] ?? null)
        );
        $mock->method('set')->willReturnCallback(
            static function (string $key, mixed $value) use ($store): void {
                $store[$key] = $value;
            }
        );
        $mock->method('delete')->willReturnCallback(
            static function (string $key) use ($store): void {
                unset($store[$key]);
            }
        );

        return $mock;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
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
