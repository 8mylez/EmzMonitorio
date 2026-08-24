<?php declare(strict_types=1);

namespace Emz\Monitorio\Tests\Stock;

use Emz\Monitorio\Stock\MonitorioStockClient;
use Emz\Monitorio\Stock\StockEventBuilder;
use Emz\Monitorio\Stock\StockOutbox;
use Emz\Monitorio\Stock\StockPushConfig;
use Emz\Monitorio\Stock\StockPushService;
use Emz\Monitorio\Stock\StockStateStore;
use Psr\Log\NullLogger;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Welche Produkt-Spalte `stock` und `availableStock` speist - ueber alle drei
 * Datenpfade (Subscriber, Reconciliation, Baseline) und beide Shopware-Welten.
 *
 * `stock` kommt immer aus `product.stock`. Fuer `availableStock` entscheidet die
 * Shopware-Version (siehe StockStateStore::availableStockExpression()):
 *
 * - bis 6.5: `product.available_stock` ist Bestand abzueglich offener Bestellungen
 *   und damit ein eigenstaendiger Wert.
 * - ab 6.6: `product.available_stock` ist nur noch ein write-protected Spiegel von
 *   `product.stock`, den ausschliesslich DAL-Writes und der Order-Lifecycle
 *   nachziehen - ein Direkt-SQL-Import laesst ihn veralten. Quelle ist daher `stock`.
 *
 * Alle Faelle nutzen ABSICHTLICH unterschiedliche Werte fuer beide Spalten: mit
 * gleichen Werten waeren Vertauschen, Kopieren oder Vorzeichenfehler unsichtbar.
 * Fuer Monitorio ist der Unterschied kritisch - Alarm- und Out-of-stock-Logik
 * werten ausschliesslich `availableStock <= 0` aus.
 */
