<?php declare(strict_types=1);

namespace Emz\Monitorio\Stock\ScheduledTask;

use Emz\Monitorio\Stock\StockPushService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Registrierung in services.xml mit explizitem `handles`-Tag-Attribut: der
 * generische __invoke(ScheduledTask)-Typehint der Basisklasse wuerde sonst
 * auf alle ScheduledTask-Messages matchen.
 */
#[AsMessageHandler(handles: StockReconciliationTask::class)]
final class StockReconciliationTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly StockPushService $stockPushService,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        $this->stockPushService->runReconciliation();
    }
}
