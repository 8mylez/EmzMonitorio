<?php declare(strict_types=1);

namespace Emz\Monitorio\Tests\Log;

use Emz\Monitorio\Log\ShopLogEntry;
use PHPUnit\Framework\TestCase;

final class ShopLogEntryTest extends TestCase
{
    /**
     * Monitorio benutzt logged_at als Pull-Cursor und vergleicht
     * mikrosekundengenau. Ein sekundengenau serialisierter Wert laesst
     * ganze Sekunden-Batches bei jedem Pull erneut uebertragen.
     */
    public function testJsonSerializeKeepsMicroseconds(): void
    {
        $entry = new ShopLogEntry(
            externalKey: 'abc',
            loggedAt: new \DateTimeImmutable('2026-08-26T19:08:44.552569+00:00'),
            level: 'ERROR',
            channel: 'app',
            message: 'Testeintrag',
            context: [],
            file: null,
            line: null,
        );

        self::assertSame('2026-08-26T19:08:44.552569+00:00', $entry->jsonSerialize()['logged_at']);
    }
}
