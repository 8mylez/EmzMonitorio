<?php declare(strict_types=1);

namespace Emz\Monitorio\Tests\Stock;

use Emz\Monitorio\Stock\StockEventBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Event-Bildung und Chunking gegen den fixen API-Contract (Spec Abschnitt 3):
 * previous*-Belegung je Quelle, Diff-Erkennung, 500er-/Groessen-Chunking und
 * der 413-Split in NEUE Batches mit NEUEN batchIds.
 */
final class StockEventBuilderTest extends TestCase
{
    private StockEventBuilder $builder;

    private \DateTimeImmutable $occurredAt;

    protected function setUp(): void
    {
        $this->builder = new StockEventBuilder();
        $this->occurredAt = new \DateTimeImmutable('2026-08-19T10:15:03+02:00');
    }

    public function testChangedProductGetsPreviousValuesFromState(): void
    {
        $events = $this->builder->buildEvents(
            [self::row(stock: 12, availableStock: 10, stateStock: 0, stateAvailableStock: 0)],
            StockEventBuilder::SOURCE_SUBSCRIBER,
            $this->occurredAt
        );

        self::assertCount(1, $events);
        $event = $events[0];

        self::assertSame('subscriber', $event['source']);
        self::assertSame(12, $event['stock']);
        self::assertSame(10, $event['availableStock']);
        self::assertSame(0, $event['previousStock']);
        self::assertSame(0, $event['previousAvailableStock']);
        self::assertSame('2026-08-19T10:15:03+02:00', $event['occurredAt']);
    }

    public function testUnchangedProductProducesNoEvent(): void
    {
        $events = $this->builder->buildEvents(
            [self::row(stock: 5, availableStock: 5, stateStock: 5, stateAvailableStock: 5)],
            StockEventBuilder::SOURCE_RECONCILIATION,
            $this->occurredAt
        );

        self::assertSame([], $events);
    }

    public function testProductWithoutStateBecomesBaselineWithNullPrevious(): void
    {
        $events = $this->builder->buildEvents(
            [self::row(stock: 5, availableStock: 5, hasState: false)],
            StockEventBuilder::SOURCE_SUBSCRIBER,
            $this->occurredAt
        );

        self::assertCount(1, $events);
        self::assertSame('baseline', $events[0]['source']);
        self::assertNull($events[0]['previousStock']);
        self::assertNull($events[0]['previousAvailableStock']);
    }

    public function testBaselineSourceForcesNullPreviousEvenWithState(): void
    {
        $events = $this->builder->buildEvents(
            [self::row(stock: 5, availableStock: 5, stateStock: 5, stateAvailableStock: 5)],
            StockEventBuilder::SOURCE_BASELINE,
            $this->occurredAt
        );

        self::assertCount(1, $events);
        self::assertSame('baseline', $events[0]['source']);
        self::assertNull($events[0]['previousStock']);
        self::assertNull($events[0]['previousAvailableStock']);
    }

    public function testNameIsOmittedWhenMissingAndTruncatedTo255(): void
    {
        $withoutName = $this->builder->buildEvents(
            [self::row(name: null)],
            StockEventBuilder::SOURCE_BASELINE,
            $this->occurredAt
        );
        self::assertArrayNotHasKey('name', $withoutName[0]);

        $withLongName = $this->builder->buildEvents(
            [self::row(name: str_repeat('ä', 300))],
            StockEventBuilder::SOURCE_BASELINE,
            $this->occurredAt
        );
        self::assertSame(255, mb_strlen($withLongName[0]['name']));
    }

