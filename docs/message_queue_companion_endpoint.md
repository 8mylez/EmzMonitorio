# Companion-Endpunkt: Message-Queue-Backlog – Technische Anforderungen

Status: Anforderung (übergabefertig)
Stand: 2026-06-18
Ziel-Repo: Shopware-Companion-Plugin `8mylez/shopware-monitorio-*` (Namespace `Emz\`), **nicht** das monitorio-Repo.

> Diese Spec ist so geschrieben, dass eine frische Claude-Session im
> Shopware-Plugin-Repo daraus ein Konzept + Implementierung planen kann, ohne
> Zugriff auf das monitorio-Repo zu brauchen. Der **API-Contract (Abschnitt 3)
> ist FIX** — die monitorio-Gegenseite ist bereits gebaut und erwartet exakt
> dieses Verhalten. Alles unter „Implementierung" ist Vorschlag/Entscheidung
> des Shopware-Plugins.

## 1. Kontext & Ziel

Monitorio überwacht Shopware-6-Shops. Ein neues monitorio-Plugin (`message_queue`)
pollt pro Shop in einem Intervall die **Größe der Symfony-Messenger-Queue**
(Backlog wartender Messages, aufgeschlüsselt pro Queue/Receiver), speichert die
Zeitreihe und alarmiert, wenn der Backlog für X Minuten über einem Limit liegt.
Ein dauerhaft wachsender Backlog ist das klassische Frühwarnsignal für einen
hängenden oder überlasteten Worker (`messenger:consume`).

Die Standard-Admin-API liefert das nicht verlässlich: `GET /api/_info/queue.json`
ist increment-basiert, „oft ungenau", ab Shopware 6.7.8.0 **deprecated** und in
6.8.0.0 entfernt; der Nachfolger `/api/_info/message-stats.json` liefert Durchsatz,
nicht den Backlog. Deshalb braucht monitorio einen **eigenen, exakten,
versions-stabilen Endpunkt** im Companion-Plugin, der den Backlog direkt aus der
Datenbank zählt.

## 2. Einordnung

Der Endpunkt gehört in das bestehende Companion-Plugin, **analog zum schon
vorhandenen** `GET /api/monitorio/free-disk-space` (Free-Space-Feature). Es ist
dieselbe Route-Familie `/api/monitorio/*` und derselbe Auth-Weg (Admin-API der
Shop-Integration). Es ist **kein** neues Plugin, **kein** neuer Token, **keine**
neue Auth-Pipeline nötig — nur eine zusätzliche Route + Controller-Action.

## 3. API-Contract (FIX — monitorio erwartet exakt das)

```
GET /api/monitorio/message-queue
  Route-Scope: api  (Shopware Admin-API)
  Auth:        bestehende Admin-API-Integration des Shops
               (OAuth client_credentials → Authorization: Bearer <token>).
               monitorio besitzt clientId/clientSecret bereits pro Projekt;
               es ist KEIN zusätzliches Secret im Shop zu konfigurieren.
  Request-Body: keiner. Keine Query-Parameter.

  Response 200, Content-Type application/json:
  Ein JSON-ARRAY, ein Objekt pro Queue/Receiver:
  [
    { "name": "default",      "size": 1234 },
    { "name": "low_priority", "size": 5 }
  ]

  Feld   Typ     Bedeutung
  name   string  Queue-/Receiver-Name (Spalte queue_name in messenger_messages)
  size   integer Anzahl noch nicht ausgelieferter Messages dieser Queue (Backlog)
```

Verbindliche Regeln:
- **Leeres Array `[]`** ist ein valider, erwarteter Erfolgsfall (kein Backlog,
  oder kein Doctrine-Transport — siehe Abschnitt 5). **Niemals 500** wegen
  fehlender Tabelle.
- `size` muss ein echter Integer sein (nicht String). Große Werte möglich →
  intern als Integer behandeln.
- Das Format ist bewusst identisch zu Shopwares altem `queue.json`
  (`[{name,size}]`), damit monitorio später optional auf die Standard-API
  zurückfallen kann, ohne sein Datenmodell zu ändern.
- monitorio speichert pro Queue eine Zeile pro Poll. Reihenfolge der Array-
  Einträge ist egal. Doppelte `name` vermeiden (pro Queue genau ein Eintrag).

## 4. Datenquelle & Abfrage

Symfony Messenger mit **Doctrine-Transport** persistiert die Queue in der Tabelle
`messenger_messages`. Eine Message gilt als wartend/Backlog, solange
`delivered_at IS NULL`. Mehrere Transports teilen sich i.d.R. dieselbe Tabelle und
unterscheiden sich über `queue_name` — die Gruppierung trennt sie also korrekt.

```sql
SELECT queue_name AS name, COUNT(*) AS size
FROM   messenger_messages
WHERE  delivered_at IS NULL
GROUP  BY queue_name;
```

Hinweise:
- Über DBAL (`Doctrine\DBAL\Connection::fetchAllAssociative()`) abfragen, **nicht**
  über das DAL — `messenger_messages` ist keine DAL-Entity.
- `size` aus dem `COUNT(*)` explizit zu `int` casten.
- Default-Index der Doctrine-Transport-Tabelle deckt die Abfrage ausreichend ab;
  die Tabelle ist normalerweise klein. Keine Performance-Sonderbehandlung nötig.

## 5. Transport-Erkennung & Robustheit (wichtig)

Die Tabelle `messenger_messages` existiert **nur** beim Doctrine-Transport. Nutzt
der Shop AMQP/RabbitMQ, Redis oder In-Memory, ist die Tabelle leer oder gar nicht
vorhanden. Der Endpunkt muss das robust abfangen:

- Tabelle fehlt / `TableNotFoundException` / SQL-Fehler „table doesn't exist"
  → **leeres Array `[]` mit Status 200** zurückgeben (kein 500).
- Optional (nice-to-have): den konfigurierten Transport-DSN prüfen
  (`MESSENGER_TRANSPORT_DSN` bzw. die Messenger-Konfiguration). Ist kein
  Doctrine-Transport aktiv, direkt `[]` liefern und das im Plugin-Code/README als
  bekannte Einschränkung dokumentieren.
- Empfehlung: Tabellenexistenz einmal über
  `Connection::createSchemaManager()->tablesExist(['messenger_messages'])` prüfen
  (oder Abfrage in try/catch kapseln), damit der Endpunkt auf jedem Shop ohne
  Konfiguration sauber antwortet.

## 6. Shopware-Implementierung (Vorschlag)

Zuerst die **Shopware-Version aus `composer.json`/`composer.lock` ermitteln** und
Controller-/Route-Attribute entsprechend wählen (Agentur-Standard). Skizze für
6.5/6.6/6.7-Stil (Attribut-Routing, `_routeScope`):

```php
<?php declare(strict_types=1);

namespace Emz\Monitorio\Controller\Api; // an reale Plugin-Struktur anpassen

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Routing\Attribute\RouteScope; // bzw. defaults _routeScope
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
final class MessageQueueController extends AbstractController
{
    public function __construct(private readonly Connection $connection) {}

    #[Route(
        path: '/api/monitorio/message-queue',
        name: 'api.monitorio.message_queue',
        methods: ['GET'],
    )]
    public function backlog(): JsonResponse
    {
        $schema = $this->connection->createSchemaManager();
        if (!$schema->tablesExist(['messenger_messages'])) {
            return new JsonResponse([]); // kein Doctrine-Transport
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT queue_name AS name, COUNT(*) AS size
             FROM messenger_messages
             WHERE delivered_at IS NULL
             GROUP BY queue_name'
        );

        $result = array_map(
            static fn (array $r): array => ['name' => (string) $r['name'], 'size' => (int) $r['size']],
            $rows
        );

        return new JsonResponse(array_values($result));
    }
}
```

- Controller in `services.xml` registrieren (mit DBAL-`Connection`-Injection),
  Tag/Autowiring nach Plugin-Konvention.
- Route-Registrierung via `routes.xml`/Attribut wie die bestehenden
  `/api/monitorio/*`-Routen des Plugins (an `free-disk-space` orientieren).

## 7. Auth & ACL

- Die Route läuft im **Admin-API-Scope** (`_routeScope: ['api']`) und wird damit
  über Shopwares Admin-OAuth abgesichert. monitorio ruft sie mit dem Bearer-Token
  der pro Projekt hinterlegten Integration auf — exakt wie `free-disk-space`.
- Eigene ACL-Permission ist **nicht** zwingend (read-only, nur Aggregat-Count).
  Falls das Plugin für `/api/monitorio/*` bereits eine ACL-Konvention hat, dieselbe
  übernehmen; sonst keine zusätzliche einführen.
- Keine CSRF-Themen (Admin-API, kein Storefront-Formular).

## 8. Edge-Cases & Entscheidungen

- **Delayed/Scheduled Messages:** Der Doctrine-Transport hält auch verzögerte
  Messages mit `available_at` in der Zukunft und `delivered_at IS NULL`. Default:
  **alle nicht-ausgelieferten zählen** (entspricht der `queue.json`-Semantik, die
  der Contract spiegelt). Optional könnte der Endpunkt zusätzlich „ready jetzt"
  (`available_at <= NOW()`) ausweisen — das ist aber **nicht** Teil des fixen
  Contracts und würde ein zusätzliches Feld erfordern; im Zweifel weglassen.
- **Mehrere Transports / abweichender Tabellenname:** Falls der Shop den
  Doctrine-Transport mit eigenem `table_name` konfiguriert, ist der Standard
  `messenger_messages` falsch. Für V1 akzeptabel (Standard annehmen); optional den
  Tabellennamen konfigurierbar machen.
- **Keine wartenden Messages:** `[]` (nicht `[{"name":"default","size":0}]`).
- **Sehr großer Backlog:** unkritisch, reiner `COUNT(*)`.

## 9. Nicht-Ziele

- Keine Schreib-/Aktions-Endpunkte (kein Purge, kein Retry, kein Requeue) — reines
  Read-Only-Monitoring.
- Keine Inhalte/Payloads der Messages ausliefern (nur Counts pro `queue_name`).
- Keine Historie/Aggregation im Shop — Zeitreihe/Retention macht monitorio.
- Keine eigene Auth/Token-Verwaltung — Admin-API-Integration genügt.

## 10. Tests (PHPUnit)

- Endpunkt liefert ein JSON-Array `[{name,size}]`; `size` ist Integer.
- Mit künstlich eingefügten `messenger_messages`-Zeilen (delivered_at NULL vs.
  gesetzt): Counts korrekt pro `queue_name`, ausgelieferte Messages werden **nicht**
  gezählt.
- Fehlende Tabelle / kein Doctrine-Transport → `200` mit `[]`, kein 500.
- (Falls ACL übernommen) Zugriff ohne gültigen Admin-Token → 401/403.

## 11. Offene Punkte für den Shopware-Dev

1. Reale Plugin-Struktur/Namespace und der bestehende Registrierungs-Stil der
   `/api/monitorio/*`-Routen (an `free-disk-space` spiegeln) — Versionen vorab aus
   `composer.json` prüfen.
2. Soll der Doctrine-Tabellenname konfigurierbar sein, oder reicht der Standard
   `messenger_messages`?
3. ACL: dieselbe Konvention wie die anderen Companion-Routen, oder bewusst keine?
4. „Ready jetzt vs. inkl. delayed" — beim fixen Contract (alle undelivered) bleiben,
   oder später ein optionales Zusatzfeld einführen?
```
