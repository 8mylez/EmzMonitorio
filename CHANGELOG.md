# Changelog

Alle nennenswerten Änderungen an diesem Plugin werden in dieser Datei dokumentiert.

Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
und das Projekt folgt [Semantic Versioning](https://semver.org/lang/de/).

## [1.1.0] - 2026-07-09

### Hinzugefügt

- Endpunkt `GET /api/monitorio/message-queue`: liefert den Message-Queue-Backlog pro Messenger-Transport als JSON — dieselben Zahlen wie `bin/console messenger:stats`, transportunabhängig (Doctrine, AMQP/RabbitMQ, Redis) über `MessageCountAwareInterface` gezählt. Versions-stabiler Ersatz für das ab Shopware 6.7.8.0 deprecatete `/api/_info/queue.json`. Nicht zählbare Transports (z. B. `scheduler_shopware`) und nicht erreichbare Transports werden ausgelassen statt einen `500` zu erzeugen.

## [1.0.0]

### Hinzugefügt

- Regelbasiertes Weiterleiten ausgewählter Monolog-Log-Records und benutzerdefinierter Events an einen Monitorio-Ingest-Endpunkt.
- Endpunkt `GET /api/monitorio/free-disk-space`: freier und gesamter Speicherplatz des Root-Dateisystems.
- Endpunkt `GET /api/_action/emz/monitorio/logs`: Auslesen von Shop-Logs mit den Filtern `since`, `min_level` und `limit`.
