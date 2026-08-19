<?php declare(strict_types=1);

namespace Emz\Monitorio\Tests\Stock;

use Emz\Monitorio\Stock\MonitorioStockClient;
use Emz\Monitorio\Stock\StockPushConfig;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MonitorioStockClientTest extends TestCase
{
    public function testSendsBearerAuthorizedPostToIngestUrl(): void
    {
        $captured = [];
        $client = $this->client(
            static function (string $method, string $url, array $options) use (&$captured): MockResponse {
                $captured = ['method' => $method, 'url' => $url, 'options' => $options];

                return new MockResponse('', ['http_code' => 204]);
            },
            baseUrl: 'http://localhost:8080'
        );

        $result = $client->sendBatch('{"batchId":"B","events":[]}');

        self::assertSame(204, $result->statusCode);
        self::assertSame('POST', $captured['method']);
        self::assertSame('http://localhost:8080/ingest/stock/7', $captured['url']);
        self::assertSame('{"batchId":"B","events":[]}', $captured['options']['body']);
        self::assertContains(
            'Authorization: Bearer geheimes-token',
            $captured['options']['normalized_headers']['authorization'] ?? []
        );
        self::assertContains(
            'Content-Type: application/json',
            $captured['options']['normalized_headers']['content-type'] ?? []
        );
    }

    public function testTrailingSlashInBaseUrlIsNormalized(): void
    {
        $capturedUrl = null;
        $client = $this->client(
            static function (string $method, string $url) use (&$capturedUrl): MockResponse {
                $capturedUrl = $url;

                return new MockResponse('', ['http_code' => 204]);
            },
            baseUrl: 'http://localhost:8080/'
        );

        $client->sendBatch('{}');

        self::assertSame('http://localhost:8080/ingest/stock/7', $capturedUrl);
    }

    public function testParsesNumericRetryAfter(): void
    {
        $client = $this->client(static fn (): MockResponse => new MockResponse('', [
            'http_code' => 429,
            'response_headers' => ['retry-after' => '17'],
        ]));

        $result = $client->sendBatch('{}');

        self::assertSame(429, $result->statusCode);
        self::assertSame(17, $result->retryAfterSeconds);
    }

    public function testParsesHttpDateRetryAfter(): void
    {
        $client = $this->client(static fn (): MockResponse => new MockResponse('', [
            'http_code' => 503,
            'response_headers' => ['retry-after' => gmdate('D, d M Y H:i:s \G\M\T', time() + 60)],
        ]));

        $result = $client->sendBatch('{}');

        self::assertSame(503, $result->statusCode);
        self::assertNotNull($result->retryAfterSeconds);
        self::assertGreaterThanOrEqual(55, $result->retryAfterSeconds);
        self::assertLessThanOrEqual(60, $result->retryAfterSeconds);
    }

    public function testMissingRetryAfterYieldsNull(): void
    {
        $client = $this->client(static fn (): MockResponse => new MockResponse('', ['http_code' => 503]));

        self::assertNull($client->sendBatch('{}')->retryAfterSeconds);
    }

    public function testNetworkErrorBecomesTransportError(): void
    {
        $client = $this->client(static fn (): MockResponse => new MockResponse('', ['error' => 'connection refused']));

        $result = $client->sendBatch('{}');

        self::assertTrue($result->isTransportError());
        self::assertNull($result->statusCode);
        self::assertNotNull($result->errorMessage);
    }

    private function client(callable $responseFactory, string $baseUrl = 'https://app.monitorio.de'): MonitorioStockClient
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getInt')->willReturn(7);
        $systemConfig->method('getString')->willReturnCallback(static fn (string $key): string => match ($key) {
            'EmzMonitorio.config.ingestToken' => 'geheimes-token',
            'EmzMonitorio.config.monitorioBaseUrl' => $baseUrl,
            default => '',
        });

        return new MonitorioStockClient(new MockHttpClient($responseFactory), new StockPushConfig($systemConfig));
    }
}
