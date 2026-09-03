<?php
declare(strict_types=1);
namespace Pbs;

/**
 * Decorated render: QR on a white card with a light-blue frame, a
 * "PAY by square" caption below, and a small blue card-icon.
 *
 * Pure PHP (no GD / imagick, no zlib). Emits a deterministic SVG
 * (print-ready, infinite resolution) AND — new — a bitmap renderer with
 * true alpha compositing: `toPng()` writes a genuine PNG via the native
 * `Pbs\Png` encoder (no Pillow / CLI shims), `toBmp1()` mirrors the C#
 * `BmpSaver` byte-for-byte (1-bit print tile).
 *
 * The bitmap path (`geo()` / `renderRgba()`) is a 1:1 port of
 * Pay-by-Square-cs `QrTileDecorator` (Geo + RenderTile + DrawWordmark +
 * DrawIcon): same formulas, same 4x4 supersampling, same premultiplied
 * alpha compositing, same Mono flat-ink print mode. Parity is asserted
 * in the test suite against the C# build.
 */
final class Render
{
    // ---- palette (identical to the C# decorator / Python renderers) ----
    public const CARD_R   = 0xFF, CARD_G   = 0xFF, CARD_B   = 0xFF;
    public const BORDER_R = 0x7F, BORDER_G = 0xA8, BORDER_B = 0xD0;
    private const AA = 4;                          // supersampling factor
    public const CARD_COLOR   = "#ffffff";
    public const BG_COLOR     = "#212121";
    public const BORDER_COLOR = "#7fa8d0";
    public const ACCENT       = "#4a6d9c";
    public const INK          = "#d8dde2";
    public const QR_DARK      = "#000000";
    public const QR_LIGHT     = "#ffffff";
    public const ICON_FILL    = "#7fa8d0";
    public const ICON_STROKE  = "#ffffff";

