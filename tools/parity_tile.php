<?php
declare(strict_types=1);
/**
 * C# -> PHP tile parity harness (dev tool, not part of the public API).
 *
 * Usage:
 *   php tools/parity_tile.php <cs-tile-24bit.bmp> [cs-tile-1bit.bmp]
 *
 * Extracts the QR grid out of a C# reference tile, re-renders it natively
 * (Render::toPng / toBmp1Tile, scale=10, page auto-detected from the
 * corner pixel), then compares pixel-for-pixel against the C# image.
 * Also writes the re-renders to /tmp/php_parity_* for the eyes.
 *
 * Exit 0 = every comparison identical.
 */
$ROOT = dirname(__DIR__);
require $ROOT . '/src/Png.php';
require $ROOT . '/src/Assets.php';
require $ROOT . '/src/Render.php';
use Pbs\Render;

if (($argv[1] ?? null) === null || ($argv[2] ?? null) === null) {
    fwrite(STDERR, "usage: parity_tile.php <cs24.bmp> <grid.txt> [cs1.bmp]\n"); exit(2); }
$src24 = $argv[1]; $src1 = $argv[3] ?? null;
if (!is_file($src24) || !is_file($argv[2])) { fwrite(STDERR, "missing input\n"); exit(2); }

// ---- grid file (from tools/extract_grid.py against the C# tile BMP) ----
$gridFile = $argv[2] ?? '/tmp/grid_n37.txt';
if (!is_file($gridFile)) { fwrite(STDERR, "no grid file at $gridFile (run tools/extract_grid.py first)\n"); exit(2); }
$gridLines = array_values(array_filter(array_map('trim', file($gridFile)), fn($s) => $s !== ''));
$n = count($gridLines);
$modules = [];
foreach ($gridLines as $ln) {
    $row = [];
    for ($i = 0; $i < strlen($ln); $i++) $row[] = ($ln[$i] === '1');
    $modules[] = $row;
}

// ---- reference BMP (C# 24-bit tile) as a flat RGB string, top-down ----
function bmpFlatRgb(string $f): array {
    $d = file_get_contents($f);
    if (substr($d, 0, 2) !== 'BM') throw new RuntimeException('not a BMP');
    $off = unpack('V', substr($d, 10, 4))[1];
    $w   = unpack('V', substr($d, 18, 4))[1];
    $h   = unpack('V', substr($d, 22, 4))[1];
    if (unpack('v', substr($d, 28, 2))[1] !== 24) throw new RuntimeException('not 24bpp');
    $out = '';
    for ($y = 0; $y < $h; $y++) {                 // bottom-up storage -> top-down out
        $base = $off + (($h - 1 - $y) * $w) * 3;
        for ($x = 0; $x < $w; $x++) {
            $i = $base + $x * 3;
            $out .= $d[$i + 2] . $d[$i + 1] . $d[$i];   // R, G, B
        }
    }
    return [$w, $h, $out, $d[0] === 'B'];
}

$src24 = $argv[1];
list($W, $H, $csFlat, $_) = bmpFlatRgb($src24);
$cornerR = ord($csFlat[0]);
$page = $cornerR > 128 ? [255,255,255] : [0x21,0x21,0x21];

// re-render natively and pick the PNG matching the reference page
$png_dark    = Render::toPng($modules, ['moduleScale' => 10, 'page' => [0x21,0x21,0x21]]);
$png_light   = Render::toPng($modules, ['moduleScale' => 10, 'page' => [255,255,255]]);
$png = ($cornerR > 128) ? $png_light : $png_dark;
file_put_contents('/tmp/php_parity_render.png', $png);

// decode our native PNG (pure PHP) and compare flat RGB strings
list($w2, $h2, $phpFlat) = pngFlatRgba($png);   // [w, h, flat-rgb]
if ($W !== $w2 || $H !== $h2) {
    fwrite(STDERR, "size mismatch: C# {$W}x{$H}  PHP {$w2}x{$h2}\n");
    exit(1);
}
$ok = ($csFlat === $phpFlat);
printf("24-bit parity : %s  (page=%s, tile=%dx%d, %d pixels)\n",
    $ok ? 'IDENTICAL' : 'DIFFER', $cornerR > 128 ? 'light' : 'dark', $W, $H, $W * $H);

// optional 1-bit byte comparison
if (($src1 ?? null) !== null && is_file($src1)) {
    $cs1 = file_get_contents($src1);
    $pph = Render::toBmp1Tile($modules, ['moduleScale' => 10, 'page' => $page]);
    $ok1 = ($cs1 === $pph);
    printf("1-bit parity  : %s  (cs=%d B, php=%d B)\n", $ok1 ? 'IDENTICAL' : 'DIFFER', strlen($cs1), strlen($pph));
    exit($ok && $ok1 ? 0 : 1);
}
exit($ok ? 0 : 1);

// ---- pure-PHP opacity decoder (8-bit RGBA, scanlines) ----
function pngFlatRgba(string $b): array {
    if (substr($b, 0, 8) !== "\x89PNG\r\n\x1A\n") throw new RuntimeException('not a PNG');
    $pos = 8; $idat = '';
    while ($pos < strlen($b)) {
        $len = unpack('N', substr($b, $pos, 4))[1];
        $typ = substr($b, $pos + 4, 4);
        if ($typ === 'IHDR') {
            $w  = unpack('N', substr($b, $pos + 8, 4))[1];
            $h  = unpack('N', substr($b, $pos + 12, 4))[1];
            $bd = ord($b[$pos + 16]);
            $ct = ord($b[$pos + 17]);
        }
        if ($typ === 'IDAT') $idat .= substr($b, $pos + 8, $len);
        $pos += 12 + $len;
        if ($typ === 'IEND') break;
    }
    if ($bd !== 8 || $ct !== 6) throw new RuntimeException("IHDR bd=$bd ct=$ct not supported");
    $scan = 1 + $w * 4;                          // filter byte + w*4 RGBA
    $raw  = inflateRaw($idat);
    $out  = '';
    for ($y = 0; $y < $h; $y++) {
        $base = $y * $scan;
        if (ord($raw[$base]) !== 0) throw new RuntimeException("filter byte $y nonzero");
        // RGBA-interleaved: take R,G,B of each pixel (skip the alpha byte)
        $line = '';
        for ($x = 0; $x < $w; $x++) {
            $i = $base + 1 + $x * 4;
            $line .= $raw[$i] . $raw[$i + 1] . $raw[$i + 2];
        }
        $out .= $line;
    }
    return [$w, $h, $out];                        // [w, h, flat-rgb-string]
}
function inflateRaw(string $zlibStream): string {
    $o = @gzuncompress($zlibStream);              // full zlib stream (0x78 0x01 + deflate + adler32)
    if ($o === false) {
        $o = @gzinflate(substr($zlibStream, 2));  // raw-deflate fallback (strip zlib header)
    }
    if ($o === false) throw new RuntimeException('inflate failed');
    return $o;
}
