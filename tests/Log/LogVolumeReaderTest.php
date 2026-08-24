<?php declare(strict_types=1);

namespace Emz\Monitorio\Tests\Log;

use Emz\Monitorio\Log\LogVolumeReader;
use PHPUnit\Framework\TestCase;

final class LogVolumeReaderTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/emz-monitorio-volume-' . uniqid('', true);
        mkdir($this->logDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->logDir);
    }

    public function testSumsBytesAndCountsLogFiles(): void
    {
        file_put_contents($this->logDir . '/dev.log', str_repeat('a', 300));
        file_put_contents($this->logDir . '/prod.log', str_repeat('b', 120));

        $volume = (new LogVolumeReader($this->logDir))->read();

        self::assertSame(420, $volume['total_bytes']);
        self::assertSame(2, $volume['file_count']);
    }

    public function testReportsLargestFileAsBasename(): void
    {
        file_put_contents($this->logDir . '/dev.log', str_repeat('a', 300));
        file_put_contents($this->logDir . '/prod.log', str_repeat('b', 120));
        touch($this->logDir . '/dev.log', 1787000001);

        $volume = (new LogVolumeReader($this->logDir))->read();

        self::assertSame('dev.log', $volume['largest']['name']);
        self::assertSame(300, $volume['largest']['bytes']);
        self::assertSame('2026-08-17T20:53:21+00:00', $volume['largest']['modified_at']);
    }

    public function testReportsNewestModifiedAtAcrossAllFiles(): void
    {
        file_put_contents($this->logDir . '/dev.log', str_repeat('a', 300));
        file_put_contents($this->logDir . '/prod.log', str_repeat('b', 120));
        touch($this->logDir . '/dev.log', 1787000001);
        touch($this->logDir . '/prod.log', 1787009999);

        $volume = (new LogVolumeReader($this->logDir))->read();

        self::assertSame('2026-08-17T23:39:59+00:00', $volume['newest_modified_at']);
    }

    public function testCountsOnlyLogFilesLikeLogReader(): void
    {
        file_put_contents($this->logDir . '/dev.log', str_repeat('a', 300));
        file_put_contents($this->logDir . '/dev.log.1', str_repeat('b', 999));
        file_put_contents($this->logDir . '/notes.txt', str_repeat('c', 999));

        $volume = (new LogVolumeReader($this->logDir))->read();

        self::assertSame(300, $volume['total_bytes']);
        self::assertSame(1, $volume['file_count']);
    }

    public function testReturnsZeroForEmptyDirectory(): void
    {
        $volume = (new LogVolumeReader($this->logDir))->read();

        self::assertSame(0, $volume['total_bytes']);
        self::assertSame(0, $volume['file_count']);
        self::assertNull($volume['largest']);
        self::assertNull($volume['newest_modified_at']);
    }

    public function testReturnsZeroWhenDirMissingInsteadOfFailing(): void
    {
        $volume = (new LogVolumeReader($this->logDir . '/missing'))->read();

        self::assertSame(0, $volume['total_bytes']);
        self::assertSame(0, $volume['file_count']);
        self::assertNull($volume['largest']);
        self::assertNull($volume['newest_modified_at']);
    }

    public function testSkipsEntriesThatDisappearedBeforeStat(): void
    {
        file_put_contents($this->logDir . '/dev.log', str_repeat('a', 300));
        // Haengender Symlink: dasselbe, was ein zwischen glob() und stat()
        // wegrotiertes Log ausloest - filesize()/filemtime() liefern false.
        symlink($this->logDir . '/rotated-away.log', $this->logDir . '/rotated.log');

        $volume = (new LogVolumeReader($this->logDir))->read();

        self::assertSame(300, $volume['total_bytes']);
        self::assertSame(1, $volume['file_count']);
        self::assertSame('dev.log', $volume['largest']['name']);
    }
}