    /**
     * @param array $modules rows from QrCode::encode()['modules']
     * @param int   $size    modules per side (QrCode::encode()['size'])
     * @param array $opts
     *   moduleScale  int  px per QR module      (default 5)
     *   borderPx     int  blue frame thickness  (default 5)
     *   padPx        int  white gap border->QR  (default 24)
     *   capFontPx    int  caption font size     (default 20)
     *   capPay       str  left caption word     (default "PAY")
     *   capBy        str  right caption text    (default "by square")
     *   iconW        int  icon width px         (default 56)
     *   showIcon     bool default true
     */
    public static function toSvg(array $modules, int $size, array $opts = []): string
    {
        $scale    = max(1, (int)($opts['moduleScale'] ?? 5));
        $borderPx = max(0, (int)($opts['borderPx']    ?? 5));
        $padPx    = max(0, (int)($opts['padPx']       ?? 24));
        $fontPx   = max(6, (int) ($opts['capFontPx']   ?? 20));
        $capPay   = (string)($opts['capPay'] ?? 'PAY');
        $capBy    = (string)($opts['capBy']  ?? 'by square');
        $iconW    = max(0, (int) ($opts['iconW']       ?? 56));
        $showIcon = (bool)  ($opts['showIcon'] ?? true);
        $iconH    = $iconW;                     // brand asset is square

        $qrPx      = $size * $scale;          // QR pixel edge
        $cardSide  = $qrPx + 2 * $padPx;       // white card
        $frameSide = $cardSide + 2 * $borderPx;

        // caption row height + gaps (proportional, print-like)
        $gapTop      = max(10, (int)round($frameSide * 0.05));
        $capRowH     = max($iconH, (int)round($fontPx * 1.5));
        $gapBottom   = max(8, (int)round($frameSide * 0.03));
        $canvasW     = $frameSide;
        $canvasH     = $frameSide + $gapTop + $capRowH + $gapBottom;

        $s = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $canvasW . '"'
          . ' height="' . $canvasH . '" viewBox="0 0 ' . $canvasW . ' ' . $canvasH . '"'
          . ' shape-rendering="crispEdges">';

        // dark background (the whole canvas in spec: "okolo bude modry ramce,
        // padding, then caption" — reference uses a dark page + framed white card)
        $s .= '<rect width="' . $canvasW . '" height="' . $canvasH . '"'
           . ' fill="' . self::BG_COLOR . '"/>';

        // blue frame = white card border
        $s .= '<rect x="' . $borderPx . '" y="' . $borderPx . '"'
           . ' width="' . ($frameSide - 2 * $borderPx) . '" height="' . ($frameSide - 2 * $borderPx) . '"'
           . ' fill="' . self::CARD_COLOR . '" stroke="' . self::BORDER_COLOR . '"'
           . ' stroke-width="' . ($borderPx * 2) . '" rx="' . max(4, (int)round($frameSide * 0.02)) . '"/>';

        // QR (crisp module grid)
        $qx = $borderPx + $padPx;
        $qy = $borderPx + $padPx;
        $s .= '<rect x="' . $qx . '" y="' . $qy . '" width="' . $qrPx . '" height="' . $qrPx . '"'
           . ' fill="' . self::QR_LIGHT . '"/>';
        $d = '';
        for ($y = 0; $y < $size; $y++) {
            $row = $modules[$y];
            for ($x = 0; $x < $size; $x++) {
                if (!empty($row[$x])) {
                    $d .= 'M' . ($qx + $x * $scale) . ' ' . ($qy + $y * $scale)
                        . 'h' . $scale . 'v' . $scale . 'h-' . $scale . 'z';
                }
            }
        }
        $s .= '<path d="' . $d . '" fill="' . self::QR_DARK . '"/>';

        // caption row (icon on the right, text on the left)
        $capY = $frameSide + $gapTop;
        $capMidY = $capY + (int)($capRowH / 2);
        $textFamily = 'DejaVu Sans, Arial, Helvetica, sans-serif';
        // caption row — the whole "PAY  by square  [icon]" group is right-aligned
        $iconX = $canvasW - $padPx - 4 - $iconW;          // icon at the group right edge
        $g1Px  = (int)($fontPx * 0.40);                    // PAY -> by square
        $g2Px  = (int)($fontPx * 0.35);                    // by square -> icon
        // text widths estimated in the same metric the row used before
        $wPay  = (int)(strlen($capPay) * $fontPx * 0.62);
        $wBy   = (int)(strlen($capBy)  * $fontPx * 0.50);
        $xBy   = $iconX - $g2Px - $wBy;
        $xPay  = $xBy - $g1Px - $wPay;
        $s .= self::svgText($xPay, $capMidY, $capPay, $fontPx, self::ACCENT, $textFamily, '700');
        $s .= self::svgText($xBy,  $capMidY, $capBy,  $fontPx, self::INK,   $textFamily, '400');

        // icon — real brand asset (assets/card.svg), embedded as a
        // self-contained data URI; falls back to a generic card glyph
        if ($showIcon && $iconW > 0) {
            $iy = $capY + max(2, (int)round(($capRowH - $iconH) / 2));
            $s .= self::icon($iconX, $iy, $iconW, $iconH);
        }

        $s .= '</svg>';
        return $s;
    }

    /**
     * Caption icon: the real brand asset assets/card.svg (3 Bezier paths),
     * embedded via a self-contained base64 data URI so the SVG stays
     * portable. If the asset is missing, a generic card glyph is used.
     */
    private static function icon(int $x, int $y, int $iconW, int $iconH): string
    {
        $asset = __DIR__ . '/../assets/card.svg';
        if (is_file($asset)) {
            $raw = (string)file_get_contents($asset);
            // strip the standalone XML declaration (browsers dislike it in <image>)
            $raw = preg_replace('/^<\?xml[^?]*>\s*/', '', $raw);
            $b64 = base64_encode($raw);
            return '<image x="' . $x . '" y="' . $y . '" width="' . $iconW . '" height="' . $iconH . '"'
                 . ' preserveAspectRatio="xMidYMid meet"'
                 . ' xlink:href="data:image/svg+xml;base64,' . $b64 . '"'
                 . ' href="data:image/svg+xml;base64,' . $b64 . '"/>';
        }
        // fallback: blue rounded square + 3 white strokes
        return '<rect x="' . $x . '" y="' . $y . '" width="' . $iconW . '" height="' . $iconH . '"'
             . ' fill="' . self::ICON_FILL . '" rx="' . max(4, (int)round($iconH * 0.18)) . '"/>'
             . '<rect x="' . ($x + (int)round($iconW * 0.16)) . '" y="' . ($y + (int)round($iconH * 0.28)) . '"'
             . ' width="' . (int)round($iconW * 0.62) . '" height="' . max(2, (int)round($iconH * 0.07)) . '" fill="' . self::ICON_STROKE . '"/>'
             . '<rect x="' . ($x + (int)round($iconW * 0.16)) . '" y="' . ($y + (int)round($iconH * 0.52)) . '"'
             . ' width="' . (int)round($iconW * 0.62) . '" height="' . max(2, (int)round($iconH * 0.07)) . '" fill="' . self::ICON_STROKE . '"/>'
             . '<rect x="' . ($x + (int)round($iconW * 0.16)) . '" y="' . ($y + (int)round($iconH * 0.76)) . '"'
             . ' width="' . (int)round($iconW * 0.45) . '" height="' . max(2, (int)round($iconH * 0.07)) . '" fill="' . self::ICON_STROKE . '"/>';
    }

