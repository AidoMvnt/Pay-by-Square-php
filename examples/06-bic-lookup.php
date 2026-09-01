<?php
/**
 * 06 – BIC (bank code) lookup for Slovak IBANs.
 *
 * `Pbs\PayBySquare::lookUpBic('SK..')` returns the standard BIC derived
 * from the bank-code nibble in positions 5-8 of the IBAN, or `null` for an
 * unknown bank-code. Useful when a user gives you an IBAN and no BIC and
 * you want to pre-fill the payment.
 *
 *   php examples/06-bic-lookup.php
 */
declare(strict_types=1);

require __DIR__ . '/../src/QrCode.php';
require __DIR__ . '/../src/PayBySquare.php';

use Pbs\PayBySquare;

$iban = [
    'SK6807200002891987426353',  // 7200 = Tatra banka   ? (bank code 0720)
    'SK1211000000027666266441',  // 1100 = ?
    'SK3179000000149558831851',  // 7900 = ?
];

foreach ($iban as $iban) {
    $bic = PayBySquare::lookUpBic($iban);
    printf("%s  ->  BIC=%s\n", $iban, $bic ?? '(unknown — ask the user)');
}
echo "OK\n";
