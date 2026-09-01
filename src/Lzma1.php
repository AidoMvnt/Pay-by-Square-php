<?php
declare(strict_types=1);
namespace Pbs;

/**
 * LZMA1 shell wrapper.
 *
 * Produces a RAW LZMA1 body (lc=3, lp=0, pb=2, dictionary 128 KiB, end-marker
 * on) by invoking the system `xz` tool — exactly the "lzma-test.php" recipe:
 *
 *     xz --format=raw --lzma1=lc=3,lp=0,pb=2,dict=128KiB -c -
 *
 * `--format=raw` emits NO .lzma stream header (the 13-byte props/dict/size
 * block is omitted) — only the compressed range-coder body, which is precisely
 * the `lzmaBody` the official `bysquare` bank decoder expects after the 4-byte
 * bysquare header (00 00 + u16-LE length).
 *
 * This output has been verified to round-trip through the real `bysquare`
 * decoder (bysquare/lib/pay/decode.js) — byte-compatible with the C# reference
 * and with the banks' decoders.
 *
 * NOTE: this requires the `xz` binary to be present (xz utils). It shells out
 * so the PHP side stays 100% portable / dependency-free apart from xz, which
 * ships with virtually every Linux and macOS distribution.
 */
final class Lzma1
{
    /** Default 7-Zip "extreme" optimum level, mapped to xz level. */
    private const DEFAULT_LV = 6;

    /**
     * Compression presets (all pinned to the bysquare-required lc/lp/pb/dict;
     * `$optimum` tunes the LZ2 / nice/chain depth for small size vs speed).
     */
    public static function preset(int $optimum = self::DEFAULT_LV): string
    {
        // `mc` is NOT a valid --lzma1= key; `nice` is. All levels keep the
        // bysquare-required lc/lp/pb/dict and only tune `nice` for size/speed.
        $preset = [
            0 => 'lc=3,lp=0,pb=2,dict=128KiB',
            1 => 'lc=3,lp=0,pb=2,dict=128KiB,nice=6',
            2 => 'lc=3,lp=0,pb=2,dict=128KiB,nice=7',
            3 => 'lc=3,lp=0,pb=2,dict=128KiB,nice=8',
            4 => 'lc=3,lp=0,pb=2,dict=128KiB,nice=9',
            5 => 'lc=3,lp=0,pb=2,dict=128KiB,nice=10',
            6 => 'lc=3,lp=0,pb=2,dict=128KiB,nice=11',
        ];
        $k = max(0, min(6, $optimum));
        // bysquare decoder hard-requires dict=131072 (2^17); keep it pinned.
        return $preset[$k];
    }

    /**
     * Compress `$data` to a RAW LZMA1 body via the `xz` shell tool.
     *
     * @throws \RuntimeException if xz is unavailable or fails.
     */
    public static function compress(string $data, int $optimum = self::DEFAULT_LV): string
    {
        $xz = self::findXz();
        $preset = self::preset($optimum);

        // Write input to a temp file, run xz -c in > out (binary-safe file I/O).
        $in  = tempnam(sys_get_temp_dir(), 'pbs-lz-in');
        $out = tempnam(sys_get_temp_dir(), 'pbs-lz-out');
        if ($in === false || $out === false) {
            throw new \RuntimeException('LZMA1 (xz): cannot create temp files.');
        }
        file_put_contents($in, $data);

        // Exactly the "lzma-test.php" recipe, output redirected to a file:
        //   xz --format=raw --lzma1=lc=3,lp=0,pb=2,dict=128KiB -c < in > out
        $cmd = sprintf(
            '%s --format=raw --lzma1=%s -c < %s > %s 2>/dev/null',
            escapeshellarg($xz),
            $preset,
            escapeshellarg($in),
            escapeshellarg($out)
        );

        $rc = 1;
        @exec($cmd, $lines, $rc);   // $lines unused; output went to $out file

        $body = (string) file_get_contents($out);
        @unlink($in);
        @unlink($out);

        if ($rc !== 0) {
            throw new \RuntimeException("LZMA1 (xz) failed (exit $rc); is xz installed with --lzma1 support?");
        }
        if ($body === '') {
            throw new \RuntimeException('LZMA1 (xz) produced an empty body.');
        }
        return $body;
    }

    /** Locate the `xz` binary (search common paths). */
    public static function findXz(): string
    {
        $candidates = [
            getenv('XZ_PATH') ?: '',
            'xz',
            '/usr/bin/xz',
            '/usr/local/bin/xz',
            '/opt/homebrew/bin/xz',
        ];
        foreach ($candidates as $c) {
            if ($c === '' || $c === false) continue;
            if (is_executable($c)) return $c; // 'xz' relies on PATH
            if (function_exists('exec') && @exec('command -v ' . escapeshellarg($c) . ' 2>/dev/null', $o, $rc) === '' && $o) {
                return trim($o[0] ?? '');
            }
        }
        throw new \RuntimeException('xz binary not found (install xz-utils).');
    }
}
