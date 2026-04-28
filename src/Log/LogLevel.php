<?php declare(strict_types=1);

namespace Emz\Monitorio\Log;

final class LogLevel
{
    public const DEBUG = 100;
    public const INFO = 200;
    public const NOTICE = 250;
    public const WARNING = 300;
    public const ERROR = 400;
    public const CRITICAL = 500;
    public const ALERT = 550;
    public const EMERGENCY = 600;

    private const MAP = [
        'DEBUG' => self::DEBUG,
        'INFO' => self::INFO,
        'NOTICE' => self::NOTICE,
        'WARNING' => self::WARNING,
        'ERROR' => self::ERROR,
        'CRITICAL' => self::CRITICAL,
        'ALERT' => self::ALERT,
        'EMERGENCY' => self::EMERGENCY,
    ];

    public static function severity(string $name): int
    {
        $key = strtoupper($name);

        if (!isset(self::MAP[$key])) {
            throw new \InvalidArgumentException(sprintf('Unknown log level "%s"', $name));
        }

        return self::MAP[$key];
    }

    public static function exists(string $name): bool
    {
        return isset(self::MAP[strtoupper($name)]);
    }
}
