<?php declare(strict_types=1);

namespace Emz\Monitorio\Stock;

use Emz\Monitorio\Config\MonitorioBaseUrl;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Konfigurationszugriff fuer den Stock-Push. Alle Werte werden bewusst global
 * gelesen (ohne Sales-Channel-Override) - der Push meldet den Bestand des Shops,
 * nicht eines Sales-Channels (Spec Abschnitt 6).
 *
 * ingestToken ist ein Server-Geheimnis und ausdruecklich NICHT der oeffentliche
 * shopToken des jsError-Trackings.
 */
final class StockPushConfig
{
    /**
     * Name des optionalen dedizierten Messenger-Transports. Muss mit dem
     * Transport-Schluessel in `Resources/config/packages/messenger.yaml`
     * uebereinstimmen (YAML kennt keine PHP-Konstanten).
     */
    public const DEDICATED_TRANSPORT_NAME = 'emz_monitorio';

    private const CONFIG_PROJECT_ID = 'EmzMonitorio.config.projectId';
    private const CONFIG_INGEST_TOKEN = 'EmzMonitorio.config.ingestToken';
    private const CONFIG_USE_DEDICATED_TRANSPORT = 'EmzMonitorio.config.useDedicatedTransport';

    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    public function getProjectId(): int
    {
        return $this->systemConfigService->getInt(self::CONFIG_PROJECT_ID);
    }

    public function getIngestToken(): string
    {
        return trim($this->systemConfigService->getString(self::CONFIG_INGEST_TOKEN));
    }

    public function getBaseUrl(): string
    {
        return MonitorioBaseUrl::normalize(
            $this->systemConfigService->getString(MonitorioBaseUrl::CONFIG_KEY)
        );
    }

    /**
     * Der Push ist aktiv, sobald Projekt-ID und ingestToken gesetzt sind -
     * ein eigener Schalter existiert bewusst nicht.
     */
    public function isConfigured(): bool
    {
        return $this->getProjectId() > 0 && $this->getIngestToken() !== '';
    }

    /**
     * Bewusst nicht ueber getBool(): `system:config:set <key> false` speichert
     * den String "false", den ein (bool)-Cast zu true machen wuerde. Ein
     * unklarer Wert muss hier "Standard-Transport" bedeuten - sonst wandern
     * Messages in einen Transport, den womoeglich kein Worker konsumiert.
     */
    public function isDedicatedTransportEnabled(): bool
    {
        return filter_var(
            $this->systemConfigService->get(self::CONFIG_USE_DEDICATED_TRANSPORT),
            \FILTER_VALIDATE_BOOL
        );
    }

    /**
     * Stamps fuer jeden Stock-Dispatch: leer im Standardfall (das Routing
     * entscheidet dann das LowPriorityMessageInterface), sonst der Override
     * auf den dedizierten Transport. Der Stamp ueberstimmt das statische
     * Routing zur Laufzeit - der Schalter wirkt dadurch sofort, ohne
     * Container-Rebuild.
     *
     * @return list<TransportNamesStamp>
     */
    public function transportStamps(): array
    {
        return $this->isDedicatedTransportEnabled()
            ? [new TransportNamesStamp([self::DEDICATED_TRANSPORT_NAME])]
            : [];
    }
}
