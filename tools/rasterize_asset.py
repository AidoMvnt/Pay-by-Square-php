#!/usr/bin/env python3
"""Rasterize assets/card.svg -> assets/card.png (RGBA, transparent, crisp).

We render the official brand asset at high resolution with a *real* SVG
engine (cairosvg), so the negative-space card, layering and rounded corners
come out correct — then keep the alpha channel so it composites cleanly over
any card color.

If cairosvg is unavailable we fall back to a pure-Pillow path rasterizer
(assets/card.svg uses only M/C/Z + translate, so that is enough here).

Usage:
  python3 tools/rasterize_asset.py [--width 512] [--out assets/card.png]
"""
import argparse, os, re, sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
ASSET = os.path.join(ROOT, "assets", "card.svg")


def _hex(c):
    c = c.lstrip("#")
    return tuple(int(c[i:i + 2], 16) for i in (0, 2, 4))


def _cubic(p0, p1, p2, p3, steps=48):
    out = []
    for i in range(1, steps + 1):
        t = i / steps; u = 1 - t
        x = u**3*p0[0] + 3*u*u*t*p1[0] + 3*u*t*t*p2[0] + t**3*p3[0]
        y = u**3*p0[1] + 3*u*u*t*p1[1] + 3*u*t*t*p2[1] + t**3*p3[1]
        out.append((x, y))
    return out


def _fallback_pillow(asset, width):
    from PIL import Image, ImageDraw
    x = open(asset).read()
    vb = [float(v) for v in re.search(r'viewBox="([^"]+)"', x).group(1).split()]
    vx, vy, vw, vh = vb
    sx, sy = width / vw, width / vh
    img = Image.new("RGBA", (width, width), (0, 0, 0, 0))
    dr = ImageDraw.Draw(img)
    pat = r'<path\s+d="([^"]+)"\s+fill="([^"]+)"\s+transform="translate\(([^,]+),([^)]+)\)'
    for d, fill, tx, ty in re.findall(pat, x, re.S):
        tx, ty = float(tx), float(ty)
        seq = []
        for cmd, body in re.findall(r'([MCZ])([^MCZ]*)', d):
            vals = [float(v) for v in re.findall(r'-?\d+\.?\d*(?:[eE][-+]?\d+)?', body)]
            seq.append((cmd, vals))
        poly, last = [], (0.0, 0.0)
        for cmd, vals in seq:
            if cmd == "M" and vals:
                last = (vals[0], vals[1]); poly = [last]
            elif cmd == "C" and len(vals) == 6:
                p1 = (vals[0], vals[1]); p2 = (vals[2], vals[3]); p3 = (vals[4], vals[5])
                poly.extend(_cubic(last, p1, p2, p3)); last = p3
        if len(poly) >= 3:
            poly_s = [((px + tx) * sx, (py + ty) * sy) for (px, py) in poly]
            dr.polygon(poly_s, fill=_hex(fill) + (255,))
    return img


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--width", type=int, default=512)
    ap.add_argument("--out", default=os.path.join(ROOT, "assets", "card.png"))
    a = ap.parse_args()

    try:
        import cairosvg  # type: ignore
        buf = cairosvg.svg2png(bytestring=open(ASSET, "rb").read(), output_width=a.width)
        from PIL import Image
        import io
        img = Image.open(io.BytesIO(buf)).convert("RGBA")
        engine = "cairosvg"
    except Exception as e:
        img = _fallback_pillow(ASSET, a.width)
        engine = "pillow-fallback (%s)" % e

    img.save(a.out, "PNG")
    print("engine:", engine)
    print("wrote:", a.out, img.size, "mode", img.mode)
    # quick alpha sanity
    a_ext = img.getchannel("A").getextrema()
    print("alpha extrema:", a_ext)


if __name__ == "__main__":
    main()
