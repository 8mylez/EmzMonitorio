<?php declare(strict_types=1);

namespace Emz\Monitorio\Stock\Message;

use Emz\Monitorio\Stock\StockPushService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class StockChangedHandler
{
    public function __construct(private readonly StockPushService $stockPushService)
    {
    }

    public function __invoke(StockChangedMessage $message): void
    {
        $occurredAt = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $message->occurredAt);

        $this->stockPushService->pushForProducts(
            $message->productIds,
            $occurredAt instanceof \DateTimeImmutable ? $occurredAt : new \DateTimeImmutable()
        );
    }
}