    private static function svgText(int $x, int $midY, string $t, int $fontPx, string $fill, string $family, string $weight): string
    {
        return '<text x="' . $x . '" y="' . $midY . '" font-family="' . $family . '"'
             . ' font-size="' . $fontPx . '" fill="' . $fill . '"'
             . ' font-weight="' . $weight . '" dominant-baseline="central">'
             . htmlspecialchars($t, ENT_QUOTES)
             . '</text>';
    }

    // ===================== bitmap tile (1:1 C# QrTileDecorator) =====================
    // `geo()` mirrors Pay-by-Square-cs Geo exactly: same formulas, same
    // rounding. `renderRgba()` mirrors RenderTile (bg, rounded card, QR,
    // wordmark, icon) and the two DrawWordmark/DrawIcon blitts mirror the
    // C# premultiplied-alpha compositing sample-for-sample.

    /**
     * Geometry for the decorated tile — a 1:1 port of the C# `Geo` ctor.
     *
     * @param int  $n       modules per side
     * @param int  $scale   px per QR module (QrPixel)
     * @param bool $icon    show the brand icon in the caption row
     * @param bool $caption show the caption row at all
     * @return array all Geo fields as ints (C# field names)
     */
    public static function geo(int $n, int $scale, bool $icon = true, bool $caption = true): array
    {
        $qrPx   = $n * $scale;
        $pad    = max(12, (int)round($qrPx * 0.08));
        $border = max(2,  (int)round($qrPx * 0.02));
        $frame  = $qrPx + 2 * $pad + 2 * $border;
        $fontPx = max(12, (int)round($frame * 0.06));
        $iconPx = max(24, (int)round($frame * 0.14));
        $g = [
            'n' => $n, 'scale' => $scale, 'pad' => $pad, 'border' => $border,
            'frame' => $frame, 'fontPx' => $fontPx, 'iconPx' => $iconPx,
            'gapTop'  => $caption ? max(10, (int)round($frame * 0.05)) : 0,
            'gapBot'  => $caption ? max(8,  (int)round($frame * 0.04)) : 0,
        ];
        $g['capH'] = $caption
            ? max($icon ? $iconPx : 1, (int)round($fontPx * 1.5))
            : 0;
        $g['W']    = $frame;
        $g['H']    = $frame + $g['gapTop'] + $g['capH'] + $g['gapBot'];
        $g['qrX']  = $border + $pad;
        $g['qrY']  = $border + $pad;
        $g['cardRad'] = max(4, (int)round($g['W'] * 0.045));
        if ($caption) {
            $g['capTop']  = $frame + $g['gapTop'];
            $g['iconY']   = $g['capTop'] + intdiv($g['capH'] - $iconPx, 2);
            $g['iconGap'] = $icon ? max(10, (int)round($iconPx * 0.18)) : 0;
            $g['iconX']   = $g['W'] - $pad - ($icon ? $iconPx : 0);
        } else {
            $g['capTop'] = $g['iconY'] = $g['iconGap'] = 0;
            $g['iconX']  = $g['W'];
        }
        return $g;
    }

    /**
     * Round-rect coverage test — 1:1 C# `InRounded` (inclusive grid math).
     */
    private static function inRounded(int $x, int $y, int $w, int $h, int $rad): bool
    {
        if ($x < 0 || $y < 0 || $x >= $w || $y >= $h) return false;
        if ($rad <= 0) return true;
        $corner = ($x < $rad || $x >= $w - $rad) && ($y < $rad || $y >= $h - $rad);
        if (!$corner) return true;
        $cx = $x < $rad ? $x : $w - 1 - $x;
        $cy = $y < $rad ? $y : $h - 1 - $y;
        $dx = $rad - $cx; $dy = $rad - $cy;
        return $dx * $dx + $dy * $dy <= $rad * $rad;
    }

