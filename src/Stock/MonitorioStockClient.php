<?php declare(strict_types=1);

namespace Emz\Monitorio\Stock;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Outbound-Client fuer POST {monitorioBaseUrl}/ingest/stock/{projectId}
 * (Spec Abschnitt 3). Wirft nicht bei HTTP-Fehlerstatus - die Interpretation
 * der Statuscodes uebernimmt der StockPushService anhand der Contract-Tabelle.
 */
final class MonitorioStockClient
{
    /**
     * Knappe Timeouts: Ein haengendes Monitorio darf den Messenger-Worker
     * nicht festhalten; nicht Zustellbares bleibt in der Outbox liegen.
     */
    private const TIMEOUT_SECONDS = 5.0;
    private const MAX_DURATION_SECONDS = 10.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly StockPushConfig $config,
    ) {
    }

    public function sendBatch(string $batchJson): IngestResult
    {
        $url = \sprintf('%s/ingest/stock/%d', $this->config->getBaseUrl(), $this->config->getProjectId());

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config->getIngestToken(),
                    'Content-Type' => 'application/json',
                ],
                'body' => $batchJson,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
            ]);

            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $response->cancel();

            return IngestResult::fromStatus($statusCode, self::parseRetryAfter($headers['retry-after'][0] ?? null));
        } catch (TransportExceptionInterface $e) {
            return IngestResult::transportError($e->getMessage());
        }
    }

    /**
     * Retry-After kommt als Sekundenzahl oder HTTP-Datum (RFC 9110).
     */
    private static function parseRetryAfter(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }
}
