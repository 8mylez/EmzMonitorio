# EmzMonitorio

Companion-Plugin für Monitorio. Stellt Read-only-Monitoring-Endpunkte über die Shopware-Admin-API bereit, die Monitorio pro Shop pollt (Shop-Logs, Log-Volumen, Message-Queue-Backlog, freier Speicherplatz), bindet optional den Loader für das JS Error Tracking in die Storefront ein und pusht Bestands-Events an den Monitorio-Stock-Ingest.

## Funktionsumfang

- **Monitoring-Endpunkte (Pull):** Endpunkte in der Admin-API liefern Kennzahlen, die Monitorio pro Shop pollt — Shop-Log-Einträge (`/logs`), Log-Volumen (`/logs/meta`), Message-Queue-Backlog und freier Speicherplatz. Das Plugin pusht keine Logs und hängt keinen Handler in den Monolog-Schreibpfad — der Log-Abruf ist rein lesend und passiert nur beim Poll.
- **JS Error Tracking:** Optional bindet das Plugin im Storefront-`<head>` den Loader für das von Monitorio gehostete Tracking-Snippet ein.
- **Lagerbestand-Push:** Pusht Bestands-Events (Bestand vorher/nachher je Produkt) als Batches an den Monitorio-Stock-Ingest — Grundlage für Back-in-Stock-Alarme und Out-of-Stock-Reports. Details unter [Lagerbestand-Push](#lagerbestand-push-stock-monitoring).

## Voraussetzungen

- Shopware 6.5.7.0 oder neuer (der Lagerbestand-Push nutzt den `low_priority`-Transport, den es erst ab 6.5.7 gibt)
- PHP 8.1 oder neuer
- Für den Lagerbestand-Push: ein laufender Messenger-Worker, der `async`, `low_priority` und `scheduler_shopware` konsumiert (`bin/console messenger:consume async low_priority scheduler_shopware` bzw. das Betriebs-Setup des Shops — das Shopware-Standard-Setup; der Admin-Worker erfüllt es ab Werk)

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
| Monitorio-URL (optional) | `EmzMonitorio.config.monitorioBaseUrl` | leer → `https://app.monitorio.de` |
| JS Error Tracking aktivieren | `EmzMonitorio.config.jsErrorTrackingEnabled` | aus |
| Shop-Token (öffentlich) | `EmzMonitorio.config.shopToken` | leer |
| Ingest-Token (geheim) | `EmzMonitorio.config.ingestToken` | leer → Lagerbestand-Push aus |
| Dedizierten Queue-Transport verwenden | `EmzMonitorio.config.useDedicatedTransport` | aus → Stock-Messages laufen über `low_priority`; Details unter [Optional: dedizierter Queue-Transport](#optional-dedizierter-queue-transport) |

**Projekt-ID** und **Shop-Token** stehen in Monitorio unter „Einrichtung" im Einbau-Code. Beide sind für das JS Error Tracking Pflicht — das Snippet bricht ohne eines von beiden still ab, deshalb liefert das Plugin dann gar nichts aus. Das Shop-Token ist kein Geheimnis, es steht im Quelltext jeder Shopseite; die Zuordnung schützt Monitorio zusätzlich über einen Origin-Check gegen die registrierten Shop-Domains. Das **Ingest-Token** dagegen ist ein Server-Geheimnis für den Lagerbestand-Push — die beiden dürfen nie vertauscht werden, Details im Stock-Abschnitt unten.

Die Admin-API-Endpunkte weiter unten brauchen keines von beidem — die laufen über Shopwares Admin-OAuth.

Die **Monitorio-URL** bleibt normalerweise leer und benennt die eine Instanz, mit der der Shop spricht: Von ihr lädt die Storefront das Tracking-Snippet (`{monitorioBaseUrl}/t/v1.js`), an sie sendet der Server den Bestands-Push. Das Feld existiert für lokale und Staging-Instanzen; das Snippet leitet seinen Ingest-Endpunkt aus seiner Lade-URL ab, ein Wert genügt also zum Umbiegen. Bis Version 1.2 hieß die Einstellung `snippetUrl` und enthielt die volle Snippet-URL — ein gesetzter Wert wird beim Plugin-Update automatisch übernommen.

Beim Setzen per CLI Zahl und Schalter als echte JSON-Werte schreiben, nicht als String:

```bash
bin/console system:config:set --json EmzMonitorio.config.jsErrorTrackingEnabled true
bin/console system:config:set --json EmzMonitorio.config.projectId 1
bin/console system:config:set EmzMonitorio.config.shopToken '<token>'
bin/console cache:clear
```

## JS Error Tracking

Ist die Einstellung aktiv **und** sind Projekt-ID und Shop-Token hinterlegt, hängt das Plugin einen Loader in den Storefront-`<head>` — vor Favicon, Title und Stylesheets, damit auch frühe Fehler erfasst werden. Fehlt eines davon, wird nichts ausgeliefert.

Der Loader setzt `window.__monitorio` und lädt anschließend das Snippet asynchron von der konfigurierten Monitorio-URL (`{monitorioBaseUrl}/t/v1.js`, Default `https://app.monitorio.de`). Ein `preconnect`-Hint auf dieselbe Origin wärmt DNS/TLS vor, bevor das Snippet angefordert wird. Der komplette Block liegt in einem `try/catch`; ein Fehler darin bleibt folgenlos für die Storefront.

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

Das Snippet selbst hostet und versioniert Monitorio; es steckt bewusst **nicht** im Plugin, damit Snippet-Updates ohne Plugin-Release ausgerollt werden können. Der Pfad liegt als Konstante `JsErrorTrackingConfigProvider::SNIPPET_PATH` im Code; der Host kommt aus der gemeinsamen Monitorio-URL.

Eine Änderung der Einstellung greift nach `bin/console cache:clear`.

## Lagerbestand-Push (Stock-Monitoring)

Der Shop pusht Bestands-**Zustände** (Bestand vorher/nachher je Leaf-Produkt, also je Variante bzw. variantenlosem Produkt) als Batches an `POST {monitorioBaseUrl}/ingest/stock/{projectId}`. Jede Interpretation — Transition-Erkennung (back in stock / out of stock), Abverkauf-Filter, Alarme, Reports — passiert serverseitig in Monitorio. Es werden immer **alle** Leaf-Produkte gemeldet, auch inaktive und Abverkauf-Produkte (`isCloseout`); gefiltert wird in Monitorio. Der API-Contract ist in [docs/stock_companion_push.md](docs/stock_companion_push.md) festgeschrieben, die Umsetzungsentscheidungen in [docs/stock_push_konzept.md](docs/stock_push_konzept.md).

### Einrichtung

1. **Ingest-Token** von der Monitorio-Setup-Seite des Projekts holen (Projekt → Statistiken → Lagerbestand → Einrichtung). Das Token ist ein **Server-Geheimnis** — nicht zu verwechseln mit dem öffentlichen Shop-Token des JS Error Trackings.
2. Projekt-ID und Ingest-Token hinterlegen (der Push ist aktiv, sobald beide gesetzt sind — es gibt keinen eigenen Schalter):

   ```bash
   bin/console system:config:set --json EmzMonitorio.config.projectId 1
   bin/console system:config:set EmzMonitorio.config.ingestToken '<token>'
   bin/console cache:clear
   ```

3. Sicherstellen, dass der Messenger-Worker `low_priority` mitkonsumiert (siehe [Voraussetzungen](#voraussetzungen)) — sonst bleiben die Stock-Messages liegen.

4. Baseline-Vollimport anstoßen (initialisiert Monitorio und die lokale Zustandstabelle, löst nie Alarme aus; jederzeit manuell wiederholbar):

   ```bash
   bin/console emz:monitorio:stock:baseline
   ```

Das Ziel ergibt sich aus der gemeinsamen **Monitorio-URL** (Karte „Monitorio-Anbindung", leer → `https://app.monitorio.de`). Der Push liest alle Werte global — Sales-Channel-Overrides wirken nur auf das JS Error Tracking.

### Funktionsweise

Drei Quellen, im Event über `source` unterscheidbar:

- **`subscriber`** (live): `ProductStockAlteredEvent` (Order-Lifecycle — Shopware 6.7 schreibt Bestand beim Bestellen per Direkt-SQL, dieses Core-Event ist dort der Hook) plus `product.written` mit `stock`/`availableStock` im Payload (Admin, Sync-API). Der Subscriber stellt nur eine leichte Message in die Messenger-Queue — im auslösenden Request passiert nie HTTP.
- **`reconciliation`**: ScheduledTask `emz_monitorio.stock_reconciliation` (Default alle 300 s, änderbar über `scheduled_task.run_interval`). Diffed die eigene Zustandstabelle gegen `product` und fängt damit auch Änderungen, die am Event-System vorbeilaufen (ERP-Importe per Direkt-SQL).
- **`baseline`**: der Vollimport per Command; außerdem werden Produkte ohne Zustandszeile (z. B. neu angelegte) automatisch als `baseline`-Event gemeldet — sie lösen serverseitig nie Alarme aus.

Robustheit: Jeder Batch wird mitsamt seiner `batchId` in der Outbox-Tabelle persistiert, **bevor** er gesendet wird. Die Zustandstabelle (zuletzt bestätigt gemeldeter Stand, Quelle der `previous*`-Werte) wird erst nach Response `204` fortgeschrieben — schlägt der Versand fehl, meldet die nächste Reconciliation die Differenz erneut, es geht kein Endzustand verloren. Transiente Fehler (`429`/`503`/Netzwerk) werden mit `Retry-After` bzw. exponentiellem Backoff und **identischer `batchId`** wiederholt (der Server ist auf `batchId` idempotent); `413` schneidet den Batch in kleinere neue Batches; `400` wird verworfen und geloggt; bei `401`/`403` pausiert der Versand für 60 Minuten und es erscheint eine Admin-Notification (Glocke) — typisch nach einer Token-Rotation in Monitorio. Nicht zustellbare Batches werden nach 72 h bzw. ab 500 offenen Batches verworfen (Warning im Log); die Reconciliation meldet offene Differenzen anschließend erneut.

Der Versand läuft asynchron über die Messenger-Queue. Die Stock-Messages (Subscriber-Erfassung **und** die Reconciliation-Arbeit — der ScheduledTask dispatcht nur noch, statt selbst HTTP zu machen) laufen über Shopwares **`low_priority`-Transport**: Der Standard-Worker konsumiert Receiver in Reihenfolge, Shop-Messages wie Mails und Indexer gehen deshalb immer vor, ein zäher Monitorio-Ingest kann sie nicht verzögern. Voraussetzung ist ein laufender Worker, der `low_priority` mitkonsumiert (`bin/console messenger:consume async low_priority scheduler_shopware` bzw. das Betriebs-Setup des Shops — der Shopware-Standard seit 6.5.7, auch der Admin-Worker tut es ab Werk). **Setups, die nur `async` konsumieren, müssen `low_priority` ergänzen.** Das Plugin setzt deshalb `shopware/core >= 6.5.7.0` voraus — erst dort existieren der `low_priority`-Transport und sein Routing-Interface.

Zwei Plugin-Tabellen gehören dazu: `emz_monitorio_stock_state` (Zustand) und `emz_monitorio_stock_outbox` (persistierte Batches). Beide werden bei der Deinstallation (ohne „Nutzerdaten behalten") entfernt. Eine Bestandshistorie hält der Shop nicht — Historie und Auswertung macht Monitorio.

### Optional: dedizierter Queue-Transport

Für Shops, die den Monitorio-Verkehr vollständig von den eigenen Queue-Workern isolieren wollen, bringt das Plugin den Messenger-Transport **`emz_monitorio`** mit (definiert in `src/Resources/config/packages/messenger.yaml`, per Default Doctrine auf derselben `messenger_messages`-Tabelle mit eigenem `queue_name` — **kein RabbitMQ nötig**). Der Schalter **„Dedizierten Queue-Transport verwenden"** in der Plugin-Konfiguration leitet alle Stock-Messages per `TransportNamesStamp` dorthin um; er wirkt sofort, ohne Cache-/Container-Rebuild.

Aktiviert braucht der Transport **einen eigenen Worker**, z. B. als zusätzliches Supervisor-Programm:

```ini
[program:emz_monitorio_worker]
command=php /var/www/html/bin/console messenger:consume emz_monitorio --time-limit=3600 --memory-limit=512M
autostart=true
autorestart=true
```

Alternativ genügt es, `emz_monitorio` an eine bestehende `messenger:consume`-Zeile anzuhängen (dann teilt er sich den Prozess wieder — die Isolation entfällt, nur die Priorisierung bleibt). Läuft kein Worker, stauen sich die Messages: ein Watchdog im Reconciliation-Task warnt dann im Log (jeder Lauf) und per Admin-Notification (einmal je Vorfall, bei anhaltendem Zustand erneut nach 24 h); zusätzlich taucht der wachsende Backlog im `/api/monitorio/message-queue`-Endpunkt auf, den Monitorio ohnehin pollt. Soll ein anderer Broker die Queue tragen, reicht die ENV-Variable `EMZ_MONITORIO_TRANSPORT_DSN` (z. B. `amqp://…`) — der Watchdog kann dann nicht in `messenger_messages` messen und hält sich still.

**Beim Deaktivieren des Schalters:** Noch im Transport liegende Messages holt danach niemand mehr ab (neue Messages laufen wieder über `low_priority`, der Watchdog prüft nur bei aktivem Schalter). Deshalb nach dem Umschalten einmalig leerräumen: `bin/console messenger:consume emz_monitorio --limit=100 --time-limit=60`. Fachlich geht dabei nichts verloren — die Reconciliation meldet offene Bestandsdifferenzen ohnehin erneut —, aber die Zeilen blieben sonst dauerhaft in `messenger_messages` liegen.

## Tests

Unit- und DB-Tests laufen ohne Shopware-Testkernel über den Standalone-Bootstrap (DB-Tests nutzen eine eigene Datenbank `emz_monitorio_stock_test`, abgeleitet aus `DATABASE_URL`; alternativ `EMZ_MONITORIO_TEST_DATABASE_URL` setzen):

```bash
php phpunit.phar \
    --bootstrap custom/plugins/EmzMonitorio/tests/bootstrap-standalone.php \
    custom/plugins/EmzMonitorio/tests
```

In Umgebungen mit vollständigen dev-Dependencies funktioniert weiterhin `tests/TestBootstrap.php` (Shopware `TestBootstrapper`) über die `phpunit.xml` des Plugins.

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

Der Abruf ist auf große Logs ausgelegt: Dateien, deren letzte Änderung vor `since` liegt, werden ungelesen übersprungen (eine rotierte GB-Datei kostet einen stat-Call), und in großen Dateien springt der Reader per Bisektion an den Fensterstart, statt ab Byte 0 zu scannen — auch ein mehrere GB großes, aktives Log antwortet in Millisekunden. Überlange Zeilen (> 32 KiB, etwa riesige Stack-Traces im Context) werden gekürzt übernommen statt komplett geladen. Gelesen wird aufsteigend nach Datei-Änderungszeit: greift das `limit`, überleben die **ältesten** Einträge — der `since`-Cursor des nächsten Polls holt den Rest lückenlos nach. `external_key` ist ein stabiler Hash über Zeitpunkt, Kanal, Level, Message und Fundstelle (Duplikat-Erkennung beim Poll); `logged_at` kommt mikrosekundengenau, weil Monitorio den Wert als Pull-Cursor benutzt.

```json
{
    "data": [
        {
            "external_key": "9c4f2a7d1b8e35a6c0d94e17f2b86c31a5d7e90f4b823c6d1e0a9f57b4c28d63",
            "logged_at": "2026-07-09T11:32:45.418239+00:00",
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

### GET /api/_action/emz/monitorio/logs/meta

Meldet Größe, Dateianzahl, größte Datei und die vollständige Dateiliste des Log-Verzeichnisses (`%kernel.logs_dir%`), ohne eine einzige Log-Zeile zu lesen — nur `filesize()` und `filemtime()` je Datei. Der Aufwand hängt an der Anzahl Dateien, nicht an ihren Bytes. Der Endpunkt bleibt bewusst von `/logs` getrennt: Die Größenmeldung hängt so unter keinen Umständen am Eintrags-Scan (bis Version 1.5 lief der bei einem mehrere GB großen Log in den HTTP-Timeout; seit 1.6 liest `/logs` gezielt ab dem angefragten Zeitfenster, die Trennung bleibt trotzdem — ein Messgerät, das nichts liest, kann nicht vom Log-Inhalt überrascht werden).

Erfasst wird dieselbe Dateimenge wie bei `/logs` (`*.log` im Log-Verzeichnis), damit die gemeldete Größe den dortigen Scan erklärt. Keine Query-Parameter. `name` ist überall der Basename, nie der Pfad — Serverpfade gehören nicht in eine Monitoring-Antwort. Existiert das Log-Verzeichnis nicht oder enthält es keine `*.log`-Datei, kommt `total_bytes: 0` und `file_count: 0` mit `largest: null`, `newest_modified_at: null` und `files: []` — kein Fehler.

```json
{
    "data": {
        "total_bytes": 175671101,
        "file_count": 4,
        "largest": {
            "name": "dev.log",
            "bytes": 175000000,
            "modified_at": "2026-08-26T06:07:27+00:00"
        },
        "newest_modified_at": "2026-08-26T06:07:27+00:00",
        "files": [
            {
                "name": "dev.log",
                "bytes": 175000000,
                "modified_at": "2026-08-26T06:07:27+00:00"
            },
            {
                "name": "prod-2026-08-11.log",
                "bytes": 18342,
                "modified_at": "2026-08-11T23:59:12+00:00"
            }
        ]
    }
}
```

#### Die Aufschlüsselung passiert in Monitorio

`files` enthält **jede** Datei des Verzeichnisses mit Name, Bytes und Änderungszeit: kein Top-N, keine Stichprobe, keine Deckelung. Kanäle, Rotationsregel (`prod-2026-08-11.log` → Kanal `prod`) und Gruppierung rechnet Monitorio aus dieser Liste (`internal/plugins/shop_log/volume_channels.go`). Der Companion misst und legt nicht aus.

Der Grund ist der Rollout: Das Plugin steht auf jedem Shop einzeln. Eine Auslegungsregel hier wäre nur mit einem Rollout über alle Shops zu ändern, und ein Shop mit abweichendem Rotationsformat würde still falsch klassifizieren, ohne dass Monitorio das geradeziehen könnte. In Go liegt dieselbe Regel an einer Stelle und ist mit einem Deploy korrigiert.

Daraus folgen zwei Zusicherungen, auf die sich die Go-Seite verlässt:

- **Vollständigkeit.** Jede von `glob('*.log')` gefundene und statbare Datei taucht genau einmal in `files` auf, und `file_count` ist deren Anzahl. Fehlt eine Datei, rechnet Monitorio einen Kanal zu klein, ohne es merken zu können. Nicht statbare Einträge (zwischen `glob()` und `stat()` wegrotiert) werden übersprungen und zählen dann in keinem der Werte mit.
- **`modified_at` ist RFC3339 in UTC.** Monitorio vergleicht die Werte lexikalisch, um je Kanal die neueste Datei zu bestimmen; ein wechselnder Offset würde diese Reihenfolge still verdrehen.

Die Reihenfolge von `files` ist die von `glob()` (alphabetisch) und kein Teil des Vertrags — Monitorio sortiert selbst, worauf es ankommt. `largest` und `newest_modified_at` bleiben trotz Redundanz zu `files` in der Antwort: Sie sind die Werte, die ein Alarm ohne Vorverarbeitung braucht.

#### Nutzlast und Laufzeit

Ohne Deckelung wächst die Antwort mit der Dateizahl. Gemessen (leere Dateien, bester von drei Läufen, lokal im ddev-Container):

| Verzeichnis | Laufzeit | Peak | Antwort roh | gzip |
|---|---|---|---|---|
| 5.041 Dateien | 11 ms | 6 MB | 394 KB | 14 KB |
| 50.001 Dateien | 105 ms | 44 MB | 3,81 MB | 132 KB |
| 200.002 Dateien | 432 ms | 176 MB | 15,26 MB | 527 KB |

Die Dateigröße kostet nichts: Die Laufzeit hängt allein an der Anzahl Dateien. Die Nutzlast ist unkritisch, weil lauter ähnliche Dateinamen und Zeitstempel um **Faktor 29** komprimieren — bei 50.001 Dateien bleiben 132 KB über die Leitung, weniger als der Eintrags-Endpunkt desselben Plugins routinemäßig überträgt.

Die verbleibende Grenze ist der Speicher: 176 MB Peak bei 200.002 Dateien, bei einem `memory_limit` von 1 GB im getesteten Container. Ein Log-Verzeichnis dieser Größenordnung ist unabhängig davon ein Befund — ein realer Shop hat eine zwei- bis dreistellige Zahl Dateien und liegt damit weit unter der ersten Tabellenzeile.

### GET /api/monitorio/message-queue

**Zweck:** Liefert den Message-Queue-Backlog pro **Messenger-Transport** — dieselben Zahlen wie `bin/console messenger:stats`, als JSON. Funktioniert transportunabhängig (Doctrine/Datenbank, AMQP/RabbitMQ, Redis, …), weil über die `messenger.receiver`-getaggten Transports und deren `MessageCountAwareInterface::getMessageCount()` gezählt wird. Monitorio pollt den Endpunkt pro Shop, speichert die Zeitreihe und alarmiert bei dauerhaft wachsendem Backlog (Frühwarnsignal für einen hängenden oder überlasteten `messenger:consume`-Worker). Der Endpunkt ist ein versions-stabiler Ersatz für die Standard-API `GET /api/_info/queue.json`, die ab Shopware 6.7.8.0 deprecated und in 6.8.0.0 entfernt ist.

**Auth:** Admin-API-Integration über OAuth `client_credentials` (`Authorization: Bearer <token>`), Route-Scope `api`. Es ist kein zusätzliches Secret im Shop zu konfigurieren.

**Request:** Kein Request-Body, keine Query-Parameter.

**Response** (`200`, `application/json`): Ein JSON-Array mit einem Objekt pro Transport. `name` ist der Transport-Name (wie in `messenger:stats`, z. B. `async`, `low_priority`, `failed`), `size` die Anzahl wartender Messages als Integer. Transports ohne wartende Messages erscheinen mit `size: 0`. Ab Plugin-Version 1.6 taucht auch der plugineigene Transport `emz_monitorio` auf — mit `size: 0`, solange der [dedizierte Queue-Transport](#optional-dedizierter-queue-transport) nicht aktiviert ist.

```json
[
    { "name": "failed", "size": 0 },
    { "name": "async", "size": 1234 },
    { "name": "low_priority", "size": 5 },
    { "name": "emz_monitorio", "size": 0 }
]
```

Ein leeres Array `[]` ist ein valider Erfolgsfall (keine zählbaren Transports konfiguriert).

**Bekannte Einschränkungen:**

- Transports, die kein `MessageCountAwareInterface` implementieren (z. B. `scheduler_shopware`), können nicht gezählt werden und fehlen in der Antwort — analog zur NOTE von `messenger:stats`.
- Ist ein Transport nicht erreichbar (z. B. AMQP-Broker down), wird er ausgelassen statt die Antwort mit `500` zu beenden; sein Eintrag fehlt dann im Poll.
- Die Zählung entspricht der `messenger:stats`-Semantik des jeweiligen Transports; beim Doctrine-Transport zählen z. B. verzögerte Messages (`available_at` in der Zukunft) nicht mit.

## Lizenz

MIT
