<?php
namespace Pbs;
/**
 * Lzma1Encoder — pure-PHP LZMA1 ("legacy .lzma / lzma_alone") compressor.
 * AI-generated with Claude Code, as part of a multi-agent project
 * (Claude Code, Hermes Agent, Qwen 3.8-128K, Gemma 4-128K) — see README.
 *
 * Produces a standard 13-byte-header .lzma stream that decodes correctly
 * with `xz --format=lzma -d`, `lzma -d`, 7-Zip, Python's lzma module, etc.
 *
 * This is a *correct*, not *optimal*, encoder: it uses hash-chain match
 * finding with 1-step lazy matching and rep-distance preference, not full
 * optimal parsing. Compression ratio will be noticeably behind 7-Zip/xz,
 * but every byte round-trips.
 *
 * Usage:
 *   $compressed = Lzma1Encoder::compress($data);
 *   file_put_contents('out.lzma', $compressed);
 */

final class Lzma1Encoder
{
    const NUM_BIT_MODEL_TOTAL_BITS = 11;
    const BIT_MODEL_TOTAL = 1 << self::NUM_BIT_MODEL_TOTAL_BITS; // 2048
    const NUM_MOVE_BITS = 5;
    const PROB_INIT = self::BIT_MODEL_TOTAL >> 1; // 1024
    const TOP_VALUE = 1 << 24;

    const NUM_POS_BITS_MAX = 4;
    const NUM_STATES = 12;
    const NUM_LEN_TO_POS_STATES = 4;
    const NUM_ALIGN_BITS = 4;
    const END_POS_MODEL_INDEX = 14;
    const NUM_FULL_DISTANCES = 128; // 1 << (END_POS_MODEL_INDEX >> 1)
    const MATCH_MIN_LEN = 2;
    const MAX_LEN = 273; // MATCH_MIN_LEN(2) + 8 + 8 + 256 - 1

    /** @var array<int,int> raw byte values of the input, 0-indexed */
    private $data;
    private $n;

    private $lc, $lp, $pb;

    // range coder
    private $low = 0;
    private $range = 0xFFFFFFFF;
    private $cache = 0;
    private $cacheSize = 1;
    private $out = array();

    private $state = 0;
    private $rep0 = 0;
    private $rep1 = 0;
    private $rep2 = 0;
    private $rep3 = 0;

    private $isMatch;      // [state][posState]
    private $isRep;        // [state]
    private $isRepG0;      // [state]
    private $isRepG1;      // [state]
    private $isRepG2;      // [state]
    private $isRep0Long;   // [state][posState]

    private $posSlot;      // [lenState][0..63]
    private $alignProbs;   // [0..15]
    private $posDecoders;  // shared reverse-bit-tree probs

    // length coders: choice/choice2/low[posState][8]/mid[posState][8]/high[256]
    private $lenChoice, $lenLow, $lenMid, $lenHigh;
    private $repLenChoice, $repLenLow, $repLenMid, $repLenHigh;

    private $litProbs;

    private $pos = 0; // number of bytes "written" so far == current position
    private $dictSize = PHP_INT_MAX; // matches beyond this distance are not used

