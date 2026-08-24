# Konzept: Stock-Push (Companion-Push für das Monitorio-Plugin `stock`)

Umsetzungskonzept zur Spezifikation [stock_companion_push.md](stock_companion_push.md).
Der API-Contract (Spec Abschnitt 3) ist fix und wird hier nicht wiederholt — dieses Dokument
beschreibt die Shop-seitige Umsetzung und entscheidet die offenen Punkte aus Spec Abschnitt 10.

Zielshop: Shopware **6.7.8.2** (aus `composer.lock` des Shops), Plugin-Stand v1.2.0.

## 1. Entscheidungen zu den offenen Punkten (Spec Abschnitt 10)

| # | Punkt | Entscheidung |
|---|-------|--------------|
| 1 | Erfassungs-Hook | **Keine Dekoration nötig.** Der 6.7-Core dispatcht nach `StockStorage::alter()` (Checkout/Order-Lifecycle, schreibt per Raw-SQL an der DAL vorbei) bereits das Event `ProductStockAlteredEvent` mit den Produkt-IDs. Ein Subscriber auf dieses Event plus `product.written` (Admin-Edits, Sync-API — dort steht `stock` im Payload) deckt beide Live-Pfade ab, ohne mit anderen `AbstractStockStorage`-Dekoratoren (ERP-Plugins) zu kollidieren. ERP-Importe per Direkt-SQL fängt die Reconciliation. |
| 2 | ScheduledTask-Intervall | Default **300 s** (`StockReconciliationTask::getDefaultInterval()`). Konfigurierbar über den Shopware-Standardweg: `scheduled_task.run_interval` in der DB (Admin-API/`scheduled_task`-Entity) — kein eigenes Config-Feld. |
| 3 | Fallback-Puffer-Retention | Offene Batches werden **nach 72 h verworfen** (Warning-Log), zusätzlich Deckel **max. 500 offene Batches** (älteste zuerst verwerfen). Das ist verlustfrei im Endzustand: die Zustandstabelle wird nur nach 204 fortgeschrieben, also findet die Reconciliation jede noch offene Differenz erneut, sobald Monitorio wieder erreichbar ist. Verloren gehen nur Zwischen-Transitionen während des Ausfalls. |
| 4 | Inaktive Produkte | **Senden.** Kein `active`-Filter in den Queries — konsistent zur `isCloseout`-Entscheidung; Monitorio filtert. |
| 5 | Baseline-Trigger | CLI-Command **`bin/console emz:monitorio:stock:baseline`**. Zusätzlich heilt sich das System selbst: Produkte ohne Zeile in der Zustandstabelle werden von Subscriber-Handler und Reconciliation als `source=baseline`-Event (previous = null) gemeldet — neue Produkte lösen so nie Alarme aus, und ein konfigurierter Shop initialisiert sich notfalls auch ohne manuellen Command-Lauf. |
| 6 | Delete-Signale | Wie in der Spec: v1 sendet nichts. Verwaiste Zeilen der Zustandstabelle räumt die Reconciliation lokal auf (ohne Event). |

## 2. Architektur

```
ProductStockAlteredEvent ─┐
product.written (stock) ──┴─> StockChangeSubscriber ──> StockChangedMessage (async, Messenger)
                                                             │
                                                             v
                                              StockChangedHandler ──> StockPushService
                                                                          │
StockReconciliationTask (300 s) ──> StockReconciliationTaskHandler ───────┤
emz:monitorio:stock:baseline ─────> StockBaselineCommand ─────────────────┤
                                                                          v
                        ┌──────────────────────────────────────────────────────────┐
                        │ StockPushService                                         │
                        │  1. Diffs bilden (StockStateStore: product ⟷ State)      │
                        │  2. Events/Batches bauen (StockEventBuilder, ULIDs)      │
                        │  3. Batch + batchId persistieren (StockOutbox)           │
                        │  4. flushOutbox(): senden (MonitorioStockClient)         │
                        │     204 → State fortschreiben + Outbox-Zeile löschen     │
                        │     sonst → Contract-Tabelle (Abschnitt 5)               │
                        └──────────────────────────────────────────────────────────┘
```

