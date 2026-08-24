# Anforderungsprompt: JS Error Tracking — Backend

> Übergabe an den Monitorio-Backend-Agent. Die Storefront-Seite (EmzMonitorio-Plugin)
> ist gebaut und im Echtbetrieb verifiziert; alles hier Beschriebene liegt hinter dem Ingest.
> Grundlage: `docs/monitorio-js-error-tracking-konzept.md`.

## Auftrag

Bring den Check-Typ „JavaScript-Fehler" auf einen Stand, in dem er an Kundenshops
ausgeliefert werden kann. Teil 1 sind Blocker — ohne sie ist der Check nicht auslieferbar,
egal wie gut die Erfassung funktioniert. Teil 2 ist die Ausbaustufe danach.

Arbeite die Punkte in der angegebenen Reihenfolge ab. Wo eine Entscheidung ansteht, triff
sie begründet und dokumentiere sie, statt zurückzufragen.

## Ausgangslage: was funktioniert

End-to-end gegen eine lokale Instanz verifiziert (Shopware 6.7.8.2, Projekt 1):

- Plugin injiziert Loader und Konfiguration im Storefront-`<head>`, vor Stylesheets
- Snippet lädt, erfasst Laufzeitfehler und unhandled Rejections
- Client-Dedup greift (`count: 4` statt vier Ereignissen)
- Batching, `sendBeacon`, Flush nach 5 s und bei `pagehide`
- Extension-Frames und Ressourcenfehler werden verworfen
- Query-Strings werden vor dem Versand gestrippt
- `POST /t/e/1` → 204, `js_error_new_alert` erkennt Gruppen
- Schwelle 3 arbeitet korrekt: `groups` wuchs 2 → 3 → 4, während ein Fingerprint mit
  `count 1` erst beim dritten Seitenaufruf die Schwelle erreichte

**Die Erfassung ist nicht das Problem. Alles Folgende liegt hinter dem Ingest.**

## Contract: was aus der Storefront kommt

`window.__monitorio`, vom Plugin gesetzt:

```json
{
  "projectId": 1,
  "shopToken": "55e7169d9cc49a4a340a3bb9c0ca2afd",
  "salesChannelId": "019d35ac2abd70519c62ece1c7be0b69",
  "context": "storefront",
  "buildId": "42db61d5c4d99e92176f9a234541db08"
}
```

`context` ist `storefront`, `checkout` oder `account`, abgeleitet aus dem Präfix der
Shopware-Route (`frontend.checkout.` / `frontend.account.`). Die Registrierung im
Checkout-Flow zählt als `checkout`, das eigenständige Registrierungsformular als `account`.

Payload, der beim Ingest ankommt (Stand heute):

```json
{
  "token": "…", "snippetVersion": "v1",
  "salesChannelId": "…", "context": "checkout",
  "events": [{ "type": "TypeError", "message": "…", "source": "",
               "line": 4, "col": 15, "stack": "…", "url": "…", "count": 4 }]
}
```

Endpunkt: `<base>/t/e/<projectId>`, vom Snippet aus der eigenen Script-URL abgeleitet.
`window.__monitorio.endpoint` überschreibt das.

---

# Teil 1 — Blocker

## 1.1 Alarm-Zustand und Entwarnung

**Befund:** Der Check meldet bei *jedem* 15-Sekunden-Zyklus dieselben Gruppen als „neu" —
über Minuten hinweg, auch Gruppen aus einem Testlauf von vor einer Viertelstunde:

```json
{"msg":"New js error groups found","alert_id":37,"alert_type":"js_error_new_alert","project_id":1,"groups":4}
```

Es gibt keinen Zustand pro Fingerprint, also auch nichts, das man entwarnen könnte.

**Zu bauen:**

1. Zustand `firing` / `resolved` je (Projekt, Fingerprint), mit `fired_at` und `resolved_at`.
2. Benachrichtigung nur beim Übergang nach `firing`, nicht bei jedem Zyklus.
3. Entwarnung als **eigene** Meldung, die auf die ursprüngliche verweist — ein stiller
   Zustandswechsel wird von niemandem bemerkt.

**Auflösungsregel je Alarmart, die beiden unterscheiden sich:**

- **Neuer Fehler** (ereignisförmig): auflösen, wenn `last_seen` älter als 24 h **und**
  im selben Fenster mindestens N Pageviews stattfanden. Die zweite Bedingung ist nicht
  optional — ohne sie ist „24 h kein Vorkommen" nicht von „24 h war niemand da"
  unterscheidbar. Ein ruhiger Sales-Channel oder eine Nacht ohne Traffic erzeugt sonst
  laufend falsche Entwarnungen. Die Pageview-Zahl kommt aus 1.2.
