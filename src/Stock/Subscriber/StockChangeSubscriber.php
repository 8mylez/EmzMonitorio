<?php declare(strict_types=1);

namespace Emz\Monitorio\Stock\Subscriber;

use Emz\Monitorio\Stock\Message\StockChangedMessage;
use Emz\Monitorio\Stock\StockPushConfig;
use Shopware\Core\Content\Product\Events\ProductStockAlteredEvent;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Live-Erfassung von Bestandsaenderungen (source=subscriber), Shopware 6.7:
 *
 * - ProductStockAlteredEvent: der Order-Lifecycle (StockStorage::alter) schreibt
 *   den Bestand per Raw-SQL an der DAL vorbei - dieses Core-Event ist dort der
 *   einzige Hook. Eine Dekoration von AbstractStockStorage ist damit unnoetig
 *   und wuerde nur mit ERP-Dekoratoren kollidieren (Konzept, Punkt 1).
 * - product.written: Admin-Edits und Sync-API schreiben ueber die DAL; relevant
 *   nur, wenn stock/availableStock im Payload stehen.
 *
 * Aenderungsquellen ganz am Event-System vorbei (Direkt-SQL-Importe) faengt die
 * Reconciliation. Der Subscriber stellt nur eine Message in die Queue.
 */
final class StockChangeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly StockPushConfig $config,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductStockAlteredEvent::class => 'onStockAltered',
            ProductEvents::PRODUCT_WRITTEN_EVENT => 'onProductWritten',
        ];
    }

    public function onStockAltered(ProductStockAlteredEvent $event): void
    {
        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        $this->dispatch($event->getIds());
    }

    public function onProductWritten(EntityWrittenEvent $event): void
    {
        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        $ids = [];
        foreach ($event->getWriteResults() as $result) {
            if ($result->getOperation() === EntityWriteResult::OPERATION_DELETE) {
                continue;
            }

            $payload = $result->getPayload();
            if (!\array_key_exists('stock', $payload) && !\array_key_exists('availableStock', $payload)) {
                continue;
            }

            $primaryKey = $result->getPrimaryKey();
            if (\is_array($primaryKey)) {
                $primaryKey = $primaryKey['id'] ?? null;
            }

            if (\is_string($primaryKey) && $primaryKey !== '') {
                $ids[] = $primaryKey;
            }
        }

        $this->dispatch($ids);
    }

    /**
     * @param list<string> $productIds
     */
    private function dispatch(array $productIds): void
    {
        $productIds = array_values(array_unique($productIds));

        if ($productIds === [] || !$this->config->isConfigured()) {
            return;
        }

        $this->messageBus->dispatch(new StockChangedMessage(
            $productIds,
            (new \DateTimeImmutable())->format(\DATE_ATOM)
        ));
    }
}
