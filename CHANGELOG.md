# Changelog

Alle nennenswerten Änderungen an diesem Plugin werden in dieser Datei dokumentiert.

Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
und das Projekt folgt [Semantic Versioning](https://semver.org/lang/de/).

## [1.3.0] - 2026-08-20

### Hinzugefügt

- Lagerbestand-Push an den Monitorio-Stock-Ingest (`POST {monitorioBaseUrl}/ingest/stock/{projectId}`, Bearer-Auth): meldet Bestands-Zustände (vorher/nachher) aller Leaf-Produkte als Batches — live über `ProductStockAlteredEvent` + `product.written` (asynchron via Messenger), per Reconciliation-ScheduledTask `emz_monitorio.stock_reconciliation` (Default 300 s, fängt auch Direkt-SQL-Importe) und als Baseline-Vollimport über `bin/console emz:monitorio:stock:baseline`. Transition-Erkennung, Filter und Alarme passieren serverseitig in Monitorio.
- Die Quelle für `availableStock` richtet sich nach der Shopware-Version: ab 6.6 `product.stock`, davor `product.available_stock`. Ab 6.6 ist `available_stock` nur noch ein write-protected Spiegel von `stock`, den ausschließlich DAL-Writes und der Order-Lifecycle nachziehen — ein Direkt-SQL-Import (ERP) ließe ihn veralten und Monitorios Out-of-stock-Logik (`availableStock <= 0`) auf einem toten Wert rechnen. `stock` ist ab 6.6 zugleich der Wert, über den Shopware selbst Verfügbarkeit entscheidet.
- Zustandstabelle `emz_monitorio_stock_state` (zuletzt bestätigt gemeldeter Stand, Quelle der `previous*`-Werte; wird erst nach Response 204 fortgeschrieben) und Outbox `emz_monitorio_stock_outbox` (persistierte Batches: Retries mit identischer `batchId` über Prozess-Neustarts hinweg, Fallback-Puffer bei nicht erreichbarem Monitorio, Retention 72 h / max. 500 offene Batches). Beide Tabellen werden bei Deinstallation ohne „Nutzerdaten behalten" entfernt.
- Statuscode-Handling nach fixem API-Contract (`docs/stock_companion_push.md`): 429/503/Netzwerkfehler → Retry mit `Retry-After`/Backoff und identischer `batchId`; 413 → kleinere neue Batches mit neuen `batchId`s; 400 → verwerfen + Error-Log; 401/403 → Versand 60 min pausieren + Admin-Notification.
- Neue Konfiguration: `EmzMonitorio.config.ingestToken` (Server-Geheimnis für den Bestands-Push, ausdrücklich nicht der öffentliche `shopToken`). Der Push ist aktiv, sobald Projekt-ID und Ingest-Token gesetzt sind; er liest alle Werte global, nicht je Sales-Channel.
- Standalone-Test-Bootstrap (`tests/bootstrap-standalone.php`): Unit- und DB-Tests laufen ohne Shopware-Testkernel; DB-Tests in eigener Test-Datenbank.

### Geändert

- Plugin-Konfiguration neu geschnitten: Die gemeinsame **Monitorio-URL** (`EmzMonitorio.config.monitorioBaseUrl`, Karte „Monitorio-Anbindung", Default `https://app.monitorio.de`) ersetzt die bisherige `snippetUrl` und gilt für beide Richtungen — von ihr lädt die Storefront das Tracking-Snippet (`{monitorioBaseUrl}/t/v1.js`), an sie sendet der Server den Bestands-Push. Ein gesetzter `snippetUrl`-Wert wird beim Plugin-Update je Scope automatisch übernommen (das `/t/v1.js`-Suffix entfällt, eine bereits gesetzte Monitorio-URL gewinnt) und der Alt-Schlüssel entfernt.
- Die Token-Felder tragen ihre Vertraulichkeit im Label: Das **Shop-Token (öffentlich)** ist in die Karte „JavaScript-Fehler-Tracking" gezogen (es gehört nur zu diesem Feature und steht im HTML jeder Shopseite), das **Ingest-Token (geheim)** bleibt in „Lagerbestand-Monitoring" — die Help-Texte warnen in beide Richtungen vor dem Vertauschen.

### Behoben

- `LogReaderTest`: zwei Tests lasen mit `since='-1 day'` gegen Fixtures mit festem Zeitstempel (2026-04-26) und schlugen deshalb seit dem 27.04.2026 fehl.

## [1.2.0] - 2026-08-17

### Hinzugefügt

- Plugin-Konfiguration mit den Einstellungen `projectId`, `shopToken` (beide aus dem Monitorio-Einbau-Code), `jsErrorTrackingEnabled` (Default: aus) und `snippetUrl` (optionaler Override für lokale und Staging-Instanzen), alle je Sales-Channel überschreibbar.
- Feld `buildId` in der Storefront-Konfiguration: Kennung des Storefront-Builds, identisch mit dem Theme-Verzeichnis in den Asset-URLs (`/theme/<buildId>/js/...`). Wechselt bei jedem `theme:compile` und dient später als Deploy-Marker und Sourcemap-Schlüssel. Entfällt bei Sales-Channels ohne Theme. Wird von Monitorio derzeit noch nicht ausgewertet.
- JS Error Tracking: Ist die Einstellung aktiv und sind Projekt-ID und Shop-Token hinterlegt, injiziert das Plugin im Storefront-`<head>` ein `window.__monitorio`-Objekt (`projectId`, `shopToken`, `salesChannelId`, `context`, `buildId`) und lädt anschließend asynchron das von Monitorio gehostete Snippet. Der Loader hängt vor Favicon, Title und Stylesheets, läuft komplett in `try/catch` und kann die Storefront nicht brechen. Tracking-Code selbst bringt das Plugin nicht mit. `context` ergibt sich aus dem Präfix der aktiven Route: `frontend.checkout.` → `checkout`, `frontend.account.` → `account`, sonst `storefront`.

## [1.1.0] - 2026-07-09

### Hinzugefügt

- Endpunkt `GET /api/monitorio/message-queue`: liefert den Message-Queue-Backlog pro Messenger-Transport als JSON — dieselben Zahlen wie `bin/console messenger:stats`, transportunabhängig (Doctrine, AMQP/RabbitMQ, Redis) über `MessageCountAwareInterface` gezählt. Versions-stabiler Ersatz für das ab Shopware 6.7.8.0 deprecatete `/api/_info/queue.json`. Nicht zählbare Transports (z. B. `scheduler_shopware`) und nicht erreichbare Transports werden ausgelassen statt einen `500` zu erzeugen.

## [1.0.0]

### Hinzugefügt

- Regelbasiertes Weiterleiten ausgewählter Monolog-Log-Records und benutzerdefinierter Events an einen Monitorio-Ingest-Endpunkt.
- Endpunkt `GET /api/monitorio/free-disk-space`: freier und gesamter Speicherplatz des Root-Dateisystems.
- Endpunkt `GET /api/_action/emz/monitorio/logs`: Auslesen von Shop-Logs mit den Filtern `since`, `min_level` und `limit`.