    /**
     * Render the decorated tile into a flat int buffer of packed 0xRRGGBB
     * pixels (row-major, row 0 = top) — exactly what the C#
     * `RenderTile` returns.
     *
     * @param array  $modules  rows of bool (QrCode::encode()['modules'])
     * @param array  $g        geometry from self::geo()
     * @param array  $page     [r,g,b] page background (default dark 0x212121)
     * @param bool   $mono     print mode — wordmark/icon as flat ink
     * @return array{width:int,height:int,rgb:int[]}
     */
    public static function renderRgba(array $modules, array $g,
                                      array $page = null, bool $mono = false): array
    {
        $n  = $g['n'];
        if (count($modules) !== $n || count(reset($modules)) !== $n) {
            throw new \InvalidArgumentException('module matrix must be square');
        }
        $W = $g['W']; $H = $g['H'];
        $page = $page ?? [0x21, 0x21, 0x21];
        $bg      = self::pack24($page[0], $page[1], $page[2]);
        $card    = self::pack24(self::CARD_R, self::CARD_G, self::CARD_B);
        $borderC = self::pack24(self::BORDER_R, self::BORDER_G, self::BORDER_B);
        $qrLight = 0xFFFFFF;
        $qrDark  = 0x000000;
        $ink     = self::monoInk($page);

        $buf = array_fill(0, $W * $H, $bg);                      // 1) page

        // 2) card: blue rounded frame, then white rounded body
        $side  = $g['frame'] - 2 * $g['border'];
        $inner = $g['frame'] - 4 * $g['border'];
        self::fillRounded($buf, $W, $H, $g['border'], $g['border'], $side, $side, $g['cardRad'], $borderC);
        self::fillRounded($buf, $W, $H, $g['border'] * 2, $g['border'] * 2, $inner, $inner, max(0, $g['cardRad'] - $g['border']), $card);

        // 3) QR: white field, then dark modules
        $qx = $g['qrX']; $qy = $g['qrY']; $sc = $g['scale'];
        self::fillRect($buf, $W, $H, $qx, $qy, $n * $sc, $n * $sc, $qrLight);
        for ($y = 0; $y < $n; $y++) {
            $row = $modules[$y];
            for ($x = 0; $x < $n; $x++) {
                if (!empty($row[$x])) {
                    self::fillRect($buf, $W, $H, $qx + $x * $sc, $qy + $y * $sc, $sc, $sc, $qrDark);
                }
            }
        }

        // 4) caption row: baked wordmark + brand icon
        if ($g['capH'] > 0) {
            self::drawWordmark($buf, $W, $H, $g['iconX'], $g['capTop'], $g['capH'],
                               $g['fontPx'], $g['iconGap'], $mono ? $ink : null);
            if ($g['iconPx'] > 0) {
                self::drawIcon($buf, $W, $H, $g['iconX'], $g['iconY'], $g['iconPx'],
                               $mono ? $ink : null);
            }
        }

        return ['width' => $W, 'height' => $H, 'rgb' => $buf];
    }

    /** Flat-ink color for Mono (print) mode — 1:1 C# `Ink` (lum>0x80 => black). */
    private static function monoInk(array $page): array
    {
        $lum = intdiv($page[0] + $page[1] + $page[2], 3);
        return $lum > 0x80 ? [0, 0, 0] : [255, 255, 255];
    }

    /** @return int packed 0xRRGGBB */
    private static function pack24(int $r, int $g, int $b): int
    {
        return (($r & 0xFF) << 16) | (($g & 0xFF) << 8) | ($b & 0xFF);
    }

    private static function fillRect(array &$buf, int $W, int $H,
                                     int $x0, int $y0, int $w, int $h, int $color): void
    {
        for ($yy = $y0; $yy < $y0 + $h; $yy++) {
            for ($xx = $x0; $xx < $x0 + $w; $xx++) {
                if ($xx >= 0 && $xx < $W && $yy >= 0 && $yy < $H) {
                    $buf[$yy * $W + $xx] = $color;
                }
            }
        }
    }

