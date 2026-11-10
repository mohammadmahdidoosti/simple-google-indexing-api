// SGIA relay: forwards the plugin's Google API calls from a server Google accepts.
//
//   POST https://relay.example.com/<google-host>/<path>?<query>
//   X-Relay-Key: <RELAY_KEY>
//
// Only a fixed list of Google API hosts is reachable, every request needs the
// shared key, and nothing is stored or logged except status lines.
'use strict';

const http = require('http');
const crypto = require('crypto');

const PORT = Number(process.env.PORT || 3000);
const KEY = String(process.env.RELAY_KEY || '');
const HOSTS = new Set([
  'oauth2.googleapis.com',
  'indexing.googleapis.com',
  'searchconsole.googleapis.com',
  'www.googleapis.com',
]);
const MAX_BODY = 1024 * 1024; // 1 MB
const TIMEOUT = 25000;
// Tests only: send to a local fake instead of Google.
const TEST_UPSTREAM = process.env.RELAY_TEST_UPSTREAM || '';

function send(res, code, obj) {
  const body = JSON.stringify(obj);
  res.writeHead(code, { 'Content-Type': 'application/json; charset=utf-8', 'Content-Length': Buffer.byteLength(body) });
  res.end(body);
}

function keyOk(given) {
  if (KEY.length < 16 || typeof given !== 'string') return false;
  const a = crypto.createHash('sha256').update(given).digest();
  const b = crypto.createHash('sha256').update(KEY).digest();
  return crypto.timingSafeEqual(a, b);
}

const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://relay');

  if (url.pathname === '/health') {
    return send(res, 200, { ok: true, configured: KEY.length >= 16 });
  }
  if (KEY.length < 16) {
    return send(res, 503, { error: { code: 503, message: 'Relay not configured: set RELAY_KEY (16+ characters).' } });
  }
  if (!keyOk(req.headers['x-relay-key'])) {
    return send(res, 403, { error: { code: 403, message: 'Relay: wrong or missing X-Relay-Key.' } });
  }

  const m = url.pathname.match(/^\/([a-z0-9.-]+)(\/.*)$/i);
  if (!m || !HOSTS.has(m[1].toLowerCase())) {
    return send(res, 404, { error: { code: 404, message: 'Relay: host not allowed.' } });
  }
  if (!['GET', 'POST'].includes(req.method)) {
    return send(res, 405, { error: { code: 405, message: 'Relay: method not allowed.' } });
  }

  const chunks = [];
  let size = 0;
  req.on('data', (c) => {
    size += c.length;
    if (size > MAX_BODY) {
      send(res, 413, { error: { code: 413, message: 'Relay: body too large.' } });
      req.destroy();
      return;
    }
    chunks.push(c);
  });
  req.on('end', async () => {
    if (res.headersSent) return;
    const target = (TEST_UPSTREAM ? TEST_UPSTREAM + '/' : 'https://') + m[1].toLowerCase() + m[2] + url.search;
    const headers = {};
    for (const h of ['authorization', 'content-type', 'accept']) {
      if (req.headers[h]) headers[h] = req.headers[h];
    }
    const started = Date.now();
    try {
      const r = await fetch(target, {
        method: req.method,
        headers,
        body: req.method === 'POST' ? Buffer.concat(chunks) : undefined,
        signal: AbortSignal.timeout(TIMEOUT),
        redirect: 'manual',
      });
      const buf = Buffer.from(await r.arrayBuffer());
      res.writeHead(r.status, { 'Content-Type': r.headers.get('content-type') || 'application/json', 'Content-Length': buf.length });
      res.end(buf);
      console.log(new Date().toISOString(), req.method, m[1], r.status, (Date.now() - started) + 'ms');
    } catch (e) {
      console.log(new Date().toISOString(), req.method, m[1], 'ERR', e.name);
      send(res, 502, { error: { code: 502, message: 'Relay could not reach Google: ' + e.name } });
    }
  });
});

server.requestTimeout = 60000;
server.listen(PORT, () => console.log('sgia-relay listening on ' + PORT + (KEY.length >= 16 ? '' : ' (RELAY_KEY missing!)')));
