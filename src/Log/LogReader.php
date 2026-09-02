<?php declare(strict_types=1);

namespace Emz\Monitorio\Log;

/**
 * Liest Log-Eintraege ab einem Zeitpunkt, ohne grosse Dateien von vorn zu
 * scannen: Dateien ganz ausserhalb des Fensters fallen per mtime weg, in
 * grossen Dateien findet eine Bisektion den Fensterstart. Der Vollscan von
 * frueher lief bei einem GB-Log in den HTTP-Timeout und hielt dabei je Poll
 * einen FPM-Worker fest.
 */
final class LogReader
{
    /**
     * fgets-Kappung: laengere Zeilen (Monolog-Context mit riesigem Stack-Trace)
     * werden gekuerzt uebernommen statt komplett in den Speicher geladen. Der
     * Schnittpunkt ist deterministisch, der externalKey bleibt damit stabil.
     */
    private const MAX_LINE_BYTES = 32768;

    /**
     * Ab dieser Dateigroesse lohnt die Bisektion; darunter ist linear billiger
     * als die Seek-Probes.
     */
    private const BINARY_SEARCH_MIN_BYTES = 262144;

    /**
     * Restfenster, ab dem die Bisektion stoppt - den Rest uebernimmt der
     * lineare Nachlauf.
     */
    private const BINARY_SEARCH_STOP_BYTES = 8192;

    /**
     * Sicherheits-Ruecksprung hinter den gefundenen Fensterstart: faengt lokal
     * nicht-monotone Zeitstempel (parallel schreibende Prozesse, Uhr-Drift).
     * Zeilen, die chronologisch WEIT versetzt stehen, sind die dokumentierte
     * Grenze der Optimierung.
     */
    private const SEEK_BACK_BYTES = 65536;

    /**
     * Probe-Zeilen je Bisektions-Schritt, bevor der Bereich als "nichts
     * Parsebares" gilt (laengere Junk-Bloecke verschieben das Fenster nur
     * konservativ nach vorn).
     */
    private const PROBE_MAX_LINES = 50;

    public function __construct(
        private readonly string $logDir,
        private readonly LogLineParser $parser,
    ) {
    }

    /**
     * @return ShopLogEntry[]
     */
    public function readSince(\DateTimeInterface $since, string $minLevel, int $limit = 1000): array
    {
        if (!is_dir($this->logDir)) {
            return [];
        }

        $threshold = LogLevel::severity($minLevel);
        $entries = [];

        foreach ($this->relevantFilesOldestFirst($since) as $file) {
            $this->collectFromFile($file, $since, $threshold, $entries, $limit);

            if (count($entries) >= $limit) {
                break;
            }
        }

        usort($entries, static fn (ShopLogEntry $a, ShopLogEntry $b): int => $a->loggedAt <=> $b->loggedAt);

        return array_slice($entries, 0, $limit);
    }

    /**
     * Dateien mit mtime vor `since` koennen keine juengeren Zeilen enthalten
     * (Logs sind append-only) und werden ungelesen uebersprungen - eine
     * rotierte GB-Datei von gestern kostet damit nur noch ihren stat-Call.
     * Aufsteigend nach mtime gelesen behaelt ein Limit-Schnitt die aeltesten
     * Eintraege: die Cursor-Semantik des Monitorio-Pulls (naechster Poll holt
     * das neue Ende, es entsteht keine Luecke).
     *
     * @return list<string>
     */
    private function relevantFilesOldestFirst(\DateTimeInterface $since): array
    {
        $sinceTimestamp = $since->getTimestamp();
        $files = [];

        foreach (glob(rtrim($this->logDir, '/') . '/*.log') ?: [] as $path) {
            $modifiedAt = @filemtime($path);

            if ($modifiedAt === false || $modifiedAt < $sinceTimestamp) {
                continue;
            }

            $files[$path] = $modifiedAt;
        }

        asort($files);

        return array_keys($files);
    }