    private static function fillRounded(array &$buf, int $W, int $H,
                                        int $x0, int $y0, int $w, int $h, int $rad, int $color): void
    {
        for ($y = 0; $y < $h; $y++) {
            $ty = $y0 + $y;
            if ($ty < 0 || $ty >= $H) continue;
            for ($x = 0; $x < $w; $x++) {
                $tx = $x0 + $x;
                if ($tx < 0 || $tx >= $W) continue;
                if (self::inRounded($x, $y, $w, $h, $rad)) {
                    $buf[$ty * $W + $tx] = $color;
                }
            }
        }
    }

    /**
     * Blit the baked "PAY by square" wordmark — sample-for-sample 1:1 port
     * of C# `DrawWordmark` (4x4 supersampled premultiplied average, aspect
     * preserved, right-aligned to iconGap before the icon or to the card
     * right pad without one, vertically centered in the caption row).
     *
     * @param int[]   $ink  flat-ink [r,g,b] for Mono mode, or null for brand
     */
    private static function drawWordmark(array &$buf, int $W, int $H, int $iconX,
                                         int $capTop, int $capH, int $fontPx,
                                         int $iconGap, ?array $ink): void
    {
        if ($fontPx <= 0 || $capH <= 0) return;
        $capW = Assets::CAP_W; $capHr = Assets::CAP_H;
        if ($capW === 0 || $capHr === 0) return;

        $dw = (int)round($fontPx * $capW / $capHr);       // aspect-preserving width
        if ($dw <= 0) return;

        $minLeft = max(4, (int)round($W * 0.01));
        $right   = $iconX < $W ? max($minLeft + 1, $iconX - $iconGap) : $W - $minLeft;
        $left    = $right - $dw;
        $top     = $capTop + intdiv($capH - $fontPx, 2);  // vertical centre

        $aa_ = self::AA;
        $n_  = $aa_ * $aa_;
        $dn  = 255 * $n_;                                  // 40800
        for ($dy = 0; $dy < $fontPx; $dy++) {
            $ty = $top + $dy;
            if ($ty < 0 || $ty >= $H) continue;
            for ($dx = 0; $dx < $dw; $dx++) {
                $tx = $left + $dx;
                if ($tx < 0 || $tx >= $W) continue;

                $ar = 0; $ag = 0; $ab = 0; $aa = 0;
                for ($sy = 0; $sy < $aa_; $sy++) {
                    $iy = (int)(($dy + ($sy + 0.5) / $aa_) * $capHr / $fontPx);
                    if ($iy < 0) $iy = 0; elseif ($iy >= $capHr) $iy = $capHr - 1;
                    $base = $iy * $capW;
                    for ($sx = 0; $sx < $aa_; $sx++) {
                        $ix = (int)(($tx - $left + ($sx + 0.5) / $aa_) * $capW / $dw);
                        if ($ix < 0) $ix = 0; elseif ($ix >= $capW) $ix = $capW - 1;
                        $p = Assets::$CAP_RGBA[$base + $ix];
                        $a = $p[3];
                        if ($a === 0) continue;
                        $ar += $p[0] * $a; $ag += $p[1] * $a; $ab += $p[2] * $a;
                        $aa += $a;
                    }
                }
                if ($aa === 0) continue;                          // fully transparent
                $al = intdiv($aa + intdiv($n_, 2), $n_);          // avg coverage 0..255
                $pr = intdiv($ar + intdiv($dn, 2), $dn);          // avg premult ink
                $pg = intdiv($ag + intdiv($dn, 2), $dn);
                $pb = intdiv($ab + intdiv($dn, 2), $dn);
                if ($ink !== null) { $pr = $ink[0]; $pg = $ink[1]; $pb = $ink[2]; }

                $back = $buf[$ty * $W + $tx];
                $br = ($back >> 16) & 0xFF; $bgc = ($back >> 8) & 0xFF; $bb = $back & 0xFF;
                $buf[$ty * $W + $tx] = self::pack24(
                    min(255, $pr + intdiv($br  * (255 - $al), 255)),
                    min(255, $pg + intdiv($bgc * (255 - $al), 255)),
                    min(255, $pb + intdiv($bb  * (255 - $al), 255)));
            }
        }
    }