    public function testMoreThan500EventsAreChunkedIntoBatchesWithOwnBatchIds(): void
    {
        $rows = [];
        for ($i = 0; $i < 501; ++$i) {
            $rows[] = self::row(id: self::hexId($i));
        }

        $events = $this->builder->buildEvents($rows, StockEventBuilder::SOURCE_BASELINE, $this->occurredAt);
        $batches = $this->builder->buildBatches($events);

        self::assertCount(2, $batches);
        self::assertSame(500, $batches[0]['eventCount']);
        self::assertSame(1, $batches[1]['eventCount']);
        self::assertNotSame($batches[0]['batchId'], $batches[1]['batchId']);

        foreach ($batches as $batch) {
            $decoded = json_decode($batch['json'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertSame($batch['batchId'], $decoded['batchId']);
            self::assertCount($batch['eventCount'], $decoded['events']);
        }
    }

    public function testOversizedChunksAreSplitByBodySize(): void
    {
        // 500 Events mit maximal langen Namen und Produktnummern sprengen die
        // 240-KiB-Marge deutlich - der Builder muss am fertigen JSON messen.
        $rows = [];
        for ($i = 0; $i < 500; ++$i) {
            $rows[] = self::row(
                id: self::hexId($i),
                name: str_repeat('x', 255),
                productNumber: str_repeat('9', 64)
            );
        }

        $events = $this->builder->buildEvents($rows, StockEventBuilder::SOURCE_BASELINE, $this->occurredAt);
        $batches = $this->builder->buildBatches($events);

        self::assertGreaterThan(1, \count($batches));
        self::assertSame(500, array_sum(array_column($batches, 'eventCount')));
        self::assertCount(\count($batches), array_unique(array_column($batches, 'batchId')));

        foreach ($batches as $batch) {
            self::assertLessThanOrEqual(240 * 1024, \strlen($batch['json']));
        }
    }

    public function testSplitForRetryCreatesTwoNewBatchesKeepingEventIds(): void
    {
        $rows = [];
        for ($i = 0; $i < 4; ++$i) {
            $rows[] = self::row(id: self::hexId($i));
        }

        $events = $this->builder->buildEvents($rows, StockEventBuilder::SOURCE_BASELINE, $this->occurredAt);
        [$original] = $this->builder->buildBatches($events);

        $parts = $this->builder->splitForRetry($original['json']);

        self::assertCount(2, $parts);
        self::assertSame(2, $parts[0]['eventCount']);
        self::assertSame(2, $parts[1]['eventCount']);

        $partIds = [$parts[0]['batchId'], $parts[1]['batchId']];
        self::assertNotContains($original['batchId'], $partIds, 'Teile muessen NEUE batchIds bekommen');
        self::assertNotSame($partIds[0], $partIds[1]);

        $originalEventIds = array_column(json_decode($original['json'], true)['events'], 'eventId');
        $partEventIds = array_merge(
            array_column(json_decode($parts[0]['json'], true)['events'], 'eventId'),
            array_column(json_decode($parts[1]['json'], true)['events'], 'eventId')
        );
        self::assertSame($originalEventIds, $partEventIds, 'eventIds bleiben beim Split erhalten');
    }

    public function testSingleEventBatchCannotBeSplit(): void
    {
        $events = $this->builder->buildEvents([self::row()], StockEventBuilder::SOURCE_BASELINE, $this->occurredAt);
        [$batch] = $this->builder->buildBatches($events);

        self::assertSame([], $this->builder->splitForRetry($batch['json']));
    }

    /**
     * Zeile im Format des StockStateStore (normalizeRow).
     *
     * @return array<string, mixed>
     */
    private static function row(
        string $id = '0189f2f3a4b5c6d7e8f9a0b1c2d3e4f5',
        int $stock = 12,
        int $availableStock = 10,
        ?int $stateStock = null,
        ?int $stateAvailableStock = null,
        ?bool $hasState = null,
        ?string $name = 'Beispielprodukt',
        string $productNumber = 'SW-1001',
        bool $isCloseout = false,
    ): array {
        $hasState ??= $stateStock !== null;

        return [
            'id' => $id,
            'product_number' => $productNumber,
            'name' => $name,
            'stock' => $stock,
            'available_stock' => $availableStock,
            'is_closeout' => $isCloseout,
            'has_state' => $hasState,
            'state_stock' => $stateStock,
            'state_available_stock' => $stateAvailableStock,
        ];
    }

    private static function hexId(int $i): string
    {
        return str_pad(dechex($i), 32, '0', \STR_PAD_LEFT);
    }
}
