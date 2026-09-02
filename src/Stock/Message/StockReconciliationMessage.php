<?php declare(strict_types=1);

namespace Emz\Monitorio\Stock\Message;

use Shopware\Core\Framework\MessageQueue\LowPriorityMessageInterface;

/**
 * Traegermessage der Reconciliation-Arbeit (Outbox-Flush + Diff inkl. der
 * HTTP-Calls an Monitorio). Der ScheduledTask-Handler dispatcht nur noch diese
 * Message, statt die Arbeit selbst im scheduler_shopware-Worker auszufuehren -
 * damit laeuft ALLE Monitorio-HTTP-Arbeit im selben (per Config waehlbaren)
 * Transport wie der Subscriber-Pfad.
 *
 * Ueberlappende Verarbeitung ist harmlos: die Outbox claimt per Lease, und die
 * Diff-Phase laeuft nur bei leerer Outbox. Eine Deduplizierung braucht es
 * deshalb nicht (das DeduplicatableMessageInterface des Cores ist bis 6.8
 * experimental und scheidet fuer >=6.5-Kompatibilitaet ohnehin aus).
 */
final class StockReconciliationMessage implements LowPriorityMessageInterface
{
}