Subscriber und ScheduledTask stellen nur Messages in die Queue bzw. laufen selbst schon im
Messenger-Worker — im auslösenden Request/Command passiert nie HTTP (Spec Abschnitt 5).

## 3. Datenmodell (Migration)

**`emz_monitorio_stock_state`** — zuletzt **erfolgreich an Monitorio gemeldeter** Stand je Produkt.
Wird ausschließlich nach Response 204 fortgeschrieben, mit den Werten **aus dem bestätigten
Batch** (nicht dem dann aktuellen DB-Stand) — so bleibt die `previous*`-Kette exakt.

| Spalte | Typ | Bemerkung |
|--------|-----|-----------|
| `product_id` | BINARY(16) PK | Leaf-Produkt (Variante oder variantenloses Produkt), Live-Version |
| `stock` | BIGINT NOT NULL | zuletzt gemeldeter physischer Bestand |
| `available_stock` | BIGINT NOT NULL | zuletzt gemeldeter verfügbarer Bestand |
| `updated_at` | DATETIME(3) NOT NULL | |

**`emz_monitorio_stock_outbox`** — persistierte Batches (= Fallback-Puffer **und** Retry-Speicher;
die `batchId` überlebt so Prozess-Neustarts, Spec Abschnitt 5).

| Spalte | Typ | Bemerkung |
|--------|-----|-----------|
| `batch_id` | VARCHAR(26) PK | ULID, identisch mit `batchId` im Payload |
| `payload` | LONGTEXT NOT NULL | fertiger JSON-Request-Body — Retries senden identische Bytes |
| `event_count` | INT NOT NULL | für Logs/Aufräum-Entscheidungen |
| `attempts` | INT NOT NULL DEFAULT 0 | Sende-Versuche |
| `next_retry_at` | DATETIME(3) NULL | NULL = sofort fällig; dient auch als Claim-Lease gegen parallele Worker |
| `created_at` | DATETIME(3) NOT NULL | Sende-Reihenfolge + Retention |

Kein Entity/DAL für beide Tabellen: reine Infrastruktur-Tabellen des Plugins, Zugriff per DBAL
(`Connection`) — kein Admin-CRUD, keine Associations, keine API-Exposition erwünscht.

## 4. Event-Bildung

- **Leaf-Produkte**: `child_count = 0 OR child_count IS NULL`, `version_id = live`. Kein
  `active`-Filter, kein `is_closeout`-Filter.
- **Vererbung**: `isCloseout` = `COALESCE(p.is_closeout, parent.is_closeout, 0)`; `name` aus
  `product_translation` in der System-Default-Sprache, Fallback Parent-Translation, auf 255
  Zeichen gekürzt; fehlt er ganz, wird das optionale Feld weggelassen. `availableStock` =
  `COALESCE(available_stock, 0)` (Spalte ist nullable), `productNumber` notfalls `''`.
- **previous***: aus der Zustandstabelle. Produkt ohne State-Zeile ⇒ Event als
  `source=baseline` mit `previous* = null` (einziger erlaubter null-Fall). Mit State-Zeile ⇒
  `source=subscriber|reconciliation` mit den State-Werten.
- **Diff-Regel**: Ein Event entsteht nur, wenn `(stock, availableStock)` vom State abweicht
  oder keine State-Zeile existiert. Dadurch deduplizieren sich Subscriber und Reconciliation
  gegenseitig (Spec Abschnitt 4) — und doppelte Trigger (z. B. `product.written` +
  `ProductStockAlteredEvent` im selben Vorgang) verpuffen.
- **Chunking**: max. 500 Events **und** max. ~240 KiB JSON pro Batch (Sicherheitsmarge unter
  256 KiB, gemessen am fertigen Body); zu große Chunks werden halbiert. Jeder Chunk = eigener
  Batch mit eigener ULID-`batchId`, jedes Event mit eigener ULID-`eventId`.
