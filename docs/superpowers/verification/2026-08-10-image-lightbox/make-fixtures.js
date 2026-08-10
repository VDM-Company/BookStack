/* Minimal PNG writer — solid/gradient RGB images, no dependencies. */
const zlib = require('zlib');
const fs = require('fs');
const path = require('path');

function crc32(buf) {
    let c, crc = 0xFFFFFFFF;
    for (let n = 0; n < buf.length; n++) {
        c = (crc ^ buf[n]) & 0xFF;
        for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1;
        crc = c ^ (crc >>> 8);
    }
    return (crc ^ 0xFFFFFFFF) >>> 0;
}

function chunk(type, data) {
    const len = Buffer.alloc(4);
    len.writeUInt32BE(data.length);
    const td = Buffer.concat([Buffer.from(type, 'ascii'), data]);
    const crc = Buffer.alloc(4);
    crc.writeUInt32BE(crc32(td));
    return Buffer.concat([len, td, crc]);
}

function png(width, height, hue) {
    const raw = Buffer.alloc((width * 3 + 1) * height);
    let o = 0;
    for (let y = 0; y < height; y++) {
        raw[o++] = 0; // filter: none
        for (let x = 0; x < width; x++) {
            // Diagonal gradient + grid lines, so zoom/pan is visually verifiable.
            const grid = (x % 64 < 2 || y % 64 < 2) ? 60 : 0;
            raw[o++] = Math.min(255, ((x / width) * 200 + hue) | 0) + grid > 255 ? 255 : (((x / width) * 200 + hue) | 0) + grid;
            raw[o++] = Math.min(255, ((y / height) * 200 + 30) | 0 + grid);
            raw[o++] = Math.min(255, (255 - hue) | 0);
        }
    }
    const ihdr = Buffer.alloc(13);
    ihdr.writeUInt32BE(width, 0);
    ihdr.writeUInt32BE(height, 4);
    ihdr[8] = 8;  // bit depth
    ihdr[9] = 2;  // colour type: truecolour
    return Buffer.concat([
        Buffer.from([0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A]),
        chunk('IHDR', ihdr),
        chunk('IDAT', zlib.deflateSync(raw)),
        chunk('IEND', Buffer.alloc(0)),
    ]);
}

const dir = process.argv[2];
fs.mkdirSync(dir, {recursive: true});
const files = [
    ['big.png', 2400, 2400, 20],
    ['big-thumb.png', 320, 320, 20],
    ['second.png', 2400, 1600, 120],
    ['second-thumb.png', 320, 213, 120],
    ['small.png', 180, 180, 200],
];
for (const [name, w, h, hue] of files) {
    fs.writeFileSync(path.join(dir, name), png(w, h, hue));
    console.log(`${name} ${w}x${h}`);
}
