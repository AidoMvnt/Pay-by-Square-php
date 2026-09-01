<?php
/**
 * 02 – Full payment with every field.
 *
 * Amount, currency, due date, variable + constant + specific symbols,
 * payment note, payer, payee (name / street / city), single account.
 * Prints the base32hex QR string and a readable breakdown.
 *
 *   php examples/02-full.php
 */
declare(strict_types=1);

require __DIR__ . '/../src/QrCode.php';
require __DIR__ . '/../src/PayBySquare.php';

use Pbs\Payment;
use Pbs\PayBySquare;

$p = new Payment();
$p->invoiceId  = 'INV-2026-14';
$p->amount     = '1234.56';
$p->currency   = 'EUR';
$p->dueDate    = '20260930';
$p->setVariableSymbol('2026090114');
$p->setConstantSymbol('12345');
$p->setSpecificSymbol('98765');
$p->paymentNote = 'Faktura 14/2026 za dodávky';
$p->payerName   = 'Martin Bujnak';
$p->payeeName   = 'Mainvent s.r.o.';
$p->payeeStreet = 'Priemyselná 12';
$p->payeeCity   = '604 00 Bratislava';
$p->addAccount('SK6807200002891987426353', 'TATRSKBX');

try {
    $qrs = PayBySquare::encode($p);
    fwrite(STDERR, "pbstring ({$p->amount} {$p->currency}, VS {$p->getVariableSymbol()}):\n$qrs\n\n");
    echo "OK\n";
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, 'Validation failed: ' . $e->getMessage() . "\n");
    exit(2);
}
