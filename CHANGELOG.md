# Changelog

Alle nennenswerten Änderungen an diesem Plugin werden in dieser Datei dokumentiert.

Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
und das Projekt folgt [Semantic Versioning](https://semver.org/lang/de/).

## [1.2.0] - 2026-08-17

### Hinzugefügt

- Plugin-Konfiguration mit den Einstellungen `projectId`, `shopToken` (beide aus dem Monitorio-Einbau-Code), `jsErrorTrackingEnabled` (Default: aus) und `snippetUrl` (optionaler Override für abweichende Monitorio-Instanzen; Default ist `https://staging-app.monitorio.de/t/v1.js`), alle je Sales-Channel überschreibbar.
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