    /**
     * Blit the brand icon — 1:1 port of C# `DrawIcon` (4x4 supersampled
     * alpha-weighted average, flat ink in Mono mode).
     *
     * @param int[]   $ink  flat-ink [r,g,b] for Mono mode, or null for brand
     */
    private static function drawIcon(array &$buf, int $W, int $H,
                                     int $ix, int $iy, int $size, ?array $ink): void
    {
        if ($size <= 0) return;
        $iconSize = Assets::ICON;
        $aa_ = self::AA;
        $n_  = $aa_ * $aa_;
        for ($dy = 0; $dy < $size; $dy++) {
            $ty = $iy + $dy;
            if ($ty < 0 || $ty >= $H) continue;
            for ($dx = 0; $dx < $size; $dx++) {
                $tx = $ix + $dx;
                if ($tx < 0 || $tx >= $W) continue;

                $a = 0; $ar = 0; $ag = 0; $ab = 0;
                for ($sy = 0; $sy < $aa_; $sy++) {
                    $fy = (int)(($dy + ($sy + 0.5) / $aa_) * $iconSize / $size);
                    if ($fy < 0) $fy = 0; elseif ($fy >= $iconSize) $fy = $iconSize - 1;
                    $base = $fy * $iconSize;
                    for ($sx = 0; $sx < $aa_; $sx++) {
                        $fx = (int)(($dx + ($sx + 0.5) / $aa_) * $iconSize / $size);
                        if ($fx < 0) $fx = 0; elseif ($fx >= $iconSize) $fx = $iconSize - 1;
                        $p = Assets::$ICON_RGBA[$base + $fx];
                        $pa = $p[3];
                        if ($pa === 0) continue;
                        $a += $pa;
                        $ar += $p[0] * $pa; $ag += $p[1] * $pa; $ab += $p[2] * $pa;
                    }
                }
                if ($a === 0) continue;
                $al = intdiv($a, $n_);                             // avg source alpha
                $ir = intdiv($ar, $a); $ig = intdiv($ag, $a); $ib = intdiv($ab, $a);
                if ($ink !== null) { $ir = $ink[0]; $ig = $ink[1]; $ib = $ink[2]; }

                $back = $buf[$ty * $W + $tx];
                $br = ($back >> 16) & 0xFF; $bgc = ($back >> 8) & 0xFF; $bb = $back & 0xFF;
                $buf[$ty * $W + $tx] = self::pack24(
                    intdiv($ir * $al + $br  * (255 - $al), 255),
                    intdiv($ig * $al + $bgc * (255 - $al), 255),
                    intdiv($ib * $al + $bb  * (255 - $al), 255));
            }
        }
    }

    // ===================== PNG export (native) =====================

    /**
     * Render the decorated tile as a PNG string — fully native PHP
     * (Pbs\Png encoder; no GD / Pillow / shell-out).
     *
     * @param array $modules rows of bool (QrCode::encode()['modules'])
     * @param array $opts
     *   moduleScale int px per QR module (default 10)
     *   page        [r,g,b] page background (default [33,33,33] dark)
     *   mono        bool print mode (flat ink wordmark/icon)
     *   showCaption bool (default true)
     *   showIcon    bool (default true)
     * @return string PNG bytes
     */
    public static function toPng(array $modules, array $opts = []): string
    {
        if (!class_exists(__NAMESPACE__ . '\\Png')) {
            require __DIR__ . '/Png.php';
        }
        $size  = count($modules);
        $scale = max(1, (int)($opts['moduleScale'] ?? 10));
        $cap   = (bool)($opts['showCaption'] ?? true);
        $icon  = (bool)($opts['showIcon'] ?? true);
        $page  = $opts['page'] ?? [0x21, 0x21, 0x21];
        $mono  = (bool)($opts['mono'] ?? false);

        $g    = self::geo($size, $scale, $icon, $cap);
        $tile = self::renderRgba($modules, $g, $page, $mono);

        // expand packed 0xRRGGBB to an RGBA byte string (tile is opaque)
        $W = $tile['width']; $H = $tile['height'];
        $rgba = '';
        $rgb = $tile['rgb'];
        for ($i = 0, $cnt = $W * $H; $i < $cnt; $i++) {
            $c = $rgb[$i];
            $rgba .= chr(($c >> 16) & 0xFF) . chr(($c >> 8) & 0xFF) . chr($c & 0xFF) . "\xFF";
        }
        return Png::encodeRgba($W, $H, $rgba);
    }

    /** Convenience: render the tile to $path as PNG. */
    public static function savePng(array $modules, string $path, array $opts = []): int
    {
        $n = (int)file_put_contents($path, self::toPng($modules, $opts));
        if ($n === false) throw new \RuntimeException('savePng: cannot write ' . $path);
        return $n;
    }

