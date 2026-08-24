<?php declare(strict_types=1);

namespace Emz\Monitorio\Stock\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * Leichte Queue-Message des Erfassungs-Subscribers: nur Produkt-IDs und
 * Zeitpunkt. Den aktuellen Bestand liest erst der Handler aus der Datenbank -
 * so blockiert der ausloesende Request nie (Spec Abschnitt 5), und mehrere
 * Messages zum selben Produkt kollabieren im Handler zu einem Event.
 */
final class StockChangedMessage implements AsyncMessageInterface
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
