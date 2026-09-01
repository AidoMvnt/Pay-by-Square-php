<?php
/**
 * 04 – Specific symbol payment (no variable symbol).
 *
 * Some invoices (e.g. recurring utility bills) are identified by a specific
 * symbol instead of a variable one. This example shows the `--ss` path and
 * exercises the 10-digit `checkSymbol` validator for the specific symbol.
 *
 *   php examples/04-specific-symbol.php
 */
declare(strict_types=1);

require __DIR__ . '/../src/QrCode.php';
require __DIR__ . '/../src/PayBySquare.php';

use Pbs\Payment;
use Pbs\PayBySquare;

$p = new Payment();
$p->amount = '87.20';
$p->currency = 'EUR';
$p->dueDate = '20260930';
$p->setSpecificSymbol('1234567890'); // 10-digit specific symbol
$p->paymentNote = 'Elektrina 08/2026';
$p->payeeName = 'SEPS Slovenská energetika';
$p->payeeCity = 'Poprad';
$p->addAccount('SK6956010000000123456789', 'TATRSKBX');

$qrs = PayBySquare::encode($p);
fwrite(STDERR, sprintf(
    "specific-symbol payment (SS %s):\n%s\n\n",
    $p->getSpecificSymbol(),
    $qrs
));
echo "OK\n";

// Negative check: an 11-digit specific symbol must be rejected.
try {
    $bad = new Payment();
    $bad->setSpecificSymbol('12345678901'); // 11 digits — over the 10-digit cap
    echo "ERROR: expected InvalidArgumentException\n";
    exit(2);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, "validator correctly rejected 11-digit SS: {$e->getMessage()}\n");
}
