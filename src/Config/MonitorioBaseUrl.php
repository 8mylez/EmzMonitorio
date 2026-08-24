<?php declare(strict_types=1);

namespace Emz\Monitorio\Config;

/**
 * Die eine Monitorio-Instanz, gegen die dieser Shop arbeitet. Aus ihr leiten sich alle
 * Ziele ab: die Snippet-Auslieferung des JS Error Trackings und der Stock-Ingest.
 * Getrennte URLs je Funktion gibt es bewusst nicht - ein Snippet, das seine Fehler an
 * eine andere Instanz meldet als der Stock-Push, waere eine Fehlkonfiguration.
 */
final class MonitorioBaseUrl
{
    /**
     * Bewusst der App-Host ohne www-Umweg: die Apex-Domain monitorio.de antwortet mit
     * 307 auf www und wuerde jeden Request einen zusaetzlichen Round-Trip kosten.
     */
    public const DEFAULT = 'https://app.monitorio.de';

    public const CONFIG_KEY = 'EmzMonitorio.config.monitorioBaseUrl';

    private function __construct()
    {
    }

    /**
     * Konfigurierten Wert auf eine nutzbare Basis bringen: leer faellt auf den Default
     * zurueck, ein trailing Slash entfaellt, damit Pfade einheitlich angehaengt werden.
     */
    public static function normalize(string $configured): string
    {
        $configured = trim($configured);

        return rtrim($configured !== '' ? $configured : self::DEFAULT, '/');
    }

    /**
     * Uebersetzt das bis 1.2.0 genutzte Feld `snippetUrl` (volle URL der Snippet-Datei)
     * in die Basis-URL. null, wenn kein Override noetig ist, weil der alte Wert leer
     * war oder ohnehin auf die Default-Instanz zeigte.
     */
    public static function fromLegacySnippetUrl(string $snippetUrl): ?string
    {
        $base = trim($snippetUrl);

        if (str_ends_with($base, '/t/v1.js')) {
            $base = substr($base, 0, -\strlen('/t/v1.js'));
        }

        $base = rtrim($base, '/');

        return $base === '' || $base === self::DEFAULT ? null : $base;
    }
}
