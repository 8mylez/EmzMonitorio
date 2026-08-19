<?php declare(strict_types=1);

namespace Emz\Monitorio\Stock\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Snapshot-Diff-Reconciliation (Spec Abschnitt 4b). Das Intervall ist ueber
 * den Shopware-Standardweg konfigurierbar: `scheduled_task.run_interval`
 * in der Datenbank (z. B. per Admin-API).
 */
final class StockReconciliationTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'emz_monitorio.stock_reconciliation';
    }

    public static function getDefaultInterval(): int
    {
        return 300;
    }
}
