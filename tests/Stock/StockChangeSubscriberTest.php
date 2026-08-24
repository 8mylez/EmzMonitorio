<?php declare(strict_types=1);

namespace Emz\Monitorio\Tests\Stock;

use Emz\Monitorio\Stock\Message\StockChangedMessage;
use Emz\Monitorio\Stock\StockPushConfig;
use Emz\Monitorio\Stock\Subscriber\StockChangeSubscriber;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\Events\ProductStockAlteredEvent;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class StockChangeSubscriberTest extends TestCase
{
    private const PRODUCT_ID = '0189f2f3a4b5c6d7e8f9a0b1c2d3e4f5';

    public function testStockAlteredEventDispatchesMessage(): void
    {
        $bus = $this->busSpy();
        $subscriber = new StockChangeSubscriber($bus, $this->config());

        $subscriber->onStockAltered(new ProductStockAlteredEvent([self::PRODUCT_ID], $this->liveContext()));

        self::assertCount(1, $bus->messages);
        $message = $bus->messages[0];
        self::assertInstanceOf(StockChangedMessage::class, $message);
        self::assertSame([self::PRODUCT_ID], $message->productIds);
        self::assertInstanceOf(
            \DateTimeImmutable::class,
            \DateTimeImmutable::createFromFormat(\DATE_ATOM, $message->occurredAt) ?: null,
            'occurredAt muss RFC3339/DATE_ATOM sein'
        );
    }

    public function testStockAlteredEventIsIgnoredForNonLiveVersion(): void
    {
        $bus = $this->busSpy();
        $subscriber = new StockChangeSubscriber($bus, $this->config());

        $context = new Context(new SystemSource(), [], Defaults::CURRENCY, [Defaults::LANGUAGE_SYSTEM], Uuid::randomHex());
        $subscriber->onStockAltered(new ProductStockAlteredEvent([self::PRODUCT_ID], $context));

        self::assertSame([], $bus->messages);
    }

    public function testProductWrittenWithStockPayloadDispatchesMessage(): void
    {
        $bus = $this->busSpy();
        $subscriber = new StockChangeSubscriber($bus, $this->config());

        $subscriber->onProductWritten($this->writtenEvent([
            new EntityWriteResult(self::PRODUCT_ID, ['stock' => 5], 'product', EntityWriteResult::OPERATION_UPDATE),
        ]));

        self::assertCount(1, $bus->messages);
        self::assertSame([self::PRODUCT_ID], $bus->messages[0]->productIds);
    }

    public function testProductWrittenWithoutStockFieldsIsIgnored(): void
    {
        $bus = $this->busSpy();
        $subscriber = new StockChangeSubscriber($bus, $this->config());

        $subscriber->onProductWritten($this->writtenEvent([
            new EntityWriteResult(self::PRODUCT_ID, ['name' => 'Neuer Name', 'active' => true], 'product', EntityWriteResult::OPERATION_UPDATE),
        ]));

        self::assertSame([], $bus->messages);
    }

    public function testProductDeleteIsIgnored(): void
    {
        $bus = $this->busSpy();
        $subscriber = new StockChangeSubscriber($bus, $this->config());

        $subscriber->onProductWritten($this->writtenEvent([
            new EntityWriteResult(self::PRODUCT_ID, ['stock' => 0], 'product', EntityWriteResult::OPERATION_DELETE),
        ]));

        self::assertSame([], $bus->messages);
    }

    public function testDuplicateIdsAreDispatchedOnce(): void
    {
        $bus = $this->busSpy();
        $subscriber = new StockChangeSubscriber($bus, $this->config());

        $subscriber->onProductWritten($this->writtenEvent([
            new EntityWriteResult(self::PRODUCT_ID, ['stock' => 5], 'product', EntityWriteResult::OPERATION_UPDATE),
            new EntityWriteResult(self::PRODUCT_ID, ['availableStock' => 5], 'product', EntityWriteResult::OPERATION_UPDATE),
        ]));

        self::assertCount(1, $bus->messages);
        self::assertSame([self::PRODUCT_ID], $bus->messages[0]->productIds);
    }

    public function testNothingIsDispatchedWhenNotConfigured(): void
    {
        $bus = $this->busSpy();
        $subscriber = new StockChangeSubscriber($bus, $this->config(ingestToken: ''));

        $subscriber->onStockAltered(new ProductStockAlteredEvent([self::PRODUCT_ID], $this->liveContext()));

        self::assertSame([], $bus->messages);
    }

    /**
     * @param list<EntityWriteResult> $results
     */
    private function writtenEvent(array $results): EntityWrittenEvent
    {
        return new EntityWrittenEvent('product', $results, $this->liveContext());
    }

    private function liveContext(): Context
    {
        return new Context(new SystemSource());
    }

    /**
     * @return MessageBusInterface&object{messages: list<StockChangedMessage>}
     */
    private function busSpy(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            /** @var list<StockChangedMessage> */
            public array $messages = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                \assert($message instanceof StockChangedMessage);
                $this->messages[] = $message;

                return new Envelope($message);
            }
        };
    }

    private function config(int $projectId = 1, string $ingestToken = 'token'): StockPushConfig
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getInt')->willReturn($projectId);
        $systemConfig->method('getString')->willReturnCallback(static fn (string $key): string => match ($key) {
            'EmzMonitorio.config.ingestToken' => $ingestToken,
            default => '',
        });

        return new StockPushConfig($systemConfig);
    }
}
