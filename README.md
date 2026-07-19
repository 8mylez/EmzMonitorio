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

**Zweck:** Liefert den Message-Queue-Backlog pro **Messenger-Transport** — dieselben Zahlen wie `bin/console messenger:stats`, als JSON. Funktioniert transportunabhängig (Doctrine/Datenbank, AMQP/RabbitMQ, Redis, …), weil über die `messenger.receiver`-getaggten Transports und deren `MessageCountAwareInterface::getMessageCount()` gezählt wird. Monitorio pollt den Endpunkt pro Shop, speichert die Zeitreihe und alarmiert bei dauerhaft wachsendem Backlog (Frühwarnsignal für einen hängenden oder überlasteten `messenger:consume`-Worker). Der Endpunkt ist ein versions-stabiler Ersatz für die Standard-API `GET /api/_info/queue.json`, die ab Shopware 6.7.8.0 deprecated und in 6.8.0.0 entfernt ist.

**Auth:** Admin-API-Integration über OAuth `client_credentials` (`Authorization: Bearer <token>`), Route-Scope `api`. Es ist kein zusätzliches Secret im Shop zu konfigurieren.

**Request:** Kein Request-Body, keine Query-Parameter.

**Response** (`200`, `application/json`): Ein JSON-Array mit einem Objekt pro Transport. `name` ist der Transport-Name (wie in `messenger:stats`, z. B. `async`, `low_priority`, `failed`), `size` die Anzahl wartender Messages als Integer. Transports ohne wartende Messages erscheinen mit `size: 0`.

```json
[
    { "name": "failed", "size": 0 },
    { "name": "async", "size": 1234 },
    { "name": "low_priority", "size": 5 }
]
```

Ein leeres Array `[]` ist ein valider Erfolgsfall (keine zählbaren Transports konfiguriert).

**Bekannte Einschränkungen:**

- Transports, die kein `MessageCountAwareInterface` implementieren (z. B. `scheduler_shopware`), können nicht gezählt werden und fehlen in der Antwort — analog zur NOTE von `messenger:stats`.
- Ist ein Transport nicht erreichbar (z. B. AMQP-Broker down), wird er ausgelassen statt die Antwort mit `500` zu beenden; sein Eintrag fehlt dann im Poll.
- Die Zählung entspricht der `messenger:stats`-Semantik des jeweiligen Transports; beim Doctrine-Transport zählen z. B. verzögerte Messages (`available_at` in der Zukunft) nicht mit.

## Lizenz

MIT
