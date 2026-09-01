<?php
declare(strict_types=1);
namespace Pbs;

/**
 * QR Code generator — verbatim PHP port of Project Nayuki's qrcodegen (MIT).
 * Reference: https://github.com/nayuki/QR-Code-generator/blob/master/c/qrcodegen.c
 * PHP port of /home/aido/Projects/Pay-by-Square-cs/src/QrCode.cs (1:1 structure).
 * Versions 1..40, 4 ECL, numeric/alphanumeric/byte modes, Reed-Solomon, 7 masks.
 */
final class QrCode
{
    const VERSION_MIN = 1, VERSION_MAX = 40;
    const MASK_AUTO = -1, MASK_MIN = 0, MASK_MAX = 7;
    const LENGTH_OVERFLOW = -1;
    const RS_DEGREE_MAX = 30;
    const MODE_NUMERIC = 1, MODE_ALPHANUMERIC = 2, MODE_BYTE = 4;
    const PENALTY_N1 = 3, PENALTY_N2 = 3, PENALTY_N3 = 40, PENALTY_N4 = 10;
    const ALPHANUMERIC_CHARSET = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ \$%*+-./:";

    private const ECC_CODEWORDS_PER_BLOCK = [
        [-1,  7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30], // L
        [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28], // M
        [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30], // Q
        [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30], // H
    ];
    private const NUM_ERROR_CORRECTION_BLOCKS = [
        [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25], // L
        [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49], // M
        [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68], // Q
        [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81], // H
    ];

    public int $version;
    public int $size;
    public int $ecl;  // 0=L 1=M 2=Q 3=H
    public int $mask;
    /** @var bool[] flat row-major */
    public array $dark;
    /** @var bool[] flat row-major */
    public array $isFunc;

    private function __construct(int $version, int $ecl, int $mask, array $dark, array $isFunc)
    {
        $this->version = $version;
        $this->size    = $version * 4 + 17;
        $this->ecl     = $ecl;
        $this->mask    = $mask;
        $this->dark    = $dark;
        $this->isFunc  = $isFunc;
    }

    public function getModule(int $x, int $y): bool
    {
        return $x >= 0 && $x < $this->size
            && $y >= 0 && $y < $this->size
            && $this->dark[$y * $this->size + $x];
    }

