#!/usr/bin/env node
// Decode a QR code from a raw RGBA dump.
//
// Usage: node qrdecode.cjs <file.raw>
//   <file.raw> = <width:uint32 LE><height:uint32 LE><RGBA bytes>
//
// Outputs: "DECODED:<data>" on success, "NODECODE" if no QR found,
//          "ERR:<message>" on failure.
"use strict";
const path = require("path");
const fs = require("fs");
let jsQR;
try {
    jsQR = require(path.join(__dirname, "..", "node_modules", "jsqr", "dist", "jsQR.js"));
} catch (e) {
    jsQR = require("jsqr");
}
if (jsQR && jsQR.default) { jsQR = jsQR.default; }
const raw = fs.readFileSync(process.argv[2]);
const w = raw.readUInt32LE(0);
const h = raw.readUInt32LE(4);
const px = new Uint8ClampedArray(raw.buffer, raw.byteOffset + 8, w * h * 4);
const o = jsQR(px, w, h, { inversionAttempts: "attemptBoth" });
if (o) { console.log("DECODED:" + o.data); }
else { console.log("NODECODE"); process.exit(3); }
