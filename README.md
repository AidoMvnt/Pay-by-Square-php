# Pay by Square — PHP (QR generator)

> ⚠️ **Disclaimer:** This code is **AI-generated** and provided **without any
> warranty whatsoever**. Use it **at your own risk** only — not intended for
> real financial transactions without thorough verification.

A small, dependency-light **QR Code generator** with a dedicated **Pay by Square
(SK) payment layer** on top. Given a payment (IBAN, amount, variable/constant/
specific symbol, due date, parties), it produces the exact **base32hex `pbstring`**
that Slovak banks expect, and renders it as a scannable QR Code (SVG).

## What it does

- **QR engine** — Reed–Solomon error correction, all mask patterns, versions 1–40.
  A verbatim PHP port of Project Nayuki's `qrcodegen` (see license notes below).
- **Pay by Square layer** — builds the tab-separated field record, prepends a
  little-endian CRC32, compresses with **LZMA1** (`lc=3, lp=0, pb=2`, dictionary
  128 KiB, end-marker on) using a **pure-PHP LZMA1 encoder with zero
  dependencies** (`src/Lzma1.php`, `Lzma1Encoder::compressRaw()`), wraps in the
  4-byte header, and encodes to **Base32Hex**. Output is byte-compatible with
  the official `bysquare` bank decoder (verified by decode round-trip).
- **OS independent** — pure PHP 8, no GDI, no Composer packages, and
  **no external binaries at all** (the LZMA1 encoder is built in). Runs on
  Windows, Linux and macOS.
- **Self-contained** — no `composer.json`, no autoloader. All classes live
  under the single `Pbs` namespace and load with plain `require`.
- **Tests** — `tests/run-tests.php` covers QR (matrix + ECC + penalty +
  round-trip via jsQR), CRC32 against the C# oracle, LZMA1 round-trip, and
  Pay-by-Square wire round-trip against the real `bysquare` decoder.

## Quick start

```bash
# unit tests (LZMA1 + CRC32 + bysquare round-trip via node — the harness
# skips the jsQR check gracefully if node is unavailable)
php tests/run-tests.php

# Pay by Square QR (Slovakia) — print the base32hex `pbstring`
php -r '
  require "src/QrCode.php";
  require "src/PayBySquare.php";
  $p = new Pbs\Payment();
  $p->amount = "42.50";
  $p->currency = "EUR";
  $p->dueDate = "20260930";
  $p->setVariableSymbol("2026090114");
  $p->setConstantSymbol("12345");
  $p->paymentNote = "Faktura 14/26";
  $p->addAccount("SK6807200002891987426353", "TATRSKBX");
  $p->payeeName = "Mainvent s.r.o.";
  $p->payeeCity = "Bratislava";
  echo Pbs\PayBySquare::encode($p), PHP_EOL;
'
```

`examples/` provides ready-to-run PHP scripts for a number of common
variations (minimal payment, full payment, multiple accounts, specific-symbol
only, BIC lookup, decorated QR tile). Each of them is a single file — `php
examples/<name>.php`.

## Requirements

- **PHP >= 8.0** (only stdlib; no `mbstring` or other extensions required).
  **No external binaries** — the LZMA1 encoder ships with the library.

## Decorated QR tile

The library renders the QR into a payment tile that matches the official
Pay by Square look: a **card** with a light-blue frame around a quiet-zone
QR code, and below it the **"PAY by square"** caption with the small card icon.

    $ php bin/qr.php                  # demo payment -> out/05-payment.{svg,png,bmp1,json}
    $ php bin/qr.php --light          # white page background
    $ php examples/07-render-tile.php # same, via the examples entry point

- `out/05-payment.svg`  — **vector** tile (PHP only; `src/Render.php`), crisp at any size.
- `out/05-payment.png`  — **raster 24-bit** tile, **native pure PHP**
  (Pbs\Png encoder + `Render::toPng()` — no Pillow, no python, no shell).
