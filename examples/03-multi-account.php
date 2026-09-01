<?php
/**
 * 03 – Multiple destination accounts.
 *
 * One payment routed to several IBANs at once (bysquare splits the amount
 * across them at the bank). Shows how the ordered `bankAccounts[]` list is
 * passed through and how the QR grows with each additional account.
 *
 *   php examples/03-multi-account.php
 */
declare(strict_types=1);

require __DIR__ . '/../src/QrCode.php';
require __DIR__ . '/../src/PayBySquare.php';

use Pbs\Payment;
use Pbs\PayBySquare;

$p = new Payment();
$p->amount = '1000.00';
$p->currency = 'EUR';
$p->dueDate = '20261015';
$p->setVariableSymbol('2026090114');
$p->setConstantSymbol('12345');
$p->paymentNote = 'Splynutie dvoch faktúr';
$p->payerName = 'Martin Bujnak';
$p->payeeName = 'Mainvent s.r.o.';
$p->payeeCity = 'Bratislava';

// Two (real, different) Slovak SK IBANs — the bank will split 1000.00 EUR
// across both accounts as configured by the receiving institution.
$p->addAccount('SK6807200002891987426353', 'TATRSKBX');
$p->addAccount('SK1211000000027666266441', 'SBKRSKBX');

$qrs = PayBySquare::encode($p);
fwrite(STDERR, sprintf(
    "multi-account payment (2 IBANs):\n%s\n\n",
    $qrs
));
echo 'OK (' . strlen($qrs) . " base32hex chars, " . count($p->bankAccounts) . ' accounts)' . "\n";
