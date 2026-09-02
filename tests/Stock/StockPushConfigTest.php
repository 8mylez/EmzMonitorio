<?php declare(strict_types=1);

namespace Emz\Monitorio\Tests\Stock;

use Emz\Monitorio\Stock\StockPushConfig;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class StockPushConfigTest extends TestCase
{
    /**
     * `bin/console system:config:set <key> false` legt den String "false" ab; ein
     * (bool)-Cast wuerde daraus true machen - und Messages in einen Transport
     * routen, den womoeglich niemand konsumiert. Unklare Werte muessen als
     * "Standard-Transport" zaehlen.
     *
     * @dataProvider ambiguousOrFalsyValues
     */
    public function testDedicatedTransportCountsAsDisabledForAmbiguousValues(mixed $stored): void
    {
        self::assertFalse($this->config($stored)->isDedicatedTransportEnabled());
        self::assertSame([], $this->config($stored)->transportStamps());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function ambiguousOrFalsyValues(): iterable
    {
        yield 'string false' => ['false'];
        yield 'null (nie gesetzt)' => [null];
        yield 'leerer String' => [''];
        yield 'bool false' => [false];
        yield 'string 0' => ['0'];
        yield 'unsinniger String' => ['banana'];
    }

    /**
     * @dataProvider truthyValues
     */
    public function testDedicatedTransportEnabledForTruthyValues(mixed $stored): void
    {
        self::assertTrue($this->config($stored)->isDedicatedTransportEnabled());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function truthyValues(): iterable
    {
        yield 'bool true (Admin-UI)' => [true];
        yield 'string true' => ['true'];
        yield 'string 1' => ['1'];
    }

    public function testTransportStampsCarryTheDedicatedTransportWhenEnabled(): void
    {
        $stamps = $this->config(true)->transportStamps();

        self::assertCount(1, $stamps);
        self::assertInstanceOf(TransportNamesStamp::class, $stamps[0]);
        self::assertSame([StockPushConfig::DEDICATED_TRANSPORT_NAME], $stamps[0]->getTransportNames());
    }

    private function config(mixed $useDedicatedTransport): StockPushConfig
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key): mixed => $key === 'EmzMonitorio.config.useDedicatedTransport'
                ? $useDedicatedTransport
                : null
        );

        return new StockPushConfig($systemConfig);
    }
}
