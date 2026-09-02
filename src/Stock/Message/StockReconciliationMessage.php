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
 * Der ScheduledTask-Status-Mutex entfaellt durch den Umbau; ueberlappende
 * Verarbeitung ist durch zwei Bremsen gedeckt: die Outbox claimt per Lease
 * (kein Doppelversand desselben Batches), und die Diff-Phase laeuft nur bei
 * komplett leerer Outbox - ein paralleler Lauf sieht die geclaimten Batches
 * des anderen als offen und ueberspringt den Diff. Uebrig bleibt ein
 * Sub-Sekunden-Race (Diff-Read vor apply-Commit des anderen), das
 * schlimmstenfalls inhaltsgleiche Events unter neuen batch-/eventIds doppelt
 * meldet; der Endzustand bleibt korrekt, previous*-Ketten brechen nicht.
 * Eine Deduplizierung braucht es deshalb nicht (das
 * DeduplicatableMessageInterface des Cores ist bis 6.8 experimental und
 * scheidet fuer die Ziel-Kompatibilitaet ohnehin aus).
 */
final class StockReconciliationMessage implements LowPriorityMessageInterface
{
}
