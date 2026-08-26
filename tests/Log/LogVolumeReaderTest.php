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
            @chmod($file, 0o644);
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
        self::assertSame(['dev.log'], array_column($volume['files'], 'name'));
    }

    public function testReturnsZeroForEmptyDirectory(): void
    {
        $volume = (new LogVolumeReader($this->logDir))->read();

        self::assertSame(0, $volume['total_bytes']);
        self::assertSame(0, $volume['file_count']);
        self::assertNull($volume['largest']);
        self::assertNull($volume['newest_modified_at']);
        self::assertSame([], $volume['files']);
    }

    public function testReturnsZeroWhenDirMissingInsteadOfFailing(): void
    {
        $volume = (new LogVolumeReader($this->logDir . '/missing'))->read();

        self::assertSame(0, $volume['total_bytes']);
        self::assertSame(0, $volume['file_count']);
        self::assertNull($volume['largest']);
        self::assertNull($volume['newest_modified_at']);
        self::assertSame([], $volume['files']);
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
        self::assertSame(['dev.log'], array_column($volume['files'], 'name'));
    }

    public function testReportsEachFileAsBasenameWithBytesAndModifiedAt(): void
    {
        file_put_contents($this->logDir . '/prod-2026-08-11.log', str_repeat('a', 18342));
        touch($this->logDir . '/prod-2026-08-11.log', 1787000001);

        $volume = (new LogVolumeReader($this->logDir))->read();

        self::assertSame(
            [[
                'name' => 'prod-2026-08-11.log',
                'bytes' => 18342,
                'modified_at' => '2026-08-17T20:53:21+00:00',
            ]],
            $volume['files']
        );
    }

    /**
     * Die Dateiliste ist die Grundlage, auf der Monitorio Kanaele rechnet -
     * fehlt eine Datei oder taucht sie doppelt auf, rechnet Monitorio falsch,
     * ohne es merken zu koennen.
     */
    public function testListsEveryFileExactlyOnce(): void
    {
        $expected = [
            'dev.log' => 300,
            'prod.log' => 120,
            'prod-2026-08-11.log' => 50,
            'prod-2026-08-12.log' => 70,
            'mollie_prod-2026-08-25.log' => 90,
        ];

        foreach ($expected as $name => $bytes) {
            file_put_contents($this->logDir . '/' . $name, str_repeat('a', $bytes));
        }

        $volume = (new LogVolumeReader($this->logDir))->read();

        $byName = array_column($volume['files'], 'bytes', 'name');
        ksort($byName);
        $sortedExpected = $expected;
        ksort($sortedExpected);

        self::assertSame($sortedExpected, $byName);
        self::assertCount(\count($expected), $volume['files']);
        self::assertSame(\count($expected), $volume['file_count']);
    }

    /**
     * Kein Top-N, keine Stichprobe: Auch ein rotierendes Verzeichnis mit vielen
     * Tagesdateien wird vollstaendig gemeldet. Die Gruppierung passiert in
     * Monitorio und braucht dafuer jede Datei.
     */
    public function testListsAllFilesInADirectoryWithManyOfThem(): void
    {
        for ($day = 1; $day <= 250; $day++) {
            file_put_contents(
                sprintf('%s/prod-%04d.log', $this->logDir, $day),
                str_repeat('a', $day)
            );
        }

        $volume = (new LogVolumeReader($this->logDir))->read();

        self::assertSame(250, $volume['file_count']);
        self::assertCount(250, $volume['files']);
        self::assertCount(250, array_unique(array_column($volume['files'], 'name')));
        self::assertSame(31375, $volume['total_bytes']);
        self::assertSame(31375, array_sum(array_column($volume['files'], 'bytes')));
        self::assertSame('prod-0250.log', $volume['largest']['name']);
    }

    /**
     * Monitorio bestimmt die neueste Datei je Kanal ueber einen lexikalischen
     * Vergleich der Zeitstempel. Das geht nur auf, solange jeder Wert RFC3339
     * in UTC ist - eine Zeitzone aus der PHP-Konfiguration wuerde die
     * Reihenfolge still verdrehen.
     */
    public function testFormatsEveryModifiedAtAsRfc3339InUtc(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            file_put_contents($this->logDir . '/dev.log', 'a');
            file_put_contents($this->logDir . '/prod-2026-08-11.log', 'b');
            touch($this->logDir . '/dev.log', 1787000001);
            touch($this->logDir . '/prod-2026-08-11.log', 1787009999);

            $volume = (new LogVolumeReader($this->logDir))->read();
        } finally {
            date_default_timezone_set($previous);
        }

        $byName = array_column($volume['files'], 'modified_at', 'name');
        self::assertSame('2026-08-17T20:53:21+00:00', $byName['dev.log']);
        self::assertSame('2026-08-17T23:39:59+00:00', $byName['prod-2026-08-11.log']);
        self::assertSame('2026-08-17T23:39:59+00:00', $volume['newest_modified_at']);
    }

    /**
     * Sichert die Kernzusicherung des Endpunkts ab: gelesen wird nichts, nur
     * stat. Eine Datei ohne Leserecht laesst sich weiterhin statten, aber nicht
     * oeffnen - ein fopen() im Reader wuerde hier sofort auffallen.
     */
    public function testStatsFilesWithoutOpeningThem(): void
    {
        $unreadable = $this->logDir . '/prod-2026-08-11.log';
        file_put_contents($unreadable, str_repeat('a', 300));
        chmod($unreadable, 0o000);

        if (is_readable($unreadable)) {
            self::markTestSkipped('Laeuft als root - Dateirechte greifen nicht.');
        }

        // Faengt auch ein mit @ unterdruecktes Oeffnen: seit PHP 8 laeuft der
        // Error-Handler auch dann, lediglich error_reporting() ist reduziert -
        // was hier bewusst nicht geprueft wird. stat() auf eine Datei ohne
        // Leserecht bleibt derweil warnungsfrei, das x-Recht am Verzeichnis
        // genuegt dafuer.
        set_error_handler(static function (int $severity, string $message): bool {
            throw new \RuntimeException('Dateizugriff statt stat: ' . $message);
        });

        try {
            $volume = (new LogVolumeReader($this->logDir))->read();
        } finally {
            restore_error_handler();
        }

        self::assertSame(300, $volume['total_bytes']);
        self::assertSame(1, $volume['file_count']);
        self::assertSame('prod-2026-08-11.log', $volume['files'][0]['name']);
        self::assertSame(300, $volume['files'][0]['bytes']);
    }
}