- **Fehlerraten-Anomalie** (zustandsförmig): auflösen, wenn die Rate für k
  aufeinanderfolgende Fenster wieder unter dem Multiplikator liegt. Größenordnung Minuten,
  nicht 24 h — sonst steht ein Spike von 10 Uhr am Abend noch als „firing".

Sauberer wäre, die Pageviews auf den betroffenen URLs zu zählen (`affected_urls` aus der
Groups-MV), weil ein globaler Zähler nicht beweist, dass die kaputte Seite besucht wurde.
Falls das v1 sprengt: global zählen, aber die Einschränkung in der UI benennen.

## 1.2 Pageview-Zählung

**Befund:** Es gibt sie nicht — weder im Snippet noch im Ingest. Eine Seite ohne JS-Fehler
sendet nachweislich **keinen einzigen Request**, und der Ingest kennt nur einen Pfad:

```
POST /t/e/1    → 204
POST /t/p/1    → 404      POST /t/pv/1 → 404      GET /t/v/1 → 404
```

Entsprechend existiert von den zwei Alarmarten des Konzepts nur eine: im Alert-Log kommen
zwölf Typen vor, aber genau ein einziger für JS-Fehler (`js_error_new_alert`).

**Folgen, die zusammen den Check entwerten:**

- Die Fehlerraten-Anomalie (Alarmart 2) kann nicht existieren.
- Die Entwarnung aus 1.1 kann „behoben" nicht von „kein Traffic" unterscheiden.
- Absolute Zahlen sind ohne Bezug wertlos: „3 Vorkommen" ist bei 3 Seitenaufrufen eine
  Katastrophe und bei 300.000 Rauschen. In der Gruppenliste sieht beides identisch aus.

