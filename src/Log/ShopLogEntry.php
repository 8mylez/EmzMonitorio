<?php declare(strict_types=1);

namespace Emz\Monitorio\Log;

final class ShopLogEntry implements \JsonSerializable
{
    public function __construct(
        public readonly string $externalKey,
        public readonly \DateTimeImmutable $loggedAt,
        public readonly string $level,
        public readonly string $channel,
        public readonly string $message,
        public readonly array $context,
        public readonly ?string $file,
        public readonly ?int $line,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'external_key' => $this->externalKey,
            // Mit Mikrosekunden: Monitorio benutzt den Wert als Pull-Cursor
            // und vergleicht mikrosekundengenau — sekundengenau serialisiert
            // wuerde jeder Batch innerhalb einer Sekunde erneut uebertragen.
            'logged_at' => $this->loggedAt->format('Y-m-d\TH:i:s.uP'),
            'level' => $this->level,
            'channel' => $this->channel,
            'message' => $this->message,
            'context' => (object) $this->context,
            'file' => $this->file,
            'line' => $this->line,
        ];
    }
}
