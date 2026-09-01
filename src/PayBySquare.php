<?php
declare(strict_types=1);
namespace Pbs;

require_once __DIR__ . '/Lzma1.php';
require_once __DIR__ . '/Bics.php';

/**
 * Bank account (IBAN + optional BIC). If BIC is empty,
 * `PayBySquare::encode` looks it up from the standard Slovak dictionary.
 */
final class BankAccount
{
    public string $iban = '';
    public string $bic  = '';

    public function __construct(string $iban, string $bic = '')
    {
        $this->iban = $iban;
        $this->bic  = $bic;
    }
}

/**
 * A single Pay by Square payment input.
 * Symbols are validated (digits only, max length, trimmed) — same rules
 * as the C# reference and the SK bank standard.
 */
final class Payment
{
    public string $invoiceId = '';
    public string $amount    = '0.00';
    public string $currency  = 'EUR';
    public string $dueDate   = '';          // YYYYMMDD
    private string $variableSymbol = '';
    private string $constantSymbol = '';
    private string $specificSymbol = '';
    public string $paymentNote = '';
    public string $payerName   = '';
    public string $payeeName   = '';
    public string $payeeStreet = '';
    public string $payeeCity   = '';
    /** Ordered list of destination accounts. */
    public array $bankAccounts = [];

    public function setVariableSymbol(string $s): self { $this->variableSymbol = $this->checkSymbol($s, 'VariableSymbol', 28); return $this; }
    public function setConstantSymbol(string $s): self { $this->constantSymbol = $this->checkSymbol($s, 'ConstantSymbol', 5);   return $this; }
    public function setSpecificSymbol(string $s): self { $this->specificSymbol = $this->checkSymbol($s, 'SpecificSymbol', 10); return $this; }

    public function getVariableSymbol(): string { return $this->variableSymbol; }
    public function getConstantSymbol(): string { return $this->constantSymbol; }
    public function getSpecificSymbol(): string { return $this->specificSymbol; }

    public function addAccount(string $iban, string $bic = ''): self
    {
        $this->bankAccounts[] = new BankAccount($iban, $bic);
        return $this;
    }

    private function checkSymbol(string $value, string $name, int $maxLen): string
    {
        $v = trim($value ?: '');
        if ($v !== '' && !preg_match('/^\d+$/', $v)) {
            throw new \InvalidArgumentException("$name must contain digits only (got \"$value\").");
        }
        if (strlen($v) > $maxLen) {
            throw new \InvalidArgumentException("$name exceeds $maxLen digits (got " . strlen($v) . ").");
        }
        return $v;
    }
}

/**
 * Pay by Square — encoder producing a base32hex QR content string.
 *
 * Wire format (bysquare.sk, byte-verified against the C# reference
 * implementation that round-trips through the official bank decoder):
 *
 *   00 07 | u16-LE(len of payload) | raw LZMA1 body (lc=3, lp=0, pb=2, dict 128 KiB)
 *
 * where payload = 4-byte little-endian CRC32 over UTF-8(tab-separated fields)
 * followed by that UTF-8, and the whole thing is base32hex encoded
 * (big-endian, 5 bits/group, zero-padded).
 */
final class PayBySquare
{
    private const B32 = "0123456789ABCDEFGHIJKLMNOPQRSTUV";
    private const MAX_RAW = 65535;