    private function __construct(array $dataBytes, $lc, $lp, $pb, $dictSize = null)
    {
        $this->data = $dataBytes;
        $this->n = count($dataBytes);
        $this->lc = $lc;
        $this->lp = $lp;
        $this->pb = $pb;
        if ($dictSize !== null) {
            $this->dictSize = $dictSize;
        }

        $pbn = 1 << $pb;

        $this->isMatch = array();
        $this->isRep0Long = array();
        for ($s = 0; $s < self::NUM_STATES; $s++) {
            $this->isMatch[$s] = array_fill(0, $pbn, self::PROB_INIT);
            $this->isRep0Long[$s] = array_fill(0, $pbn, self::PROB_INIT);
        }
        $this->isRep = array_fill(0, self::NUM_STATES, self::PROB_INIT);
        $this->isRepG0 = array_fill(0, self::NUM_STATES, self::PROB_INIT);
        $this->isRepG1 = array_fill(0, self::NUM_STATES, self::PROB_INIT);
        $this->isRepG2 = array_fill(0, self::NUM_STATES, self::PROB_INIT);

        $this->posSlot = array();
        for ($i = 0; $i < self::NUM_LEN_TO_POS_STATES; $i++) {
            $this->posSlot[$i] = array_fill(0, 64, self::PROB_INIT);
        }
        $this->alignProbs = array_fill(0, 16, self::PROB_INIT);
        $this->posDecoders = array_fill(0, 1 + self::NUM_FULL_DISTANCES - self::END_POS_MODEL_INDEX, self::PROB_INIT);

        $this->lenChoice = array(self::PROB_INIT, self::PROB_INIT);
        $this->lenLow = array();
        $this->lenMid = array();
        for ($i = 0; $i < $pbn; $i++) {
            $this->lenLow[$i] = array_fill(0, 8, self::PROB_INIT);
            $this->lenMid[$i] = array_fill(0, 8, self::PROB_INIT);
        }
        $this->lenHigh = array_fill(0, 256, self::PROB_INIT);

        $this->repLenChoice = array(self::PROB_INIT, self::PROB_INIT);
        $this->repLenLow = array();
        $this->repLenMid = array();
        for ($i = 0; $i < $pbn; $i++) {
            $this->repLenLow[$i] = array_fill(0, 8, self::PROB_INIT);
            $this->repLenMid[$i] = array_fill(0, 8, self::PROB_INIT);
        }
        $this->repLenHigh = array_fill(0, 256, self::PROB_INIT);

        $numLitStates = 1 << ($lc + $lp);
        $this->litProbs = array_fill(0, 0x300 * $numLitStates, self::PROB_INIT);
    }

    // ---------------- range coder ----------------

    private function shiftLow()
    {
        if ($this->low < 0xFF000000 || $this->low > 0xFFFFFFFF) {
            $temp = $this->cache;
            $carry = $this->low >> 32;
            do {
                $this->out[] = ($temp + $carry) & 0xFF;
                $temp = 0xFF;
                $this->cacheSize--;
            } while ($this->cacheSize != 0);
            $this->cache = ($this->low >> 24) & 0xFF;
        }
        $this->cacheSize++;
        $this->low = ($this->low << 8) & 0xFFFFFFFF;
    }

    /** @param array $probs passed by reference so updates persist */
    private function encodeBit(array &$probs, $idx, $bit)
    {
        $v = $probs[$idx];
        $bound = ($this->range >> self::NUM_BIT_MODEL_TOTAL_BITS) * $v;
        if ($bit == 0) {
            $this->range = $bound;
            $v += (self::BIT_MODEL_TOTAL - $v) >> self::NUM_MOVE_BITS;
        } else {
            $this->low += $bound;
            $this->range -= $bound;
            $v -= $v >> self::NUM_MOVE_BITS;
        }
        $probs[$idx] = $v;
        if ($this->range < self::TOP_VALUE) {
            $this->range = ($this->range << 8) & 0xFFFFFFFF;
            $this->shiftLow();
        }
    }

    private function encodeDirectBits($v, $numBits)
    {
        for ($i = $numBits - 1; $i >= 0; $i--) {
            $this->range = $this->range >> 1;
            $bit = ($v >> $i) & 1;
            if ($bit) {
                $this->low += $this->range;
            }
            if ($this->range < self::TOP_VALUE) {
                $this->range = ($this->range << 8) & 0xFFFFFFFF;
                $this->shiftLow();
            }
        }
    }

    private function flushRange()
    {
        for ($i = 0; $i < 5; $i++) {
            $this->shiftLow();
        }
    }

    private function encodeBitTree(array &$probs, $base, $numBits, $symbol)
    {
        $m = 1;
        for ($i = $numBits - 1; $i >= 0; $i--) {
            $bit = ($symbol >> $i) & 1;
            $this->encodeBit($probs, $base + $m, $bit);
            $m = ($m << 1) | $bit;
        }
    }