    /**
     * @param list<ShopLogEntry> $entries
     */
    private function collectFromFile(string $path, \DateTimeInterface $since, int $threshold, array &$entries, int $limit): void
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return;
        }

        try {
            $this->seekToWindowStart($handle, $since);

            while (count($entries) < $limit) {
                $line = $this->readLineCapped($handle);

                if ($line === null) {
                    break;
                }

                $entry = $this->parser->parse($line);

                if ($entry === null) {
                    continue;
                }

                if ($entry->loggedAt < $since) {
                    continue;
                }

                if (LogLevel::severity($entry->level) < $threshold) {
                    continue;
                }

                $entries[] = $entry;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Bisektion ueber die chronologisch wachsende Datei: positioniert das
     * Handle kurz VOR der ersten Zeile mit loggedAt >= since, in
     * O(log Dateigroesse) Seeks statt einem Scan von vorn.
     *
     * @param resource $handle
     */
    private function seekToWindowStart($handle, \DateTimeInterface $since): void
    {
        $stat = fstat($handle);
        $size = \is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;

        if ($size < self::BINARY_SEARCH_MIN_BYTES) {
            return;
        }

        $low = 0;
        $high = $size;

        while ($high - $low > self::BINARY_SEARCH_STOP_BYTES) {
            $mid = intdiv($low + $high, 2);
            $probedAt = $this->firstTimestampAfter($handle, $mid, $high);

            // null (nichts Parsebares bis high) zaehlt konservativ als "im
            // Fenster": der Start wandert nach vorn, gelesen wird hoechstens
            // zu viel, nie zu wenig.
            if ($probedAt === null || $probedAt >= $since) {
                $high = $mid;
                continue;
            }

            $low = $mid;
        }

        $start = max(0, $low - self::SEEK_BACK_BYTES);
        fseek($handle, $start);

        if ($start === 0) {
            return;
        }

        // Nur einen echten Zeilen-Anriss verwerfen: steht direkt vor dem
        // Startbyte ein Zeilenumbruch, beginnt hier bereits eine volle Zeile.
        fseek($handle, $start - 1);

        if (fread($handle, 1) !== "\n") {
            $this->skipRestOfLine($handle);
        }
    }

    /**
     * Zeitstempel der ersten parsebaren Zeile nach dem Offset, begrenzt durch
     * $end und PROBE_MAX_LINES.
     *
     * @param resource $handle
     */
    private function firstTimestampAfter($handle, int $offset, int $end): ?\DateTimeImmutable
    {
        fseek($handle, $offset);

        if ($offset > 0) {
            $this->skipRestOfLine($handle);
        }

        for ($i = 0; $i < self::PROBE_MAX_LINES; ++$i) {
            if (ftell($handle) >= $end) {
                return null;
            }

            $line = $this->readLineCapped($handle);

            if ($line === null) {
                return null;
            }

            $entry = $this->parser->parse($line);

            if ($entry !== null) {
                return $entry->loggedAt;
            }
        }

        return null;
    }

    /**
     * Liest eine Zeile, gekappt auf MAX_LINE_BYTES; der Ueberhang laengerer
     * Zeilen wird verworfen, damit der naechste Aufruf wieder an einem echten
     * Zeilenanfang steht. null bei EOF.
     *
     * @param resource $handle
     */
    private function readLineCapped($handle): ?string
    {
        $line = fgets($handle, self::MAX_LINE_BYTES);

        if ($line === false) {
            return null;
        }

        // fgets liefert hoechstens MAX_LINE_BYTES-1 Bytes; kam dabei kein
        // Zeilenende mit, war die Zeile laenger.
        if (!str_ends_with($line, "\n") && \strlen($line) === self::MAX_LINE_BYTES - 1) {
            $this->skipRestOfLine($handle);
        }

        return $line;
    }

    /**
     * @param resource $handle
     */
    private function skipRestOfLine($handle): void
    {
        do {
            $chunk = fgets($handle, self::MAX_LINE_BYTES);
        } while ($chunk !== false && !str_ends_with($chunk, "\n"));
    }
}
