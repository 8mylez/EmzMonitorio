<?php declare(strict_types=1);

namespace Emz\Monitorio\Tests\Log;

use Emz\Monitorio\Log\LogLineParser;
use PHPUnit\Framework\TestCase;

final class LogLineParserTest extends TestCase
{
    private LogLineParser $parser;

    protected function setUp(): void
    {
        $this->parser = new LogLineParser();
    }

    public function testParsesIso8601Line(): void
    {
        $line = '[2026-04-26T10:15:00.123456+00:00] business.ERROR: Something broke {"file":"/var/www/App.php","line":42} []';

        $entry = $this->parser->parse($line);

        self::assertNotNull($entry);
        self::assertSame('ERROR', $entry->level);
        self::assertSame('business', $entry->channel);
        self::assertSame('Something broke', $entry->message);
        self::assertSame('/var/www/App.php', $entry->file);
        self::assertSame(42, $entry->line);
    }

    public function testParsesLegacyDateLine(): void
    {
        $line = '[2026-04-26 10:15:00] app.WARNING: Heads up [] []';

        $entry = $this->parser->parse($line);

        self::assertNotNull($entry);
        self::assertSame('WARNING', $entry->level);
        self::assertSame('app', $entry->channel);
        self::assertSame('Heads up', $entry->message);
        self::assertNull($entry->file);
        self::assertNull($entry->line);
    }

    public function testReturnsNullOnGarbage(): void
    {
        self::assertNull($this->parser->parse('not a log line'));
        self::assertNull($this->parser->parse(''));
    }

    public function testExternalKeyIsStableForSameInput(): void
    {
        $line = '[2026-04-26T10:15:00.000000+00:00] app.ERROR: msg [] []';

        $a = $this->parser->parse($line);
        $b = $this->parser->parse($line);

        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertSame($a->externalKey, $b->externalKey);
    }

    public function testExternalKeyDiffersOnDifferentInput(): void
    {
        $a = $this->parser->parse('[2026-04-26T10:15:00.000000+00:00] app.ERROR: msg-a [] []');
        $b = $this->parser->parse('[2026-04-26T10:15:00.000000+00:00] app.ERROR: msg-b [] []');

        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertNotSame($a->externalKey, $b->externalKey);
    }
}
