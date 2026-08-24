# Baukonzept: JS Error Tracking für Monitorio

**Status:** Konzept · **Ziel:** Neuer Check-Typ "JavaScript-Fehler" in Monitorio
**Detailausarbeitung:** Client-Snippet-Auslieferung via Claude Code im EmzMonitorio-Plugin

---

## 1. Zielbild

Monitorio erkennt JS-Fehler in der Storefront der angebundenen Shopware-6-Shops, gruppiert sie zu Fehlergruppen und alertet auf **neue Fehler** und **Anomalien in der Fehlerrate** — nicht auf jedes einzelne Event. Kein Sentry-Ersatz (keine Breadcrumbs, Replays, Release-Tracking), sondern ein integrierter Monitoring-Check mit Monitorio-Alerting.

**Nicht-Ziele (v1):** Sourcemap-Auflösung, Session Replay, User-Feedback, Frontend-Performance-Daten (läuft bereits über CWV-Checks).

---

## 2. Architektur-Überblick

```
Storefront (Snippet, via EmzMonitorio ausgeliefert)
        │  Batch via sendBeacon / fetch keepalive
        ▼
Ingest-Endpoint (Go)  ──  Validierung, Auth, Rate-Limit, Fingerprinting
        ▼
ClickHouse
  ├─ js_errors_raw            (Rohevents, TTL z. B. 30–90 Tage)
  └─ js_error_groups (MV)     (Aggregation je Fingerprint)
        ▼
Monitorio-Backend  ──  Check-Auswertung, Alerting, UI
```

---

## 3. Komponenten

### 3.1 Client-Snippet

**Auslieferung:** über EmzMonitorio-Plugin als Storefront-Script — konfigurierbar an/aus im Plugin. Snippet wird von Monitorio gehostet und versioniert (`/t/v1.js`), Plugin injiziert nur den Loader mit Shop-Token. So können Snippet-Updates ohne Plugin-Release ausgerollt werden.

**Erfasst:**

| Quelle | Mechanismus |
|---|---|
| Laufzeitfehler | `window.addEventListener('error', …, true)` |
| Ressourcenfehler (img/script/css 404) | gleicher Listener, `e.target`-Prüfung; v1: nur zählen, nicht gruppieren |
| Unhandled Promise Rejections | `unhandledrejection`-Listener |
| `console.error` | Monkey-Patch, Original weiterreichen |

**Event-Payload:**
`type, message, source, line, col, stack (gekürzt), url, userAgent, viewport, timestamp, salesChannelId, context (storefront|checkout|account), snippetVersion`

**Kontext Shopware:** Sales-Channel-ID und Seitenbereich (Checkout vs. Rest) kommen aus dem Plugin als Data-Attribute/window-Config mit. Checkout-Fehler bekommen später eigene Alert-Schwelle.

**Anforderungen ans Snippet:**
- IIFE, keine Dependencies, komplett in try/catch, so früh wie möglich im `<head>`
- Client-Dedup: gleicher Fingerprint innerhalb der Session → Counter statt neues Event
- Hardcap: max. 20 Events pro Pageview
- Batching: Flush alle 5 s sowie bei `visibilitychange`/`pagehide`
- Transport: `navigator.sendBeacon()`, Fallback `fetch({ keepalive: true })`
- Payload-Limit clientseitig (z. B. 16 KB pro Batch)
- Bekanntes Rauschen filtern: `"Script error."` ohne Stack nur als Zähler mitführen, Browser-Extension-Frames (`chrome-extension://` etc.) verwerfen

### 3.2 Ingest-Endpoint (Go)

- Eigener leichtgewichtiger Service oder Route im bestehenden Monitorio-Ingest
- **Auth:** Shop-Token im Payload + Origin-Check gegen registrierte Shop-Domain(s)
- **Rate-Limit:** pro Shop und pro IP; bei Überschreitung 429 + serverseitiges Sampling-Flag
- **Validierung:** Payload-Schema, Größencap (Stacks kürzen, z. B. max. 8 KB), Feld-Whitelist
- **Fingerprinting serverseitig** (Client-Fingerprint nur für Dedup, Server ist Wahrheit):
  1. Stack normalisieren: Zahlen, UUIDs, Query-Strings, Hashes in Dateinamen entfernen
  2. Erste 3–5 Frames + Fehlertyp + normalisierte Message
  3. → xxhash/SHA-Fingerprint
