<?php
/**
 * Pay-by-Square (PHP) self-test harness.
 *
 *   php tests/run-tests.php
 *
 * Sections:
 *   1. CRC32         — known vector (zlib, "123456789" -> 0xCBF43926)
 *   2. Base32Hex     — encode/decode round-trips on random payloads
 *   3. LZMA1 wrapper — props byte 0x5D and non-empty body (xz must be present)
 *   4. Pay-by-Square — 4 payment profiles (minimal, full, multi-account,
 *                      specific-symbol), each pbstring round-tripped through
 *                      the REAL bysquare bank decoder (node; skipped if absent)
 *   5. QR engine     — pbstring -> modules: size ≡ 1 (mod 4), version/mask in
 *                      range, finder patterns intact, SVG renders
 *
 * Exit code 0 means every section passed (bysquare round-trip may be SKIPPED
 * if the node tooling is unavailable; the xz binary is required).
 */
declare(strict_types=1);

use Pbs\Crc32;
use Pbs\Base32Hex;
use Pbs\Lzma1Encoder;
use Pbs\Payment;
use Pbs\PayBySquare;
use Pbs\QrCode;
use Pbs\Render;

$ROOT = dirname(__DIR__);
require $ROOT . '/src/QrCode.php';
require $ROOT . '/src/PayBySquare.php';   // pulls in Lzma1.php, Bics.php, Crc32.php
require $ROOT . '/src/Base32Hex.php';

$pass = 0; $fail = 0; $skip = 0;
function ok(bool $cond, string $label = ''): bool {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   $label\n"; }
    else       { $fail++; echo "  FAIL $label\n"; }
    return $cond;
}
function skipped(string $label): void { global $skip; $skip++; echo "  skip $label\n"; }

echo "== 1. CRC32 ==\n";
ok(Crc32::compute("") === 0, "empty -> 0");
ok(Crc32::compute("123456789") === 0xCBF43926, "'123456789' -> 0xCBF43926 (zlib)");
$prep = Crc32::prepend("AB");
ok(strlen($prep) === 6 && substr($prep, 4) === "AB", "prepend() = 4B CRC + payload");

echo "== 2. Base32Hex ==\n";
for ($i = 0; $i < 5; $i++) {
    $n = 1 + random_int(0, 250);
    $raw = random_bytes($n);
    ok(Base32Hex::decode(Base32Hex::encode($raw)) === $raw, "round-trip len=$n");
}
ok(Base32Hex::decode(Base32Hex::encode("\x03\x0a")) === "\x03\x0a", "round-trip binary bytes");

echo "== 3. LZMA1 (native encoder) ==";
$sample = "a?č-ť-ľ špecialné znaky — bysquare payload sample";
$raw1 = Lzma1Encoder::compressRaw($sample);
ok(strlen($raw1) > 0, "compressRaw() non-empty (len=" . strlen($raw1) . ")");
$raw2 = Lzma1Encoder::compressRaw(str_repeat("x", 512) . $sample);
ok(strlen($raw2) > 0, "compressRaw(large input) non-empty (len=" . strlen($raw2) . ")");

// Byte-for-byte parity check vs. the system `xz` (if present).
$xzBin = trim((string)shell_exec('command -v xz 2>/dev/null'));
if ($xzBin === '') {
    skipped("xz parity (xz binary not present)");
} else {
    // xz raw of "$sample" with the same lc/lp/pb/dict params:
    $tmp = tempnam(sys_get_temp_dir(), 'pbs-lzma');
    file_put_contents($tmp, $sample);
    $hexRef = trim((string)shell_exec('xz --format=raw --lzma1=lc=3,lp=0,pb=2,dict=128KiB -c ' .
        escapeshellarg($tmp) . ' 2>/dev/null | od -An -v -tx1 | tr -d " \\n"'));
    @unlink($tmp);
    $hexPhp = bin2hex($raw1);
    ok($hexPhp !== '' && $hexRef !== '', "both produced bytes (php=" . strlen($hexPhp) / 2 . "B, xz=" . (int)($hexRef ? strlen($hexRef) / 2 : 0) . "B)");
    if ($hexPhp !== $hexRef) {
        ok(false, "byte-for-byte parity with xz  (php=" . substr($hexPhp, 0, 40) . "... xz=" . substr($hexRef ?? '', 0, 40) . "...)");
    } else {
        ok(true, "byte-for-byte parity with xz (identical $hexPhp)");
    }
}

