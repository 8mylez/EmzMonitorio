# Konzept: Message-Queue-Backlog-Endpunkt (EmzMonitorio)

Status: Konzept zur Umsetzung
Basis: [message_queue_companion_endpoint.md](message_queue_companion_endpoint.md) — **API-Contract dort ist FIX**
Branch: `feature/EMZ-message-queue-endpoint`

> **Revision 2026-07-09 (nach Erstumsetzung):** Auf Anweisung wurde die Datenquelle
> von der DBAL-Query auf `messenger_messages` (funktioniert nur beim Doctrine-Transport,
> d. h. bei kleinen Shops) auf die **`messenger:stats`-Mechanik** umgestellt:
> `tagged_iterator` über `messenger.receiver` (index-by `alias`) +
> `MessageCountAwareInterface::getMessageCount()` pro Transport. Damit funktioniert der
> Endpunkt auch mit AMQP/RabbitMQ/Redis. Response-Format `[{name,size}]` bleibt identisch;
> Semantik-Deltas gegenüber der Spec: `name` = Transport-Name statt `queue_name`
> (`async` statt `default`), zählbare Transports erscheinen auch mit `size: 0` (z. B.
> `failed`), delayed Messages zählen beim Doctrine-Transport nicht mit
> (`available_at <= now`-Filter des Receivers), nicht zählbare/nicht erreichbare
> Transports werden ausgelassen. Die Abschnitte unten beschreiben die ursprüngliche
> V1-Umsetzung und bleiben als Historie stehen.

## Ziel-Umgebung

- Referenz-Shop: Shopware 6.7.8.2 (demo-1, ddev), DBAL 4.4.3, Symfony 7.4
- Plugin-Kompatibilität laut `composer.json`: `shopware/core >= 6.5.7.0` (seit 1.6.0 — der Stock-Push nutzt den `low_priority`-Transport; zur Zeit dieses Konzepts galt noch `>= 6.5.0.0`), PHP >= 8.1
  → Code muss DBAL 3 **und** 4 vertragen; `Symfony\Component\Routing\Annotation\Route`
  verwenden (wie Bestand — `Attribute\Route` existiert erst ab Symfony 6.4, SW 6.5 nutzt 6.2/6.3)
- Messenger-Transport im Referenz-Shop: Doctrine (Default, kein `MESSENGER_TRANSPORT_DSN` gesetzt);
  `messenger_messages` existiert, Backlog aktuell leer

## Anforderung (fix)

`GET /api/monitorio/message-queue` (Route-Scope `api`, Auth = bestehende Admin-API-Integration)
liefert `200` mit JSON-Array `[{"name": "<queue_name>", "size": <int>}]` — Backlog
(`delivered_at IS NULL`) pro `queue_name` aus `messenger_messages`. Fehlende Tabelle
(kein Doctrine-Transport) → `200` mit `[]`, niemals 500. Kein Request-Body, keine Query-Parameter.

## Verbindliche Signaturen (für parallele Umsetzung)

Alle Agenten arbeiten gegen exakt diese Schnittstellen — nicht abweichen:

```php
// src/Api/MessageQueueApiController.php
namespace Emz\Monitorio\Api;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
final class MessageQueueApiController extends AbstractController
{
    public function __construct(private readonly Connection $connection) {}

    #[Route(
        path: '/api/monitorio/message-queue',
        name: 'api.monitorio.message_queue',
        methods: ['GET']
    )]
    public function getMessageQueueBacklog(): JsonResponse
    // 1. $this->connection->createSchemaManager()->tablesExist([self::MESSENGER_TABLE])
    //    → false: return new JsonResponse([]);  (MESSENGER_TABLE = private const 'messenger_messages')
    // 2. fetchAllAssociative('SELECT queue_name AS name, COUNT(*) AS size
    //    FROM messenger_messages WHERE delivered_at IS NULL GROUP BY queue_name')
    // 3. je Zeile: ['name' => (string) ..., 'size' => (int) ...] — DBAL kann COUNT als String liefern!
    // 4. return new JsonResponse(<gemapptes Array>);  (kein array_values nötig:
    //    fetchAllAssociative liefert eine Liste, array_map erhält die Keys → immer JSON-Array)
}
```

Design-Entscheidungen:
- **Eigener Controller** statt Erweiterung von `MonitorioApiController` (kleine Klassen,
  sauberer Diff; Naming analog `LogsApiController`). `final` gemäß PHP-Standard für neue Klassen.
- **Kein eigener Service** für die eine Aggregat-Query (Analogie `free-disk-space`:
  Logik direkt in der Action; ein Extra-Service wäre Overengineering).
- **Kein try/catch zusätzlich zu `tablesExist`** — die Spec verlangt eines von beidem.
  Andere DB-Fehler dürfen regulär als 500 hochkommen (echte Störung, soll monitorio sehen).
- `routes.xml` bleibt unverändert (importiert bereits `../../Api/*Controller.php` per Attribut).

services.xml-Eintrag (Muster `LogsApiController`):

```xml
<service id="Emz\Monitorio\Api\MessageQueueApiController" public="true">
    <argument type="service" id="Doctrine\DBAL\Connection"/>
    <call method="setContainer">
        <argument type="service" id="service_container"/>
    </call>
</service>
```

