<?php declare(strict_types=1);

namespace Emz\Monitorio\Stock\ScheduledTask;

use Emz\Monitorio\Stock\DedicatedTransportWatchdog;
use Emz\Monitorio\Stock\Message\StockReconciliationMessage;
use Emz\Monitorio\Stock\StockPushConfig;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Registrierung in services.php mit explizitem `handles`-Tag-Attribut: der
 * generische __invoke(ScheduledTask)-Typehint der Basisklasse wuerde sonst
 * auf alle ScheduledTask-Messages matchen.
 *
 * Der Handler fuehrt die Reconciliation nicht mehr selbst aus, sondern
 * dispatcht sie als StockReconciliationMessage: die HTTP-Arbeit laeuft damit
 * im selben (per Config waehlbaren) Transport wie der Subscriber-Pfad und
 * blockiert nicht den Worker des scheduler_shopware-Transports.
 */
#[AsMessageHandler(handles: StockReconciliationTask::class)]
final class StockReconciliationTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly MessageBusInterface $messageBus,
        private readonly StockPushConfig $config,
        private readonly DedicatedTransportWatchdog $watchdog,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        if (!$this->config->isConfigured()) {
            return;
        }

        // Bewusst hier im scheduler_shopware-Kontext, VOR dem Dispatch: der
        // Watchdog muss genau dann laufen, wenn der Worker des dedizierten
        // Transports fehlt - dort hinterher kaeme er nie zur Ausfuehrung.
        $this->watchdog->check();

        $this->messageBus->dispatch(new StockReconciliationMessage(), $this->config->transportStamps());
    }
}
