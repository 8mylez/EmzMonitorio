<?php declare(strict_types=1);

namespace Emz\Monitorio\Tests\Stock;

use Emz\Monitorio\Stock\MonitorioStockClient;
use Emz\Monitorio\Stock\StockEventBuilder;
use Emz\Monitorio\Stock\StockOutbox;
use Emz\Monitorio\Stock\StockPushConfig;
use Emz\Monitorio\Stock\StockPushService;
use Emz\Monitorio\Stock\StockStateStore;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Statuscode-Handling exakt nach der Contract-Tabelle (Spec Abschnitt 3),
 * gegen einen Mock-Endpunkt und eine echte Datenbank. Kernpunkte:
 * Zustandstabelle erst NACH 204, Retries mit IDENTISCHER batchId,
 * 413 => kleinere NEUE Batches mit NEUEN batchIds.
 */
final class StockPushServiceTest extends StockDbTestCase
{
    private \DateTimeImmutable $occurredAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->occurredAt = new \DateTimeImmutable('2026-08-19T10:15:03+02:00');
    }

    public function test204ConfirmsBatchWritesStateAndClearsOutbox(): void
    {
        self::insertProduct(self::productId(1), stock: 12, availableStock: 10);
        self::insertState(self::productId(1), stock: 0, availableStock: 0);

        $sent = [];
        $service = $this->service([new MockResponse('', ['http_code' => 204])], $sent);

        $service->pushForProducts([self::productId(1)], $this->occurredAt);

        self::assertCount(1, $sent);
        $event = $this->singleEvent($sent[0]['body']);
        self::assertSame('subscriber', $event['source']);
        self::assertSame(12, $event['stock']);
        self::assertSame(10, $event['availableStock']);
        self::assertSame(0, $event['previousStock']);
        self::assertSame(0, $event['previousAvailableStock']);
        self::assertSame('Beispielprodukt', $event['name']);
        self::assertFalse($event['isCloseout']);

        self::assertSame(['stock' => 12, 'available_stock' => 10], self::fetchState(self::productId(1)));
        self::assertSame([], self::fetchOutboxRows());
    }

    public function testUnknownProductIsSentAsBaselineEvent(): void
    {
        self::insertProduct(self::productId(1), stock: 5, availableStock: 5);

        $sent = [];
        $service = $this->service([new MockResponse('', ['http_code' => 204])], $sent);

        $service->pushForProducts([self::productId(1)], $this->occurredAt);

        $event = $this->singleEvent($sent[0]['body']);
        self::assertSame('baseline', $event['source']);
        self::assertNull($event['previousStock']);
        self::assertNull($event['previousAvailableStock']);

        self::assertSame(['stock' => 5, 'available_stock' => 5], self::fetchState(self::productId(1)));
    }

    public function testUnchangedProductSendsNothing(): void
    {
        self::insertProduct(self::productId(1), stock: 5, availableStock: 5);
        self::insertState(self::productId(1), stock: 5, availableStock: 5);

        $sent = [];
        $service = $this->service([], $sent);

        $service->pushForProducts([self::productId(1)], $this->occurredAt);

        self::assertSame([], $sent);
        self::assertSame([], self::fetchOutboxRows());
    }

    public function testTransientErrorRetriesWithIdenticalBatchIdAndBody(): void
    {
        self::insertProduct(self::productId(1), stock: 7, availableStock: 7);
        self::insertState(self::productId(1), stock: 0, availableStock: 0);

        $sent = [];
        $service = $this->service([
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('', ['http_code' => 204]),
        ], $sent);

        $service->pushForProducts([self::productId(1)], $this->occurredAt);

        // Harte Anforderung: Zustandstabelle NICHT fortgeschrieben, Batch bleibt liegen.
        self::assertCount(1, $sent);
        self::assertSame(['stock' => 0, 'available_stock' => 0], self::fetchState(self::productId(1)));

        $rows = self::fetchOutboxRows();
        self::assertCount(1, $rows);
        self::assertSame(1, (int) $rows[0]['attempts']);
        self::assertNotNull($rows[0]['next_retry_at']);
        self::assertSame(
            $rows[0]['batch_id'],
            json_decode($rows[0]['payload'], true)['batchId'],
            'Persistierte batchId und Payload-batchId muessen uebereinstimmen'
        );

        // Retry (Zeitreise): identische Bytes, identische batchId - erst jetzt Zustand fortschreiben.
        self::makeOutboxDue();
        self::assertTrue($service->flushOutbox());

        self::assertCount(2, $sent);
        self::assertSame($sent[0]['body'], $sent[1]['body'], 'Retry muss den identischen Payload senden');
        self::assertSame(['stock' => 7, 'available_stock' => 7], self::fetchState(self::productId(1)));
        self::assertSame([], self::fetchOutboxRows());
    }

    public function testRetryAfterHeaderIsRespectedOn429(): void
    {
        self::insertProduct(self::productId(1), stock: 3, availableStock: 3);
        self::insertState(self::productId(1), stock: 0, availableStock: 0);

        $sent = [];
        $service = $this->service([
            new MockResponse('', ['http_code' => 429, 'response_headers' => ['retry-after' => '120']]),
        ], $sent);

        $service->pushForProducts([self::productId(1)], $this->occurredAt);

        $rows = self::fetchOutboxRows();
        self::assertCount(1, $rows);

        $delay = (new \DateTimeImmutable((string) $rows[0]['next_retry_at']))->getTimestamp() - time();
        self::assertGreaterThanOrEqual(110, $delay);
        self::assertLessThanOrEqual(125, $delay);
    }

    public function test400DiscardsBatchAndReconciliationReportsAgain(): void
    {
        self::insertProduct(self::productId(1), stock: 7, availableStock: 7);
        self::insertState(self::productId(1), stock: 0, availableStock: 0);

        $logger = $this->spyLogger();
        $sent = [];
        $service = $this->service([new MockResponse('', ['http_code' => 400])], $sent, $logger);

        $service->pushForProducts([self::productId(1)], $this->occurredAt);

        // Kein Retry: Batch verworfen, Zustand unveraendert, Fehler geloggt.
        self::assertCount(1, $sent);
        self::assertSame([], self::fetchOutboxRows());
        self::assertSame(['stock' => 0, 'available_stock' => 0], self::fetchState(self::productId(1)));
        self::assertTrue(
            (bool) array_filter($logger->records, static fn (array $r) => $r['level'] === 'error' && str_contains($r['message'], '400')),
            '400 muss als Error geloggt werden'
        );

        // Die offene Differenz meldet der naechste Reconciliation-Lauf erneut.
        $service->runReconciliation();

        self::assertCount(2, $sent);
        $event = $this->singleEvent($sent[1]['body']);
        self::assertSame('reconciliation', $event['source']);
        self::assertSame(0, $event['previousStock']);
        self::assertSame(7, $event['stock']);
        self::assertSame(['stock' => 7, 'available_stock' => 7], self::fetchState(self::productId(1)));
    }

    public function test413SplitsIntoTwoNewBatchesWithNewBatchIds(): void
    {
        for ($i = 1; $i <= 4; ++$i) {
            self::insertProduct(self::productId($i), stock: 10 + $i, availableStock: 10 + $i);
            self::insertState(self::productId($i), stock: 0, availableStock: 0);
        }

        $sent = [];
        $service = $this->service([
            new MockResponse('', ['http_code' => 413]),
            new MockResponse('', ['http_code' => 204]),
            new MockResponse('', ['http_code' => 204]),
        ], $sent);

        $service->pushForProducts([
            self::productId(1), self::productId(2), self::productId(3), self::productId(4),
        ], $this->occurredAt);

        self::assertCount(3, $sent);

        $original = json_decode($sent[0]['body'], true);
        $partA = json_decode($sent[1]['body'], true);
        $partB = json_decode($sent[2]['body'], true);

        self::assertCount(4, $original['events']);
        self::assertCount(2, $partA['events']);
        self::assertCount(2, $partB['events']);

        self::assertNotSame($original['batchId'], $partA['batchId']);
        self::assertNotSame($original['batchId'], $partB['batchId']);
        self::assertNotSame($partA['batchId'], $partB['batchId']);

        // Chunk-/Versand-Reihenfolge ist laut Contract egal - nur die Event-Menge zaehlt.
        self::assertEqualsCanonicalizing(
            array_column($original['events'], 'eventId'),
            array_merge(array_column($partA['events'], 'eventId'), array_column($partB['events'], 'eventId')),
            'Der Split behaelt die Events (eventIds) bei'
        );

        self::assertSame([], self::fetchOutboxRows());
        for ($i = 1; $i <= 4; ++$i) {
            self::assertSame(['stock' => 10 + $i, 'available_stock' => 10 + $i], self::fetchState(self::productId($i)));
        }
    }

    public function testAuthErrorPausesOutboxWithoutTouchingState(): void
    {
        self::insertProduct(self::productId(1), stock: 5, availableStock: 5);
        self::insertState(self::productId(1), stock: 0, availableStock: 0);

        $sent = [];
        $service = $this->service([new MockResponse('', ['http_code' => 403])], $sent);

        $service->pushForProducts([self::productId(1)], $this->occurredAt);

        self::assertCount(1, $sent);
        self::assertSame(['stock' => 0, 'available_stock' => 0], self::fetchState(self::productId(1)));

        $rows = self::fetchOutboxRows();
        self::assertCount(1, $rows);

        $delay = (new \DateTimeImmutable((string) $rows[0]['next_retry_at']))->getTimestamp() - time();
        self::assertGreaterThan(3000, $delay, 'Versand muss pausiert sein (naechster Versuch ~60 min)');
    }

    public function testReconciliationSkipsDiffPhaseWhilePendingBatchesAreNotDeliverable(): void
    {
        // P1 haengt nach einem transienten Fehler mit Backoff in der Outbox ...
        self::insertProduct(self::productId(1), stock: 5, availableStock: 5);
        self::insertState(self::productId(1), stock: 0, availableStock: 0);

        $sent = [];
        $service = $this->service([new MockResponse('', ['http_code' => 503])], $sent);
        $service->pushForProducts([self::productId(1)], $this->occurredAt);
        self::assertCount(1, $sent);

        // ... und P2 hat inzwischen ebenfalls eine Differenz.
        self::insertProduct(self::productId(2), stock: 8, availableStock: 8);
        self::insertState(self::productId(2), stock: 1, availableStock: 1);

        $service->runReconciliation();

        // Kein weiterer Request, keine neuen Batches: die Diff-Phase wartet,
        // bis die Outbox leer ist - sonst entstuenden Duplikat-Batches.
        self::assertCount(1, $sent);
        self::assertCount(1, self::fetchOutboxRows());
        self::assertSame(['stock' => 1, 'available_stock' => 1], self::fetchState(self::productId(2)));
    }

    public function testReconciliationDeliversPendingThenReportsDiffsAndCleansOrphans(): void
    {
        // Offener Batch von P1 (transienter Fehler), jetzt wieder faellig:
        self::insertProduct(self::productId(1), stock: 5, availableStock: 5);
        self::insertState(self::productId(1), stock: 0, availableStock: 0);

        $sent = [];
        $service = $this->service([new MockResponse('', ['http_code' => 503])], $sent);
        $service->pushForProducts([self::productId(1)], $this->occurredAt);
        self::makeOutboxDue();

        // P2 mit Differenz, P3 ohne Zustandszeile, P99 als Waise im Zustand:
        self::insertProduct(self::productId(2), stock: 3, availableStock: 3);
        self::insertState(self::productId(2), stock: 1, availableStock: 1);
        self::insertProduct(self::productId(3), stock: 9, availableStock: 9);
        self::insertState(self::productId(99), stock: 4, availableStock: 4);

        $service->runReconciliation();

        // Request 2 = Nachlieferung des P1-Batches, Request 3 = Diff-Batch.
        self::assertCount(3, $sent);

        $diffEvents = json_decode($sent[2]['body'], true)['events'];
        $bySource = array_column($diffEvents, 'source', 'productId');
        self::assertSame('reconciliation', $bySource[self::productId(2)]);
        self::assertSame('baseline', $bySource[self::productId(3)]);

        self::assertSame(['stock' => 5, 'available_stock' => 5], self::fetchState(self::productId(1)));
        self::assertSame(['stock' => 3, 'available_stock' => 3], self::fetchState(self::productId(2)));
        self::assertSame(['stock' => 9, 'available_stock' => 9], self::fetchState(self::productId(3)));
        self::assertNull(self::fetchState(self::productId(99)), 'Waisen-Zustand geloeschter Produkte wird aufgeraeumt');
        self::assertSame([], self::fetchOutboxRows());
    }

    public function testBaselineChunksBatchesOfAtMost500Events(): void
    {
        $connection = self::connection();
        \assert($connection !== null);

        $values = [];
        $params = ['versionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)];
        for ($i = 1; $i <= 501; ++$i) {
            $values[] = "(:id{$i}, :versionId, 'SW-{$i}', {$i}, {$i}, 0, 0)";
            $params["id{$i}"] = Uuid::fromHexToBytes(self::productId($i));
        }
        $connection->executeStatement(
            'INSERT INTO product (id, version_id, product_number, stock, available_stock, is_closeout, child_count) VALUES '
            . implode(',', $values),
            $params
        );

        $sent = [];
        $service = $this->service([], $sent);

        $result = $service->enqueueBaseline();

        self::assertSame(501, $result['products']);
        self::assertSame(2, $result['batches']);
        self::assertSame([], $sent, 'enqueueBaseline() reiht nur ein, der Versand ist Sache des Aufrufers');

        $rows = self::fetchOutboxRows();
        self::assertCount(2, $rows);
        self::assertSame([500, 1], array_map(static fn (array $r) => (int) $r['event_count'], $rows));
        self::assertNotSame($rows[0]['batch_id'], $rows[1]['batch_id']);

        foreach (json_decode($rows[0]['payload'], true)['events'] as $event) {
            self::assertSame('baseline', $event['source']);
            self::assertNull($event['previousStock']);
            self::assertNull($event['previousAvailableStock']);
        }

        self::assertTrue($service->flushOutbox());
        self::assertCount(2, $sent);
        self::assertSame(
            501,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM emz_monitorio_stock_state'),
            'Baseline initialisiert die Zustandstabelle nach Bestaetigung'
        );
    }

    /**
     * @param list<MockResponse> $responses ausstehende Antworten; danach faellt der Mock auf 204 zurueck
     * @param list<array{url: string, body: string}> $sent aufgezeichnete Requests (by reference)
     */
    private function service(array $responses, array &$sent, ?LoggerInterface $logger = null): StockPushService
    {
        $connection = self::connection();
        \assert($connection !== null);

        $config = $this->pushConfig();

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent, &$responses): MockResponse {
            $sent[] = ['url' => $url, 'body' => (string) $options['body']];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 204]);
        });

        return new StockPushService(
            $config,
            new StockStateStore($connection),
            new StockOutbox($connection),
            new StockEventBuilder(),
            new MonitorioStockClient($httpClient, $config),
            $logger ?? new NullLogger(),
            null
        );
    }

    private function pushConfig(): StockPushConfig
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getInt')->willReturn(1);
        $systemConfig->method('getString')->willReturnCallback(static fn (string $key): string => match ($key) {
            'EmzMonitorio.config.ingestToken' => 'test-ingest-token',
            'EmzMonitorio.config.monitorioBaseUrl' => 'http://monitorio.test',
            default => '',
        });

        return new StockPushConfig($systemConfig);
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

    /**
     * @return AbstractLogger&object{records: list<array{level: string, message: string, context: array<string, mixed>}>}
     */
    private function spyLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }
}
