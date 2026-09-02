#!/usr/bin/env python3
"""Render the Pay by Square QR (modules JSON) to a decorated PNG via Pillow.

Layout matches src/Render.php (SVG): dark page, white rounded card with a
light-blue frame, the QR inside, and a "PAY by square" caption with the
brand icon (assets/card.png) on the right.

The icon is composited from the pre-rasterized transparent-alpha
assets/card.png (official brand asset, generated from assets/card.svg by
tools/rasterize_asset.py) -- so the alpha channel is correct and no
hand-rolled vector fill is needed.

Usage:
  python3 render.py --json /path/05-payment.json --out /path/05-payment.png
                    [--root /path/to/repo] [--scale 10]
                    [--font /path/DejaVuSans.ttf]
                    [--font-bold /path/DejaVuSans-Bold.ttf]
"""
import argparse, json, os

try:
    from PIL import Image, ImageDraw, ImageFont
except Exception as e:  # pragma: no cover
    raise SystemExit("Pillow not available: %s" % e)

BG      = (33, 33, 33)
CARD    = (255, 255, 255)
BORDER  = (127, 168, 208)
ACCENT  = (74, 109, 156)
INK     = (216, 221, 226)
QR_DARK = (0, 0, 0)


def load_icon(root, size):
    """Return the brand icon as an RGBA image resized to ~size x size.

    Prefers assets/card.png (transparent, official asset rasterized by
    tools/rasterize_asset.py). If it is missing, rasterizes assets/card.svg
    on the fly when cairosvg is available, and finally falls back to a
    simple card glyph.
    """
    png = os.path.join(root, "assets", "card.png")
    if os.path.isfile(png):
        return Image.open(png).convert("RGBA").resize((size, size), Image.LANCZOS)

    svg = os.path.join(root, "assets", "card.svg")
    if os.path.isfile(svg):
        try:
            import cairosvg, io
            buf = cairosvg.svg2png(bytestring=open(svg, "rb").read(), output_width=size)
            return Image.open(io.BytesIO(buf)).convert("RGBA")
        except Exception:
            pass

    # last-resort generic card glyph
    img = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    dr = ImageDraw.Draw(img)
    r = max(2, round(size * 0.16))
    dr.rounded_rectangle([1, 1, size - 2, size - 2], radius=r, fill=BORDER + (255,))
    for k in range(2):
        ly = round(size * (0.30 + k * 0.30))
        lh = max(2, round(size * 0.08))
        dr.rounded_rectangle([round(size * 0.18), ly, round(size * 0.82), ly + lh],
                             radius=max(1, lh // 2), fill=CARD + (255,))
    return img


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--json", required=True, help="modules JSON (size + modules[][])")
    ap.add_argument("--out", required=True, help="output .png path")
    ap.add_argument("--root", default=os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    ap.add_argument("--scale", type=int, default=10, help="px per module (crisp)")
    ap.add_argument("--font", default="/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf")
    ap.add_argument("--font-bold", default="/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf")
    a = ap.parse_args()

    data = json.load(open(a.json, "r"))
    mods = data["modules"]
    n = data["size"]
    assert len(mods) == n, "modules mismatch"

    sc      = a.scale
    qrpx    = n * sc
    pad     = max(12, round(qrpx * 0.08))
    border  = max(2,  round(qrpx * 0.02))
    frame   = qrpx + 2 * pad + 2 * border

    fontpx   = max(12, round(frame * 0.045))
    iconPx   = max(24, round(frame * 0.13))
    gapTop   = max(12, round(frame * 0.06))
    capH     = max(iconPx, round(fontpx * 1.6))
    gapBot   = max(10, round(frame * 0.04))
    W = frame
    H = frame + gapTop + capH + gapBot

    img  = Image.new("RGBA", (W, H), BG + (255,))
    dr   = ImageDraw.Draw(img)

    # white card with light-blue frame (top square)
    cr = max(4, round(frame * 0.022))
    dr.rounded_rectangle([border, border, W - border - 1, frame - border - 1],
                         radius=cr, fill=CARD + (255,), outline=BORDER + (255,), width=border)

    # QR modules
    qx = qy = border + pad
    for y in range(n):
        for x in range(n):
            if mods[y][x]:
                dr.rectangle([qx + x * sc, qy + y * sc, qx + x * sc + sc - 1, qy + y * sc + sc - 1],
                             fill=QR_DARK + (255,))

    # caption row
    capY  = frame + gapTop
    capCY = capY + capH // 2
    font   = ImageFont.truetype(a.font, fontpx)
    bold   = ImageFont.truetype(a.font_bold, fontpx)
    pay, by = "PAY", "by square"
    pw = dr.textlength(pay, font=bold)
    bw = dr.textlength(by,  font=font)
    start = pad
    dr.text((start, capCY), pay, font=bold, fill=ACCENT + (255,), anchor="lm")
    bxx = start + pw + fontpx * 0.4
    dr.text((bxx, capCY), by, font=font, fill=INK + (255,), anchor="lm")

    # brand icon (real asset, transparent alpha), right-aligned: right edge = W - pad (mirrors left text offset)
    icon = load_icon(a.root, iconPx)
    ix = int(W - pad - iconPx)
    iy = int(capY + (capH - iconPx) // 2)
    img.alpha_composite(icon, (ix, iy))

    # store RGB (opaque, no alpha) -- the tile is fully opaque anyway
    img.convert("RGB").save(a.out, "PNG")
    print("PNG", a.out, W, "x", H)


if __name__ == "__main__":
    main()
