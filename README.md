# EmzMonitorio

Companion-Plugin für Monitorio. Leitet ausgewählte Monolog-Log-Records und benutzerdefinierte Events regelbasiert an einen Monitorio-Ingest-Endpunkt weiter und stellt darüber hinaus Read-only-Monitoring-Endpunkte über die Shopware-Admin-API bereit, die Monitorio pro Shop abfragt.

## Funktionsumfang

- **Log-Forwarding:** Ausgewählte Monolog-Log-Records und benutzerdefinierte Events werden regelbasiert an einen Monitorio-Ingest-Endpunkt weitergeleitet.
- **Monitoring-Endpunkte:** Endpunkte in der Admin-API liefern Kennzahlen, die Monitorio pro Shop pollt (freier Speicherplatz, Shop-Logs, Message-Queue-Backlog).
- **JS Error Tracking:** Optional bindet das Plugin im Storefront-`<head>` den Loader für das von Monitorio gehostete Tracking-Snippet ein.

## Voraussetzungen

- Shopware 6.5.0.0 oder neuer
- PHP 8.1 oder neuer

## Installation

```bash
bin/console plugin:refresh
bin/console plugin:install --activate EmzMonitorio
bin/console cache:clear
```

## Konfiguration

Die Einstellungen stehen im Admin unter **Erweiterungen > Meine Erweiterungen > Monitorio Event Ingest > Konfiguration** und lassen sich je Sales-Channel überschreiben.

| Einstellung | Schlüssel | Default |
|---|---|---|
| Projekt-ID | `EmzMonitorio.config.projectId` | leer |
| Shop-Token | `EmzMonitorio.config.shopToken` | leer |
| JS Error Tracking aktivieren | `EmzMonitorio.config.jsErrorTrackingEnabled` | aus |
| Snippet-URL (optional) | `EmzMonitorio.config.snippetUrl` | leer → gehostetes Snippet |

**Projekt-ID** und **Shop-Token** stehen in Monitorio unter „Einrichtung" im Einbau-Code. Beide sind für das JS Error Tracking Pflicht — das Snippet bricht ohne eines von beiden still ab, deshalb liefert das Plugin dann gar nichts aus. Das Token ist kein Geheimnis, es steht im Quelltext jeder Shopseite; die Zuordnung schützt Monitorio zusätzlich über einen Origin-Check gegen die registrierten Shop-Domains.

Die Admin-API-Endpunkte weiter unten brauchen keines von beidem — die laufen über Shopwares Admin-OAuth.

Die **Snippet-URL** bleibt normalerweise leer. Sie existiert für lokale und Staging-Instanzen von Monitorio; das Snippet leitet seinen Ingest-Endpunkt aus genau dieser URL ab, ein Wert genügt also zum Umbiegen.

Beim Setzen per CLI Zahl und Schalter als echte JSON-Werte schreiben, nicht als String:

```bash
bin/console system:config:set --json EmzMonitorio.config.jsErrorTrackingEnabled true
bin/console system:config:set --json EmzMonitorio.config.projectId 1
bin/console system:config:set EmzMonitorio.config.shopToken '<token>'
bin/console cache:clear
```

## JS Error Tracking

Ist die Einstellung aktiv **und** sind Projekt-ID und Shop-Token hinterlegt, hängt das Plugin einen Loader in den Storefront-`<head>` — vor Favicon, Title und Stylesheets, damit auch frühe Fehler erfasst werden. Fehlt eines davon, wird nichts ausgeliefert.

Der Loader setzt `window.__monitorio` und lädt anschließend das Snippet asynchron von `https://app.monitorio.de/t/v1.js`. Der komplette Block liegt in einem `try/catch`; ein Fehler darin bleibt folgenlos für die Storefront.

Der Loader lädt mit `crossOrigin="anonymous"`, damit keine Cookies an den App-Host mitgehen. Das setzt voraus, dass der Endpunkt `Access-Control-Allow-Origin` sendet und den Content-Type `application/javascript` — bei `text/html` blockiert der Browser das Script wegen `X-Content-Type-Options: nosniff`.

```js
window.__monitorio = {
    "projectId": 1,
    "shopToken": "<token aus der plugin-konfiguration>",
    "salesChannelId": "<id des aufgerufenen sales channels>",
    "context": "storefront",
    "buildId": "cb1116e70d578ba33c978843afbdd646"
};
```

`projectId` und `shopToken` sind Pflichtfelder des Snippets, `salesChannelId` und `context` optional — das Snippet sendet sie als leeren String weiter, wenn sie fehlen.

`buildId` kennzeichnet den Storefront-Build, aus dem die ausgelieferten JS-Dateien stammen. Es ist derselbe Hash, der als Verzeichnis in den Asset-URLs steht:

```
/theme/cb1116e70d578ba33c978843afbdd646/js/storefront/storefront.js
```

Der Wert wechselt bei jedem `bin/console theme:compile` und identifiziert damit sowohl den Deploy als auch das Verzeichnis, auf das die Stack-Frames zeigen — die Grundlage für eine spätere Sourcemap-Auflösung. Sales-Channels ohne Theme (headless) liefern keine Storefront aus; dort entfällt das Feld. **Monitorio wertet `buildId` derzeit noch nicht aus**, das Plugin liefert es vor, damit die Auswertung später keinen Plugin-Release braucht.

## Nach dem Ausrollen prüfen

Ob die Einbindung in einem Shop wirklich greift, lässt sich in der Browser-Konsole der Storefront in zwei Schritten prüfen.

**1. Liefert das Plugin die Konfiguration aus?**

```js
window.__monitorio
```

Kommt `undefined` zurück, greift die Einstellung nicht — Setting aus, Projekt-ID oder Token fehlen, oder der Cache ist nicht geleert. Der Inhalt zeigt außerdem, ob `context` zur aufgerufenen Seite passt.

**2. Kommt ein Fehler bei Monitorio an?**

```js
setTimeout(() => { throw new Error("Monitorio Smoketest " + Date.now()); });
```

Das Snippet sammelt fünf Sekunden lang und schickt dann gebündelt; im Netzwerk-Tab erscheint danach ein `POST` auf `/t/e/<projektId>` mit Status `204`. Die Meldung taucht anschließend in Monitorio als neue Fehlergruppe auf. Die Standard-Schwelle für einen Alarm liegt bei drei Vorkommen — für einen reinen Zustelltest genügt eines.

`context` leitet sich aus dem Namen der aktiven Storefront-Route ab:

| Routen-Präfix | `context` | Seiten |
|---|---|---|
| `frontend.checkout.` | `checkout` | Warenkorb, Bestellbestätigung, Abschluss, Registrierung im Checkout-Flow |
| `frontend.account.` | `account` | Kundenkonto inkl. Login und eigenständiger Registrierung |
| alle übrigen | `storefront` | Startseite, Kategorien, Detailseiten, Suche, CMS |

Das Snippet selbst hostet und versioniert Monitorio; es steckt bewusst **nicht** im Plugin, damit Snippet-Updates ohne Plugin-Release ausgerollt werden können. Die URL liegt als Konstante `JsErrorTrackingConfigProvider::SNIPPET_URL` im Code.

Eine Änderung der Einstellung greift nach `bin/console cache:clear`.

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
