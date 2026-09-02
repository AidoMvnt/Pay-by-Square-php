#!/usr/bin/env python3
"""Render the Pay by Square QR (modules JSON) to a decorated PNG via Pillow.

Layout matches src/Render.php SVG: dark page, white rounded card with a
light-blue frame, the QR, a "PAY by square" caption, and a small blue
card-icon on the right.

Usage:
  python3 render.py --json /path/05-payment.json --out /path/05-payment.png
                    [--font /path/DejaVuSans.ttf]
"""
import argparse, json, sys

try:
    from PIL import Image, ImageDraw, ImageFont
except Exception as e:
    sys.exit("Pillow not available: %s" % e)

BG      = (33, 33, 33)
CARD    = (255, 255, 255)
BORDER  = (127, 168, 208)
ACCENT  = (74, 109, 156)
INK     = (216, 221, 226)
QR_DARK = (0, 0, 0)
QR_LITE = (255, 255, 255)
ICON    = (127, 168, 208)
ICON_S  = (255, 255, 255)

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--json", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--scale", type=int, default=10, help="px per module (PNG; crisp)")
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
    card    = qrpx + 2 * pad
    frame   = card + 2 * border

    fontpx   = max(12, round(frame * 0.045))
    iconW    = max(24, round(frame * 0.125))
    iconH    = round(iconW * 0.78)
    gapTop   = max(12, round(frame * 0.06))
    capH     = max(iconH, round(fontpx * 1.6))
    gapBot   = max(10, round(frame * 0.04))

    W = frame
    H = frame + gapTop + capH + gapBot

    img  = Image.new("RGB", (W, H), BG)
    dr   = ImageDraw.Draw(img)

    # frame (white card, blue border) via rounded rect
    cr = max(4, round(frame * 0.022))
    dr.rounded_rectangle([border, border, W-border-1, frame-border-1],
                         radius=cr, fill=CARD, outline=BORDER, width=border)
    # (frame area is the top square)

    # QR
    qx, qy = border + pad, border + pad
    for y in range(n):
        row = mods[y]
        for x in range(n):
            if row[x]:
                dr.rectangle([qx + x*sc, qy + y*sc, qx + x*sc + sc - 1, qy + y*sc + sc - 1],
                             fill=QR_DARK)

    # caption row
    capY  = frame + gapTop
    capCY = capY + capH // 2
    font   = ImageFont.truetype(a.font, fontpx)
    bold   = ImageFont.truetype(a.font_bold, fontpx)
    pay  = "PAY"
    by   = "by square"
    pw   = dr.textlength(pay, font=bold)
    bw   = dr.textlength(by,  font=font)
    total = pw + bw + fontpx * 0.4
    start = pad
    dr.text((start, capCY), pay, font=bold, fill=ACCENT, anchor="lm")
    bxx = start + pw + fontpx*0.4
    dr.text((bxx, capCY), by, font=font, fill=INK, anchor="lm")

    # icon (rounded square + 3 strokes) right after the caption text
    ix = bxx + bw + round(fontpx * 0.35)
    iy = capY + (capH - iconH)//2
    dr.rounded_rectangle([ix, iy, ix+iconW-1, iy+iconH-1], radius=max(4, round(iconH*0.2)), fill=ICON)
    for k in range(3):
        lw = round(iconW * 0.62)
        ly = iy + round(iconH * (0.28 + k*0.24))
        lh = max(2, round(iconH*0.07))
        dr.rectangle([ix+round(iconW*0.16), ly, ix+round(iconW*0.16)+lw, ly+lh], fill=ICON_S)

    img.save(a.out, "PNG")
    print("PNG", a.out, W, "x", H)

if __name__ == "__main__":
    main()
