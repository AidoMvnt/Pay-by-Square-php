<?php
declare(strict_types=1);
/*
 * 07 - Decorated QR tile (PNG + SVG) for a Pay by Square payment.
 *   php examples/07-render-tile.php            -> runs bin/qr.php (demo payment)
 *   php examples/07-render-tile.php --json P   -> reuses a dumped payment JSON
 *
 * Writes to <repo>/out/ :  05-payment.svg / .png / .json  + pbstring.txt
 */
$extra = array_slice($argv, 1);
passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../bin/qr.php') . ($extra ? ' ' . escapeshellarg(implode(' ', $extra)) : ''), $rc);
exit($rc);
