<?php declare(strict_types=1);

namespace Emz\Monitorio\Log;

/**
 * Meldet die Groesse des Log-Verzeichnisses, ohne eine einzige Zeile zu lesen:
 * nur glob() + filesize()/filemtime(). Der Aufwand haengt an der Anzahl Dateien,
 * nicht an ihren Bytes - deshalb bleibt der Endpunkt auch bei einem Log
 * antwortfaehig, das den Scan in LogReader in den HTTP-Timeout laufen laesst.
 *
 * Gemeldet wird die rohe Dateiliste, nicht ihre Auslegung: Kanaele,
 * Rotationsregel und Gruppierung rechnet Monitorio. Der Companion steht auf
 * jedem Shop einzeln - eine Auslegungsregel hier waere nur mit einem Rollout
 * ueber alle Shops zu aendern, und ein abweichendes Rotationsformat wuerde
 * still falsch klassifizieren, ohne dass Monitorio es geradeziehen koennte.
 */
final class LogVolumeReader
{
    public function __construct(
        private readonly string $logDir,
    ) {
    }

    /**
     * @return array{
     *     total_bytes: int,
     *     file_count: int,
     *     largest: array{name: string, bytes: int, modified_at: string}|null,
     *     newest_modified_at: string|null,
     *     files: list<array{name: string, bytes: int, modified_at: string}>
     * }
     */
    public function read(): array
    {
        // Dieselbe Dateimenge wie LogReader::readSince() - sonst erklaert die
        // gemeldete Groesse nicht den Scan, den sie erklaeren soll.
        $paths = glob(rtrim($this->logDir, '/') . '/*.log') ?: [];

        $files = [];
        $totalBytes = 0;
        $largest = null;
        $newest = null;

        foreach ($paths as $path) {
            // filesize() und filemtime() nacheinander auf denselben Pfad: der
            // zweite Aufruf bedient sich aus PHPs stat-Cache, es bleibt bei
            // einem stat je Datei. Dazwischen darf weder clearstatcache() noch
            // ein Zugriff auf einen anderen Pfad stehen.
            $bytes = @filesize($path);
            $modifiedAt = @filemtime($path);

            // Zwischen glob() und stat() rotiert weggeraeumt: auslassen statt
            // die Antwort daran scheitern zu lassen.
            if ($bytes === false || $modifiedAt === false) {
                continue;
            }

            $file = [
                'name' => basename($path),
                'bytes' => $bytes,
                'modified_at' => $this->formatTimestamp($modifiedAt),
            ];

            $files[] = $file;
            $totalBytes += $bytes;

            if ($largest === null || $bytes > $largest['bytes']) {
                $largest = $file;
            }

            if ($newest === null || $modifiedAt > $newest) {
                $newest = $modifiedAt;
            }
        }

        return [
            'total_bytes' => $totalBytes,
            'file_count' => \count($files),
            'largest' => $largest,
            'newest_modified_at' => $newest === null ? null : $this->formatTimestamp($newest),
            'files' => $files,
        ];
    }

    /**
     * Immer UTC: Der '@'-Zeitstempel erzeugt eine Zeit in UTC, unabhaengig von
     * date.timezone. Monitorio vergleicht die Werte lexikalisch, um je Kanal
     * die neueste Datei zu bestimmen - ein wechselnder Offset wuerde diese
     * Reihenfolge still verfaelschen.
     */
    private function formatTimestamp(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->format(\DateTimeInterface::RFC3339);
    }
}
