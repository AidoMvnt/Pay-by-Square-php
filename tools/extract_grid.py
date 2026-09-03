"""
extract_grid.py — pull the QR module grid out of a C# reference tile BMP.

Usage:
    python3 tools/extract_grid.py <cs-tile.bmp> [out-grid.txt]

Assumes the C# `--tile` defaults (scale = 10 px/module; see
Pay-by-Square-cs Program.cs / QrTileDecorator.cs):

    pad    = max(12, round(qrPx * 0.08))
    border = max(2,  round(qrPx * 0.02))
    frame  = qrPx + 2*pad + 2*border          (qrPx = n * scale)

The grid size n is solved from the tile width (n % 4 == 1), the top-left
module is located by that geometry, and each module is sampled at its
center. Dark module = black ink (gray < 128). Output: ASCII '0'/'1' rows,
top-left origin, default /tmp/grid.txt.
"""
import sys
import struct


def bmp24(path):
    with open(path, "rb") as f:
        d = f.read()
    if d[:2] != b"BM":
        raise SystemExit("not a BMP")
    off = struct.unpack_from("<I", d, 10)[0]
    w, h = struct.unpack_from("<ii", d, 18)
    bpp = struct.unpack_from("<H", d, 28)[0]
    if bpp != 24:
        raise SystemExit(f"expected 24bpp, got {bpp}")

    def px(x, y):
        i = off + ((h - 1 - y) * w + x) * 3   # bottom-up rows
        return d[i + 2], d[i + 1], d[i]      # -> (R, G, B)

    return w, h, px


def solve_n(width, scale=10):
    def frame(n):
        qr = n * scale
        pad = max(12, round(qr * 0.08))
        border = max(2, round(qr * 0.02))
        return qr + 2 * (pad + border)

    for n in range(17, 120, 4):           # QR sizes are 1 mod 4
        if frame(n) == width:
            return n
    raise SystemExit(f"no n matches width {width} (scale={scale})")


def main():
    if len(sys.argv) < 2:
        sys.exit(f"usage: {sys.argv[0]} <cs-tile.bmp> [grid.txt]")
    src = sys.argv[1]
    out = sys.argv[2] if len(sys.argv) > 2 else "/tmp/grid.txt"

    w, h, px = bmp24(src)
    scale = 10
    n = solve_n(w, scale)

    qr = n * scale
    pad = max(12, round(qr * 0.08))
    border = max(2, round(qr * 0.02))
    frame = qr + 2 * (pad + border)
    x0 = (w - frame) // 2 + pad + border   # left edge of module grid
    y0 = pad + border                      # top edge of module grid

    gray = lambda x, y: (px(x, y)[0] + px(x, y)[1] + px(x, y)[2]) / 3
    grid = []
    for r in range(n):
        row = []
        for c in range(n):
            x = x0 + c * scale + scale // 2
            y = y0 + r * scale + scale // 2
            row.append("1" if gray(x, y) < 128 else "0")
        grid.append(row)

    with open(out, "w") as f:
        for row in grid:
            f.write("".join(row) + "\n")
    dark = sum(r.count("1") for r in grid)
    print(f"grid {n}x{n} (dark={dark}) -> {out}")


if __name__ == "__main__":
    main()
