<?php
declare(strict_types=1);
namespace Pbs;

/**
 * Slovak BIC dictionary (bank-code prefix -> BIC) — mirrors the C#
 * PayBySquare reference (source: official bysquare.sk implementation).
 */
final class Bics
{
    private const TABLE = [
        '0200' => 'SUBASKBX', '0720' => 'NBSBSKBX', '0900' => 'GIBASKBX',
        '1100' => 'TATRSKBX', '1111' => 'UNCRSKBX', '2010' => 'FIOBCZPP',
        '3000' => 'SLZBSKBA', '3100' => 'LUBASKBX', '5200' => 'OTPVSKBX',
        '5600' => 'KOMASK2X', '5900' => 'PRVASKBA', '6500' => 'POBNSKBA',
        '7300' => 'INGBSKBX', '7500' => 'CEKOSKBX', '7930' => 'WUSTSKBA',
        '8020' => 'CRLYSKBX', '8050' => 'COBASKBX', '8100' => 'KOMBSKBA',
        '8120' => 'BSLOSK22', '8130' => 'CITISKBA', '8160' => 'EXSKSKBX',
        '8170' => 'KBSPSKBX', '8180' => 'SPSRSKBA', '8300' => 'HSBCSKBA',
        '8320' => 'JTBPSKBA', '8330' => 'FIOZSKBA', '8350' => 'ABNASKBX',
        '8360' => 'BREXSKBX', '8370' => 'OBKLSKBA', '8390' => 'AKCTCZ21',
        '8410' => 'RIDBSKBX', '8420' => 'BFKKSKBB', '8430' => 'KODBSKBX',
        '8440' => 'BNPASA',   '9950' => 'FDXXSKBA', '9951' => 'XBRASKB1',
        '9952' => 'TPAYSKBX',
    ];

    public static function lookup(string $iban): ?string
    {
        $clean = strtoupper(preg_replace('/\s+/', '', $iban));
        if (str_starts_with($clean, 'SK') && strlen($clean) >= 8) {
            return self::TABLE[substr($clean, 4, 4)] ?? null;
        }
        return null;
    }

    /** Public test hook. */
    public static function table(): array { return self::TABLE; }
}