- `out/05-payment.bmp1` — **1-bit print** tile (flat black/white ink,
  byte-for-byte compatible with the C# `BmpSaver` reference).
- `out/05-payment.json` — module matrix + meta (handy for custom renderers).

All raster outputs are **exactly decodable**: `tests/run-tests.php` section 6
renders the native PNG and decodes it with an independent decoder (jsQR),
expecting back the identical pbstring. `bin/qrdecode.cjs` is the standalone
decoder helper (raw RGBA in -> text out).

Parity is pinned against the C# reference implementation
(`AidoMvnt/Pay-by-Square-cs`, `QrTileDecorator`): the PHP `Render::toPng()`
replicates it pixel-for-pixel (identical geometry, AA supersampling, caption
compositing) — verified by rendering the same QR grid on both sides and
byte-comparing.

Custom look: `Render::toPng($modules, $opts)` accepts `moduleScale`, `page`
(r,g,b), `mono` (print mode), `showCaption`, `showIcon`. `Render::toSvg(...)`
accepts `moduleScale`, `borderPx`, `padPx`, `capFontPx`, `capPay`, `capBy`,
`iconW`, `showIcon` plus the palette constants on the class — all overridable
per call.

### Brand assets (baked from the C# ground truth)

`src/Assets.php` holds the "PAY by square" wordmark (470×76 RGBA) and the
64×64 card icon as packed pixel arrays, exported from the C# reference
binary assets so the wordmark is **pixel-identical** to the ground-truth
render — no re-baking with a different font / rasterizer. Regenerate with:

    $ python3 tools/gen_assets.py   # parses C# TileAssets.cs -> src/Assets.php

## Project layout

```
src/
  QrCode.php        Pure-PHP QR encoder (Nayuki port, versions 1–40, all masks)
  Crc32.php         CRC32 (IEEE, init 0xFFFFFFFF, final XOR) over UTF-8 bytes
  Lzma1.php         Pure-PHP LZMA1 encoder (no external deps)
  Base32Hex.php     Base32Hex encode/decode (0-9 A-Z, hex nibble pairs)
  Bics.php          Slovak bank-code → BIC dictionary
  PayBySquare.php   Payment model + wire format (build, CRC, LZMA, header, B32Hex)
  Render.php        Decorated tile: SVG + native PNG + 1-bit BMP (geo 1:1 with C#)
  Png.php           Pure-PHP PNG encoder (deflate stored blocks + hand adler32)
  Assets.php        Baked wordmark (470×76) + icon (64×64) RGBA — C# ground truth

bin/
  qr.php            CLI generator: payment -> out/{svg,png,bmp1,json} (decorated tile)
  qrdecode.cjs      QR decoder helper (jsQR): raw RGBA file -> decoded text
assets/             Brand icon: card.svg (vector original) + card.png (raster, alpha)
tools/
  gen_assets.py     Parse C# TileAssets.cs -> src/Assets.php (pixel-exact ground truth)
  rasterize_asset.py  Re-rasterize assets/card.svg -> assets/card.png
examples/           Ready-to-run sample scripts (php examples/<name>.php)
tests/              Self-test harness (php tests/run-tests.php)
node_modules/       dev-only (jsQR) — used by the harness's decode self-check
```

## Sources / credit

- **QR Code generator:** port of **Project Nayuki — QR Code generator**
  (https://github.com/nayuki/QR-Code-generator, MIT License), originally from the
  C reference implementation, adapted to PHP.
- **LZMA1 compression:** `src/Lzma1.php` — a self-contained pure-PHP LZMA1
  range coder (hash-chain match finding, lc/lp/pb-configurable). AI-generated
  with Claude Code, as part of a multi-agent project (see
  "How it was generated"). Follows the LZMA algorithm authored by Igor Pavlov
  (7‑Zip, public-domain-style license); no binary or extension dependency.
  Verified to decode byte-compatible with the official `bysquare` npm decoder.
- **Pay by Square wire format:** the field ordering, CRC32, LZMA1 parameters and
  Base32Hex layout follow the **bysquare.sk** official reference
  implementation; the BIC dictionary is derived from that same reference
  (Slovak bank code → BIC).

## How it was generated

This implementation is a collaborative, **AI-generated** project — written with
**Claude Code** (Anthropic's coding agent), **Hermes Agent** (the orchestration
assistant driving the session), **Qwen 3.8-128K** (used for coding work) and
**Gemma 4-128K** (primary chat model), both of the latter two running locally
on Ollama — while assisting Martin. The agents drew on: the Nayuki QR
reference, the `bysquare` (npm) reference implementation for the Pay by Square
wire format and BIC dictionary, and a pure-PHP LZMA1 encoder. Golden test
vectors and decode round-trips (via the real `bysquare` npm decoder) were used
to verify byte-compatibility. No proprietary payment data has been baked in.

## Troubleshooting

### Output does not decode in a bank app
Regenerate with a valid 24-digit IBAN, 1–16-digit numeric variable symbol,
and a numeric amount. The `examples/` scripts are known-good inputs;
`tests/run-tests.php` performs a decode round-trip through the real
`bysquare` bank decoder.

## License

**MIT License** — free and open for **any** use, including commercial use.
Anyone may use, copy, modify, and redistribute this code.

The bundled/port of Project Nayuki QR-Code-generator code additionally carries
its own **MIT** license (see `src/QrCode.php` header). XZ/LZMA components are
public-domain-style.

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in the
Software without restriction, including without limitation the rights to use, copy,
modify, merge, publish, distribute, sublicense, and/or sell copies of the Software,
and to permit persons to whom the Software is furnished to do so, subject to the
following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED,
INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A
PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF
CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE
OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

Copyright © 2026 Martin Bujnak (Mainvent s.r.o.). All rights free as per MIT.