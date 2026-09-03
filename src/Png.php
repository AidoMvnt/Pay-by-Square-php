<?php
declare(strict_types=1);
namespace Pbs;

/**
 * True-color RGBA PNG encoder — pure PHP, no GD/imagick, no zlib extension.
 *
 * Output is a valid 8-bit RGBA (color type 6), non-interlaced PNG whose
 * IDAT uses a DEFLATE stream of STORED blocks (BTYPE=00, zero-compression
 * but fully spec-compliant — no Huffman tables to get wrong). The zlib
 * wrapper (0x78 0x01 … adler32) is hand-built so the encoder has zero
 * native dependencies, matching this project's constraint (same stance as
 * the native LZMA1 range coder and the table-driven Crc32).
 *
 * Also carries the low-level helpers (chunk framing, crc32, adler32,
 * deflate) so future codecs (BMP, etc.) can reuse them.
 */
final class Png
{
    private const SIGNATURE = "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A";   // \x89 PNG\r\n\x1a\n

    /**
     * Encode an RGBA image to a 1×1-safe PNG string.
     *
     * @param int    $w    width  (>= 1)
     * @param int    $h    height (>= 1)
     * @param string $rgba  raw bytes, w*h*4 bytes, row-major, row 0 = TOP,
     *                      per pixel order R,G,B,A
     */
    public static function encodeRgba(int $w, int $h, string $rgba): string
    {
        if ($w < 1 || $h < 1) throw new \InvalidArgumentException('w/h must be >= 1');
        $n = $w * $h * 4;
        if (strlen($rgba) !== $n) {
            throw new \InvalidArgumentException("rgba must be $n bytes, got " . strlen($rgba));
        }

        // Raw IDAT payload: one filter byte (0 = None) per scanline + row bytes.
        $rowBytes = $w * 4;
        $raw = '';
        for ($y = 0; $y < $h; $y++) {
            $raw .= "\x00";
            $raw .= substr($rgba, $y * $rowBytes, $rowBytes);
        }

        $ihdr = pack('NN', $w, $h) . "\x08\x06\x00\x00\x00";   // 8 b/chan, RGBA, none, none, no-interlace
        return self::SIGNATURE
             . self::chunk('IHDR', $ihdr)
             . self::chunk('IDAT', self::zlib($raw))
             . self::chunk('IEND', '');
    }

    // ================= low-level helpers =================

    /** One PNG chunk: len(4 BE) + name + data + crc32(name+data, 4 BE). */
    public static function chunk(string $name, string $data): string
    {
        return pack('N', strlen($data)) . $name . $data . pack('N', crc32($name . $data) & 0xFFFFFFFF);
    }

    /**
     * zlib stream over $data: header 0x78 0x01, DEFLATE stored blocks
     * (<= 65535 bytes each, byte-aligned), adler32 checksum (4 bytes BE).
     * Stored blocks are the only DEFLATE form we ever need here — they are
     * trivially lossless and need no Huffman machinery.
     */
    public static function zlib(string $data): string
    {
        $out  = "\x78\x01";                  // CMF=0x78 (deflate, 32K window), FLG=0x01 (no dict); (0x78*256+1) % 31 == 0
        $len  = strlen($data);
        $off  = 0;
        while ($off < $len) {
            $take = min(65535, $len - $off);
            $final = ($off + $take) === $len;
            // Block header byte: bit0 = BFINAL, bits1-2 = BTYPE (00 = stored);
            // the remaining 5 bits are zero padding to the byte boundary.
            $out .= chr($final ? 0x01 : 0x00);
            $out .= pack('v', $take);           // LEN  (uint16 LE)
            $out .= pack('v', ~$take & 0xFFFF); // NLEN (uint16 LE, one's complement of LEN)
            $out .= substr($data, $off, $take);
            $off += $take;
        }
        // Empty input: one empty final stored block so the stream is well-formed.
        if ($len === 0) {
            $out .= chr(0x01) . pack('v', 0) . pack('v', 0xFFFF);
        }
        $out .= self::adler32Bytes($data);
        return $out;
    }

    /**
     * adler32 (RFC 1950) as 4 bytes BIG-endian: (b << 16) | a.
     * PHP's native adler32() (core standard, unsigned int return — same stance
     * as Crc32::compute's native crc32()) is used when available; otherwise a
     * pure-PHP reference implementation.
     */
    public static function adler32Bytes(string $s): string
    {
        $v = function_exists('adler32')
            ? ((int)adler32($s) & 0xFFFFFFFF)
            : self::adler32Pure($s);
        return chr((($v >> 24) & 0xFF)) . chr((($v >> 16) & 0xFF)) . chr((($v >> 8) & 0xFF)) . chr(($v & 0xFF));
    }

    /** Manual adler32 (fallback when the native function is unavailable). */
    private static function adler32Pure(string $s): int
    {
        $a = 1; $b = 0; $MOD = 65521;
        $len = strlen($s);
        // NMAX chunking keeps a,b below 2^31 on 32-bit builds even with
        // long inputs (PHP ints are 64-bit here, but stay portable).
        $i = 0;
        while ($i < $len) {
            $chunk = min(5552, $len - $i);
            for ($j = 0; $j < $chunk; $j++) {
                $a += ord($s[$i + $j]);
                $b += $a;
            }
            $a %= $MOD; $b %= $MOD;
            $i += $chunk;
        }
        return ($b << 16) | $a;
    }
}
