# Companion-Push: Bestands-Events (Stock-Monitoring) – Technische Anforderungen

Status: Anforderung (übergabefertig)
Stand: 2026-08-19
Ziel-Repo: Shopware-Companion-Plugin `EmzMonitorio` (github `8mylez-GmbH/EmzMonitorio`,
Namespace `Emz\Monitorio`), **nicht** das monitorio-Repo.

> Diese Spec ist so geschrieben, dass eine frische Claude-Session im
> Shopware-Plugin-Repo daraus ein Konzept + Implementierung planen kann, ohne
> Zugriff auf das monitorio-Repo zu brauchen. Der **API-Contract (Abschnitt 3)
> ist FIX** — die monitorio-Gegenseite wird exakt so gebaut und erwartet exakt
> dieses Verhalten. Die Abschnitte 4–7 beschreiben die Shop-seitigen
> Anforderungen (harte Anforderungen sind als solche markiert);
> Umsetzungsdetails entscheidet das Plugin.

## 1. Kontext & Ziel

Monitorio bekommt ein neues Plugin `stock`: Es alarmiert, sobald ein Artikel wieder
verfügbar wird (back in stock — wichtigster Fall), und liefert einen Report, wann ein
Artikel out-of-stock ging, wann wieder Bestand da war und wie lange er fehlte.

Pull scheidet dafür aus: Polling verpasst Transitionen zwischen zwei Abfragen, und ein
Vollabzug aller Produkte über die Admin-API skaliert nicht. Deshalb **pusht das
Companion-Plugin Bestands-Events als Batches** an einen Ingest-Endpunkt von Monitorio.

Arbeitsteilung: Der Shop liefert ausschließlich **Zustände** (Bestand vorher/nachher je
Produkt). Jede Interpretation — Transition-Erkennung, Abverkauf-Filter, Alarme,
Reports — passiert serverseitig in Monitorio.

## 2. Einordnung

Das Feature gehört in das bestehende Companion-Plugin `EmzMonitorio` (Stand v1.2.0).
Das Plugin kennt heute zwei Kommunikationswege:

- **Pull:** `/api/monitorio/*`-Routen (Admin-API), die Monitorio periodisch abruft.
- **Browser-Push:** jsError-Reporting aus der Storefront mit dem **öffentlichen**
  Config-Key `shopToken`.

Der Stock-Push ist der **erste serverseitige Push-Client** des Plugins: Der Shop sendet
aktiv HTTP-Requests an Monitorio (Richtungsumkehr gegenüber den Pull-Routen; und anders
als jsError mit einem Server-Geheimnis, siehe Abschnitt 6).

Neu hinzu kommen: ein Outbound-HTTP-Client, Messenger-Message + -Handler, ein
Event-Subscriber, ein ScheduledTask (Reconciliation), eine Migration für die
Zustandstabelle (+ ggf. Fallback-Puffer) und der Config-Key `ingestToken`. **Keine
neuen HTTP-Routen im Shop** — die bestehende Plugin-Struktur (Registrierung in
`services.xml`, `routes.xml`-Attribut-Import, `config.xml`) wird nur um Services und
Konfiguration erweitert.

## 3. API-Contract (FIX — monitorio erwartet exakt das)

```
POST {monitorioBaseUrl}/ingest/stock/{projectId}
  Headers:      Authorization: Bearer <ingestToken>
                Content-Type: application/json
  Limits:       Body max. 256 KiB; max. 500 Events pro Batch
  Validierung:  strikt — unbekannte JSON-Felder => 400
```

`monitorioBaseUrl` (Default `https://app.monitorio.de`) und `projectId` kommen aus der
Plugin-Konfiguration (Abschnitt 6).

Request-Body (ein Batch):

```json
{
  "batchId": "01JD2ZC8Y0T2N9V4W6XKQ5R7HM",
  "events": [
    {
      "eventId": "01JD2ZC8Y1A3B5C7D9E2F4G6H8",
      "occurredAt": "2026-08-19T10:15:03+02:00",
      "source": "subscriber",
      "productId": "0189f2f3a4b5c6d7e8f9a0b1c2d3e4f5",
      "productNumber": "SW-1001",
      "name": "Beispielprodukt",
      "stock": 12,
      "availableStock": 10,
      "previousStock": 0,
      "previousAvailableStock": 0,
      "isCloseout": false
    }
  ]
}
```

