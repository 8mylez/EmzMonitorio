<?php declare(strict_types=1);

namespace Emz\Monitorio\Stock;

use Shopware\Core\System\SystemConfig\SystemConfigService;

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
     * Default analog zum snippetUrl-Muster: Konstante als Standard, Config-Feld
     * als Override fuer lokale und Staging-Instanzen von Monitorio.
     */
    public const DEFAULT_BASE_URL = 'https://app.monitorio.de';

    private const CONFIG_PROJECT_ID = 'EmzMonitorio.config.projectId';
    private const CONFIG_INGEST_TOKEN = 'EmzMonitorio.config.ingestToken';
    private const CONFIG_BASE_URL = 'EmzMonitorio.config.monitorioBaseUrl';

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
        $configured = trim($this->systemConfigService->getString(self::CONFIG_BASE_URL));

        return rtrim($configured !== '' ? $configured : self::DEFAULT_BASE_URL, '/');
    }

    /**
     * Der Push ist aktiv, sobald Projekt-ID und ingestToken gesetzt sind -
     * ein eigener Schalter existiert bewusst nicht.
     */
    public function isConfigured(): bool
    {
        return $this->getProjectId() > 0 && $this->getIngestToken() !== '';
    }
}