    // ===================== 1-bit BMP (print) =====================

    /**
     * Encode a packed 0xRRGGBB buffer as a 1-bit BMP — byte-for-byte
     * mirror of the C# `BmpSaver` (same palette, same bit order,
     * same threshold R&G&B > 0x80 = white/palette-0).
     */
    public static function toBmp1(int $w, int $h, array $rgb): string
    {
        $T = 0x80;
        if ($w <= 0 || $h <= 0) throw new \InvalidArgumentException('w/h must be positive');
        if (count($rgb) < $w * $h) {
            throw new \InvalidArgumentException('rgb has ' . count($rgb) . ' entries, need ' . $w * $h);
        }
        $rowBytes       = intdiv($w + 7, 8);
        $rowBytesPadded = $rowBytes & ~3;
        $pixelDataSize  = $rowBytesPadded * $h;
        $fileSize       = 14 + 40 + 8 + $pixelDataSize;

        $out  = "BM";
        $out .= pack('V', $fileSize);
        $out .= pack('v', 0) . pack('v', 0);
        $out .= pack('V', 14 + 40 + 8);          // offset
        $out .= pack('V', 40);                    // infohdr size
        $out .= pack('V', $w) . pack('V', $h);    // bottom-up positive
        $out .= pack('v', 1) . pack('v', 1);      // planes, bpp
        $out .= pack('V', 0) . pack('V', $pixelDataSize);
        $out .= pack('V', 2835) . pack('V', 2835);
        $out .= pack('V', 2) . pack('V', 0);      // clr used, important
        $out .= "\xFF\xFF\xFF\x00";               // idx0 WHITE (BGRA)
        $out .= "\x00\x00\x00\x00";               // idx1 BLACK

        $light = function (int $c) use ($T): bool {
            $r = ($c >> 16) & 0xFF; $gg = ($c >> 8) & 0xFF; $b = $c & 0xFF;
            return $r > $T && $gg > $T && $b > $T;
        };
        for ($y = $h - 1; $y >= 0; $y--) {          // bottom-up rows
            $row = str_repeat("\x00", $rowBytesPadded);
            for ($x = 0; $x < $w; $x++) {
                if (!$light($rgb[$y * $w + $x])) {
                    $row[intdiv($x, 8)] = $row[intdiv($x, 8)] | chr(1 << (7 - ($x & 7)));
                }
            }
            $out .= $row;
        }
        return $out;
    }

    /** Render the tile as a 1-bit (print) BMP string. */
    public static function toBmp1Tile(array $modules, array $opts = []): string
    {
        $size  = count($modules);
        $scale = max(1, (int)($opts['moduleScale'] ?? 10));
        $page  = $opts['page'] ?? [0x21, 0x21, 0x21];
        $g     = self::geo($size, $scale, true, true);
        $tile  = self::renderRgba($modules, $g, $page, true);   // Mono print mode
        return self::toBmp1($tile['width'], $tile['height'], $tile['rgb']);
    }

    /** Convenience: render the tile to $path as a 1-bit BMP. */
    public static function saveBmp1(array $modules, string $path, array $opts = []): int
    {
        $n = (int)file_put_contents($path, self::toBmp1Tile($modules, $opts));
        if ($n === false) throw new \RuntimeException('saveBmp1: cannot write ' . $path);
        return $n;
    }

    /**
     * Render the tile as a raw RGBA dump for external decoders
     * (bin/qrdecode.cjs + jsQR): [width:uint32 LE][height:uint32 LE][RGBA].
     */
    public static function toRawRgba(array $modules, array $opts = []): string
    {
        $tile = self::renderRgba(
            $modules,
            self::geo(count($modules), max(1, (int)($opts['moduleScale'] ?? 10)),
                      (bool)($opts['showIcon'] ?? true), (bool)($opts['showCaption'] ?? true)),
            $opts['page'] ?? [0x21, 0x21, 0x21],
            (bool)($opts['mono'] ?? false)
        );
        $W = $tile['width']; $H = $tile['height'];
        $px = '';
        foreach ($tile['rgb'] as $c) {
            $px .= chr(($c >> 16) & 0xFF) . chr(($c >> 8) & 0xFF) . chr($c & 0xFF) . "\xFF";
        }
        return pack('V', $W) . pack('V', $H) . $px;
    }
}