| Feld | Typ | Bedeutung |
|------|-----|-----------|
| `batchId` | string, ULID | Idempotenz-Schlüssel des Batches. Retries MÜSSEN dieselbe `batchId` senden. |
| `events` | array, 1–500 | Event-Objekte; Reihenfolge innerhalb des Batches egal. |
| `eventId` | string, ULID | Global eindeutig je Event. |
| `occurredAt` | string, RFC3339 | Zeitpunkt der Bestandsänderung (Shop-Zeit). |
| `source` | string | `baseline` \| `subscriber` \| `reconciliation` (Abschnitt 4). |
| `productId` | string | Shopware-UUID (hex) des Produkts bzw. der Variante. |
| `productNumber` | string | Produktnummer; darf leer sein. |
| `name` | string, optional | Produktname, max. 255 Zeichen. |
| `stock` | int64 | Physischer Bestand nach der Änderung. |
| `availableStock` | int64 | Verfügbarer Bestand nach der Änderung. |
| `previousStock` | int64 \| null | Stand vor der Änderung = zuletzt gemeldeter Stand (Abschnitt 4). `null` NUR bei `source=baseline`. |
| `previousAvailableStock` | int64 \| null | dito für den verfügbaren Bestand. `null` NUR bei `source=baseline`. |
| `isCloseout` | bool | Abverkauf-Flag des Produkts. |

Verbindliche Regeln:

- **previous*-Pflicht:** `previousStock`/`previousAvailableStock` dürfen nur bei
  `source=baseline` `null` sein — sonst antwortet der Server mit 400.
- **Idempotenz:** Der Server ist idempotent auf `(projectId, batchId)`. Ein bereits
  bekannter Batch wird mit 204 beantwortet, ohne erneut zu schreiben. Deshalb MÜSSEN
  Retries dieselbe `batchId` senden — ein Retry ist dadurch gefahrlos.
- **Transition rechnet der Server**, stateless je Event:
  `previousAvailableStock <= 0 && availableStock > 0` => `back_in_stock`;
  `previousAvailableStock > 0 && availableStock <= 0` => `went_oos`; sonst — und bei
  `source=baseline` immer — `none`. OOS-Definition v1: `availableStock <= 0`. Das
  Plugin schickt nur Zustände, keine Interpretation.
- **Immer alle Produkte senden**, auch Abverkauf (`isCloseout=true`). Der Ein-/Ausschluss
  von Abverkauf-Produkten ist reine Monitorio-Konfiguration (Alert-Config + UI-Filter);
  Config-Änderungen dort brauchen weder Shop-Deployment noch neue Baseline.
- **Baseline-Vollimport** in Chunks à max. 500 Events; jeder Chunk hat eine eigene
  `batchId`; Chunk-Reihenfolge egal; löst serverseitig nie Alarme aus.

Statuscodes und Client-Verhalten:

| Status | Bedeutung | Handlung des Plugins |
|--------|-----------|----------------------|
| 204 | Batch angenommen ODER bekanntes `(projectId, batchId)`-Duplikat | Fertig. Versand gilt als bestätigt => Zustandstabelle fortschreiben (Abschnitt 4). |
| 400 | Batch ungültig (Schema, unbekannte Felder, > 500 Events, previous*-Regel verletzt) | NICHT retryen. Batch verwerfen + loggen (Programmierfehler im Plugin; offene Differenzen meldet die Reconciliation erneut). |
| 401 | Bearer-Header fehlt | Konfig-Fehler; Versand pausieren. |
| 403 | Token falsch, Projekt unbekannt oder Monitoring deaktiviert (bewusst ein Sammel-Code gegen Enumeration) | Versand pausieren, Admin-Hinweis anzeigen. |
| 413 | Body > 256 KiB | Batch kleiner schneiden und neu senden. Die kleineren Batches sind NEUE Batches => eigene `batchId`s (sonst würde der zweite Teil als Duplikat des ersten verworfen). |
| 429 | Rate-Limit: 60 Batches/min/Projekt, 120/min/IP | `Retry-After` beachten; später mit GLEICHER `batchId` retryen. |
| 503 | Monitorio-Speicher nicht verfügbar | `Retry-After` beachten; später mit GLEICHER `batchId` retryen. |

## 4. Datenerfassung im Shop (Hybrid)

Drei Quellen, im Event über `source` unterscheidbar:

**(a) Event-Subscriber (`source=subscriber`)** — live, sekundengenaues `occurredAt`.
Erfasst Bestandsänderungen über den Shopware-Stack (Checkout, Admin, Sync-API).
Kandidaten je nach Shopware-Version: `product.written`-Payload bzw. ab 6.6 Dekoration
von `AbstractStockStorage` — Versions-Matrix siehe Abschnitt 10.