final class StockValueMappingTest extends StockDbTestCase
{
    private \DateTimeImmutable $occurredAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->occurredAt = new \DateTimeImmutable('2026-08-19T10:15:03+02:00');
    }

    public function testLegacyShopReadsTheAvailableStockColumn(): void
    {
        self::insertProduct(self::productId(1), stock: 12, availableStock: 10);

        $event = $this->baselineEvent(self::SHOPWARE_LEGACY_STOCK);

        self::assertSame(12, $event['stock']);
        self::assertSame(10, $event['availableStock'], 'Bis 6.5 ist available_stock ein eigener Wert');
        self::assertSame(['stock' => 12, 'available_stock' => 10], self::fetchState(self::productId(1)));
    }

    public function testModernShopReadsAvailableStockFromTheStockColumn(): void
    {
        self::insertProduct(self::productId(1), stock: 12, availableStock: 10);

        $event = $this->baselineEvent(self::SHOPWARE_MIRRORED_STOCK);

        self::assertSame(12, $event['stock']);
        self::assertSame(12, $event['availableStock'], 'Ab 6.6 ist stock die Quelle, der Spiegel wird ignoriert');
        self::assertSame(['stock' => 12, 'available_stock' => 12], self::fetchState(self::productId(1)));
    }

    public function testModernShopReportsErpStockWriteThatLeftTheMirrorStale(): void
    {
        // Direkt-SQL-Import eines ERP: schreibt product.stock auf 0, laesst den
        // Spiegel available_stock auf 50 stehen. Genau der Fall, fuer den die
        // Reconciliation existiert - und der als went_oos ankommen muss.
        self::insertProduct(self::productId(1), stock: 0, availableStock: 50);
        self::insertState(self::productId(1), stock: 50, availableStock: 50);

        $sent = [];
        $this->service($sent, self::SHOPWARE_MIRRORED_STOCK)->runReconciliation();

        $event = $this->singleEvent($sent[0]['body']);
        self::assertSame('reconciliation', $event['source']);
        self::assertSame(0, $event['stock']);
        self::assertSame(0, $event['availableStock'], 'Der veraltete Spiegel darf den Alarm nicht unterdruecken');
        self::assertSame(50, $event['previousStock']);
        self::assertSame(50, $event['previousAvailableStock']);

        self::assertSame(['stock' => 0, 'available_stock' => 0], self::fetchState(self::productId(1)));
    }

    public function testLegacyShopDetectsAnIsolatedAvailableStockChange(): void
    {
        // Bis 6.5 der Normalfall: offene Bestellungen druecken available_stock auf 0,
        // der physische Bestand bleibt stehen.
        self::insertProduct(self::productId(1), stock: 10, availableStock: 0);
        self::insertState(self::productId(1), stock: 10, availableStock: 5);

        $sent = [];
        $this->service($sent, self::SHOPWARE_LEGACY_STOCK)->runReconciliation();

        $event = $this->singleEvent($sent[0]['body']);
        self::assertSame('reconciliation', $event['source']);
        self::assertSame(10, $event['stock']);
        self::assertSame(0, $event['availableStock']);
        self::assertSame(10, $event['previousStock']);
        self::assertSame(5, $event['previousAvailableStock']);
    }

    public function testLegacyShopFallsBackToZeroForNullAvailableStock(): void
    {
        // `product.available_stock` ist nullable; bis 6.5 heisst NULL "noch nie
        // berechnet" - der physische Bestand darf dann NICHT einspringen.
        self::insertProduct(self::productId(1), stock: 7, availableStock: null);

        $event = $this->baselineEvent(self::SHOPWARE_LEGACY_STOCK);

        self::assertSame(7, $event['stock']);
        self::assertSame(0, $event['availableStock']);
    }

    public function testModernShopNeedsNoFallbackForNullAvailableStock(): void
    {
        // Ab 6.6 ist die NULL-Spalte belanglos: stock ist NOT NULL und die Quelle.
        self::insertProduct(self::productId(1), stock: 7, availableStock: null);

        $event = $this->baselineEvent(self::SHOPWARE_MIRRORED_STOCK);

        self::assertSame(7, $event['stock']);
        self::assertSame(7, $event['availableStock']);
    }

    public function testSubscriberCarriesPreviousValuesFromTheStateTable(): void
    {
        self::insertProduct(self::productId(1), stock: 12, availableStock: 12);
        self::insertState(self::productId(1), stock: 5, availableStock: 3);

        $sent = [];
        $this->service($sent, self::SHOPWARE_MIRRORED_STOCK)
            ->pushForProducts([self::productId(1)], $this->occurredAt);

        $event = $this->singleEvent($sent[0]['body']);
        self::assertSame(12, $event['stock']);
        self::assertSame(12, $event['availableStock']);
        self::assertSame(5, $event['previousStock'], 'previous* kommen aus der Zustandstabelle, nicht aus product');
        self::assertSame(3, $event['previousAvailableStock']);
    }

    public function testBaselineReportsNullPreviousValues(): void
    {
        self::insertProduct(self::productId(1), stock: 12, availableStock: 12);

        $event = $this->baselineEvent(self::SHOPWARE_MIRRORED_STOCK);

        self::assertSame('baseline', $event['source']);
        self::assertNull($event['previousStock']);
        self::assertNull($event['previousAvailableStock']);
    }

    public function testNegativeStockIsReportedWithItsSign(): void
    {
        // Ueberverkauf ohne Abverkauf-Flag: Shopware laesst negative Bestaende zu.
        self::insertProduct(self::productId(1), stock: -4, availableStock: -7);

        $event = $this->baselineEvent(self::SHOPWARE_LEGACY_STOCK);

        self::assertSame(-4, $event['stock']);
        self::assertSame(-7, $event['availableStock']);
        self::assertSame(['stock' => -4, 'available_stock' => -7], self::fetchState(self::productId(1)));
    }

    public function testOnlyVariantsAreReportedAndCloseoutIsInheritedFromParent(): void
    {
        // Parent mit Abverkauf-Flag, Variante 1 erbt es, Variante 2 ueberschreibt es.
        self::insertProduct(self::productId(10), stock: 0, availableStock: 0, productNumber: 'SW-PARENT', name: 'Parent', isCloseout: true, childCount: 2);
        self::insertProduct(self::productId(11), stock: 4, availableStock: 4, productNumber: 'SW-V1', name: null, isCloseout: null, parentIdHex: self::productId(10));
        self::insertProduct(self::productId(12), stock: 9, availableStock: 9, productNumber: 'SW-V2', name: null, isCloseout: false, parentIdHex: self::productId(10));

        $sent = [];
        $service = $this->service($sent, self::SHOPWARE_MIRRORED_STOCK);
        $service->enqueueBaseline();
        $service->flushOutbox();

        $events = json_decode($sent[0]['body'], true, 512, \JSON_THROW_ON_ERROR)['events'];
        $byNumber = array_column($events, null, 'productNumber');

        self::assertSame(['SW-V1', 'SW-V2'], array_keys($byNumber), 'Bestand lebt auf den Leaf-Produkten - der Parent wird nicht gemeldet');

        self::assertSame(4, $byNumber['SW-V1']['stock']);
        self::assertSame(4, $byNumber['SW-V1']['availableStock']);
        self::assertTrue($byNumber['SW-V1']['isCloseout'], 'isCloseout wird vom Parent geerbt');

        self::assertSame(9, $byNumber['SW-V2']['stock']);
        self::assertSame(9, $byNumber['SW-V2']['availableStock']);
        self::assertFalse($byNumber['SW-V2']['isCloseout'], 'Eigener Wert der Variante schlaegt den Parent');
    }

    /**
     * Baseline fuer genau ein Produkt senden und dessen Event liefern.
     *
     * @return array<string, mixed>
     */
    private function baselineEvent(string $shopwareVersion): array
    {
        $sent = [];
        $service = $this->service($sent, $shopwareVersion);
        $service->enqueueBaseline();
        $service->flushOutbox();

        return $this->singleEvent($sent[0]['body']);
    }

    /**
     * @param list<array{url: string, body: string}> $sent aufgezeichnete Requests (by reference)
     */
    private function service(array &$sent, string $shopwareVersion): StockPushService
    {
        $connection = self::connection();
        \assert($connection !== null);

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getInt')->willReturn(1);
        $systemConfig->method('getString')->willReturnCallback(static fn (string $key): string => match ($key) {
            'EmzMonitorio.config.ingestToken' => 'test-ingest-token',
            'EmzMonitorio.config.monitorioBaseUrl' => 'http://monitorio.test',
            default => '',
        });
        $config = new StockPushConfig($systemConfig);

        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent[] = ['url' => $url, 'body' => (string) $options['body']];

            return new MockResponse('', ['http_code' => 204]);
        });

        return new StockPushService(
            $config,
            new StockStateStore($connection, $shopwareVersion),
            new StockOutbox($connection),
            new StockEventBuilder(),
            new MonitorioStockClient($httpClient, $config),
            new NullLogger(),
            null
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function singleEvent(string $body): array
    {
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertCount(1, $decoded['events']);

        return $decoded['events'][0];
    }
}
