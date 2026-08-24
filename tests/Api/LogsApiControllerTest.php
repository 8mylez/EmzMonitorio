<?php declare(strict_types=1);

namespace Emz\Monitorio\Tests\Api;

use Emz\Monitorio\Api\LogsApiController;
use Emz\Monitorio\Log\LogLineParser;
use Emz\Monitorio\Log\LogReader;
use Emz\Monitorio\Log\LogVolumeReader;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Test gegen den API-Contract von
 * GET /api/_action/emz/monitorio/logs/meta.
 */
final class LogsApiControllerTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/emz-monitorio-meta-' . uniqid('', true);
        mkdir($this->logDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->logDir);
    }

    public function testLogsMetaReturnsVolumeUnderDataKey(): void
    {
        file_put_contents($this->logDir . '/dev.log', str_repeat('a', 300));
        touch($this->logDir . '/dev.log', 1787000001);

        $response = $this->controller()->getLogsMeta();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [
                'data' => [
                    'total_bytes' => 300,
                    'file_count' => 1,
                    'largest' => [
                        'name' => 'dev.log',
                        'bytes' => 300,
                        'modified_at' => '2026-08-17T20:53:21+00:00',
                    ],
                    'newest_modified_at' => '2026-08-17T20:53:21+00:00',
                ],
            ],
            json_decode((string) $response->getContent(), true)
        );
    }

    private function controller(): LogsApiController
    {
        return new LogsApiController(
            new LogReader($this->logDir, new LogLineParser()),
            new LogVolumeReader($this->logDir)
        );
    }
}
