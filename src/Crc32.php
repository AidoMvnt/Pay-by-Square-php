<?php
declare(strict_types=1);
namespace Pbs;

/**
 * CRC32 (zlib/ISO-HDLC) + the Pay by Square "checksum prepend" step.
 * Uses PHP's native, verified crc32().
 */
final class Crc32
{
    /** Return unsigned 32-bit CRC32 of $bytes. */
    public static function compute(string $bytes): int
    {
        return crc32($bytes) & 0xFFFFFFFF;
    }

    /**
     * Prepend a 4-byte little-endian CRC32 to the payload (the bysquare
     * "addChecksum" step).
     */
    public static function prepend(string $payload): string
    {
        $crc = self::compute($payload);
        return chr($crc & 0xFF) . chr(($crc >> 8) & 0xFF) . chr(($crc >> 16) & 0xFF) . chr(($crc >> 24) & 0xFF) . $payload;
    }
}
