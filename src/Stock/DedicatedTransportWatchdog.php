<?php declare(strict_types=1);

namespace Emz\Monitorio\Stock;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Erkennt den gefaehrlichsten Fehlgriff des dedizierten Transports: Schalter
 * an, aber kein Worker konsumiert ihn - Messages laegen dann still fuer immer.
 * Gemessen wird das Alter der aeltesten unzugestellten Message direkt in
 * `messenger_messages` (der mitgelieferte Transport-DSN ist Doctrine auf
 * derselben Tabelle); laeuft der Transport per ENV auf einem anderen Broker,
 * kann dieser Watchdog nichts messen und haelt sich still - den Backlog sieht
 * Monitorio dann weiterhin ueber /api/monitorio/message-queue.
 *
 * Der Aufruf sitzt bewusst im ScheduledTask-Handler (scheduler_shopware),
 * nicht im ueberwachten Transport selbst: er muss genau dann laufen, wenn
 * dessen Worker fehlt.
 */
final class DedicatedTransportWatchdog
{
    /**
     * Zwei Reconciliation-Intervalle: eine Message, die so lange liegt, wird
     * von keinem Worker abgeholt (Symfony-Retries liegen im Sekundenbereich).
     */
    private const STALE_AFTER_SECONDS = 600;

    /**
     * Admin-Notification nur im Uebergangsfenster nach dem Schwellwert (etwa
     * ein Task-Lauf breit) - die Log-Warnung kommt bei jedem Lauf, aber das
     * Notification-Center soll nicht alle 5 Minuten einen neuen Eintrag
     * bekommen, solange der Zustand anhaelt.
     */
    private const NOTIFY_WINDOW_SECONDS = 450;

    public function __construct(
        private readonly Connection $connection,
        private readonly StockPushConfig $config,
        private readonly LoggerInterface $logger,
        private readonly ?EntityRepository $notificationRepository = null,
    ) {
    }

    public function check(?\DateTimeImmutable $now = null): void
    {
        if (!$this->config->isDedicatedTransportEnabled()) {
            return;
        }

        $now ??= new \DateTimeImmutable();

        try {
            $oldest = $this->connection->fetchOne(
                'SELECT MIN(created_at) FROM messenger_messages
                 WHERE queue_name = :queue AND delivered_at IS NULL',
                ['queue' => StockPushConfig::DEDICATED_TRANSPORT_NAME]
            );
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Monitorio stock push: dedicated transport watchdog skipped: ' . $e->getMessage()
            );

            return;
        }

        if (!\is_string($oldest) || $oldest === '') {
            return;
        }

        $ageSeconds = $now->getTimestamp() - (new \DateTimeImmutable($oldest))->getTimestamp();

        if ($ageSeconds < self::STALE_AFTER_SECONDS) {
            return;
        }

        $this->logger->warning(
            'Monitorio stock push: oldest message in dedicated transport "'
            . StockPushConfig::DEDICATED_TRANSPORT_NAME . '" is ' . $ageSeconds . 's old and unconsumed. '
            . 'Is a worker running? (bin/console messenger:consume ' . StockPushConfig::DEDICATED_TRANSPORT_NAME . ')'
        );

        if ($ageSeconds < self::STALE_AFTER_SECONDS + self::NOTIFY_WINDOW_SECONDS) {
            $this->notifyAdmin();
        }
    }

    private function notifyAdmin(): void
    {
        if ($this->notificationRepository === null) {
            return;
        }

        try {
            $this->notificationRepository->create([[
                'id' => Uuid::randomHex(),
                'status' => 'error',
                'message' => 'Monitorio-Lagerbestand-Push: Der dedizierte Transport "'
                    . StockPushConfig::DEDICATED_TRANSPORT_NAME . '" wird von keinem Worker konsumiert. '
                    . 'Bitte einen Worker einrichten (messenger:consume '
                    . StockPushConfig::DEDICATED_TRANSPORT_NAME . ') oder den Schalter '
                    . '"Dedizierten Queue-Transport verwenden" deaktivieren.',
                'adminOnly' => false,
                'requiredPrivileges' => [],
            ]], Context::createCLIContext());
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Monitorio stock push: could not create watchdog admin notification: ' . $e->getMessage()
            );
        }
    }
}
