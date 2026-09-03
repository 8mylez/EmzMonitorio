<?php declare(strict_types=1);

namespace Emz\Monitorio\Stock\Message;

use Shopware\Core\Framework\MessageQueue\LowPriorityMessageInterface;

/**
 * Leichte Queue-Message des Erfassungs-Subscribers: nur Produkt-IDs und
 * Zeitpunkt. Den aktuellen Bestand liest erst der Handler aus der Datenbank -
 * so blockiert der ausloesende Request nie (Spec Abschnitt 5), und mehrere
 * Messages zum selben Produkt kollabieren im Handler zu einem Event.
 *
 * low_priority statt async: der Standard-Worker (`messenger:consume async
 * low_priority`, ebenso der Admin-Worker) konsumiert Receiver in Reihenfolge -
 * Shop-Messages wie Mails und Indexer gehen damit immer vor, ein zaeher
 * Monitorio-Ingest kann sie nicht mehr verzoegern. Bei aktiviertem dedizierten
 * Transport ueberstimmt ein TransportNamesStamp dieses Routing
 * ({@see \Emz\Monitorio\Stock\StockPushConfig::transportStamps()}).
 */
final class StockChangedMessage implements LowPriorityMessageInterface
{
    /**
     * @param list<string> $productIds hex-UUIDs der Leaf-Produkte
     * @param string $occurredAt Zeitpunkt der Aenderung, RFC3339 (DATE_ATOM)
     */
    public function __construct(
        public readonly array $productIds,
        public readonly string $occurredAt,
    ) {
    }
}