    private function encodeBitTreeReverse(array &$probs, $base, $numBits, $symbol)
    {
        $m = 1;
        $sym = $symbol;
        for ($i = 0; $i < $numBits; $i++) {
            $bit = $sym & 1;
            $sym >>= 1;
            $this->encodeBit($probs, $base + $m, $bit);
            $m = ($m << 1) | $bit;
        }
    }

    // ---------------- length coder ----------------

    private function encodeLenDirect(&$choiceArr, &$lowArr, &$midArr, &$highArr, $posState, $sym)
    {
        if ($sym < 8) {
            $this->encodeBit($choiceArr, 0, 0);
            $this->encodeBitTree($lowArr[$posState], 0, 3, $sym);
        } else {
            $this->encodeBit($choiceArr, 0, 1);
            $sym -= 8;
            if ($sym < 8) {
                $this->encodeBit($choiceArr, 1, 0);
                $this->encodeBitTree($midArr[$posState], 0, 3, $sym);
            } else {
                $this->encodeBit($choiceArr, 1, 1);
                $sym -= 8;
                $this->encodeBitTree($highArr, 0, 8, $sym);
            }
        }
    }

    // ---------------- literal coder ----------------

    private function getByteBefore($dist)
    {
        // dist = 1 means the immediately preceding byte
        return $this->data[$this->pos - $dist];
    }

    private function encodeLiteral($byte)
    {
        $totalPos = $this->pos;
        $prevByte = $totalPos > 0 ? $this->data[$totalPos - 1] : 0;
        $litState = (($totalPos & ((1 << $this->lp) - 1)) << $this->lc) + ($prevByte >> (8 - $this->lc));
        $base = 0x300 * $litState;
        $symbol = 1;
        $b = $byte;

        if ($this->state >= 7) {
            $matchByte = $this->getByteBefore($this->rep0 + 1);
            while ($symbol < 0x100) {
                $matchBit = ($matchByte >> 7) & 1;
                $matchByte = ($matchByte << 1) & 0xFF;
                $bit = ($b >> 7) & 1;
                $b = ($b << 1) & 0xFF;
                $idx = $base + ((1 + $matchBit) << 8) + $symbol;
                $this->encodeBit($this->litProbs, $idx, $bit);
                $symbol = ($symbol << 1) | $bit;
                if ($matchBit != $bit) {
                    break;
                }
            }
        }
        while ($symbol < 0x100) {
            $bit = ($b >> 7) & 1;
            $b = ($b << 1) & 0xFF;
            $this->encodeBit($this->litProbs, $base + $symbol, $bit);
            $symbol = ($symbol << 1) | $bit;
        }
    }

    // ---------------- distance coder ----------------

    private function getPosSlot($dist)
    {
        if ($dist < 4) {
            return $dist;
        }
        $nbits = self::bitLength($dist) - 1;
        return ($nbits << 1) | (($dist >> ($nbits - 1)) & 1);
    }

    private static function bitLength($x)
    {
        $n = 0;
        while ($x > 0) {
            $x >>= 1;
            $n++;
        }
        return $n;
    }

    private function encodeDistance($length0, $dist)
    {
        $lenState = min($length0, self::NUM_LEN_TO_POS_STATES - 1);
        $posSlot = $this->getPosSlot($dist);
        $this->encodeBitTree($this->posSlot[$lenState], 0, 6, $posSlot);
        if ($posSlot >= 4) {
            $numDirectBits = ($posSlot >> 1) - 1;
            $base = (2 | ($posSlot & 1)) << $numDirectBits;
            if ($posSlot < self::END_POS_MODEL_INDEX) {
                $this->encodeBitTreeReverse($this->posDecoders, $base - $posSlot, $numDirectBits, $dist - $base);
            } else {
                $this->encodeDirectBits($dist >> self::NUM_ALIGN_BITS, $numDirectBits - self::NUM_ALIGN_BITS);
                $this->encodeBitTreeReverse($this->alignProbs, 0, self::NUM_ALIGN_BITS, $dist & 0xF);
            }
        }
    }

