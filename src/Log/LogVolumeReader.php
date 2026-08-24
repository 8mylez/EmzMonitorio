<?php declare(strict_types=1);

namespace Emz\Monitorio\Log;

/**
 * Meldet die Groesse des Log-Verzeichnisses, ohne eine einzige Zeile zu lesen:
 * nur glob() + filesize()/filemtime(). Der Aufwand haengt an der Anzahl Dateien,
 * nicht an ihren Bytes - deshalb bleibt der Endpunkt auch bei einem Log
 * antwortfaehig, das den Scan in LogReader in den HTTP-Timeout laufen laesst.
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
     *     newest_modified_at: string|null
     * }
     */
    public function read(): array
    {
        // Dieselbe Dateimenge wie LogReader::readSince() - sonst erklaert die
        // gemeldete Groesse nicht den Scan, den sie erklaeren soll.
        $files = glob(rtrim($this->logDir, '/') . '/*.log') ?: [];

        $totalBytes = 0;
        $fileCount = 0;
        $largest = null;
        $newestModifiedAt = null;

        foreach ($files as $file) {
            $bytes = @filesize($file);
            $modifiedAt = @filemtime($file);

            // Zwischen glob() und stat() rotiert weggeraeumt: auslassen statt
            // die Antwort daran scheitern zu lassen.
            if ($bytes === false || $modifiedAt === false) {
                continue;
            }

            $totalBytes += $bytes;
            $fileCount++;

            if ($largest === null || $bytes > $largest['bytes']) {
                $largest = [
                    'name' => basename($file),
                    'bytes' => $bytes,
                    'modified_at' => $this->formatTimestamp($modifiedAt),
                ];
            }

            if ($newestModifiedAt === null || $modifiedAt > $newestModifiedAt) {
                $newestModifiedAt = $modifiedAt;
            }
        }

        return [
            'total_bytes' => $totalBytes,
            'file_count' => $fileCount,
            'largest' => $largest,
            'newest_modified_at' => $newestModifiedAt === null ? null : $this->formatTimestamp($newestModifiedAt),
        ];
    }

    private function formatTimestamp(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->format(\DateTimeInterface::RFC3339);
    }
}