**Zu entscheiden und zu bauen:** offener Punkt 2 des Konzepts („eigener Ping vs. vorhandene
Daten"). Ein eigener Ping kostet einen Request pro Seitenaufruf auf jedem Kundenshop —
prüfe zuerst, ob vorhandene Monitorio-Daten (Uptime-, Pagespeedy-, oder Shop-seitige
Kennzahlen) als Näherung taugen. Falls ein Ping nötig ist: Er muss ins Snippet, und dann
lieber im ersten Error-Batch mitgezählt als ein zusätzlicher Request.

## 1.3 Ausnahmen

**Befund:** Es gibt keine Möglichkeit, irgendetwas stillzustellen.

**Warum das ein Blocker ist:** Der erste Shop mit einem hartnäckigen Drittanbieter-Fehler
erzeugt eine Gruppe, die nie verschwindet und bei jedem Zyklus neu meldet. Danach schaut
niemand mehr hin, und der Check ist tot — unabhängig von der Qualität des Rests. Ein
Monitoring-Check, den man nicht leiser drehen kann, überlebt den ersten echten Kunden nicht.

**Zu bauen — zwei Ebenen, nicht drei:**

| Ebene | wofür | Kosten |
|---|---|---|
| **Ingest: Muster-Regeln** | wiederkehrende Klassen von Rauschen | ein Request, danach nichts |
| **UI: Fingerprint muten** | Einzelfälle, rückwirkend | Speicher + Gruppierung |

Regeln gehören ins Ingest, nicht ins Snippet: dort sind sie ohne Snippet- **und** ohne
Plugin-Release änderbar. Dasselbe Argument, mit dem das Snippet zentral gehostet wird.

Regel-Dimensionen über das Message-Pattern hinaus:

- **URL-Pattern** — nie auf `/admin` alarmieren, oder nur auf Checkout
- **Source-/Dateiname** — Drittanbieter-Skripte, die der Shop nicht reparieren kann:
  Payment-Provider, Consent-Tool, Tag-Manager
- **Browser / User-Agent** — steinalte Browser und Bots erzeugen Fehler, die niemand behebt
- **`"Script error."` als eigener Schalter** — inhaltsleer per Definition (siehe unten),
  deshalb eine eigene Entscheidung wert

**Pflicht:** Jede Regel zählt mit, was sie verworfen hat. Ein Filter, der seine eigene
Wirkung versteckt, ist in einem Monitoring-Produkt gefährlich — „ruhig" und „stillgelegt"
müssen unterscheidbar bleiben.

---

# Teil 2 — danach

## 2.1 `buildId` durchreichen

Kleinste Änderung, größter Hebel. Das Plugin liefert `buildId` bereits aus; das Snippet
kennt das Feld nicht und verwirft es. Zu tun: im Snippet in den Payload übernehmen, im
Ingest in `js_errors_raw` mitschreiben.

Der Wert ist der Theme-Build-Hash und zugleich das Verzeichnis in den Asset-URLs:

```
"buildId": "42db61d5c4d99e92176f9a234541db08"
   ↕ identisch
/theme/42db61d5c4d99e92176f9a234541db08/js/storefront/storefront.js
```

Er wechselt bei jedem `bin/console theme:compile` (verifiziert: `cb1116e7…` → `42db61d5…`,
Asset-Pfad wanderte mit). Damit ist er beides:

- **Deploy-Marker** → „erstmals gesehen 12 Minuten nach Release X". Für eine Agentur ist das
  der Satz, der Handeln auslöst — mehr als jeder Stacktrace. Ein Fehler mit Verursacher hat
  einen Rollback-Pfad.
- **Sourcemap-Schlüssel** → die Stack-Frames zeigen auf genau dieses Verzeichnis.

Sourcemap-Auflösung selbst ist der Schritt danach und braucht eine Entscheidung, wohin
Maps hochgeladen werden. Erst dann kann die Plugin-Seite den Upload aus dem Theme-Build
nachliefern.

Hinweis: Release-Tracking steht im Konzept unter den Nicht-Zielen. Das ist eine bewusste
Ausweitung, keine Ausführung des Plans.

## 2.2 Viewport

Vergleich des tatsächlichen Payloads mit Abschnitt 3.1 des Konzepts: `userAgent`,
`viewport` und `timestamp` fehlen. Zwei davon sind egal — den User-Agent liest der Server
aus dem Request-Header, den Zeitpunkt stempelt er beim Empfang (bei 5 s Batching genau genug).

**`viewport` ist der einzige, der serverseitig nicht rekonstruierbar ist**, und beantwortet
eine Frage, die im Shop-Kontext dauernd auftaucht: tritt das nur auf Mobile auf? Offcanvas,
Sticky-Header, Slider — ein großer Teil der Storefront-Fehler ist viewportabhängig. Ein Feld
im Snippet, eine Spalte, eine Facette in der UI. Bestes Aufwand-Nutzen-Verhältnis der Liste.

## 2.3 Letzter fehlgeschlagener Request

Der typische Storefront-Fehler ist Folge, nicht Ursache: `POST /checkout/line-item/add`
liefert 500, danach stolpert das JS über `undefined`. Der Stack zeigt die Stolperstelle,
der Request die Ursache.

Minimaler `fetch`/XHR-Patch im Snippet, der ausschließlich Methode, gestrippte URL und
Status des letzten Fehlschlags mitführt. Keine vollständigen Breadcrumbs — die bleiben
Nicht-Ziel.

## 2.4 Alarm-Reife

- **Regression:** ein aufgelöster Fingerprint kommt zurück. Eigene Art, eigene Schwelle —
  sonst flattert ein instabiler Fehler ewig zwischen „neu" und „entwarnt", und beide
  Meldungen verlieren ihren Wert.
- **Burst-Gruppierung:** Ein kaputtes Deploy erzeugt nicht einen neuen Fingerprint, sondern
  fünfzehn gleichzeitig. Fünfzehn Einzelmeldungen liest niemand. Gewünscht ist eine:
  „12 neue Fehlergruppen seit Build 42db61d5". Steht so nicht im Konzept und ist genau der
  Fall, in dem der Check am wichtigsten wäre.
- **Checkout mit eigener Schwelle** (Konzept Phase 2) ist nur noch ein Konfigurationswert,
  sobald die Zustandsmaschine aus 1.1 steht.

## 2.5 Ressourcenfehler als eigener Check

Das Konzept will sie in Phase 3 in die JS-Fehler einreihen. Besser trennen: **404 auf
Produktbilder ist ein Shop-Problem, kein JavaScript-Problem.** Andere Zielgruppe, andere
Dringlichkeit — und in der JS-Fehlerliste würden sie den Blick auf echte Exceptions
verstellen. Als eigener Check-Typ neben Uptime und Pagespeedy passt es ins vorhandene Raster.

---

## Korrekturen am Konzept

**Ressourcenfehler.** Abschnitt 3.1 verspricht „v1: nur zählen, nicht gruppieren". Das
ausgelieferte Snippet **verwirft** sie vor allem anderen:

```js
// v1.js, onError:
if (e && e.target && e.target !== window) {
  return;
}
```

Verifiziert: sechs fehlgeschlagene Ressourcen (4 × `<img>`, 1 × `<script>`, 1 × `<link>`) →
null Ingest-Requests. Entweder das Konzept oder den Code angleichen; sonst sucht später
jemand nach Zahlen, die es nie gab.

**`console.error`.** Steht in Abschnitt 3.1 als erfasste Quelle, ist im ausgelieferten
Snippet nicht implementiert (kein Patch, Listener sind nur `error`, `unhandledrejection`,
`pagehide`, `visibilitychange`).

## Nicht bauen

- **`console.error`-Capture** (Konzept Phase 2). Bibliotheken loggen freizügig, das meiste
  davon ist kein Fehlerzustand. Ihr baut euch damit die Arbeit wieder auf, die 1.3 gerade
  abräumt.
- **Sampling** (Phase 3). Antwort auf ein Volumenproblem, das es noch nicht gibt. Wenn das
  Rate-Limit zu greifen beginnt, ist der Zeitpunkt da.
- **Session Replay.** Andere Produktkategorie, andere Datenschutzdiskussion.

## Nebenbefunde

- **`HEAD /t/v1.js` → 404**, `GET` → 200. Der Handler kennt nur GET. Für Browser irrelevant,
  aber jeder Uptime-Check per HEAD meldet den Endpunkt als tot.
- **`https://app.monitorio.de/t/v1.js` liefert HTTP 200 mit `text/html`** — der SPA-Catch-all
  beantwortet unbekannte Pfade mit `index.html`. Ein Health-Check, der nur den Status-Code
  prüft, meldet „grün", obwohl der Endpunkt fehlt. Beim Ausrollen brauchen:
  `Access-Control-Allow-Origin`, `Content-Type: application/javascript` und eine eigene
  `Cache-Control` (aktuell erbt der Pfad die 4 h der SPA-Shell). Die lokale Instanz macht
  das bereits korrekt, `https://staging-app.monitorio.de/t/v1.js` ebenfalls (gemessen am
  2026-08-17): 200 mit `application/javascript; charset=utf-8`,
  `Access-Control-Allow-Origin: *` und `Cache-Control: public, max-age=14400`.
- **`https://monitorio.de/t/v1.js` 307-redirectet auf `www.`** und kostet damit einen
  zusätzlichen Round-Trip pro Seitenaufruf. Das Plugin zeigt deshalb auf `app.monitorio.de`.
- **Minifizierung.** In Produktion liefert Shopware `all.js` minifiziert aus; jeder echte
  Stack wird zu `all.js:1:284736`. Bis 2.1 steht, liefert der Check **Message, URL, Kontext
  und Häufigkeit** — nicht „welche Zeile deines Codes". Die UI sollte den Sample-Stack
  entsprechend nicht in den Mittelpunkt stellen.
- **Extension-Fehler** sind abgedeckt: `cleanStack` wirft Extension-Frames weg, `capture`
  verwirft das Ereignis danach ganz (`if (stack && !cleanedStack) return;`). Ein Leck bleibt:
  die Bedingung greift nur, wenn es überhaupt einen Stack gab. Ein Extension-Skript von
  fremder Domain ohne CORS-Header landet als `"Script error."` ohne Stack im Zähler — der
  Eimer, für den 1.3 den eigenen Schalter vorsieht.
- **Sales-Channel in der Gruppierung.** Das Plugin schickt `salesChannelId`, die
  Groups-MV ist laut Konzept aber auf `shop_id, fingerprint` geschlüsselt. Falls so gebaut,
  sieht ein Shop mit drei Sales-Channels nicht, welcher kaputt ist — bei Agenturkunden eher
  die Regel als die Ausnahme.
- **Rate-Limit und TTL** (Konzept 3.2 und offener Punkt 3): keine Belege gefunden, dass sie
  existieren. Ein kaputtes Deploy erzeugt bis zu 20 Gruppen pro Seitenaufruf.
- **Consent-Position fehlt schriftlich.** Technisch spricht alles für „kein Consent nötig":
  Query-Strings werden gestrippt, dank `crossOrigin="anonymous"` gehen keine Cookies mit,
  im Payload stehen keine Nutzerdaten. Das deckt sich mit der Empfehlung in offenem Punkt 6 —
  nur steht die Einschätzung nirgends, und die Frage kommt beim ersten Kunden.