    // ---------------- top-level symbol emitters ----------------

    private function emitLiteral($byte)
    {
        $posState = $this->pos & ((1 << $this->pb) - 1);
        $this->encodeBit($this->isMatch[$this->state], $posState, 0);
        $this->encodeLiteral($byte);
        $this->pos++;
        $this->state = self::stateLit($this->state);
    }

    private function emitShortRep()
    {
        $posState = $this->pos & ((1 << $this->pb) - 1);
        $this->encodeBit($this->isMatch[$this->state], $posState, 1);
        $this->encodeBit($this->isRep, $this->state, 1);
        $this->encodeBit($this->isRepG0, $this->state, 0);
        $this->encodeBit($this->isRep0Long[$this->state], $posState, 0);
        $this->pos++;
        $this->state = self::stateShortRep($this->state);
    }

    private function emitRepMatch($repIndex, $length)
    {
        $posState = $this->pos & ((1 << $this->pb) - 1);
        $this->encodeBit($this->isMatch[$this->state], $posState, 1);
        $this->encodeBit($this->isRep, $this->state, 1);

        if ($repIndex == 0) {
            $this->encodeBit($this->isRepG0, $this->state, 0);
            $this->encodeBit($this->isRep0Long[$this->state], $posState, 1);
        } else {
            $this->encodeBit($this->isRepG0, $this->state, 1);
            if ($repIndex == 1) {
                $this->encodeBit($this->isRepG1, $this->state, 0);
                $dist = $this->rep1;
                $this->rep1 = $this->rep0;
                $this->rep0 = $dist;
            } else {
                $this->encodeBit($this->isRepG1, $this->state, 1);
                if ($repIndex == 2) {
                    $this->encodeBit($this->isRepG2, $this->state, 0);
                    $dist = $this->rep2;
                    $this->rep2 = $this->rep1;
                    $this->rep1 = $this->rep0;
                    $this->rep0 = $dist;
                } else {
                    $this->encodeBit($this->isRepG2, $this->state, 1);
                    $dist = $this->rep3;
                    $this->rep3 = $this->rep2;
                    $this->rep2 = $this->rep1;
                    $this->rep1 = $this->rep0;
                    $this->rep0 = $dist;
                }
            }
        }

        $length0 = $length - self::MATCH_MIN_LEN;
        $this->encodeLenDirect($this->repLenChoice, $this->repLenLow, $this->repLenMid, $this->repLenHigh, $posState, $length0);
        $this->state = self::stateRep($this->state);
        $this->pos += $length;
    }

    private function emitMatch($dist, $length)
    {
        $posState = $this->pos & ((1 << $this->pb) - 1);
        $this->encodeBit($this->isMatch[$this->state], $posState, 1);
        $this->encodeBit($this->isRep, $this->state, 0);
        $this->state = self::stateMatch($this->state);

        $this->rep3 = $this->rep2;
        $this->rep2 = $this->rep1;
        $this->rep1 = $this->rep0;
        $this->rep0 = $dist - 1; // model works with 0-based distance

        $length0 = $length - self::MATCH_MIN_LEN;
        $this->encodeLenDirect($this->lenChoice, $this->lenLow, $this->lenMid, $this->lenHigh, $posState, $length0);
        $this->encodeDistance($length0, $this->rep0);
        $this->pos += $length;
    }

    // ---------------- state transition helpers ----------------

    private static function stateLit($s)
    {
        if ($s < 4) return 0;
        if ($s < 10) return $s - 3;
        return $s - 6;
    }

    private static function stateMatch($s)
    {
        return $s < 7 ? 7 : 10;
    }

    private static function stateRep($s)
    {
        return $s < 7 ? 8 : 11;
    }

    private static function stateShortRep($s)
    {
        return $s < 7 ? 9 : 11;
    }

    // ---------------- match finder ----------------

