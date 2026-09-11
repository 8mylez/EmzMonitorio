# Changelog

Alle nennenswerten Änderungen an diesem Plugin werden in dieser Datei dokumentiert.

Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
und das Projekt folgt [Semantic Versioning](https://semver.org/lang/de/).

## [1.6.0] - 2026-09-02

### Geändert

- **Mindestanforderung angehoben: `shopware/core >= 6.5.7.0`** (vorher `>= 6.5.0.0`). Der `low_priority`-Transport und sein `LowPriorityMessageInterface` existieren erst ab 6.5.7.0 — auf 6.5.0–6.5.6 würde das Laden der Stock-Messages mit einem Fatal enden.
- Stock-Push-Messages laufen über Shopwares `low_priority`-Transport statt `async`. Der Standard-Worker (`messenger:consume async low_priority`, ebenso der Admin-Worker ab Werk) konsumiert Receiver in Reihenfolge — Shop-Messages wie Mails und Indexer gehen damit immer vor, ein zäher Monitorio-Ingest kann sie nicht mehr verzögern. **Betriebs-Hinweis:** Worker-Setups, die entgegen dem Shopware-Standard nur `messenger:consume async` fahren, müssen `low_priority` ergänzen, sonst bleiben Stock-Messages liegen.
- Der Reconciliation-ScheduledTask führt die Arbeit (Outbox-Flush + Diff inklusive der HTTP-Calls) nicht mehr selbst im `scheduler_shopware`-Worker aus, sondern dispatcht sie als `StockReconciliationMessage` in denselben (per Konfiguration wählbaren) Transport wie den Subscriber-Pfad. Damit blockiert Monitorio-HTTP nie mehr den Task-Worker; überlappende Läufe bleiben durch Outbox-Claim-Lease und die „Diff nur bei leerer Outbox"-Bremse harmlos.
- Plugin-Beschreibung (`composer.json`, Anzeige im Extension-Manager) korrigiert: Das Plugin leitet keine Monolog-Records weiter und hängt keinen Handler in den Log-Schreibpfad — Logs werden von Monitorio über die Admin-API gepullt. Die Beschreibung nennt jetzt die tatsächlichen Funktionen (Monitoring-Endpunkte, JS-Error-Tracking-Loader, Lagerbestand-Push).
- Log-Abruf `GET /api/_action/emz/monitorio/logs`: liest nicht mehr jede `*.log` ab Byte 0. Dateien mit `mtime` vor `since` werden ungelesen übersprungen (eine rotierte GB-Datei kostet einen stat-Call), in großen Dateien findet eine Bisektion den Fensterstart (O(log Größe) Seeks, 64-KiB-Sicherheitsrücksprung gegen lokal nicht-monotone Zeitstempel), überlange Zeilen werden bei 32 KiB gekappt statt komplett in den Speicher geladen, und Dateien werden aufsteigend nach `mtime` gelesen, damit ein Limit-Schnitt die ältesten Einträge behält (Cursor-Semantik des Pulls). Gemessen an einer realen 871-MB-`dev.log`: 22 ms / 12 MB Peak statt >120 s ohne Antwort. Contract unverändert (`loggedAt == since` bleibt inklusive, `externalKey` stabil).

### Hinzugefügt

- Optionaler dedizierter Messenger-Transport `emz_monitorio` (`src/Resources/config/packages/messenger.yaml`): per Default Doctrine auf derselben `messenger_messages`-Tabelle mit eigenem `queue_name` — kein zusätzlicher Broker nötig, per ENV `EMZ_MONITORIO_TRANSPORT_DSN` auf z. B. AMQP umbiegbar. Der neue Schalter `EmzMonitorio.config.useDedicatedTransport` (Default aus; unklare Werte zählen fail-closed als aus) leitet alle Stock-Messages per `TransportNamesStamp` dorthin — zur Laufzeit, ohne Container-Rebuild. Erfordert einen eigenen Worker (`messenger:consume emz_monitorio`); der ungenutzte Transport kostet nichts und erscheint lediglich mit `size: 0` in `messenger:stats` und im `/api/monitorio/message-queue`-Endpunkt.
- Watchdog für den dedizierten Transport im Reconciliation-Task (läuft bewusst im `scheduler_shopware`-Kontext, damit er auch bei fehlendem Worker ausgeführt wird): misst das Alter der ältesten unzugestellten Message direkt in `messenger_messages` (als UTC geparst — der Doctrine-Transport schreibt `created_at` in UTC); ab 10 Minuten warnt er bei jedem Lauf im Log und per Admin-Notification einmal je Vorfall (persistierter Marker in der `system_config` statt eines Zeitfensters, damit auch ein erst spät laufender Check notifiziert; bei anhaltendem Zustand erneut nach 24 h). Der Marker fällt mit Hysterese erst deutlich unterhalb der Warnschwelle (< 5 min) bzw. bei leerem Transport — ein um die Schwelle pendelnder Backlog erzeugt so keine Notification-Serie — und wird bei der Deinstallation mit entfernt. Bei einem per ENV umgebogenen Broker kann der Watchdog nicht messen und hält sich still — den Backlog sieht Monitorio dann weiterhin über den Message-Queue-Endpunkt.
- `<link rel="preconnect">` auf die Monitorio-Origin im Storefront-Head (nur bei aktivem JS Error Tracking, mit `crossorigin` passend zum anonymen Script-Load): der Browser baut DNS/TLS zur Monitorio-Instanz schon auf, bevor das Snippet angefordert wird.
- Installation per Composer (`composer require emz/monitorio`) samt `LICENSE`-Datei (MIT). Tests, PHPUnit-Konfiguration und Entwicklungsdokumente sind per `.gitattributes` vom Dist-Archiv ausgenommen; das Plugin-Icon ist auf 256 × 256 px verkleinert (275 KB → 6 KB), weil es mit jeder Installation ausgeliefert wird.

## [1.4.0] - 2026-08-24

### Hinzugefügt

- Endpunkt `GET /api/_action/emz/monitorio/logs/meta`: meldet Größe (`total_bytes`), Dateianzahl (`file_count`), größte Datei (`largest`, Basename statt Pfad) und jüngste Änderung (`newest_modified_at`) des Log-Verzeichnisses. Erhoben wird ausschließlich über `glob('*.log')` + `filesize()`/`filemtime()` — keine Log-Zeile wird gelesen, kein Parser läuft. Fehlt das Log-Verzeichnis oder ist es leer, kommt `total_bytes: 0` / `file_count: 0` statt eines Fehlers.
- Vollständige Dateiliste in derselben Antwort (`files`): jede `*.log` des Verzeichnisses mit Basename, Bytes und `modified_at` — ohne Deckelung, ohne Top-N, ohne Stichprobe. `modified_at` ist durchgängig RFC3339 in **UTC**, weil Monitorio die Werte lexikalisch vergleicht, um je Kanal die neueste Datei zu bestimmen; ein wechselnder Offset würde diese Reihenfolge still verdrehen.
- Kanäle, Rotationsregel und Gruppierung rechnet **Monitorio** aus dieser Liste (`internal/plugins/shop_log/volume_channels.go`), nicht mehr der Companion. Damit entfällt die zuvor auf diesem Branch entwickelte, nie veröffentlichte Aufschlüsselung im Plugin (`channels` mit `rotates`/`remaining_*`) samt ihrer Notbremsen (`truncated`, 50.000 Dateien / 2.000 Kanäle) ersatzlos. Grund ist der Rollout: Das Plugin steht auf jedem Shop einzeln — eine Auslegungsregel hier wäre nur mit einem Rollout über alle Shops zu ändern, und ein Shop mit abweichendem Rotationsformat würde still falsch klassifizieren, ohne dass Monitorio das geradeziehen könnte. In Go liegt dieselbe Regel an einer Stelle und ist mit einem Deploy korrigiert. Der Companion ist wieder reines Messgerät: `glob()` + `filesize()`/`filemtime()`, keine Auslegung.
- Die dadurch größere Nutzlast ist geprüft und unkritisch: gemessen 5.041 Dateien → 11 ms, 394 KB roh / 14 KB gzip; 50.001 Dateien → 105 ms bei 44 MB Peak, 3,81 MB / 132 KB; 200.002 Dateien → 432 ms bei 176 MB Peak, 15,26 MB / 527 KB. Lauter ähnliche Dateinamen und Zeitstempel komprimieren um Faktor 29, und der Eintrags-Endpunkt desselben Plugins überträgt routinemäßig mehr. Die Laufzeit hängt weiterhin allein an der Anzahl Dateien, nicht an ihren Bytes.
- Der Endpunkt ist bewusst von `GET /api/_action/emz/monitorio/logs` getrennt und kein `meta`-Block in dessen Antwort: Der dortige Scan liest jede `*.log` ab Byte 0 und parst jede Zeile, läuft bei einem mehrere GB großen Log in den HTTP-Timeout und liefert dann gar keine Antwort — die Größenmeldung wäre also ausgerechnet im kritischen Fall nicht abrufbar. Anlass ist ein realer Vorfall: eine nie rotierte 2,08 GB große `var/log/dev.log` ließ den Log-Abruf über einen Monat lang stumm in den Timeout laufen. Beide Endpunkte sehen dieselbe Dateimenge, damit die gemeldete Größe den Scan erklärt.

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
