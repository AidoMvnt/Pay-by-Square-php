<?php
declare(strict_types=1);
/**
 * Pay by Square - decorated QR generator (CLI).
 *   php bin/qr.php                      # demo -> out/{svg,png,bmp1,json}
 *   php bin/qr.php --amount 123.45 --vs 2026090114 --iban SK39...
 *   php bin/qr.php --light              # white page bg
 *   php bin/qr.php --module-scale 8     # px per QR module (default 10)
 *
 * Native pure-PHP PNG + 1-bit BMP rendering - no Pillow / no shell-out.
 */
require __DIR__ . '/../src/QrCode.php';
require __DIR__ . '/../src/PayBySquare.php';
require __DIR__ . '/../src/Base32Hex.php';
require __DIR__ . '/../src/Png.php';
require __DIR__ . '/../src/Assets.php';
require __DIR__ . '/../src/Render.php';

use Pbs\{Payment, PayBySquare, QrCode, Render};

function argvOpt(string $k): ?string { $i = array_search('--' . $k, $GLOBALS['argv']); return ($i !== false && isset($GLOBALS['argv'][$i+1])) ? $GLOBALS['argv'][$i+1] : null; }
function argvFlag(string $k): bool  { return in_array('--' . $k, $GLOBALS['argv'], true); }

$p = new Payment();
$p->amount    = argvOpt('amount') ?? '42.50';
$p->currency  = 'EUR';
$p->dueDate   = argvOpt('due')    ?? '20260930';
if (($vs = argvOpt('vs')) !== null) $p->setVariableSymbol($vs);
if (($cc = argvOpt('cc')) !== null && preg_match('/^\d{1,10}$/', $cc)) $p->setConstantSymbol($cc);
$p->paymentNote = argvOpt('note')  ?? 'Faktura 14/2026';
$p->payeeName   = argvOpt('payee') ?? 'Mainvent s.r.o.';
$p->payeeCity   = argvOpt('city')  ?? 'Bratislava';
$iban = argvOpt('iban') ?? 'SK6807200002891987426353';
$bic  = argvOpt('bic')  ?? (Pbs\Bics::lookup($iban) ?? '');
$p->addAccount($iban, $bic);

$qrs = PayBySquare::encode($p);
$qr  = QrCode::encode($qrs, 3, QrCode::MASK_AUTO, true);
echo "pbstring : $qrs\n";
echo "qr       : v{$qr['version']} {$qr['size']}x{$qr['size']} mask {$qr['mask']}\n";

$scale = max(1, (int)(argvOpt('module-scale') ?? 10));
$page  = argvFlag('light') ? [255, 255, 255] : [0x21, 0x21, 0x21];
$opts  = ['moduleScale' => $scale, 'page' => $page];

$outDir  = __DIR__ . '/../out';
@mkdir($outDir, 0775, true);

$pngPath  = $outDir . '/05-payment.png';
Render::savePng($qr['modules'], $pngPath, $opts);
$bmp1Path = $outDir . '/05-payment.bmp1';
Render::saveBmp1($qr['modules'], $bmp1Path, $opts);
$svgPath  = $outDir . '/05-payment.svg';
$jsonPath = $outDir . '/05-payment.json';

$svg = Render::toSvg($qr['modules'], $qr['size'], [
    'moduleScale' => 5, 'borderPx' => 5, 'padPx' => 24, 'capFontPx' => 34, 'iconW' => 56,
]);
file_put_contents($svgPath, $svg);
file_put_contents($jsonPath, json_encode([
    'pbstring' => $qrs,
    'size'     => $qr['size'],
    'modules'  => array_map(fn($r) => array_map(fn($c) => (int)(bool)$c, $r), $qr['modules']),
]));

echo "png      : $pngPath (" . filesize($pngPath) . " bytes)\n";
echo "bmp1     : $bmp1Path (" . filesize($bmp1Path) . " bytes)\n";
echo "svg      : $svgPath (" . filesize($svgPath) . " bytes)\n";
echo "json     : $jsonPath\n";
echo "OK\n";