    private function hashAt($p)
    {
        $d = $this->data;
        $n = $this->n;
        if ($p + 2 < $n) {
            return ($d[$p] | ($d[$p + 1] << 8) | ($d[$p + 2] << 16)) & 0xFFFFF;
        } elseif ($p + 1 < $n) {
            return ($d[$p] | ($d[$p + 1] << 8)) & 0xFFFFF;
        }
        return $d[$p] & 0xFFFFF;
    }

    private function findMatch(&$hashTable, &$chain, $pos, $maxLenCap, $maxChain = 64)
    {
        $n = $this->n;
        $d = $this->data;
        $bestLen = 0;
        $bestDist = 0;
        if ($pos + 2 > $n) {
            return array(0, 0);
        }
        $h = $this->hashAt($pos);
        $cand = isset($hashTable[$h]) ? $hashTable[$h] : -1;
        $tries = 0;
        $limit = min($n - $pos, $maxLenCap);
        while ($cand != -1 && $tries < $maxChain) {
            $tries++;
            $l = 0;
            while ($l < $limit && $d[$cand + $l] == $d[$pos + $l]) {
                $l++;
            }
            if ($l > $bestLen) {
                $bestLen = $l;
                $bestDist = $pos - $cand;
                if ($l >= $limit) {
                    break;
                }
            }
            $cand = isset($chain[$cand]) ? $chain[$cand] : -1;
        }
        return array($bestLen, $bestDist);
    }

    private function repLenAt($p, $dist, $remaining)
    {
        if ($dist + 1 > $p) {
            return 0;
        }
        $d = $this->data;
        $l = 0;
        $cap = min($remaining, self::MAX_LEN);
        while ($l < $cap && $d[$p - $dist - 1 + $l] == $d[$p + $l]) {
            $l++;
        }
        return $l;
    }

    private function emitEndMarker()
    {
        // A "match" whose decoded distance is 0xFFFFFFFF signals end-of-stream.
        // Required for raw headerless streams, which carry no size field.
        $posState = $this->pos & ((1 << $this->pb) - 1);
        $this->encodeBit($this->isMatch[$this->state], $posState, 1);
        $this->encodeBit($this->isRep, $this->state, 0);
        $this->state = self::stateMatch($this->state);
        $length0 = 0; // len = 2, value itself is irrelevant for the marker
        $this->encodeLenDirect($this->lenChoice, $this->lenLow, $this->lenMid, $this->lenHigh, $posState, $length0);
        $this->encodeDistance($length0, 0xFFFFFFFF);
    }

    // ---------------- main driver ----------------

    private function run($appendEndMarker = false)
    {
        $n = $this->n;
        $hashTable = array();
        $chain = array();

        $insert = function ($p) use (&$hashTable, &$chain) {
            if ($p >= $this->n) return;
            $h = $this->hashAt($p);
            $chain[$p] = isset($hashTable[$h]) ? $hashTable[$h] : -1;
            $hashTable[$h] = $p;
        };

        while ($this->pos < $n) {
            $pos = $this->pos;
            $remaining = $n - $pos;

            $bestRepLen = 0;
            $bestRepIdx = -1;
            $reps = array($this->rep0, $this->rep1, $this->rep2, $this->rep3);
            foreach ($reps as $idx => $dist) {
                $rl = $this->repLenAt($pos, $dist, $remaining);
                if ($rl > $bestRepLen) {
                    $bestRepLen = $rl;
                    $bestRepIdx = $idx;
                }
            }

            list($matchLen, $matchDist) = $this->findMatch($hashTable, $chain, $pos, min($remaining, self::MAX_LEN));
            if ($matchLen > 0 && $matchDist > $this->dictSize) {
                $matchLen = 0;
                $matchDist = 0;
            }

            $useRep = $bestRepIdx !== -1 && $bestRepLen >= 2 && ($bestRepLen + 1 >= $matchLen || $matchLen < 2);
            $useMatch = (!$useRep) && $matchLen >= 2;

            if ($useRep) {
                if ($bestRepIdx === 0 && $bestRepLen === 1) {
                    $this->emitShortRep();
                    $insert($pos);
                } else {
                    $length = $bestRepLen;
                    for ($k = 0; $k < $length; $k++) {
                        $insert($pos + $k);
                    }
                    $this->emitRepMatch($bestRepIdx, $length);
                }
            } elseif ($useMatch) {
                $doMatch = true;
                $insert($pos);
                if ($pos + 1 < $n) {
                    list($nxtLen, $nxtDist) = $this->findMatch($hashTable, $chain, $pos + 1, min($n - $pos - 1, self::MAX_LEN));
                    if ($nxtLen > 0 && $nxtDist > $this->dictSize) {
                        $nxtLen = 0;
                    }
                    if ($nxtLen > $matchLen) {
                        $doMatch = false;
                    }
                }
                if ($doMatch) {
                    $length = $matchLen;
                    for ($k = 1; $k < $length; $k++) {
                        $insert($pos + $k);
                    }
                    $this->emitMatch($matchDist, $length);
                } else {
                    $this->emitLiteral($this->data[$pos]);
                }
            } else {
                $insert($pos);
                $this->emitLiteral($this->data[$pos]);
            }
        }

        if ($appendEndMarker) {
            $this->emitEndMarker();
        }
        $this->flushRange();
    }

