<?php
/**
 * 01 – Minimal Pay by Square QR.
 *
 * The smallest payment that produces a valid base32hex `pbstring`: one
 * IBAN, one amount, one variable symbol. Runs on stock PHP with system xz.
 *
 *   php examples/01-minimal.php
 */
declare(strict_types=1);

require __DIR__ . '/../src/QrCode.php';
require __DIR__ . '/../src/PayBySquare.php';

use Pbs\Payment;
use Pbs\PayBySquare;

$p = new Payment();
$p->amount = '42.50';
$p->currency = 'EUR';
$p->setVariableSymbol('2026090114');
$p->addAccount('SK6807200002891987426353', 'TATRSKBX');

$qrs = PayBySquare::encode($p);
echo "pbstring: $qrs\n";
echo strlen($qrs) . " base32hex chars\n";
echo "OK\n";
