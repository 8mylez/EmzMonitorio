<?php declare(strict_types=1);

namespace Emz\Monitorio\Stock\Command;

use Emz\Monitorio\Stock\StockOutbox;
use Emz\Monitorio\Stock\StockPushConfig;
use Emz\Monitorio\Stock\StockPushService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Baseline-Trigger (Konzept, Punkt 5): laeuft bei Aktivierung und ist manuell
 * nachtriggerbar. Sendet den kompletten Leaf-Produktbestand als
 * source=baseline (previous* = null) - loest serverseitig nie Alarme aus und
 * initialisiert Zustandstabelle und Monitorio-Datenbestand.
 */
#[AsCommand(
    name: 'emz:monitorio:stock:baseline',
    description: 'Sendet den kompletten Produktbestand als Baseline an den Monitorio-Stock-Ingest'
)]
final class StockBaselineCommand extends Command
{
    /**
     * Der Command wartet Rate-Limits (429) aktiv ab, aber nicht beliebig lange:
     * Was danach noch offen ist, liefert der Reconciliation-Task nach.
     */
    private const MAX_WAIT_SECONDS = 900;
    private const MAX_SINGLE_WAIT_SECONDS = 300;

    public function __construct(
        private readonly StockPushConfig $config,
        private readonly StockPushService $stockPushService,
        private readonly StockOutbox $outbox,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->config->isConfigured()) {
            $io->error(
                'Stock-Push ist nicht konfiguriert: EmzMonitorio.config.projectId und '
                . 'EmzMonitorio.config.ingestToken muessen gesetzt sein (Monitorio-Setup-Seite des Projekts).'
            );

            return Command::FAILURE;
        }

        $io->text(\sprintf('Sende Baseline an %s/ingest/stock/%d ...', $this->config->getBaseUrl(), $this->config->getProjectId()));

        $result = $this->stockPushService->enqueueBaseline(
            static fn (int $products, int $batches) => $io->text(
                \sprintf('  %d Produkte in %d Batches eingereiht ...', $products, $batches)
            )
        );

        $io->text(\sprintf('Baseline eingereiht: %d Produkte, %d Batches. Sende ...', $result['products'], $result['batches']));

        $deadline = time() + self::MAX_WAIT_SECONDS;

        while (true) {
            $this->stockPushService->flushOutbox();

            $open = $this->outbox->countOpen();
            if ($open === 0) {
                $io->success(\sprintf('Baseline uebertragen: %d Produkte in %d Batches bestaetigt.', $result['products'], $result['batches']));

                return Command::SUCCESS;
            }

            $wait = $this->outbox->secondsUntilNextDue() ?? 0;

            if ($wait > self::MAX_SINGLE_WAIT_SECONDS || time() + $wait > $deadline) {
                $io->warning(\sprintf(
                    '%d Batches sind noch offen (naechster Versuch in %d s) - der ScheduledTask '
                    . '"emz_monitorio.stock_reconciliation" liefert sie automatisch nach. Details im Log.',
                    $open,
                    $wait
                ));

                return Command::FAILURE;
            }

            $io->text(\sprintf('  %d Batches offen, warte %d s (Rate-Limit/Retry) ...', $open, max(1, $wait)));
            sleep(max(1, min($wait, 60)));
        }
    }
}