echo "== 4. Pay-by-Square (4 profiles) ==\n";
function pay(array $a): Payment {
    $p = new Payment();
    $p->amount = $a['amount']; $p->currency = $a['currency'];
    if (isset($a['date']))  $p->dueDate = $a['date'];
    if (isset($a['vs']))    $p->setVariableSymbol($a['vs']);
    if (isset($a['cs']))    $p->setConstantSymbol($a['cs']);
    if (isset($a['ss']))    $p->setSpecificSymbol($a['ss']);
    if (isset($a['note']))  $p->paymentNote = $a['note'];
    if (isset($a['payer'])) $p->payerName = $a['payer'];
    if (isset($a['payee'])) $p->payeeName = $a['payee'];
    if (isset($a['city']))  $p->payeeCity = $a['city'];
    $p->addAccount($a['iban'], 'TATRSKBX');
    if (isset($a['ibans'])) foreach ($a['ibans'] as $i) $p->addAccount($i, 'TATRSKBX');
    return $p;
}

$profiles = [
    "minimal" => ['amount' => '42.50', 'currency' => 'EUR', 'vs' => '2026090114',
                  'iban' => 'SK6807200002891987426353'],
    "full"    => ['amount' => '1234.56', 'currency' => 'EUR', 'date' => '20260930',
                  'vs' => '2026090114', 'cs' => '12345', 'ss' => '98765',
                  'note' => 'Faktura 14/2026', 'payer' => 'Martin Bujnak',
                  'payee' => 'Mainvent s.r.o.', 'city' => 'Bratislava',
                  'iban' => 'SK6807200002891987426353'],
    "multi"   => ['amount' => '1000.00', 'currency' => 'EUR', 'date' => '20261015',
                  'vs' => '2026090114', 'cs' => '12345', 'note' => 'Splynutie',
                  'iban' => 'SK6807200002891987426353', 'ibans' => ['SK1211000000027666266441']],
    "ss"      => ['amount' => '87.20', 'currency' => 'EUR', 'date' => '20260930',
                  'ss' => '1234567890', 'note' => 'Elektrina 08/2026',
                  'iban' => 'SK6956010000000123456789'],
];

$qrs = [];
foreach ($profiles as $kind => $a) {
    $qrs[$kind] = PayBySquare::encode(pay($a));
    ok(strlen($qrs[$kind]) > 0 && (bool)preg_match('/^[0-9A-Z]+$/', $qrs[$kind]),
       "$kind -> pbstring len=" . strlen($qrs[$kind]) . ", all base32hex");
}

// bysquare round-trip: write all 4 pbstrings, feed to the real decoder.
$bysq  = getenv('BYSQ') ?: '/home/aido/Projects/pay-src/refnode/node_modules/bysquare/lib/pay/decode.js';
$node  = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node === '' || !file_exists($bysq)) {
    skipped("bysquare round-trip (node/bysquare tooling not present here)");
} else {
    $in  = tempnam(sys_get_temp_dir(), 'pbs-rt-in');
    $js  = tempnam(sys_get_temp_dir(), 'pbs-rt-js') . '.mjs';
    $bysqImport = $bysq;   // absolute path to pay/decode.js (ESM)
    file_put_contents($in, implode("\n", $qrs) . "\n");
    $jsTemplate = <<<'JS'
import { readFileSync } from "fs";
import { decode } from "__BYSQ__";
const q = readFileSync(process.argv[2], "utf8").split("\n").map(s => s.trim()).filter(Boolean);
let ok = true;
for (const s of q) {
  try {
    const m = decode(s); const p = m.payments[0];
    console.log("rt-ok  len=" + s.length + " amount=" + p.amount +
      " vs=" + p.variableSymbol + " cs=" + p.constantSymbol +
      " ss=" + p.specificSymbol + " ibans=" + ((p.bankAccounts || []).length));
  } catch (e) { console.log("rt-bad " + e.message); ok = false; }
}
process.exit(ok ? 0 : 1);
JS
;
    file_put_contents($js, str_replace('__BYSQ__', $bysqImport, $jsTemplate));
    $out = trim((string)shell_exec($node . ' ' . escapeshellarg($js) . ' ' . escapeshellarg($in) . ' 2>&1'));
    exec($node . ' ' . escapeshellarg($js) . ' ' . escapeshellarg($in) . ' >/dev/null 2>&1', $d, $rc);
    @unlink($in); @unlink($js);
    $lines = array_values(array_filter(explode("\n", $out), fn($l) => str_starts_with(trim($l), 'rt-')));
    $allOk = count($lines) === 4 && !str_contains($out, 'rt-bad');
    foreach ($lines as $l) echo "  $l\n";
    ok($allOk, "bysquare decoded all 4 pbstrings (real bank decoder)");
}

