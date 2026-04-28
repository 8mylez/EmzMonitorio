<?php declare(strict_types=1);

namespace Emz\Monitorio\Log;

final class LogLineParser
{
    private const PATTERN = '/^\[(?P<datetime>[^\]]+)\]\s+(?P<channel>[^.]+)\.(?P<level>[A-Z]+):\s+(?P<message>.*?)(?:\s+(?P<context>(?:\{.*?\}|\[\]))\s+(?P<extra>(?:\{.*?\}|\[\])))?\s*$/u';

    public function parse(string $line): ?ShopLogEntry
    {
        $line = rtrim($line);

        if ($line === '') {
            return null;
        }

        if (!preg_match(self::PATTERN, $line, $matches)) {
            return null;
        }

        $loggedAt = $this->parseDate($matches['datetime']);

        if ($loggedAt === null) {
            return null;
        }

        $level = strtoupper($matches['level']);

        if (!LogLevel::exists($level)) {
            return null;
        }

        $context = $this->decodeJsonObject($matches['context'] ?? '');
        $file = $this->stringOrNull($context['file'] ?? null);
        $line = $this->intOrNull($context['line'] ?? null);

        return new ShopLogEntry(
            externalKey: $this->buildKey($loggedAt, $matches['channel'], $level, $matches['message'], $file, $line),
            loggedAt: $loggedAt,
            level: $level,
            channel: $matches['channel'],
            message: trim($matches['message']),
            context: $context,
            file: $file,
            line: $line,
        );
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        foreach (['Y-m-d\\TH:i:s.uP', 'Y-m-d\\TH:i:sP', 'Y-m-d H:i:s.u', 'Y-m-d H:i:s'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);

            if ($date instanceof \DateTimeImmutable) {
                return $date;
            }
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function decodeJsonObject(string $value): array
    {
        if ($value === '' || $value === '[]') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function buildKey(\DateTimeImmutable $loggedAt, string $channel, string $level, string $message, ?string $file, ?int $line): string
    {
        $payload = implode('|', [
            $loggedAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            $channel,
            $level,
            $message,
            $file ?? '',
            $line !== null ? (string) $line : '',
        ]);

        return hash('sha256', $payload);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
    }
}