**(b) Snapshot-Diff-Reconciliation (`source=reconciliation`)** — ScheduledTask, Default
~5 Minuten (konfigurierbar). Das Plugin hält eine eigene **Zustandstabelle**
(`product_id` => zuletzt gemeldeter `stock`/`available_stock`), diffed sie gegen die
`product`-Tabelle und meldet jede Abweichung. Fängt damit ALLE Änderungsquellen — auch
ERP-Importe, die per Direkt-SQL am Event-System vorbeischreiben. `occurredAt` ist hier
der Erkennungszeitpunkt (auf Intervall-Genauigkeit).

**(c) Baseline-Vollimport (`source=baseline`)** — bei Aktivierung (Trigger siehe
Abschnitt 10): kompletter Produktbestand in Chunks à max. 500 Events,
`previous* = null`. Initialisiert Zustandstabelle und Monitorio-Datenbestand, löst nie
Alarme aus.

Zentrale Rolle der Zustandstabelle:

- `previous*`-Werte kommen für `subscriber` UND `reconciliation` aus der
  Zustandstabelle — die Bedeutung ist immer „zuletzt erfolgreich an Monitorio
  gemeldeter Stand", nicht ein beliebiger Payload-Vorwert (Event-Payloads liefern
  previous ohnehin nicht verlässlich).
- **Harte Anforderung:** Die Zustandstabelle wird erst NACH bestätigtem Versand
  (Response 204) fortgeschrieben. Schlägt der Versand fehl, bleibt der alte Stand
  stehen und der nächste Reconciliation-Lauf findet die Differenz erneut — so geht
  kein Event verloren.
- Nebeneffekt: Dieselbe Mechanik dedupliziert Subscriber und Reconciliation
  gegeneinander — hat der Subscriber eine Änderung bereits erfolgreich gemeldet, sieht
  der nächste Reconciliation-Lauf kein Diff mehr.

## 5. Transport & Robustheit

- **Asynchron via Symfony Messenger:** Subscriber und ScheduledTask blockieren nie den
  auslösenden Request/Command — sie stellen nur Messages in die Queue; ein Handler
  baut Batches und sendet. Ein langsames oder ausgefallenes Monitorio darf keinen
  Checkout bremsen.
- **Batch-Bildung:** Events sammeln und flushen, sobald X Sekunden vergangen ODER
  Y Events erreicht sind (Y <= 500; konkrete Defaults entscheidet das Plugin). Jeder
  Flush erzeugt einen Batch mit frischer `batchId`.
- **Retry:** Transiente Fehler (429, 503, Netzwerkfehler, Timeout) mit Backoff
  retryen — immer mit IDENTISCHER `batchId`; `Retry-After` respektieren. Die `batchId`
  deshalb zusammen mit dem Batch persistieren, damit sie Prozess-Neustarts überlebt.
- **Fallback-Puffer:** Ist Monitorio länger unerreichbar (oder die Messenger-Queue
  gestört), Batches lokal puffern (Tabelle oder Datei) und per Cron/ScheduledTask
  nachliefern — `batchId` bleibt über den Puffer hinweg stabil. Aufräum-Strategie
  siehe Abschnitt 10.

## 6. Konfiguration & Auth

Plugin-Konfiguration (`config.xml`), global — nicht je Sales-Channel:

| Config-Key | Status | Bedeutung |
|------------|--------|-----------|
| `EmzMonitorio.config.projectId` | bestehend, wiederverwenden | Monitorio-Projekt-ID; Teil der Ingest-URL. |
| `EmzMonitorio.config.ingestToken` | **NEU** | Server-Geheimnis für den `Authorization: Bearer`-Header. |
| `EmzMonitorio.config.monitorioBaseUrl` | NEU, optional | Override analog zum bestehenden `snippetUrl`-Muster; Default `https://app.monitorio.de`. |

- `ingestToken` ist ein **Server-Geheimnis**. Ausdrückliche Abgrenzung: Der bestehende
  Key `shopToken` ist der ÖFFENTLICHE jsError-Token (landet im Browser) und darf für
  den Stock-Push NICHT verwendet werden.
- Der Kunde erhält den Token von der **Monitorio-Setup-Seite** des Projekts (dort
  anzeigen/rotieren). Nach einer Rotation in Monitorio muss der Wert im Shop
  aktualisiert werden; bis dahin antwortet der Endpunkt mit 403 (Versand pausiert,
  Admin-Hinweis).
- Kein OAuth, keine Admin-API-Integration auf diesem Weg — der Push authentifiziert
  sich ausschließlich über den Bearer-Token.

## 7. Edge-Cases & Entscheidungen