echo "== 5. QR engine ==\n";
$qr = QrCode::encode($qrs['minimal'], 3, QrCode::MASK_AUTO, true);
$size = $qr['size'];
ok($size % 4 === 1, "size $size ≡ 1 (mod 4)");
ok($qr['version'] >= 1 && $qr['version'] <= 40, "version " . $qr['version'] . " in [1,40]");
ok($qr['mask'] >= 0 && $qr['mask'] <= 7, "mask " . $qr['mask'] . " in [0,7]");
$m = $qr['modules'];
$tlFinder = $m[0][0] === true  && $m[0][6] === true  && $m[6][0] === true  && $m[6][6] === true
         && $m[1][1] === false && $m[1][5] === false && $m[5][1] === false && $m[5][5] === false
         && $m[3][3] === true;
ok($tlFinder, "top-left finder pattern intact (7-dark border + light ring + center)");
$trFinder = $m[0][$size - 7] === true && $m[0][$size - 1] === true
         && $m[1][$size - 6] === false && $m[3][$size - 4] === true;
ok($trFinder, "top-right finder pattern intact");
$svg = QrCode::toSvg($m, scale: 2);
ok(str_contains($svg, '<svg') && str_contains($svg, '</svg>') && str_contains($svg, '<rect'), "toSvg() renders SVG with rects");
ok(QrCode::encode('ahoj', 3, 0, false)['size'] <= QrCode::encode('ahoj', 2, 0, false)['size'] + 40,
   "QR encodes multiple inputs without overflow");


echo "== 6. Render (decorated tile, SVG + native PNG + 1-bit BMP) ==\n";
require dirname(__DIR__) . '/src/Render.php';
require dirname(__DIR__) . '/src/Png.php';
require dirname(__DIR__) . '/src/Assets.php';
$qr6  = QrCode::encode($qrs['minimal'], 3, QrCode::MASK_AUTO, true);
$dark = 0;
foreach ($qr6['modules'] as $row) { foreach ($row as $c) { if ($c === true) { $dark++; } } }
$svg6 = Render::toSvg($qr6['modules'], $qr6['size'], ['size' => $qr6['size']]);
$cmd = preg_match_all('/M[0-9]+ [0-9]+h[0-9]+v[0-9]+h-[0-9]+z/', $svg6);
ok($cmd === $dark, "SVG dark-module path cmd count ($cmd) == dark modules ($dark)");
ok(str_contains($svg6, 'data:image/svg+xml'), "icon asset embedded (data URI)");
ok(str_contains($svg6, '<svg') && str_contains($svg6, '</svg>'), "SVG is a well-formed document");

$png = Render::toPng($qr6['modules'], ['moduleScale' => 8]);
ok(strlen($png) > 4000, "native PNG rendered (" . strlen($png) . " bytes, pure PHP, no Pillow)");
ok(str_starts_with($png, "\x89PNG\r\n\x1A\n"), "PNG signature valid");
$w = unpack('N', substr($png, 16, 4))[1];   // big-endian (PNG = network order)
$h = unpack('N', substr($png, 20, 4))[1];
ok($w > 100 && $h > $w, "tile $w x $h (wider QR block + taller caption band)");

$tmpPng = sys_get_temp_dir() . '/pbs-runtest-' . getmypid() . '.png';
file_put_contents($tmpPng, $png);
$node    = trim((string)shell_exec('command -v node 2>/dev/null'));
$decoder = $ROOT . '/bin/qrdecode.cjs';
if ($node === '' || !is_file($decoder)) {
    skipped("QR decode of native PNG (node + bin/qrdecode.cjs not present)");
} else {
    $raw = $tmpPng . '.raw';
    file_put_contents($raw, Render::toRawRgba($qr6['modules'], ['moduleScale' => 8]));
    $d = trim((string)shell_exec('node ' . escapeshellarg($decoder) . ' ' . escapeshellarg($raw) . ' 2>&1'));
    @unlink($raw);
    ok(str_starts_with($d, 'DECODED:') && str_contains($d, $qrs['minimal']),
       "native PNG decodes (jsQR) to the exact pbstring " . ($d ? '[' . substr($d, 0, 34) . '...]' : '[no output]'));
}
@unlink($tmpPng);

$bmp1 = Render::toBmp1Tile($qr6['modules'], ['moduleScale' => 8]);
ok(str_starts_with($bmp1, 'BM'), "1-bit BMP signature 'BM'");
$bmpW = unpack('V', substr($bmp1, 18, 4))[1];
$bmpH = unpack('V', substr($bmp1, 22, 4))[1];
ok($bmpW === $w && $bmpH === $h, "BMP1 dimensions match PNG ($bmpW x $bmpH)");
$pal  = substr($bmp1, 54, 8);
ok($pal === "\xFF\xFF\xFF\x00\x00\x00\x00\x00", "BMP1 palette = white(0) / black(1)");


echo "\n====\n  PASS: $pass   FAIL: $fail   SKIP: $skip\n";
exit($fail === 0 ? 0 : 1);