    /**
     * Compress a raw binary string into a standard 13-byte-header .lzma stream.
     *
     * @param string $data raw bytes to compress
     * @param int $lc literal context bits (default 3)
     * @param int $lp literal position bits (default 0)
     * @param int $pb position bits (default 2)
     * @return string the compressed .lzma file contents
     */
    public static function compress($data, $lc = 3, $lp = 0, $pb = 2)
    {
        $n = strlen($data);
        $bytes = $n > 0 ? array_values(unpack('C*', $data)) : array();

        // dictionary size: next power of two >= input length, min 4096
        $dictSize = 1 << 12;
        while ($dictSize < $n) {
            $dictSize <<= 1;
        }

        $enc = new self($bytes, $lc, $lp, $pb, $dictSize);
        $enc->run(false); // alone-format: size is in the header, no end marker needed

        $propByte = ($pb * 5 + $lp) * 9 + $lc;

        $header = chr($propByte);
        $header .= self::packUInt32LE($dictSize);
        $header .= self::packUInt64LE($n);

        $body = '';
        foreach ($enc->out as $b) {
            $body .= chr($b);
        }

        return $header . $body;
    }

    /**
     * Compress into a headerless RAW LZMA1 stream, equivalent to:
     *   xz --format=raw --lzma1=lc=3,lp=0,pb=2,dict=128KiB -c -
     *
     * Raw streams carry no properties byte, no dict size, and no
     * uncompressed size — the decoder must be told lc/lp/pb/dict out of
     * band (exactly as in the xz command above), and the stream instead
     * ends with an explicit end-of-stream marker.
     *
     * @param string $data raw bytes to compress
     * @param int $lc literal context bits (default 3, matches xz default)
     * @param int $lp literal position bits (default 0, matches xz default)
     * @param int $pb position bits (default 2, matches xz default)
     * @param int $dictSize dictionary size in bytes (default 128 KiB = 131072)
     * @return string the raw compressed bytes (no header, no footer)
     */
    public static function compressRaw($data, $lc = 3, $lp = 0, $pb = 2, $dictSize = 131072)
    {
        $n = strlen($data);
        $bytes = $n > 0 ? array_values(unpack('C*', $data)) : array();

        $enc = new self($bytes, $lc, $lp, $pb, $dictSize);
        $enc->run(true); // raw format: no size field, so append the end marker

        $body = '';
        foreach ($enc->out as $b) {
            $body .= chr($b);
        }

        return $body;
    }

    private static function packUInt32LE($v)
    {
        return chr($v & 0xFF) . chr(($v >> 8) & 0xFF) . chr(($v >> 16) & 0xFF) . chr(($v >> 24) & 0xFF);
    }

    private static function packUInt64LE($v)
    {
        $s = '';
        for ($i = 0; $i < 8; $i++) {
            $s .= chr($v & 0xFF);
            $v >>= 8;
        }
        return $s;
    }
}