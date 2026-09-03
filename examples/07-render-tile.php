<?php
declare(strict_types=1);
/*
 * 07 - Decorated Pay by Square tile (native PNG + 1-bit print BMP + SVG + JSON).
 *
 *   php examples/07-render-tile.php                 # demo payment
 *   php examples/07-render-tile.php --amount 42.50 --iban SK6807200002891987426353 --ref 12345
 *   php examples/07-render-tile.php --json P        # reuse a dumped payment JSON
 *
 * All rendering happens in PHP (no Pillow, no GD) -> <repo>/out/.
 */
$extra = array_slice($argv, 1);
passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../bin/qr.php')
    . ($extra ? ' ' . escapeshellarg(implode(' ', $extra)) : ''), $rc);
exit($rc);
