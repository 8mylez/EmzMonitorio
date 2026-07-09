# EmzMonitorio

Companion-Plugin für Monitorio. Leitet ausgewählte Monolog-Log-Records und benutzerdefinierte Events regelbasiert an einen Monitorio-Ingest-Endpunkt weiter und stellt darüber hinaus Read-only-Monitoring-Endpunkte über die Shopware-Admin-API bereit, die Monitorio pro Shop abfragt.

## Funktionsumfang

- **Log-Forwarding:** Ausgewählte Monolog-Log-Records und benutzerdefinierte Events werden regelbasiert an einen Monitorio-Ingest-Endpunkt weitergeleitet.
- **Monitoring-Endpunkte:** Endpunkte in der Admin-API liefern Kennzahlen, die Monitorio pro Shop pollt (freier Speicherplatz, Shop-Logs, Message-Queue-Backlog).

## Voraussetzungen

- Shopware 6.5.0.0 oder neuer
- PHP 8.1 oder neuer

## Installation

```bash
bin/console plugin:refresh
bin/console plugin:install --activate EmzMonitorio
bin/console cache:clear
```

## API-Endpunkte

Alle Endpunkte laufen im Admin-API-Scope (`_routeScope: api`) und werden über Shopwares Admin-OAuth abgesichert. Der Aufruf erfolgt mit dem Bearer-Token einer Shop-Integration.

### GET /api/monitorio/free-disk-space

Liefert freien und gesamten Speicherplatz des Root-Dateisystems (`/`) in Bytes.

```json
{
    "freeDiskSpace": 123456789012,
    "totalDiskSpace": 500107862016
}
```

### GET /api/_action/emz/monitorio/logs

Liest Shop-Log-Einträge aus. Optionale Query-Parameter: `since` (ISO-8601-Zeitpunkt, Default: letzte Stunde), `min_level` (Default: `WARNING`) und `limit` (Default und Maximum: `1000`).

```json
{
    "data": [
        {
            "external_key": "shop-1",
            "logged_at": "2026-07-09T11:32:45+00:00",
            "level": "ERROR",
            "channel": "app",
            "message": "Uncaught exception ...",
            "context": {},
            "file": "/var/www/html/src/Foo.php",
            "line": 42
        }
    ]
}
```

### GET /api/monitorio/message-queue

**Zweck:** Liefert den exakten Message-Queue-Backlog — die Anzahl noch nicht ausgelieferter Messages pro `queue_name` — direkt aus der Datenbank. Monitorio pollt den Endpunkt pro Shop, speichert die Zeitreihe und alarmiert bei dauerhaft wachsendem Backlog (Frühwarnsignal für einen hängenden oder überlasteten `messenger:consume`-Worker). Der Endpunkt ist ein versions-stabiler Ersatz für die Standard-API `GET /api/_info/queue.json`, die ab Shopware 6.7.8.0 deprecated und in 6.8.0.0 entfernt ist.

**Auth:** Admin-API-Integration über OAuth `client_credentials` (`Authorization: Bearer <token>`), Route-Scope `api`. Es ist kein zusätzliches Secret im Shop zu konfigurieren.

**Request:** Kein Request-Body, keine Query-Parameter.

**Response** (`200`, `application/json`): Ein JSON-Array mit einem Objekt pro Queue. `name` ist der `queue_name`, `size` die Anzahl wartender Messages als Integer.

```json
[
    { "name": "default", "size": 1234 },
    { "name": "low_priority", "size": 5 }
]
```

Ein leeres Array `[]` ist ein valider Erfolgsfall (kein Backlog oder kein Doctrine-Transport). Gezählt werden alle noch nicht ausgelieferten Messages (`delivered_at IS NULL`), inklusive verzögerter (delayed) Messages — analog zur Semantik des alten `queue.json`.

**Bekannte Einschränkungen:**

- Der Backlog wird ausschließlich beim **Doctrine-Transport** gezählt (Tabelle `messenger_messages`). Nutzt der Shop AMQP/RabbitMQ, Redis oder einen In-Memory-Transport, liefert der Endpunkt `200` mit leerem Array `[]` (kein `500`).
- Der konfigurierte Transport wird nicht geprüft: Bleibt nach einem Wechsel weg vom Doctrine-Transport eine alte `messenger_messages`-Tabelle mit nicht ausgelieferten Alt-Einträgen zurück, meldet der Endpunkt diese Reste als (konstanten) Backlog. Nach einem Transport-Wechsel die Tabelle leeren oder entfernen.
- Ein abweichend konfigurierter `table_name` des Doctrine-Transports wird nicht unterstützt; der Standard-Tabellenname `messenger_messages` ist fest verdrahtet.

## Lizenz

MIT