- **`occurredAt`**: Subscriber = Event-Zeitpunkt (aus der Message), Reconciliation/Baseline =
  Erkennungszeitpunkt; RFC3339 (`DATE_ATOM`), Shop-Zeit.
- ULIDs erzeugt eine kleine eigene Klasse (Crockford-Base32, 48 Bit Timestamp + 80 Bit
  Zufall) — `symfony/uid` ist im Ziel-Stack nicht vorhanden.

## 5. Versand & Statuscode-Handling (Contract-Tabelle)

`flushOutbox()` arbeitet fällige Outbox-Zeilen (`next_retry_at` NULL oder ≤ now) in
`created_at`-Reihenfolge ab. Vor dem Senden wird die Zeile per Lease geclaimt
(`UPDATE … SET next_retry_at = now + 120 s WHERE batch_id = ? AND fällig`) — verhindert
Doppelversand durch parallele Worker, ohne DB-Locks über HTTP-Calls zu halten. (Selbst ein
Doppelversand wäre dank Server-Idempotenz auf `batchId` folgenlos.)

| Ergebnis | Verhalten |
|----------|-----------|
| 204 | Zustandstabelle mit den Werten aus dem Batch-Payload fortschreiben, Outbox-Zeile löschen. |
| 400 | Outbox-Zeile löschen, Error-Log (Programmierfehler; Reconciliation meldet offene Differenzen erneut). Kein Retry. |
| 401 / 403 | Versand pausieren: alle offenen Batches `next_retry_at = now + 60 min`, Error-Log, Admin-Notification (Glocke, nur beim Übergang in den Fehlerzustand, nicht je Batch), Lauf abbrechen. |
| 413 | Events des Batches halbieren → zwei **neue** Batches mit **neuen** `batchId`s in die Outbox, alte Zeile löschen, im selben Lauf weiter. Ein 413 bei nur einem Event wird verworfen + geloggt (kann bei max. 255 Zeichen Name nicht auftreten). |
| 429 / 503 | `Retry-After` respektieren, sonst Backoff `min(30 s · 2^(attempts−1), 30 min)`; `attempts`++, Zeile bleibt **mit identischer `batchId`** liegen, Lauf abbrechen (Server drosselt bzw. ist down — weitere Batches jetzt zu senden wäre sinnlos). |
| Netzwerkfehler / Timeout | wie 503 mit Backoff. |

Timeouts des HTTP-Clients: 5 s Connect, 10 s gesamt — ein hängendes Monitorio darf den
Messenger-Worker nicht festhalten.

**Duplikat-Bremse:** Die Reconciliation liefert zuerst offene Outbox-Batches nach; bleibt
danach mindestens ein Batch offen (Monitorio nicht erreichbar), überspringt der Lauf die
Diff-Phase. So häuft ein längerer Ausfall keine inhaltsgleichen Batches an. Der
Subscriber-Pfad sendet währenddessen weiter spec-konform mit `previous` = zuletzt
*bestätigtem* Stand; entstehende Mehrfach-Zustandsmeldungen sind vom Contract gedeckt
(Server rechnet Transitionen stateless je Event).

## 6. Konfiguration (`config.xml`, neue Karte „Lagerbestand-Monitoring")

| Key | Typ | Bedeutung |
|-----|-----|-----------|
| `EmzMonitorio.config.ingestToken` | password | **Server-Geheimnis** für `Authorization: Bearer`. Ausdrücklich nicht der öffentliche `shopToken` (jsError). Quelle: Monitorio-Setup-Seite des Projekts. |
| `EmzMonitorio.config.monitorioBaseUrl` | text, optional | Override analog `snippetUrl`-Muster (Konstante als Default, Feld für lokal/Staging). Default `https://app.monitorio.de`. |

`projectId` wird wiederverwendet. Alle Werte werden **global** gelesen (ohne
Sales-Channel-Override, Spec Abschnitt 6). Der Push ist aktiv, sobald `projectId` und
`ingestToken` gesetzt sind — kein eigener Schalter (und damit auch kein
`getBool`-String-Cast-Risiko). Unkonfiguriert dispatcht der Subscriber nicht einmal Messages.

