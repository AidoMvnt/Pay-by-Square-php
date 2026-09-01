<?php
declare(strict_types=1);
namespace Pbs;

/**
 * Base32Hex (0-9A-V, 5 bits/group) — byte-for-byte identical to the bysquare
 * reference `base32hex.js` (Filip Seman). Whole-byte MSB-first bit packing.
 */
final class Base32Hex
{
    private const ALPHA = "0123456789ABCDEFGHIJKLMNOPQRSTUV";
    private const MASK  = 0b11111;

    public static function encode(string $bytes): string
    {
        $out = [];
        $buffer = 0;
        $bitsLeft = 0;
        $n = strlen($bytes);
        for ($i = 0; $i < $n; $i++) {
            $buffer = ($buffer << 8) | ord($bytes[$i]);
            $bitsLeft += 8;
            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $idx = ($buffer >> $bitsLeft) & self::MASK;
                $out[] = self::ALPHA[$idx];
            }
        }
        if ($bitsLeft > 0) {
            $masked = ($buffer << (5 - $bitsLeft)) & self::MASK;
            $out[] = self::ALPHA[$masked];
        }
        return implode('', $out);
    }

    public static function decode(string $s): string
    {
        $s = preg_replace('/=+$/', '', strtoupper($s));
        $out = [];
        $buffer = 0;
        $bitsLeft = 0;
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $ch = $s[$i];
            $idx = strpos(self::ALPHA, $ch);
            if ($idx === false) throw new \InvalidArgumentException("Invalid base32hex string (char '$ch')");
            $buffer = ($buffer << 5) | $idx;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $out[] = ($buffer >> $bitsLeft) & 0xFF;
            }
        }
        return implode('', array_map(fn($b) => chr($b), $out));
    }
}
