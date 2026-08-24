<?php declare(strict_types=1);

namespace Emz\Monitorio\Stock;

/**
 * ULID-Generierung (26 Zeichen Crockford-Base32: 48 Bit Millisekunden-Timestamp
 * + 80 Bit Zufall) fuer batchId und eventId des Stock-Push-Contracts.
 *
 * Eigene Implementierung, weil symfony/uid im Ziel-Stack (Shopware 6.7,
 * Production-Install) nicht vorhanden ist.
 */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private function __construct()
    {
    }

    public static function generate(?int $timestampMs = null): string
    {
        $timestampMs ??= (int) (microtime(true) * 1000);

        $timePart = '';
        $t = $timestampMs;
        for ($i = 0; $i < 10; ++$i) {
            $timePart = self::ALPHABET[$t % 32] . $timePart;
            $t = intdiv($t, 32);
        }

        $randomPart = '';
        $bits = 0;
        $bitCount = 0;
        foreach (str_split(random_bytes(10)) as $byte) {
            $bits = ($bits << 8) | \ord($byte);
            $bitCount += 8;
            while ($bitCount >= 5) {
                $bitCount -= 5;
                $randomPart .= self::ALPHABET[($bits >> $bitCount) & 31];
            }
        }

        return $timePart . $randomPart;
    }
}
