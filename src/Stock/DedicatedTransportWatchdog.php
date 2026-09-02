<?php declare(strict_types=1);

namespace Emz\Monitorio\Stock;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

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
     * Zwei Reconciliation-Intervalle (beim 300-s-Default): eine Message, die
     * so lange liegt, wird von keinem Worker abgeholt (Symfony-Retries liegen
     * im Sekundenbereich).
     */
    private const STALE_AFTER_SECONDS = 600;

    /**
     * Haelt der Zustand an, wird fruehestens nach dieser Zeit erneut
     * notifiziert - die Log-Warnung kommt weiterhin bei jedem Lauf.
     */
    private const RENOTIFY_AFTER_SECONDS = 86400;

    /**
     * Unix-Timestamp der letzten Admin-Notification. Absichtlich persistiert
     * (system_config) statt als Zeitfenster gerechnet: die Task-Laeufe koennen
     * beliebig weit auseinanderliegen (konfigurierbares Intervall, verzoegerter
     * Scheduler - genau im Staufall), ein Fenster wuerde dann uebersprungen
     * und es kaeme NIE eine Notification. Geschrieben wird nur bei
     * Zustandswechseln, nicht bei jedem Lauf.
     */
    private const NOTIFIED_AT_KEY = 'EmzMonitorio.watchdogStockTransportNotifiedAt';

    public function __construct(
        private readonly Connection $connection,
        private readonly StockPushConfig $config,
        private readonly SystemConfigService $systemConfigService,
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
            $this->clearNotifiedMarker();

            return;
        }

        // Der Doctrine-Transport schreibt created_at in UTC (Symfony haengt
        // dort explizit new \DateTimeImmutable('UTC') an). Ohne explizite Zone
        // wuerde PHP den TZ-losen DATETIME-String in date.timezone
        // interpretieren und das Alter um den Offset verfaelschen: oestlich
        // von UTC Dauer-Warnung fuer frische Messages, westlich schlaegt der
        // Watchdog nie an.
        $oldestAt = new \DateTimeImmutable($oldest, new \DateTimeZone('UTC'));
        $ageSeconds = $now->getTimestamp() - $oldestAt->getTimestamp();

        if ($ageSeconds < self::STALE_AFTER_SECONDS) {
            $this->clearNotifiedMarker();

            return;
        }

        $this->logger->warning(
            'Monitorio stock push: oldest message in dedicated transport "'
            . StockPushConfig::DEDICATED_TRANSPORT_NAME . '" is ' . $ageSeconds . 's old and unconsumed. '
            . 'Is a worker running? (bin/console messenger:consume ' . StockPushConfig::DEDICATED_TRANSPORT_NAME . ')'
        );

        $notifiedAt = $this->systemConfigService->get(self::NOTIFIED_AT_KEY);
        $notifiedAt = is_numeric($notifiedAt) ? (int) $notifiedAt : null;

        if ($notifiedAt !== null && $now->getTimestamp() - $notifiedAt < self::RENOTIFY_AFTER_SECONDS) {
            return;
        }

        $this->notifyAdmin();
        // Marker unabhaengig vom Notification-Erfolg setzen: er ist die
        // Drossel - ein dauerhaft kaputtes notification-Repo soll nicht bei
        // jedem Lauf einen system_config-Write (Cache-Invalidierung) ausloesen.
        $this->systemConfigService->set(self::NOTIFIED_AT_KEY, $now->getTimestamp());
    }

    /**
     * Vorher lesen statt blind loeschen: delete() invalidiert den
     * Config-Cache, und der gesunde Zustand ist der Normalfall bei jedem Lauf.
     */
    private function clearNotifiedMarker(): void
    {
        if ($this->systemConfigService->get(self::NOTIFIED_AT_KEY) !== null) {
            $this->systemConfigService->delete(self::NOTIFIED_AT_KEY);
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
