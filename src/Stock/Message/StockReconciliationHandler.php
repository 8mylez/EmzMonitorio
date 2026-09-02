<?php declare(strict_types=1);

namespace Emz\Monitorio\Stock\Message;

use Emz\Monitorio\Stock\StockPushService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class StockReconciliationHandler
{
    public function __construct(private readonly StockPushService $stockPushService)
    {
    }

    public function __invoke(StockReconciliationMessage $message): void
    {
        $this->stockPushService->runReconciliation();
    }
}
