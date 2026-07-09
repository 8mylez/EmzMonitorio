# Changelog

Alle nennenswerten Änderungen an diesem Plugin werden in dieser Datei dokumentiert.

Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
und das Projekt folgt [Semantic Versioning](https://semver.org/lang/de/).

## [1.1.0] - 2026-07-09

### Hinzugefügt

- Endpunkt `GET /api/monitorio/message-queue`: liefert den exakten Message-Queue-Backlog (wartende Messages pro `queue_name`) aus der `messenger_messages`-Tabelle des Doctrine-Transports. Versions-stabiler Ersatz für das ab Shopware 6.7.8.0 deprecatete `/api/_info/queue.json`. Ohne Doctrine-Transport wird `200` mit leerem Array `[]` zurückgegeben.

## [1.0.0]

### Hinzugefügt

- Regelbasiertes Weiterleiten ausgewählter Monolog-Log-Records und benutzerdefinierter Events an einen Monitorio-Ingest-Endpunkt.
- Endpunkt `GET /api/monitorio/free-disk-space`: freier und gesamter Speicherplatz des Root-Dateisystems.
- Endpunkt `GET /api/_action/emz/monitorio/logs`: Auslesen von Shop-Logs mit den Filtern `since`, `min_level` und `limit`.
