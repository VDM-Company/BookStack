/* Static server: harness at /, real theme assets at /theme/refresh/. */
const http = require('http');
const fs = require('fs');
const path = require('path');

const HARNESS = path.join(__dirname, 'harness');
// The real, committed theme assets — not a copy, so the harness cannot drift.
const THEME = path.resolve(__dirname, '../../../../themes/refresh/public');
const FIXTURES = process.env.LB_FIXTURES || path.join(__dirname, 'fixtures');

const TYPES = {
    '.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript',
    '.png': 'image/png', '.woff2': 'font/woff2',
};

http.createServer((req, res) => {
    const url = decodeURIComponent(req.url.split('?')[0]);
    let file;
    if (url.startsWith('/theme/refresh/')) {
        file = path.join(THEME, url.slice('/theme/refresh/'.length));
    } else if (url === '/' || url === '/index.html') {
        file = path.join(HARNESS, 'index.html');
    } else if (url.startsWith('/books/')) {
        // Stands in for a real BookStack page, to prove navigation still happens.
        res.writeHead(200, {'Content-Type': 'text/html'});
        return res.end('<!DOCTYPE html><title>Book page</title><h1 id="book">Book page</h1>');
    } else if (url.startsWith('/uploads/images/lbtest/')) {
        file = path.join(FIXTURES, path.basename(url));
    } else {
        file = path.join(HARNESS, url.replace(/^\//, ''));
    }
    fs.readFile(file, (err, data) => {
        if (err) {
            res.writeHead(404); return res.end('not found');
        }
        res.writeHead(200, {'Content-Type': TYPES[path.extname(file)] || 'application/octet-stream'});
        res.end(data);
    });
}).listen(8099, () => console.log('harness on http://localhost:8099'));