- Kein Body-Parsing-Fehler darf zu 500 führen — kaputte Payloads still verwerfen, Metrik zählen

### 3.3 Storage (ClickHouse)

**`js_errors_raw`** — ein Row pro Event(-Batch-Eintrag):
`shop_id, fingerprint, type, message, source, line, col, stack, url, sales_channel_id, context, user_agent, snippet_version, received_at, client_count`
- Partitionierung nach Monat, Order by `(shop_id, fingerprint, received_at)`
- TTL 30–90 Tage (pro Plan konfigurierbar?)

**`js_error_groups`** — Materialized View:
`shop_id, fingerprint, first_seen, last_seen, total_count, sample_message, sample_stack, affected_urls (top N), contexts`

**Fehlerrate:** Bezugsgröße Pageviews. v1 pragmatisch: Snippet sendet pro Pageview ein Mini-Ping (oder zählt Pageviews im ersten Batch mit) → `js_pageviews`-Tabelle. Alternative prüfen: vorhandene Monitorio-Daten als Näherung.

### 3.4 Check & Alerting (Monitorio-Backend)

Neuer Check-Typ **"JavaScript-Fehler"** mit zwei Alert-Arten:

1. **Neuer Fehler:** Fingerprint mit `first_seen` < X Stunden und `count` ≥ Schwelle (Default z. B. 3 — Einzelausreißer ignorieren)
2. **Fehlerraten-Anomalie:** Errors/1000 Pageviews im aktuellen Fenster vs. Baseline (z. B. gleiche Stunde der Vorwoche oder rollierender 7-Tage-Schnitt); Alert bei Faktor ≥ konfigurierbarem Multiplikator

**Konfigurierbar pro Shop:** Check an/aus, Schwellen, Ignorier-Liste (Fingerprints muten), Checkout-Fehler mit eigener (schärferer) Schwelle.

**UI:** Fehlergruppen-Liste (Message, Count, first/last seen, Trend-Sparkline), Detailansicht mit Sample-Stack und betroffenen URLs, Mute-Button. Handbuch-Eintrag analog zu den anderen Checks.

---

## 4. Rollen: was baut wer

| Bereich | Ort |
|---|---|
| Snippet-Loader, Config-Injection (Token, Sales-Channel, Kontext), Plugin-Setting | **EmzMonitorio-Plugin (Claude Code)** |
| Snippet selbst (`v1.js`), Hosting, Versionierung | Monitorio |
| Ingest, Fingerprinting, ClickHouse-Schema | Monitorio |
| Check-Typ, Alerting, UI, Handbuch | Monitorio |

---

## 5. Phasen

**Phase 1 — MVP**
Snippet (error + unhandledrejection), Ingest mit Auth/Rate-Limit/Fingerprinting, Raw-Tabelle + Groups-MV, Alert "neuer Fehler", einfache Fehlergruppen-Liste in der UI.

**Phase 2 — Rate & Kontext**
Pageview-Zählung, Fehlerraten-Anomalie-Alert, Checkout-Kontext mit eigener Schwelle, Mute-Funktion, `console.error`-Capture.

**Phase 3 — Komfort**
Ressourcenfehler-Auswertung, Sampling pro Shop, Ignorier-Regeln (Pattern statt nur Fingerprint), ggf. Sourcemap-Upload via Plugin-Build.

---

## 6. Offene Entscheidungen

- [ ] Snippet-Hosting: eigenes CDN/Route unter monitorio.de vs. Shop-lokal via Plugin gebundelt (Update-Pfad!)
- [ ] Pageview-Quelle: eigener Ping vs. vorhandene Daten
- [ ] Raw-TTL und ob planabhängig
- [ ] Ingest als eigener Service oder Route im bestehenden Go-Backend
- [ ] DSGVO-Check: keine personenbezogenen Daten im Payload (keine IP persistieren? URL-Query-Strings strippen — können Tokens/E-Mails enthalten → strippen empfohlen)
- [ ] Consent: Error-Tracking als berechtigtes Interesse (technisch notwendig) einordnen oder hinter Consent — Empfehlung: Payload so datensparsam bauen, dass kein Consent nötig ist
