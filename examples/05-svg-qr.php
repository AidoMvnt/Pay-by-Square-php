<?php
/**
 * 05 – Render the Pay by Square QR as an SVG file.
 *
 * Uses the pure-PHP QR engine directly (base32hex `pbstring` -> modules ->
 * SVG). Produces a scannable vector image you can print, email, or embed in
 * a PDF. Output: ./out/05-payment.svg (scale=6 by default; ~12 px per module).
 *
 *   php examples/05-svg-qr.php
 *   # then open out/05-payment.svg in any browser
 */
declare(strict_types=1);

require __DIR__ . '/../src/QrCode.php';
require __DIR__ . '/../src/PayBySquare.php';

use Pbs\Payment;
use Pbs\PayBySquare;
use Pbs\QrCode;

$p = new Payment();
$p->amount = '42.50';
$p->currency = 'EUR';
$p->dueDate = '20260930';
$p->setVariableSymbol('2026090114');
$p->setConstantSymbol('12345');
$p->paymentNote = 'Faktura 14/2026';
$p->payeeName = 'Mainvent s.r.o.';
$p->payeeCity = 'Bratislava';
$p->addAccount('SK6807200002891987426353', 'TATRSKBX');

$qrs = PayBySquare::encode($p);
echo "pbstring: $qrs\n";

$qr = QrCode::encode($qrs, 3, QrCode::MASK_AUTO, true);
$svg = QrCode::toSvg($qr['modules'], scale: 6);

$outDir = __DIR__ . '/out';
@mkdir($outDir, 0775, true);
$outPath = $outDir . '/05-payment.svg';
file_put_contents($outPath, $svg);

echo "wrote $outPath (" . strlen($svg) . " bytes, "
   . $qr['size'] . "x" . $qr['size'] . ", v" . $qr['version']
   . ", mask " . $qr['mask'] . ")\n";
echo "scan it with any phone camera to confirm it decodes.\n";
echo "OK\n";
