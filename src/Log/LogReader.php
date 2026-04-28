<?php declare(strict_types=1);

namespace Emz\Monitorio\Log;

final class LogReader
{
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
        $files = glob(rtrim($this->logDir, '/') . '/*.log') ?: [];
        $entries = [];

        foreach ($files as $file) {
            $this->collectFromFile($file, $since, $threshold, $entries, $limit);

            if (count($entries) >= $limit) {
                break;
            }
        }

        usort($entries, static fn (ShopLogEntry $a, ShopLogEntry $b): int => $a->loggedAt <=> $b->loggedAt);

        return array_slice($entries, 0, $limit);
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
            while (!feof($handle) && count($entries) < $limit) {
                $line = fgets($handle);

                if ($line === false) {
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
}
