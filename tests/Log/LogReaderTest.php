<?php declare(strict_types=1);

namespace Emz\Monitorio\Tests\Log;

use Emz\Monitorio\Log\LogLineParser;
use Emz\Monitorio\Log\LogReader;
use PHPUnit\Framework\TestCase;

final class LogReaderTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/emz-monitorio-' . uniqid('', true);
        mkdir($this->logDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logDir . '/*.log') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->logDir);
    }

    public function testFiltersBySinceAndMinLevel(): void
    {
        file_put_contents($this->logDir . '/prod.log', implode("\n", [
            '[2026-04-26T08:00:00+00:00] app.INFO: old info [] []',
            '[2026-04-26T11:00:00+00:00] app.INFO: new info [] []',
            '[2026-04-26T11:05:00+00:00] app.WARNING: warn [] []',
            '[2026-04-26T11:10:00+00:00] business.ERROR: err [] []',
            '',
        ]));

        $reader = new LogReader($this->logDir, new LogLineParser());

        $entries = $reader->readSince(new \DateTimeImmutable('2026-04-26T10:00:00+00:00'), 'WARNING');

        self::assertCount(2, $entries);
        self::assertSame('WARNING', $entries[0]->level);
        self::assertSame('ERROR', $entries[1]->level);
    }

    public function testReturnsEmptyWhenDirMissing(): void
    {
        $reader = new LogReader($this->logDir . '/missing', new LogLineParser());

        self::assertSame([], $reader->readSince(new \DateTimeImmutable('-1 day'), 'DEBUG'));
    }

    public function testRespectsLimit(): void
    {
        $lines = [];
        for ($i = 0; $i < 10; $i++) {
            $lines[] = sprintf('[2026-04-26T11:%02d:00+00:00] app.ERROR: msg-%d [] []', $i, $i);
        }
        file_put_contents($this->logDir . '/prod.log', implode("\n", $lines));

        $reader = new LogReader($this->logDir, new LogLineParser());

        $entries = $reader->readSince(new \DateTimeImmutable('2026-04-26T00:00:00+00:00'), 'DEBUG', 3);

        self::assertCount(3, $entries);
    }

    public function testSortsByLoggedAtAscending(): void
    {
        file_put_contents($this->logDir . '/a.log', "[2026-04-26T11:10:00+00:00] app.ERROR: late [] []\n");
        file_put_contents($this->logDir . '/b.log', "[2026-04-26T11:05:00+00:00] app.ERROR: early [] []\n");

        $reader = new LogReader($this->logDir, new LogLineParser());

        $entries = $reader->readSince(new \DateTimeImmutable('2026-04-26T00:00:00+00:00'), 'DEBUG');

        self::assertCount(2, $entries);
        self::assertSame('early', $entries[0]->message);
        self::assertSame('late', $entries[1]->message);
    }

    public function testSinceBoundaryIsInclusive(): void
    {
        file_put_contents($this->logDir . '/prod.log', implode("\n", [
            '[2026-04-26T09:59:59+00:00] app.ERROR: before [] []',
            '[2026-04-26T10:00:00+00:00] app.ERROR: exact [] []',
            '[2026-04-26T10:00:01+00:00] app.ERROR: after [] []',
            '',
        ]));

        $reader = new LogReader($this->logDir, new LogLineParser());

        $entries = $reader->readSince(new \DateTimeImmutable('2026-04-26T10:00:00+00:00'), 'DEBUG');

        self::assertSame(['exact', 'after'], array_map(static fn ($e) => $e->message, $entries));
    }

    public function testSkipsFilesWithMtimeOlderThanSince(): void
    {
        $since = new \DateTimeImmutable('2026-04-26T10:00:00+00:00');

        // loggedAt liegt im Fenster, aber mtime davor: eine Datei, die zuletzt vor
        // `since` beschrieben wurde, kann real keine juengeren Zeilen enthalten -
        // der Reader darf sie ungelesen ueberspringen.
        $path = $this->logDir . '/rotated.log';
        file_put_contents($path, "[2026-04-26T11:00:00+00:00] app.ERROR: hit [] []\n");
        touch($path, $since->getTimestamp() - 3600);
        clearstatcache();

        $reader = new LogReader($this->logDir, new LogLineParser());

        self::assertSame([], $reader->readSince($since, 'DEBUG'));

        // Kontrolle: identischer Inhalt mit frischem mtime wird gelesen.
        touch($path, $since->getTimestamp() + 7200);
        clearstatcache();

        self::assertCount(1, $reader->readSince($since, 'DEBUG'));
    }

    public function testReadsFilesInMtimeOrderSoLimitKeepsOldestEntries(): void
    {
        $base = new \DateTimeImmutable('2026-04-26T10:00:00+00:00');

        // Alphabetisch kaeme a-new.log zuerst - nach mtime muss z-old.log gewinnen,
        // damit ein Limit-Schnitt die aeltesten Eintraege behaelt (Cursor-Semantik).
        $this->writeLog('z-old.log', [
            self::line($base, 'ERROR', 'oldest'),
            self::line($base->modify('+1 second'), 'ERROR', 'second'),
        ], $base->getTimestamp() + 1);
        $this->writeLog('a-new.log', [
            self::line($base->modify('+10 seconds'), 'ERROR', 'newer'),
            self::line($base->modify('+11 seconds'), 'ERROR', 'newest'),
        ], $base->getTimestamp() + 11);

        $reader = new LogReader($this->logDir, new LogLineParser());

        $entries = $reader->readSince($base->modify('-1 hour'), 'DEBUG', 2);

        self::assertSame(['oldest', 'second'], array_map(static fn ($e) => $e->message, $entries));
    }

    public function testTruncatesOverlongLinesAndKeepsFollowingEntries(): void
    {
        file_put_contents($this->logDir . '/prod.log', implode("\n", [
            '[2026-04-26T10:00:01+00:00] app.ERROR: ' . str_repeat('x', 100000) . ' [] []',
            '[2026-04-26T10:00:02+00:00] app.ERROR: after [] []',
            '',
        ]));

        $reader = new LogReader($this->logDir, new LogLineParser());

        $entries = $reader->readSince(new \DateTimeImmutable('2026-04-26T10:00:00+00:00'), 'DEBUG');

        self::assertCount(2, $entries);
        self::assertStringStartsWith('xxx', $entries[0]->message);
        self::assertLessThan(33000, \strlen($entries[0]->message));
        self::assertSame('after', $entries[1]->message);
    }

    public function testBinarySearchFindsWindowAtEndOfLargeFile(): void
    {
        $base = new \DateTimeImmutable('2026-04-26T00:00:00+00:00');
        $this->writeLargeLog('big.log', $base, 6000);

        $reader = new LogReader($this->logDir, new LogLineParser());

        // Fenster: die letzten 100 von 6000 Zeilen.
        $since = $base->modify('+5900 seconds');
        $entries = $reader->readSince($since, 'DEBUG');

        self::assertCount(100, $entries);
        self::assertSame('msg-005900', $entries[0]->message);
        self::assertSame('msg-005999', $entries[99]->message);
    }

    public function testLargeFileSinceBeforeAllEntriesReadsFromStart(): void
    {
        $base = new \DateTimeImmutable('2026-04-26T00:00:00+00:00');
        $this->writeLargeLog('big.log', $base, 6000);

        $reader = new LogReader($this->logDir, new LogLineParser());

        $entries = $reader->readSince($base->modify('-1 hour'), 'DEBUG', 1000);

        self::assertCount(1000, $entries);
        self::assertSame('msg-000000', $entries[0]->message);
    }

    public function testLargeFileSinceAfterAllEntriesReturnsEmpty(): void
    {
        $base = new \DateTimeImmutable('2026-04-26T00:00:00+00:00');
        $this->writeLargeLog('big.log', $base, 6000, mtime: $base->getTimestamp() + 999999);

        $reader = new LogReader($this->logDir, new LogLineParser());

        self::assertSame([], $reader->readSince($base->modify('+7000 seconds'), 'DEBUG'));
    }

    public function testBinarySearchToleratesContinuationLines(): void
    {
        $base = new \DateTimeImmutable('2026-04-26T00:00:00+00:00');
        $lines = [];
        for ($i = 0; $i < 6000; ++$i) {
            $lines[] = self::line($base->modify('+' . $i . ' seconds'), 'ERROR', sprintf('msg-%06d', $i));
            if ($i % 10 === 0) {
                $lines[] = '#0 /srv/shop/vendor/some/file.php(123): Foo\Bar->baz()';
                $lines[] = '  thrown in /srv/shop/vendor/other/file.php on line 45';
            }
        }
        $this->writeLog('big.log', $lines);

        $reader = new LogReader($this->logDir, new LogLineParser());

        $entries = $reader->readSince($base->modify('+5900 seconds'), 'DEBUG');

        self::assertCount(100, $entries);
        self::assertSame('msg-005900', $entries[0]->message);
    }

    public function testBinarySearchDoesNotScanForOutliersFarBeforeWindow(): void
    {
        $base = new \DateTimeImmutable('2026-04-26T00:00:00+00:00');
        $lines = [];
        for ($i = 0; $i < 10000; ++$i) {
            // Ausreisser weit vor dem Fenster (~5 % der Datei): loggedAt passt
            // ins Fenster, steht aber chronologisch falsch. Die Binaersuche
            // liest ihn bewusst nicht - dokumentierte Grenze der Optimierung
            // (und der Beweis, dass kein Vollscan mehr laeuft).
            $lines[] = $i === 500
                ? self::line($base->modify('+9999 seconds'), 'ERROR', 'outlier')
                : self::line($base->modify('+' . $i . ' seconds'), 'ERROR', sprintf('msg-%06d', $i));
        }
        $this->writeLog('big.log', $lines);

        $reader = new LogReader($this->logDir, new LogLineParser());

        $entries = $reader->readSince($base->modify('+9950 seconds'), 'DEBUG');

        self::assertCount(50, $entries);
        self::assertNotContains('outlier', array_map(static fn ($e) => $e->message, $entries));
    }

    public function testBinarySearchFindsOutlierWithinSeekBackWindow(): void
    {
        $base = new \DateTimeImmutable('2026-04-26T00:00:00+00:00');
        $lines = [];
        for ($i = 0; $i < 6000; ++$i) {
            // Ausreisser kurz vor dem Fensterstart (wenige hundert Bytes):
            // der Sicherheits-Ruecksprung muss ihn erfassen.
            $lines[] = $i === 5890
                ? self::line($base->modify('+5950 seconds'), 'ERROR', 'outlier')
                : self::line($base->modify('+' . $i . ' seconds'), 'ERROR', sprintf('msg-%06d', $i));
        }
        $this->writeLog('big.log', $lines);

        $reader = new LogReader($this->logDir, new LogLineParser());

        $entries = $reader->readSince($base->modify('+5900 seconds'), 'DEBUG');

        self::assertCount(101, $entries);
        self::assertContains('outlier', array_map(static fn ($e) => $e->message, $entries));
    }

    public function testLargeFileWithOnlyUnparsableLinesReturnsEmpty(): void
    {
        $lines = array_fill(0, 8000, '#0 /srv/shop/vendor/some/file.php(123): Foo\Bar->baz() with padding padding');
        $this->writeLog('junk.log', $lines);

        $reader = new LogReader($this->logDir, new LogLineParser());

        self::assertSame([], $reader->readSince(new \DateTimeImmutable('-1 day'), 'DEBUG'));
    }

    private static function line(\DateTimeImmutable $at, string $level, string $message): string
    {
        return sprintf('[%s] app.%s: %s [] []', $at->format('Y-m-d\TH:i:sP'), $level, $message);
    }

    /**
     * @param list<string> $lines
     */
    private function writeLog(string $name, array $lines, ?int $mtime = null): string
    {
        $path = $this->logDir . '/' . $name;
        file_put_contents($path, implode("\n", $lines) . "\n");

        if ($mtime !== null) {
            touch($path, $mtime);
            clearstatcache();
        }

        return $path;
    }

    private function writeLargeLog(string $name, \DateTimeImmutable $base, int $count, ?int $mtime = null): string
    {
        $lines = [];
        for ($i = 0; $i < $count; ++$i) {
            $lines[] = self::line($base->modify('+' . $i . ' seconds'), 'ERROR', sprintf('msg-%06d', $i));
        }

        return $this->writeLog($name, $lines, $mtime);
    }
}
