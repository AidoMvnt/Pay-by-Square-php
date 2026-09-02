<?php
declare(strict_types=1);
namespace Pbs;

/**
 * Decorated render: QR on a white card with a light-blue frame, a
 * "PAY by square" caption below, and a small blue card-icon.
 *
 * Pure PHP (no GD / imagick). Emits a deterministic SVG — print-ready,
 * infinite resolution, safe in PDF / HTML / email.
 */
final class Render
{
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
        $iconH    = (int)round($iconW * 0.78);

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
        // PAY (accent color, bold)
        $xPay = $padPx + 4;
        $s .= self::svgText($xPay, $capMidY, $capPay, $fontPx, self::ACCENT, $textFamily, '700');
        $xBy  = $xPay + (int)(strlen($capPay) * $fontPx * 0.62) + (int)($fontPx * 0.35);
        $s .= self::svgText($xBy,  $capMidY, $capBy,  $fontPx, self::INK,   $textFamily, '400');
        // icon sits right after the caption text (as in the reference design)
        $iconX = $xBy + (int)(strlen($capBy) * $fontPx * 0.5) + (int)($fontPx * 0.35);

        // icon (rounded square + 3 strokes)
        if ($showIcon && $iconW > 0) {
            $iy = $capY + max(2, (int)round(($capRowH - $iconH) / 2));
            $s .= '<rect x="' . $iconX . '" y="' . $iy . '" width="' . $iconW . '" height="' . $iconH . '"'
               . ' fill="' . self::ICON_FILL . '" rx="' . max(4, (int)round($iconH * 0.18)) . '"/>';
            for ($k = 0; $k < 3; $k++) {
                $lw  = (int)round($iconW * 0.62);
                $ly  = $iy + (int)round($iconH * (0.28 + $k * 0.24));
                $lh  = max(2, (int)round($iconH * 0.07));
                $s .= '<rect x="' . ($iconX + (int)round($iconW * 0.16)) . '" y="' . $ly . '"'
                   . ' width="' . $lw . '" height="' . $lh . '" fill="' . self::ICON_STROKE . '"/>';
            }
        }

        $s .= '</svg>';
        return $s;
    }

    private static function svgText(int $x, int $midY, string $t, int $fontPx, string $fill, string $family, string $weight): string
    {
        return '<text x="' . $x . '" y="' . $midY . '" font-family="' . $family . '"'
             . ' font-size="' . $fontPx . '" fill="' . $fill . '"'
             . ' font-weight="' . $weight . '" dominant-baseline="central">'
             . htmlspecialchars($t, ENT_QUOTES)
             . '</text>';
    }
}
