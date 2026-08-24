<?php declare(strict_types=1);

namespace Emz\Monitorio\Stock;

/**
 * Ergebnis eines Batch-Versands an den Monitorio-Ingest.
 * statusCode === null bedeutet: Request kam nicht durch (Netzwerkfehler, Timeout).
 */
final readonly class IngestResult
{
    private function __construct(
        public ?int $statusCode,
        public ?int $retryAfterSeconds,
        public ?string $errorMessage,
    ) {
    }

    public static function fromStatus(int $statusCode, ?int $retryAfterSeconds): self
    {
        return new self($statusCode, $retryAfterSeconds, null);
    }

    public static function transportError(string $message): self
    {
        return new self(null, null, $message);
    }

    public function isTransportError(): bool
    {
        return $this->statusCode === null;
    }
}
