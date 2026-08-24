<?php declare(strict_types=1);

namespace Emz\Monitorio\Tests\Config;

use Emz\Monitorio\Config\MonitorioBaseUrl;
use PHPUnit\Framework\TestCase;

final class MonitorioBaseUrlTest extends TestCase
{
    /**
     * @dataProvider normalizeCases
     */
    public function testNormalize(string $configured, string $expected): void
    {
        self::assertSame($expected, MonitorioBaseUrl::normalize($configured));
    }

    public static function normalizeCases(): iterable
    {
        yield 'leer faellt auf den default' => ['', 'https://app.monitorio.de'];
        yield 'nur whitespace faellt auf den default' => ["  \n ", 'https://app.monitorio.de'];
        yield 'gesetzter wert gewinnt' => ['http://localhost:8080', 'http://localhost:8080'];
        yield 'whitespace wird getrimmt' => ['  http://localhost:8080  ', 'http://localhost:8080'];
        yield 'trailing slash entfaellt' => ['http://localhost:8080/', 'http://localhost:8080'];
    }

    /**
     * @dataProvider legacySnippetUrlCases
     */
    public function testFromLegacySnippetUrl(string $snippetUrl, ?string $expected): void
    {
        self::assertSame($expected, MonitorioBaseUrl::fromLegacySnippetUrl($snippetUrl));
    }

    public static function legacySnippetUrlCases(): iterable
    {
        yield 'volle snippet-url wird zur basis' => [
            'https://staging-app.monitorio.de/t/v1.js',
            'https://staging-app.monitorio.de',
        ];
        yield 'default-instanz braucht keinen override' => ['https://app.monitorio.de/t/v1.js', null];
        yield 'default-basis ohne pfad braucht keinen override' => ['https://app.monitorio.de', null];
        yield 'leer bleibt leer' => ['', null];
        yield 'nur whitespace bleibt leer' => ["  \n ", null];
        yield 'basis-url ohne snippet-pfad wird uebernommen' => ['http://localhost:8080', 'http://localhost:8080'];
        yield 'trailing slash entfaellt' => ['http://localhost:8080/', 'http://localhost:8080'];
        yield 'fremder pfad bleibt unangetastet erhalten' => [
            'https://cdn.example.com/monitorio/custom.js',
            'https://cdn.example.com/monitorio/custom.js',
        ];
    }
}