## Entscheidungen zu den offenen Punkten der Spec (§11)

1. **Struktur/Registrierungs-Stil:** `src/Api/` + Attribut-Routing + `services.xml`
   public/setContainer — ermittelt aus Bestand (s. o.).
2. **Tabellenname konfigurierbar?** Nein (V1): Standard `messenger_messages`,
   Abweichung als bekannte Einschränkung ins README.
3. **ACL:** Keine — Bestand (`free-disk-space`, `logs`) hat keine ACL-Konvention,
   Spec erlaubt das ausdrücklich (read-only Aggregat).
4. **Delayed Messages:** Beim fixen Contract bleiben — alle `delivered_at IS NULL`
   zählen, kein Zusatzfeld.

## Tests (`tests/Api/MessageQueueApiControllerTest.php`)

Reine Unit-Tests mit gemockter `Connection` (Muster `tests/Log/*Test.php`: `final`,
`PHPUnit\Framework\TestCase`; Connection/AbstractSchemaManager sind nicht final → mockbar):

1. Tabelle fehlt (`tablesExist` → false) → Status 200, Body exakt `[]`,
   `fetchAllAssociative` wird **nie** aufgerufen
2. Zeilen `[['name' => 'default', 'size' => '1234'], ['name' => 'low_priority', 'size' => '5']]`
   (DBAL-Strings!) → `[{"name":"default","size":1234},{"name":"low_priority","size":5}]`,
   `size` ist Integer (`assertSame` auf dekodiertes JSON)
3. Keine wartenden Messages (leeres Result) → `[]` (JSON-Array, nicht `{}`)

Hinweis Umgebung: Im demo-1-Shop fehlt phpunit (prod-Install) → Tests werden für CI/
Dev-Umgebung angelegt; lokale Verifikation läuft über `php -l` + Live-Smoke-Test (s. u.).

## Live-Verifikation (Orchestrator, nach Integration)

1. `ddev exec bin/console cache:clear` (neue Route)
2. OAuth-Token: password grant (`client_id=administration`, admin/shopware)
3. `curl GET /api/monitorio/message-queue` → `200`, `[]` (Backlog leer)
4. SQL-Fixtures mit `queue_name='emz_smoke_test'` (konsumiert kein Worker):
   2× undelivered + 1× delivered einfügen → Endpunkt muss `[{"name":"emz_smoke_test","size":2}]`
   liefern (delivered nicht gezählt); danach Fixtures löschen
5. Negativ-Test: Aufruf ohne Token → 401

## Doku

- README: Plugin-Beschreibung + Abschnitt „API-Endpunkte“ (message-queue mit Contract-Beispiel,
  Einschränkungen: nur Doctrine-Transport, Standard-Tabellenname; free-disk-space kurz erwähnt)
- `CHANGELOG.md` neu anlegen, `composer.json` Version 1.0.0 → 1.1.0
- Spec-Datei `docs/message_queue_companion_endpoint.md` mit einchecken (ist untracked)

## Abdeckung der Monitoring-Ziele (Bild) — Gap-Analyse

| Ziel | Abdeckung |
|------|-----------|
| Queue wird korrekt abgearbeitet | ✅ dieser Endpunkt: Backlog-Zeitreihe, monitorio alarmiert bei Backlog > Limit für X min |
| Uhrzeitabhängige Schwellenwerte | ✅ rein monitorio-seitig (Schwellen-Config auf der Zeitreihe); Endpunkt liefert Momentaufnahme |
| Mind. abgearbeitete Jobs je h | ⚠️ NICHT aus Backlog ableitbar (Delta = pushed − processed). Option A: monitorio pollt zusätzlich Standard-API `GET /api/_info/message-stats.json` (SW 6.7+: `totalMessagesProcessed`, aber global statt pro Queue, Rolling-Window `shopware.messenger.stats.time_span`, Default 300 s). Option B: Companion-V2 mit eigenem per-Queue-Zähler (Subscriber auf `WorkerMessageHandledEvent` + eigene Tabelle) — neuer Contract, eigenes Feature |
| Geschwindigkeit der Abarbeitung | ⚠️ `averageTimeInQueue` aus `message-stats.json` (global); per Queue nur via Companion-V2 |

Empfehlung: **V1 = exakt die Spec** (Contract fix, monitorio-Gegenseite gebaut). Durchsatz/
Geschwindigkeit monitorio-seitig über die Standard-`message-stats.json` ergänzen (kein
Plugin-Code nötig); per-Queue-Durchsatz als separates Follow-up mit Contract-Abstimmung.

## Risiken

- Race Tabelle-verschwindet zwischen Check und Query: akademisch (Transport-Wechsel zur
  Laufzeit), bewusst nicht behandelt
- Sehr großer Backlog: unkritisch, reiner `COUNT(*)` auf indexierte kleine Tabelle
- `Annotation\Route` ist in Symfony 7.4 deprecated (Alias), aber funktionsfähig — Wechsel auf
  `Attribute\Route` erst, wenn Plugin-Mindestversion ≥ 6.6 wird (Bestand nutzt es identisch)
