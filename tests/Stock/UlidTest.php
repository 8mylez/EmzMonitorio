<?php declare(strict_types=1);

namespace Emz\Monitorio\Tests\Stock;

use Emz\Monitorio\Stock\Ulid;
use PHPUnit\Framework\TestCase;

final class UlidTest extends TestCase
{
    public function testMatchesUlidFormat(): void
    {
        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', Ulid::generate());
    }

    public function testEncodesTimestampInFirstTenCharacters(): void
    {
        self::assertStringStartsWith('0000000000', Ulid::generate(0));
        self::assertStringStartsWith('0000000001', Ulid::generate(1));
        // 1024 = 32^2 -> '100' in Base32
        self::assertStringStartsWith('0000000100', Ulid::generate(1024));
    }

    public function testTimestampPartIsLexicographicallySortable(): void
    {
        $earlier = substr(Ulid::generate(1_000_000), 0, 10);
        $later = substr(Ulid::generate(2_000_000), 0, 10);

        self::assertLessThan(0, strcmp($earlier, $later));
    }

    public function testGeneratesUniqueValues(): void
    {
        $ulids = [];
        for ($i = 0; $i < 1000; ++$i) {
            $ulids[] = Ulid::generate();
        }

        self::assertCount(1000, array_unique($ulids));
    }
}
