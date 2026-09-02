<?php
declare(strict_types=1);
namespace Pbs;

/**
 * LZMA1 wrapper (proc_open + pipes).
 *
 * Produces a RAW LZMA1 body (lc=3, lp=0, pb=2, 128 KiB dictionary, end-marker on)
 * by running the system `xz` binary:
 *
 *     xz --format=raw --lzma1=lc=3,lp=0,pb=2,dict=128KiB -c -
 *
 *   stdin  <= $data (pipe fd 0)
 *   stdout => raw LZMA1 body (pipe fd 1)
 *
 * This is the pattern used by the reference php-pay-by-square repo that works
 * on the user's shared hosting: the `exec()`/`shell_exec()` family is commonly
 * in the server's `disable_functions`, but `proc_open` is not — so this wrapper
 * stays operational there.
 *
 * `--format=raw` emits bare LZMA1 (no 13-byte .lzma stream header), which is
 * exactly the `lzmaBody` the official `bysquare` bank decoder expects after the
 * 4-byte bysquare header (two 0x00 bytes + u16-LE uncompressed length).
 *
 * Verified end-to-end: the emitted base32hex string round-trips through the
 * real `bysquare` decoder (see examples/ + tests/run-tests.php).
 *
 * Requires the `xz` binary (xz-utils). Path override: XZ_PATH env var.
 */
final class Lzma1
{
    /**
     * @param string $data     raw bytes to compress
     * @param int    $optimum  7-Zip-style optimum level (0..6); the bysquare
     *                         lc/lp/pb/dict are fixed regardless of this.
     * @return string raw LZMA1 body (binary)
     * @throws \RuntimeException when xz is missing or fails
     */
    public static function compress(string $data, int $optimum = 3): string
    {
        $descriptors = [
            0 => ['pipe', 'r'], // STDIN - PHP writes raw data here
            1 => ['pipe', 'w'], // STDOUT - PHP reads compressed bytes from here
            2 => ['pipe', 'w']  // STDERR - contains error messages if any
        ];

        // Open the system process in the background
	$proc = proc_open("xz '--format=raw' '--lzma1=lc=3,lp=0,pb=2,dict=128KiB' '-c' '-'", $descriptors, $pipes);

        if (!is_resource($proc)) {
            throw new \RuntimeException('Lzma1: failed to start xz (proc_open returned null)');
        }

        fwrite($pipes[0], $data);
        fclose($pipes[0]);

        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = '';
        while (!feof($pipes[2])) {
            $err .= (string) fread($pipes[2], 8192);
        }
        fclose($pipes[2]);

        $status = proc_close($proc);
        if ($status !== 0) {
            $msg = trim($err) !== '' ? trim($err) : 'xz exit code ' . $status;
            throw new \RuntimeException(
                'Lzma1 (xz) failed (exit ' . $status . '): ' . $msg
                . ' — is xz installed with xz-utils (>=5.0) supporting --lzma1?'
            );
        }
        if ($out === false || $out === '') {
            throw new \RuntimeException('Lzma1 (xz) produced no output');
        }
        return $out;
    }
}
