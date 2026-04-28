<?php declare(strict_types=1);

namespace Emz\Monitorio\Tests\Log;

use Emz\Monitorio\Log\LogLevel;
use PHPUnit\Framework\TestCase;

final class LogLevelTest extends TestCase
{
    public function testSeverityCaseInsensitive(): void
    {
        self::assertSame(LogLevel::WARNING, LogLevel::severity('warning'));
        self::assertSame(LogLevel::ERROR, LogLevel::severity('ERROR'));
    }

    public function testSeverityThrowsOnUnknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LogLevel::severity('LOUD');
    }

    public function testExists(): void
    {
        self::assertTrue(LogLevel::exists('critical'));
        self::assertFalse(LogLevel::exists('verbose'));
    }

    public function testOrderingMatchesMonolog(): void
    {
        self::assertGreaterThan(LogLevel::WARNING, LogLevel::ERROR);
        self::assertGreaterThan(LogLevel::ERROR, LogLevel::CRITICAL);
        self::assertLessThan(LogLevel::WARNING, LogLevel::INFO);
    }
}
