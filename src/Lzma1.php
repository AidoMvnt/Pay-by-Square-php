<?php
declare(strict_types=1);
namespace Pbs;

/**
 * LZMA1 wrapper (proc_open + pipes).
 *
 * Produces a RAW LZMA1 body (lc=3, lp=0, pb=2, dictionary 128 KiB) by running
 * the system `xz` binary:
 *
 *     xz --format=raw --lzma1=lc=3,lp=0,pb=2,dict=128KiB -c -
 *
 *   stdin  <= $data   (pipe fd 0)
 *   stdout => raw body (pipe fd 1)
 *
 * This mirrors the reference php-pay-by-square repo pattern that works on
 * shared hosting: the `exec()` / `shell_exec()` family of functions is
 * commonly listed in the server's `disable_functions`, while `proc_open` is
 * NOT — so the wrapper stays operational there.
 *
 * `--format=raw` emits bare LZMA1 (no 13-byte .lzma stream header), which is
 * exactly the `lzmaBody` the official `bysquare` bank decoder expects after the
 * 4-byte bysquare header (two 0x00 bytes + u16-LE uncompressed length).
 *
 * Verified end-to-end: the base32hex string round-trips through the real
 * `bysquare` decoder (see examples/ and tests/run-tests.php).
 *
 * Requires the `xz` binary (xz-utils). Path override: XZ_PATH env var.
 */
final class Lzma1
{
    /**
     * @param string $data    raw bytes to compress
     * @param int    $optimum 7-Zip-style level (0..6); the bysquare-mandated
     *                         lc/lp/pb/dict are fixed regardless (xz --lzma1
     *                         presets have no equivalent speed knob on this
     *                         code path — keep the fixed key set).
     * @return string raw LZMA1 body (binary)
     * @throws \RuntimeException when xz is missing or fails
     */
    public static function compress(string $data, int $optimum = 3): string
    {
        if ($data === '') {
            throw new \InvalidArgumentException('Lzma1::compress(): empty input');
        }

        // bysquare mandates lc/lp/pb/dict (props=0x1E, dict=128 KiB) — keep the
        // exact fixed key set from the reference implementation that is known
        // to work on shared hosting. (xz --lzma1= is strict: unknown keys abort.)
        $xz  = getenv('XZ_PATH') ?: 'xz';
        $cmd = [
            $xz,
            '--format=raw',
            '--lzma1=lc=3,lp=0,pb=2,dict=128KiB',
            '-c',       // write compressed data to stdout
            '-',        // read input from stdin
        ];

        $pipes = null;
        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],   // stdin  <- $data
            1 => ['pipe', 'w'],   // stdout -> raw LZMA1 body
        ], $pipes);

        if (!is_resource($proc)) {
            throw new \RuntimeException(
                'Lzma1: failed to start xz (proc_open returned null) — is `xz` installed?'
            );
        }

        fwrite($pipes[0], $data);
        fclose($pipes[0]);

        $body = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $status = proc_close($proc);
        if ($status !== 0) {
            throw new \RuntimeException(
                'LZMA1 (xz) failed (exit ' . $status . '); is xz installed with --lzma1 support?'
            );
        }
        if ($body === false || $body === '') {
            throw new \RuntimeException('Lzma1 (xz) produced no output');
        }
        return $body;
    }
}
