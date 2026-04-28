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

        $entries = $reader->readSince(new \DateTimeImmutable('-1 day'), 'DEBUG', 3);

        self::assertCount(3, $entries);
    }

    public function testSortsByLoggedAtAscending(): void
    {
        file_put_contents($this->logDir . '/a.log', "[2026-04-26T11:10:00+00:00] app.ERROR: late [] []\n");
        file_put_contents($this->logDir . '/b.log', "[2026-04-26T11:05:00+00:00] app.ERROR: early [] []\n");

        $reader = new LogReader($this->logDir, new LogLineParser());

        $entries = $reader->readSince(new \DateTimeImmutable('-1 day'), 'DEBUG');

        self::assertCount(2, $entries);
        self::assertSame('early', $entries[0]->message);
        self::assertSame('late', $entries[1]->message);
    }
}