    public static function toSvg(array $modules, int $scale = 4): string
    {
        $n = count($modules);
        $w = $n * $scale;
        $s = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $w
           . '" viewBox="0 0 ' . $n . ' ' . $n
           . '" shape-rendering="crispEdges">';
        $s .= '<rect width="' . $n . '" height="' . $n . '" fill="white"/>';
        $d = '';
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if (!empty($modules[$y][$x])) $d .= 'M' . $x . ' ' . $y . 'h1v1h-1z';
            }
        }
        $s .= '<path d="' . $d . '" fill="black"/>';
        $s .= '</svg>';
        return $s;
    }

    public static function encode(string $data, int $ecl = 3, int $mask = self::MASK_AUTO, bool $boostEcl = true): array
    {
        $qr = self::encodeText($data, $ecl, 1, self::VERSION_MAX, $mask, $boostEcl);
        $size = $qr->size;
        $modules = [];
        for ($y = 0; $y < $size; $y++) {
            $row = [];
            for ($x = 0; $x < $size; $x++) $row[$x] = $qr->getModule($x, $y);
            $modules[] = $row;
        }
        return [
            'modules' => $modules,
            'version' => $qr->version,
            'size'    => $size,
            'ecl'     => $qr->ecl,
            'mask'    => $qr->mask,
        ];
    }

    private static function encodeText(string $text, int $ecl, int $minVersion = 1, int $maxVersion = 40, int $mask = self::MASK_AUTO, bool $boostEcl = true): self
    {
        $text = ($text === null ? '' : $text);
        if ($minVersion < self::VERSION_MIN || $minVersion > $maxVersion) throw new \InvalidArgumentException('Invalid value for minVersion');
        if ($maxVersion < self::VERSION_MIN || $maxVersion > self::VERSION_MAX) throw new \InvalidArgumentException('Invalid value for maxVersion');
        if ($ecl < 0 || $ecl > 3) throw new \InvalidArgumentException('Invalid ECC level');
        if ($mask < self::MASK_AUTO || $mask > self::MASK_MAX) throw new \InvalidArgumentException('Invalid mask');

        // Choose the most compact segment mode
        $seg = null;
        if (self::isNumeric($text))            $seg = self::makeNumeric($text);
        elseif (self::isAlphanumeric($text))   $seg = self::makeAlphanumeric($text);
        else                                   $seg = self::makeBytes($text);

        // Find the minimal version number to use
        $dataUsedBits = 0;
        for ($version = $minVersion;; $version++) {
            $capBits = self::getNumDataCodewords($version, $ecl) * 8;
            $dataUsedBits = $seg === null ? 0 : self::getTotalBits($seg, $version);
            if ($dataUsedBits !== self::LENGTH_OVERFLOW && $dataUsedBits <= $capBits) break;
            if ($version >= $maxVersion) throw new \InvalidArgumentException('Data too long to fit in a QR Code');
        }

        // Increase the error correction level while the data still fits
        $finalEcl = $ecl;
        for ($i = max(1, $ecl); $i <= 3; $i++) {
            if ($boostEcl && $dataUsedBits <= self::getNumDataCodewords($version, $i) * 8) $finalEcl = $i;
        }

        // Concatenate all segments to create the data bit string
        $dataCapacityBits = self::getNumDataCodewords($version, $finalEcl) * 8;
        $data = str_repeat("\0", intdiv($dataCapacityBits + 7, 8));
        $bitLen = 0;
        if ($seg !== null) {
            self::appendBits((int) $seg['mode'], 4, $data, $bitLen);
            self::appendBits((int) $seg['numChars'], self::numCharCountBits((int) $seg['mode'], $version), $data, $bitLen);
            $bits = $seg['bits'];
            $nbits = $seg['bitLength'];
            for ($j = 0; $j < $nbits; $j++) {
                $bit = (ord($bits[$j >> 3]) >> (7 - ($j & 7))) & 1;
                self::appendBits($bit, 1, $data, $bitLen);
            }
            if ($bitLen !== $dataUsedBits) throw new \RuntimeException('Bit length mismatch');
        }

        // Add terminator and pad up to a byte if applicable
        $terminatorBits = $dataCapacityBits - $bitLen;
        if ($terminatorBits > 4) $terminatorBits = 4;
        self::appendBits(0, $terminatorBits, $data, $bitLen);
        self::appendBits(0, (8 - $bitLen % 8) % 8, $data, $bitLen);
        if (($bitLen % 8) !== 0) throw new \RuntimeException('Not byte aligned');

        // Pad with alternating bytes until data capacity is reached
        for ($padByte = 0xEC; $bitLen < $dataCapacityBits; $padByte ^= 0xEC ^ 0x11) {
            self::appendBits($padByte, 8, $data, $bitLen);
        }

        // Compute ECC, draw modules
        $allCodewords = str_repeat("\0", intdiv(self::getNumRawDataModules($version), 8));
        self::addEccAndInterleave($data, $version, $finalEcl, $allCodewords);

        $size = 4 * $version + 17;
        $dark = array_fill(0, $size * $size, false);
        $func = array_fill(0, $size * $size, false);
        self::initializeFunctionModules($version, $dark, $func, $size);
        self::drawCodewords($allCodewords, $dark, $size);
        self::drawLightFunctionModules($version, $dark, $size);
        $funcMask = array_fill(0, $size * $size, false);
        $noFunc = null;
        self::initializeFunctionModules($version, $funcMask, $noFunc, $size);

        // Do masking
        $chosenMask = $mask;
        if ($mask === self::MASK_AUTO) {
            $minPenalty = PHP_INT_MAX;
            for ($i = 0; $i < 8; $i++) {
                $msk = $i;
                self::applyMask($funcMask, $dark, $msk, $size);
                self::drawFormatBits($finalEcl, $msk, $dark, $size);
                $penalty = self::getPenaltyScore($dark, $size);
                if (getenv('PBS_DEBUG_PEN')) fprintf(STDERR, "  PHP auto-loop mask=%d penalty=%d\n", $msk, $penalty);
                if ($penalty < $minPenalty) { $chosenMask = $msk; $minPenalty = $penalty; }
                self::applyMask($funcMask, $dark, $msk, $size); // undone (XOR)
            }
        }
        if ($chosenMask < 0 || $chosenMask > self::MASK_MAX) throw new \RuntimeException('Invalid mask');
        self::applyMask($funcMask, $dark, $chosenMask, $size);
        self::drawFormatBits($finalEcl, $chosenMask, $dark, $size);

        return new self($version, $finalEcl, $chosenMask, $dark, $func);
    }

    // ============================== bit buffer ==============================

    private static function appendBits(int $val, int $numBits, string &$buffer, int &$bitLen): void
    {
        if ($numBits < 0 || $numBits > 16 || ($val >> $numBits) !== 0) throw new \RuntimeException('Invalid bit append');
        for ($i = $numBits - 1; $i >= 0; $i--, $bitLen++) {
            $buffer[$bitLen >> 3] = chr(ord($buffer[$bitLen >> 3]) | ((((($val >> $i) & 1) << (7 - ($bitLen & 7)))) & 0xFF));
        }
    }

    // ============================== Reed-Solomon ============================

    private static function addEccAndInterleave(string $data, int $version, int $ecl, string &$result): void
    {
        $numBlocks  = self::NUM_ERROR_CORRECTION_BLOCKS[$ecl][$version];
        $blockEccLen= self::ECC_CODEWORDS_PER_BLOCK[$ecl][$version];
        $rawCodewords = intdiv(self::getNumRawDataModules($version), 8);
        $dataLen = self::getNumDataCodewords($version, $ecl);
        $numShortBlocks  = $numBlocks - ($rawCodewords % $numBlocks);
        $shortBlockDataLen = intdiv($rawCodewords, $numBlocks) - $blockEccLen;

        $rsdiv = self::reedSolomonComputeDivisor($blockEccLen);
        $datPos = 0;
        for ($i = 0; $i < $numBlocks; $i++) {
            $datLen = $shortBlockDataLen + (($i < $numShortBlocks) ? 0 : 1);
            $ecc = self::reedSolomonComputeRemainder($data, $datPos, $datLen, $rsdiv, $blockEccLen);
            // interleave data
            for ($j = 0, $k = $i; $j < $datLen; $j++, $k += $numBlocks) {
                if ($j === $shortBlockDataLen) $k -= $numShortBlocks;
                $result[$k] = $data[$datPos + $j];
            }
            // interleave ECC
            for ($j = 0, $k = $dataLen + $i; $j < $blockEccLen; $j++, $k += $numBlocks) {
                $result[$k] = $ecc[$j];
            }
            $datPos += $datLen;
        }
    }

    private static function getNumDataCodewords(int $version, int $ecl): int
    {
        return intdiv(self::getNumRawDataModules($version), 8)
             - self::ECC_CODEWORDS_PER_BLOCK[$ecl][$version]
             * self::NUM_ERROR_CORRECTION_BLOCKS[$ecl][$version];
    }

    private static function getNumRawDataModules(int $ver): int
    {
        if ($ver < self::VERSION_MIN || $ver > self::VERSION_MAX) throw new \InvalidArgumentException('Invalid QR Code version');
        $result = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $numAlign = intdiv($ver, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($ver >= 7) $result -= 36;
        }
        if ($result < 208 || $result > 29648) throw new \RuntimeException('Assertion failed');
        return $result;
    }

    private static function reedSolomonComputeDivisor(int $degree): string
    {
        if ($degree < 1 || $degree > self::RS_DEGREE_MAX) throw new \InvalidArgumentException('Invalid degree');
        $result = str_repeat("\0", $degree);
        // init: x^0 -> coeff at highest index = 1 (we use low-to-high for remainder computation)
        // Nayuki: result[degree-1] = 1, then multiply by (x - root) for each root.
        $result = str_repeat("\0", $degree);
        $result[$degree - 1] = "\1"; // start with monomial x^0
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = chr(self::reedSolomonMultiply(ord($result[$j]), $root));
                if ($j + 1 < $degree) $result[$j] = chr(ord($result[$j]) ^ ord($result[$j + 1]));
            }
            $root = self::reedSolomonMultiply($root, 0x02);
        }
        return $result;
    }

    private static function reedSolomonComputeRemainder(string $data, int $dataPos, int $dataLen, string $generator, int $degree): string
    {
        if ($degree < 1 || $degree > self::RS_DEGREE_MAX) throw new \InvalidArgumentException('Invalid degree');
        $result = str_repeat("\0", $degree);
        for ($i = 0; $i < $dataLen; $i++) {
            $factor = ord($data[$dataPos + $i]) ^ ord($result[0]);
            // Array.Copy(result, 1, result, 0, degree - 1); result[degree-1] = 0;
            $tmp = "";
            for ($k = 1; $k < $degree; $k++) $tmp .= $result[$k];
            $result = $tmp . "\0";
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = chr(ord($result[$j]) ^ self::reedSolomonMultiply(ord($generator[$j]), $factor));
            }
        }
        return $result;
    }

    private static function reedSolomonMultiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= ((($y >> $i) & 1) * ($x & 0xFF));
        }
        return $z & 0xFF;
    }
    // ============================== function modules ========================

    private static function initializeFunctionModules(int $version, array &$dark, ?array &$func, int $qrsize): void
    {
        self::fillRectangle($dark, $func, $qrsize, 6, 0, 1, $qrsize);
        self::fillRectangle($dark, $func, $qrsize, 0, 6, $qrsize, 1);
        self::fillRectangle($dark, $func, $qrsize, 0, 0, 9, 9);
        self::fillRectangle($dark, $func, $qrsize, $qrsize - 8, 0, 8, 9);
        self::fillRectangle($dark, $func, $qrsize, 0, $qrsize - 8, 9, 8);
        $aline = self::getAlignmentPatternPositions($version);
        $len = count($aline);
        for ($i = 0; $i < $len; $i++) {
            for ($j = 0; $j < $len; $j++) {
                if (!($i == 0 && $j == 0) && !($i == 0 && $j == $len - 1) && !($i == $len - 1 && $j == 0)) {
                    self::fillRectangle($dark, $func, $qrsize, $aline[$i] - 2, $aline[$j] - 2, 5, 5);
                }
            }
        }
        if ($version >= 7) {
            self::fillRectangle($dark, $func, $qrsize, $qrsize - 11, 0, 3, 6);
            self::fillRectangle($dark, $func, $qrsize, 0, $qrsize - 11, 6, 3);
        }
    }

    private static function fillRectangle(array &$dark, ?array &$func, int $qrsize, int $left, int $top, int $width, int $height): void
    {
        for ($dy = 0; $dy < $height; $dy++) {
            for ($dx = 0; $dx < $width; $dx++) {
                $dark[($top + $dy) * $qrsize + ($left + $dx)] = true;
                if ($func !== null) $func[($top + $dy) * $qrsize + ($left + $dx)] = true;
            }
        }
    }

    private static function drawLightFunctionModules(int $version, array &$dark, int $qrsize): void
    {
        for ($i = 7; $i < $qrsize - 7; $i += 2) {
            self::setModule($dark, $qrsize, 6, $i, false);
            self::setModule($dark, $qrsize, $i, 6, false);
        }
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $dist = max(abs($dx), abs($dy));
                if ($dist == 2 || $dist == 4) {
                    self::setModuleUnbounded($dark, $qrsize, 3 + $dx, 3 + $dy, false);
                    self::setModuleUnbounded($dark, $qrsize, $qrsize - 4 + $dx, 3 + $dy, false);
                    self::setModuleUnbounded($dark, $qrsize, 3 + $dx, $qrsize - 4 + $dy, false);
                }
            }
        }
        $aline = self::getAlignmentPatternPositions($version);
        $len = count($aline);
        for ($i = 0; $i < $len; $i++) {
            for ($j = 0; $j < $len; $j++) {
                if (($i == 0 && $j == 0) || ($i == 0 && $j == $len - 1) || ($i == $len - 1 && $j == 0)) continue;
                for ($dy = -1; $dy <= 1; $dy++) {
                    for ($dx = -1; $dx <= 1; $dx++) {
                        self::setModule($dark, $qrsize, $aline[$i] + $dx, $aline[$j] + $dy, $dx == 0 && $dy == 0);
                    }
                }
            }
        }
        if ($version >= 7) {
            $rem = $version;
            for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            $bits = (($version << 12) | $rem);
            for ($i = 0; $i < 6; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $k = $qrsize - 11 + $j;
                    self::setModule($dark, $qrsize, $k, $i, ($bits & 1) != 0);
                    self::setModule($dark, $qrsize, $i, $k, ($bits & 1) != 0);
                    $bits >>= 1;
                }
            }
        }
    }

    private static function drawFormatBits(int $ecl, int $mask, array &$dark, int $qrsize): void
    {
        $table = [1, 0, 3, 2];
        $data = ($table[$ecl] << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        $bits = (($data << 10) | $rem) ^ 0x5412;
        for ($i = 0; $i <= 5; $i++) self::setModule($dark, $qrsize, 8, $i, self::getBit($bits, $i));
        self::setModule($dark, $qrsize, 8, 7, self::getBit($bits, 6));
        self::setModule($dark, $qrsize, 8, 8, self::getBit($bits, 7));
        self::setModule($dark, $qrsize, 7, 8, self::getBit($bits, 8));
        for ($i = 9; $i < 15; $i++) self::setModule($dark, $qrsize, 14 - $i, 8, self::getBit($bits, $i));
        for ($i = 0; $i < 8; $i++) self::setModule($dark, $qrsize, $qrsize - 1 - $i, 8, self::getBit($bits, $i));
        for ($i = 8; $i < 15; $i++) self::setModule($dark, $qrsize, 8, $qrsize - 15 + $i, self::getBit($bits, $i));
        self::setModule($dark, $qrsize, 8, $qrsize - 8, true);
    }

    private static function getBit(int $x, int $i): bool { return (($x >> $i) & 1) != 0; }

    private static function setModule(array &$dark, int $qrsize, int $x, int $y, bool $isDark): void
    {
        $dark[$y * $qrsize + $x] = $isDark;
    }

    private static function setModuleUnbounded(array &$dark, int $qrsize, int $x, int $y, bool $isDark): void
    {
        if ($x >= 0 && $x < $qrsize && $y >= 0 && $y < $qrsize) $dark[$y * $qrsize + $x] = $isDark;
    }

    private static function getAlignmentPatternPositions(int $version): array
    {
        if ($version == 1) return [];
        $numAlign = intdiv($version, 7) + 2;
        $step = intdiv($version * 8 + $numAlign * 3 + 5, $numAlign * 4 - 4) * 2;
        $result = array_fill(0, $numAlign, 0);
        for ($i = $numAlign - 1, $pos = $version * 4 + 10; $i >= 1; $i--, $pos -= $step) {
            $result[$i] = $pos;
        }
        $result[0] = 6;
        return $result;
    }

    // ============================== drawing data & masking ==================

    private static function drawCodewords(string $data, array &$dark, int $qrsize): void
    {
        $i = 0; // bit index into data
        for ($right = $qrsize - 1; $right >= 1; $right -= 2) {
            if ($right == 6) $right = 5;
            for ($vert = 0; $vert < $qrsize; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) == 0;
                    $y = $upward ? $qrsize - 1 - $vert : $vert;
                    if (!$dark[$y * $qrsize + $x] && $i < strlen($data) * 8) {
                        $isDark = (ord($data[$i >> 3]) >> (7 - ($i & 7))) & 1;
                        $dark[$y * $qrsize + $x] = $isDark !== 0;
                        $i++;
                    }
                }
            }
        }
        if ($i !== strlen($data) * 8) throw new \RuntimeException('Assertion i == dataLen * 8 failed');
    }

    private static function applyMask(array $func, array &$dark, int $mask, int $qrsize): void
    {
        for ($y = 0; $y < $qrsize; $y++) {
            for ($x = 0; $x < $qrsize; $x++) {
                if ($func[$y * $qrsize + $x]) continue;
                switch ($mask) {
                    case 0:  $invert = ($x + $y) % 2 == 0;                    break;
                    case 1:  $invert = $y % 2 == 0;                          break;
                    case 2:  $invert = $x % 3 == 0;                          break;
                    case 3:  $invert = ($x + $y) % 3 == 0;                   break;
                    case 4:  $invert = (intdiv($x, 3) + intdiv($y, 2)) % 2 == 0; break;
                    case 5:  $invert = ($x * $y) % 2 + ($x * $y) % 3 == 0;   break;
                    case 6:  $invert = (($x * $y) % 2 + ($x * $y) % 3) % 2 == 0; break;
                    case 7:  $invert = ((($x + $y) % 2) + ($x * $y) % 3) % 2 == 0; break;
                    default: throw new \RuntimeException('Invalid mask');
                }
                $idx = $y * $qrsize + $x;
                $dark[$idx] = $dark[$idx] ^ $invert;
            }
        }
    }

    private static function getPenaltyScore(array $dark, int $qrsize): int
    {
        $result = 0;

        for ($y = 0; $y < $qrsize; $y++) {
            $runColor = false;
            $runX = 0;
            $runHistory = array_fill(0, 7, 0);
            for ($x = 0; $x < $qrsize; $x++) {
                $cur = (bool) $dark[$y * $qrsize + $x];
                if ($cur === $runColor) {
                    $runX++;
                    if ($runX == 5) $result += self::PENALTY_N1;
                    elseif ($runX > 5) $result++;
                } else {
                    self::finderPenaltyAddHistory($runX, $runHistory, $qrsize);
                    if (!$runColor) $result += self::finderPenaltyCountPatterns($runHistory, $qrsize) * self::PENALTY_N3;
                    $runColor = $cur;
                    $runX = 1;
                }
            }
            $result += self::finderPenaltyTerminateAndCount($runColor, $runX, $runHistory, $qrsize) * self::PENALTY_N3;
        }
        for ($x = 0; $x < $qrsize; $x++) {
            $runColor = false;
            $runY = 0;
            $runHistory = array_fill(0, 7, 0);
            for ($y = 0; $y < $qrsize; $y++) {
                $cur = (bool) $dark[$y * $qrsize + $x];
                if ($cur === $runColor) {
                    $runY++;
                    if ($runY == 5) $result += self::PENALTY_N1;
                    elseif ($runY > 5) $result++;
                } else {
                    self::finderPenaltyAddHistory($runY, $runHistory, $qrsize);
                    if (!$runColor) $result += self::finderPenaltyCountPatterns($runHistory, $qrsize) * self::PENALTY_N3;
                    $runColor = $cur;
                    $runY = 1;
                }
            }
            $result += self::finderPenaltyTerminateAndCount($runColor, $runY, $runHistory, $qrsize) * self::PENALTY_N3;
        }

        // 2x2 blocks of same color
        for ($y = 0; $y < $qrsize - 1; $y++) {
            for ($x = 0; $x < $qrsize - 1; $x++) {
                $color = (bool) $dark[$y * $qrsize + $x];
                if ($color === $dark[$y * $qrsize + $x + 1] &&
                    $color === $dark[($y + 1) * $qrsize + $x] &&
                    $color === $dark[($y + 1) * $qrsize + $x + 1]) {
                    $result += self::PENALTY_N2;
                }
            }
        }

        // Dark/light balance
        $darkCount = 0;
        foreach ($dark as $d) if ($d) $darkCount++;
        $total = $qrsize * $qrsize;
        $k = intdiv(abs($darkCount * 20 - $total * 10) + $total - 1, $total) - 1;
        if ($k < 0 || $k > 9) throw new \RuntimeException('Assertion failed');
        return $result + $k * self::PENALTY_N4;
    }

    private static function finderPenaltyCountPatterns(array $runHistory, int $qrsize): int
    {
        $n = $runHistory[1];
        if ($n > $qrsize * 3) throw new \RuntimeException('Assertion failed');
        $core = $n > 0 && $runHistory[2] == $n && $runHistory[3] == $n * 3 && $runHistory[4] == $n && $runHistory[5] == $n;
        return (int) (
            ($core && $runHistory[0] >= $n * 4 && $runHistory[6] >= $n ? 1 : 0) +
            ($core && $runHistory[6] >= $n * 4 && $runHistory[0] >= $n ? 1 : 0));
    }

    private static function finderPenaltyTerminateAndCount(bool $currentRunColor, int $currentRunLength, array &$runHistory, int $qrsize): int
    {
        if ($currentRunColor) {
            self::finderPenaltyAddHistory($currentRunLength, $runHistory, $qrsize);
            $currentRunLength = 0;
        }
        $currentRunLength += $qrsize;
        self::finderPenaltyAddHistory($currentRunLength, $runHistory, $qrsize);
        return self::finderPenaltyCountPatterns($runHistory, $qrsize);
    }

    private static function finderPenaltyAddHistory(int $currentRunLength, array &$runHistory, int $qrsize): void
    {
        if ($runHistory[0] == 0) $currentRunLength += $qrsize;
        for ($i = 6; $i > 0; $i--) $runHistory[$i] = $runHistory[$i - 1];
        $runHistory[0] = $currentRunLength;
    }

    // ============================== segment handling ========================

    private static function isNumeric(string $text): bool
    {
        if (strlen($text) == 0) return true;
        for ($i = 0; $i < strlen($text); $i++) {
            $c = $text[$i];
            if ($c < '0' || $c > '9') return false;
        }
        return true;
    }

    private static function isAlphanumeric(string $text): bool
    {
        for ($i = 0; $i < strlen($text); $i++) {
            if (strpos(self::ALPHANUMERIC_CHARSET, $text[$i]) === false) return false;
        }
        return true;
    }

    private static function calcSegmentBitLength(int $mode, int $numChars): int
    {
        if ($numChars > 0xFFFF) return self::LENGTH_OVERFLOW;
        $result = $numChars;
        if ($mode == self::MODE_NUMERIC)      $result = intdiv($result * 10 + 2, 3);
        elseif ($mode == self::MODE_ALPHANUMERIC) $result = intdiv($result * 11 + 1, 2);
        elseif ($mode == self::MODE_BYTE)     $result *= 8;
        else return self::LENGTH_OVERFLOW;
        if ($result > 0xFFFF) return self::LENGTH_OVERFLOW;
        return $result;
    }

    private static function makeBytes(string $bytes): array
    {
        $bitLength = self::calcSegmentBitLength(self::MODE_BYTE, strlen($bytes));
        if ($bitLength == self::LENGTH_OVERFLOW) throw new \InvalidArgumentException('Data too long');
        return ['mode' => self::MODE_BYTE, 'bitLength' => $bitLength, 'numChars' => strlen($bytes), 'bits' => $bytes];
    }

    private static function makeNumeric(string $digits): array
    {
        $len = strlen($digits);
        $bitLen = self::calcSegmentBitLength(self::MODE_NUMERIC, $len);
        if ($bitLen == self::LENGTH_OVERFLOW) throw new \InvalidArgumentException('Data too long');
        $buf = str_repeat("\0", intdiv($bitLen + 7, 8));
        $bitLength = 0;
        $accumData = 0;
        $accumCount = 0;
        for ($i = 0; $i < $len; $i++) {
            $accumData = $accumData * 10 + ord($digits[$i]) - ord('0');
            $accumCount++;
            if ($accumCount == 3) {
                self::appendBits($accumData, 10, $buf, $bitLength);
                $accumData = 0;
                $accumCount = 0;
            }
        }
        if ($accumCount > 0) // 1 or 2 digits remaining
            self::appendBits($accumData, $accumCount * 3 + 1, $buf, $bitLength);
        if ($bitLength !== $bitLen) throw new \RuntimeException('Assertion failed');
        return ['mode' => self::MODE_NUMERIC, 'numChars' => $len, 'bitLength' => $bitLen, 'bits' => $buf];
    }

    private static function makeAlphanumeric(string $text): array
    {
        $len = strlen($text);
        $bitLen = self::calcSegmentBitLength(self::MODE_ALPHANUMERIC, $len);
        if ($bitLen == self::LENGTH_OVERFLOW) throw new \InvalidArgumentException('Data too long');
        $buf = str_repeat("\0", intdiv($bitLen + 7, 8));
        $bitLength = 0;
        $accumData = 0;
        $accumCount = 0;
        for ($i = 0; $i < $len; $i++) {
            $idx = strpos(self::ALPHANUMERIC_CHARSET, $text[$i]);
            $accumData = $accumData * 45 + $idx;
            $accumCount++;
            if ($accumCount == 2) {
                self::appendBits($accumData, 11, $buf, $bitLength);
                $accumData = 0;
                $accumCount = 0;
            }
        }
        if ($accumCount > 0)
            self::appendBits($accumData, 6, $buf, $bitLength);
        if ($bitLength !== $bitLen) throw new \RuntimeException('Assertion failed');
        return ['mode' => self::MODE_ALPHANUMERIC, 'numChars' => $len, 'bitLength' => $bitLen, 'bits' => $buf];
    }

    private static function getTotalBits(array $seg, int $version): int
    {
        $ccbits = self::numCharCountBits($seg['mode'], $version);
        if ($seg['numChars'] >= (1 << $ccbits)) return self::LENGTH_OVERFLOW;
        $result = 4 + $ccbits + $seg['bitLength'];
        if ($result > 0xFFFF) return self::LENGTH_OVERFLOW;
        return $result;
    }

    private static function numCharCountBits(int $mode, int $version): int
    {
        $i = intdiv($version + 7, 17);
        switch ($mode) {
            case self::MODE_NUMERIC:      return [10, 12, 14][$i];
            case self::MODE_ALPHANUMERIC: return [9, 11, 13][$i];
            case self::MODE_BYTE:         return [8, 16, 16][$i];
            default: throw new \RuntimeException('Invalid mode');
        }
    }
}
