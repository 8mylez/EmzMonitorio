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
            'logged_at' => $this->loggedAt->format(\DateTimeInterface::RFC3339),
            'level' => $this->level,
            'channel' => $this->channel,
            'message' => $this->message,
            'context' => (object) $this->context,
            'file' => $this->file,
            'line' => $this->line,
        ];
    }
}