- **Varianten:** Bestand lebt in Shopware auf den Leaf-Produkten (Varianten bzw.
  variantenlose Produkte) — genau diese senden. Parent-Aggregation ist Sache von
  Monitorio und nicht Aufgabe des Shops.
- **Gelöschte/deaktivierte Produkte:** v1 sendet KEINE Delete-Events; ein gelöschtes
  Produkt bleibt in Monitorio mit letztem Stand stehen (bewusste Entscheidung, als
  offener Punkt notiert — Abschnitt 10). Die Zustandstabelle darf verwaiste Zeilen
  lokal aufräumen, ohne etwas zu senden.
- **Clock-Skew:** `occurredAt` ist Shop-Zeit. Der Server speichert zusätzlich ein
  eigenes `received_at` und lehnt wegen Zeitversatz nichts ab. Das Plugin braucht
  keine Zeit-Synchronisation — `occurredAt` ehrlich setzen genügt.
- **Chunking-Grenzen:** Doppel-Limit beachten — max. 500 Events UND max. 256 KiB pro
  Request. Beim Batch-Schnitt beide prüfen (lange Produktnamen!); 413 => kleiner
  schneiden (siehe Statuscode-Tabelle).
- **Reihenfolge:** Innerhalb eines Batches egal; Baseline-Chunks ebenfalls in
  beliebiger Reihenfolge. Die fachliche Ordnung tragen `eventId` + `occurredAt`; die
  Transition steckt je Event vollständig in `previous*` => neu, nicht in der
  Ankunftsreihenfolge.

## 8. Nicht-Ziele

- **Kein Pull-Endpoint** für Bestände im Shop — Monitorio ruft für Stock nichts ab,
  der Kanal ist reiner Push.
- **Keine Bestandshistorie im Shop:** Die Zustandstabelle hält nur den zuletzt
  gemeldeten Stand, keine Zeitreihe. Historie, Auswertung und Retention macht
  Monitorio.
- **Keine Filterlogik im Shop** — auch nicht für `isCloseout`: Es werden immer alle
  Produkte gesendet, gefiltert wird in Monitorio.
- **Keine Preis-/Sales-Daten** (v1) — nur Bestandszustände.

## 9. Tests (PHPUnit)

- Zustandstabellen-Diff: neue, geänderte und unveränderte Produkte werden korrekt als
  Event / kein Event erkannt.
- `previous*`-Belegung: `subscriber`/`reconciliation` aus der Zustandstabelle;
  `baseline` => `null`.
- Baseline-Chunking: > 500 Produkte ergeben mehrere Chunks à max. 500, jeder mit
  eigener `batchId`.
- Retry sendet die identische `batchId` (429-/503-/Netzfehler-Simulation gegen einen
  Mock-Endpunkt).
- Statuscode-Handling: 429/503 => Retry mit gleicher `batchId` (inkl. `Retry-After`);
  400 => Batch verworfen + geloggt, kein Retry.
- Subscriber-Erfassung: Bestandsänderung über den Shopware-Stack erzeugt ein Event mit
  korrekten neuen und previous-Zuständen.
- Zustandstabelle wird erst nach 204 fortgeschrieben: Bei fehlgeschlagenem Versand
  bleibt der alte Stand, der nächste Reconciliation-Lauf meldet erneut.

## 10. Offene Punkte für den Shopware-Dev

1. **Versions-Matrix 6.4–6.7:** Welcher Erfassungs-Hook je Shopware-Version —
   `product.written`-Payload vs. Dekoration von `AbstractStockStorage` (ab 6.6)?
   Shopware-Version des Zielshops zuerst aus `composer.json`/`composer.lock` ermitteln
   (Agentur-Standard), dann festlegen.
2. **ScheduledTask-Intervall:** Default für die Reconciliation (~5 min vorgeschlagen)
   final festlegen und konfigurierbar machen.
3. **Fallback-Puffer aufräumen:** Retention/Max-Größe des Puffers und Verhalten bei
   dauerhaft unerreichbarem Monitorio definieren.
4. **Inaktive Produkte:** senden oder nicht? Empfehlung: senden — Monitorio filtert
   (konsistent zur `isCloseout`-Entscheidung).
5. **Baseline-Trigger:** Empfehlung: CLI-Command
   `bin/console emz:monitorio:stock:baseline` (läuft bei Aktivierung und ist manuell
   nachtriggerbar).
6. **Delete-/Deactivate-Signale** als v2-Kandidat (siehe Abschnitt 7) — bräuchte eine
   Contract-Erweiterung auf Monitorio-Seite und ist in v1 bewusst ausgeklammert.