    /**
     * Generate the base32hex Pay by Square QR content string.
     * Requires at least one bank account on $pay.
     *
     * Field order (tab-separated, from bysquare.sk spec — same as the C# ref):
     *   invoiceId, "1" (numPayments), "1" (type: instant), amount, currency, dueDate,
     *   variableSymbol, constantSymbol, specificSymbol, "" (SEPA, unused), note,
     *   numAccounts, then per account: [iban, bic]*,
     *   "0" (standing-order:no), "0" (direct-debit:no),
     *   payeeName, payeeStreet, payeeCity.
     */
    public static function encode(Payment $pay): string
    {
        if (count($pay->bankAccounts) === 0) {
            throw new \InvalidArgumentException('At least one bank account (IBAN) is required.');
        }

        // 1) Field string
        $f = [
            $pay->invoiceId,
            "1",                       // number of payments
            "1",                       // type: 1 = instant
            $pay->amount,
            $pay->currency,
            $pay->dueDate,
            $pay->getVariableSymbol(),
            $pay->getConstantSymbol(),
            $pay->getSpecificSymbol(),
            "",                        // SEPA ref (unused)
            $pay->paymentNote,
            (string) count($pay->bankAccounts),
        ];
        foreach ($pay->bankAccounts as $a) {
            $f[] = $a->iban;
            $f[] = $a->bic !== '' ? $a->bic : (Bics::lookup($a->iban) ?? '');
        }
        $f[] = "0";                     // standing order: no
        $f[] = "0";                     // direct debit:  no
        $f[] = $pay->payeeName;
        $f[] = $pay->payeeStreet;
        $f[] = $pay->payeeCity;
        $tabbed = implode("\t", $f);
        $utf8   = $tabbed; // UTF-8 by definition in PHP

        // 2) CRC32 (little-endian) prepended
        $crc = self::crc32($utf8);
        $payload = "\x00\x00\x00\x00" . $utf8;
        // write 4 bytes LE
        $payload[0] = chr($crc & 0xFF);
        $payload[1] = chr(($crc >> 8) & 0xFF);
        $payload[2] = chr(($crc >> 16) & 0xFF);
        $payload[3] = chr(($crc >> 24) & 0xFF);
        $plen = strlen($payload);
        if ($plen > self::MAX_RAW) {
            throw new \InvalidArgumentException('Payload too large for Pay by Square.');
        }

        // 3) LZMA1 raw (lc=3, lp=0, pb=2, dict=128 KiB) via pure-PHP port
        $body = Lzma1::compress($payload, 5);
        if (strlen($body) < 13) {
            throw new \RuntimeException("LZMA body too short: " . strlen($body));
        }

        // 4) Header: 00 00 + u16-LE(payload length)  [C# ground truth]
        $out = "\x00\x00" . chr($plen & 0xFF) . chr(($plen >> 8) & 0xFF) . $body;

        // 5) base32hex encode
        return self::base32HexEncode($out);
    }

    /** base32hex encode — C# big-endian, zero-padded (NOT bysquare v4's LSB-first). */
    public static function base32HexEncode(string $data): string
    {
        $out = '';
        $acc = 0; $bits = 0;
        $n = strlen($data);
        for ($i = 0; $i < $n; $i++) {
            $b = ord($data[$i]);
            for ($j = 7; $j >= 0; $j--) {
                $acc = ($acc << 1) | (($b >> $j) & 1);
                $bits++;
                if ($bits === 5) {
                    $out .= self::B32[$acc & 0x1F];
                    $acc = 0; $bits = 0;
                }
            }
        }
        if ($bits > 0) {
            $acc <<= (5 - $bits);
            $out .= self::B32[$acc & 0x1F];
        }
        return $out;
    }

    public static function base32HexDecode(string $s): string
    {
        $bytes = str_repeat("\0", (int) ((strlen($s) * 5) / 8));
        $acc = 0; $bits = 0;
        $total = strlen($bytes);
        for ($i = 0; $i < strlen($s); $i++) {
            $ch = $s[$i];
            $v = strpos(self::B32, $ch);
            if ($v === false) throw new \ValueException("Invalid base32hex char '$ch'");
            $acc = ($acc << 5) | $v;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $idx = ($total * 8) - ($bits + 8);
                $bytes[(int) ($idx / 8)] = chr(($acc >> $bits) & 0xFF);
            }
        }
        return $bytes;
    }

    public static function lookUpBic(string $iban): ?string { return Bics::lookup($iban); }

    private static function crc32(string $d): int
    {
        // PHP's native crc32 is fine; normalize to unsigned
        return (int) crc32($d); // PHP returns a signed int; we need bit-cast
    }
}
