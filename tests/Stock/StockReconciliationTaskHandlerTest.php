<?php declare(strict_types=1);

namespace Emz\Monitorio\Tests\Stock;

use Doctrine\DBAL\Connection;
use Emz\Monitorio\Stock\DedicatedTransportWatchdog;
use Emz\Monitorio\Stock\Message\StockReconciliationMessage;
use Emz\Monitorio\Stock\ScheduledTask\StockReconciliationTaskHandler;
use Emz\Monitorio\Stock\StockPushConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class StockReconciliationTaskHandlerTest extends TestCase
{
    public function testDispatchesReconciliationMessageWithoutStampsByDefault(): void
    {
        $bus = $this->busSpy();
        $connection = $this->createMock(Connection::class);
        // Watchdog ist bei deaktiviertem Schalter ein No-op - keine DB-Query.
        $connection->expects(self::never())->method('fetchOne');

        $this->handler($bus, $connection, $this->config())->run();

        self::assertCount(1, $bus->messages);
        self::assertInstanceOf(StockReconciliationMessage::class, $bus->messages[0]);
        self::assertSame([[]], $bus->stampLists);
    }

    public function testDispatchesWithDedicatedStampAndRunsWatchdogWhenEnabled(): void
    {
        $bus = $this->busSpy();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->willReturn(false);

        $this->handler($bus, $connection, $this->config(useDedicatedTransport: true))->run();

        self::assertCount(1, $bus->messages);
        $stamps = $bus->stampLists[0];
        self::assertCount(1, $stamps);
        self::assertInstanceOf(TransportNamesStamp::class, $stamps[0]);
        self::assertSame([StockPushConfig::DEDICATED_TRANSPORT_NAME], $stamps[0]->getTransportNames());
    }

    public function testDoesNothingWhenPushIsNotConfigured(): void
    {
        $bus = $this->busSpy();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');

        $this->handler($bus, $connection, $this->config(ingestToken: ''))->run();

        self::assertSame([], $bus->messages);
    }

    private function handler(
        MessageBusInterface $bus,
        Connection $connection,
        StockPushConfig $config,
    ): StockReconciliationTaskHandler {
        return new StockReconciliationTaskHandler(
            $this->createMock(EntityRepository::class),
            new NullLogger(),
            $bus,
            $config,
            new DedicatedTransportWatchdog($connection, $config, new NullLogger())
        );
    }

    /**
     * @return MessageBusInterface&object{messages: list<object>, stampLists: list<array<int, object>>}
     */
    private function busSpy(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            /** @var list<object> */
            public array $messages = [];

            /** @var list<array<int, object>> */
            public array $stampLists = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->messages[] = $message;
                $this->stampLists[] = $stamps;

                return new Envelope($message);
            }
        };
    }

    private function config(int $projectId = 1, string $ingestToken = 'token', mixed $useDedicatedTransport = null): StockPushConfig
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getInt')->willReturn($projectId);
        $systemConfig->method('getString')->willReturnCallback(static fn (string $key): string => match ($key) {
            'EmzMonitorio.config.ingestToken' => $ingestToken,
            default => '',
        });
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key): mixed => $key === 'EmzMonitorio.config.useDedicatedTransport'
                ? $useDedicatedTransport
                : null
        );

        return new StockPushConfig($systemConfig);
    }
}