## 7. Klassen (alle `final`, `declare(strict_types=1)`, Namespace `Emz\Monitorio\Stock`)

| Klasse | Aufgabe |
|--------|---------|
| `Ulid` | ULID-Generierung (statisch, testbar) |
| `StockPushConfig` | Config-Zugriff: `projectId`, `ingestToken`, Basis-URL-Auflösung, `isConfigured()` |
| `MonitorioStockClient` | POST `{base}/ingest/stock/{projectId}`, liefert `IngestResult` (Status, `Retry-After`); wirft nicht bei 4xx/5xx |
| `IngestResult` | DTO: Statuscode, Retry-After-Sekunden, Transportfehler-Flag |
| `StockStateStore` | Zustandstabelle: Zustände lesen/upserten (aus bestätigten Events), Diff-Query (product ⟷ State), Leaf-Iteration für Baseline, Orphan-Cleanup |
| `StockOutbox` | Outbox: einfügen, fällige claimen, löschen, Backoff/Pause setzen, Retention |
| `StockEventBuilder` | Rows + State → Event-Arrays → Batches (Chunking, Größen-Limit, ULIDs, JSON) |
| `StockPushService` | Orchestrierung: `pushForProducts()`, `runReconciliation()`, `runBaseline()`, `flushOutbox()` inkl. Statuscode-Handling |
| `Message\StockChangedMessage` | productIds + occurredAt, `AsyncMessageInterface` |
| `Message\StockChangedHandler` | Messenger-Handler → `pushForProducts()` |
| `Subscriber\StockChangeSubscriber` | `ProductStockAlteredEvent` + `product.written` → Message (nur Live-Version, nur wenn konfiguriert; bei `product.written` nur wenn `stock`/`availableStock` im Payload) |
| `ScheduledTask\StockReconciliationTask` | Name `emz_monitorio.stock_reconciliation`, Default 300 s |
| `ScheduledTask\StockReconciliationTaskHandler` | Nachliefern → ggf. Diff → Retention/Orphan-Cleanup; Registrierung mit `<tag name="messenger.message_handler" handles="…StockReconciliationTask"/>` (Basisklassen-Typehint `__invoke(ScheduledTask)` würde sonst alle Tasks matchen) |
| `Command\StockBaselineCommand` | `emz:monitorio:stock:baseline`: alle Leaf-Produkte als `source=baseline`/`previous=null` in Chunks, sendet synchron mit Fortschritt, wartet bei 429/503 gemäß Retry-After |

Plugin-Klasse: `uninstall()` ohne `keepUserData` droppt beide Tabellen.

## 8. Tests (Spec Abschnitt 9 → Umsetzung)

Unit-Tests ohne Kernel (eigene Klassen + `MockHttpClient`); DB-nahe Tests gegen eine echte
MariaDB-Connection (ddev), da State-/Outbox-Verhalten („erst nach 204 fortschreiben",
„identische batchId nach Fehler") genau die DB-Seite ist:

- `StockEventBuilder`: Diff-Erkennung (neu/geändert/unverändert), `previous*`-Belegung je
  Quelle, Baseline-Chunking > 500, Größen-Chunking.
- `Ulid`: Format, Monotonie des Timestamps, Eindeutigkeit.
- `MonitorioStockClient`: URL/Header/Body, `Retry-After`-Parsing (MockHttpClient).
- `StockPushService` (Mock-Endpunkt via MockHttpClient, echte DB): 204 schreibt State fort;
  503/429/Netzfehler lassen Outbox-Zeile mit identischer `batchId` + Backoff stehen, zweiter
  Flush sendet identischen Body; 400 verwirft + loggt ohne Retry; 413 erzeugt zwei neue
  Batches mit neuen `batchId`s; fehlgeschlagener Versand ⇒ State unverändert ⇒ nächster
  Reconciliation-Lauf meldet erneut.
- `StockChangeSubscriber`: `ProductStockAlteredEvent`/`product.written` erzeugen Messages mit
  korrekten IDs; Nicht-Live-Version und stock-lose Writes nicht.
