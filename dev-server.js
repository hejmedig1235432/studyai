// Studiepilot local dev server (Windows-friendly).
//
// PHP's built-in server handles ONE request at a time, so a slow AI call would freeze the whole app.
// This script starts several PHP server processes plus the background worker, and puts a small proxy
// in front that sends each request to the least busy PHP process.
//
//   node dev-server.js                 → http://localhost:8088
//   PORT=8090 PHP_WORKERS=8 node dev-server.js
//   PHP_BINARY="C:\path\to\php.exe" node dev-server.js
//
// No npm packages needed. For hosting, use Apache/Nginx + PHP-FPM instead (see README).
const http = require('http');
const { spawn } = require('child_process');
const path = require('path');

const PORT = +(process.env.PORT || 8088);
const COUNT = Math.max(2, +(process.env.PHP_WORKERS || 6));
const BASE_PORT = +(process.env.PHP_BASE_PORT || 9100);
const PHP = process.env.PHP_BINARY || 'php';
const ROOT = __dirname;

const backends = [];
const children = [];

function startPhp(i) {
  const port = BASE_PORT + i;
  const child = spawn(PHP, ['-S', `127.0.0.1:${port}`, '-t', 'public', 'public/router.php'], { cwd: ROOT, stdio: ['ignore', 'ignore', 'pipe'] });
  const b = backends[i] || (backends[i] = { port, busy: 0, alive: false });
  b.alive = true;
  child.stderr.on('data', d => {
    const s = d.toString();
    // php -S logs every request to stderr; only show real problems.
    if (/Fatal|Failed to listen|Warning|Error/i.test(s) && !/Accepted|Closing|\[200\]|\[30\d\]|\[4\d\d\]/.test(s)) process.stderr.write(`[php:${port}] ${s}`);
  });
  child.on('exit', code => {
    b.alive = false;
    if (!shuttingDown) {
      console.error(`[php:${port}] exited (${code}), restarting…`);
      setTimeout(() => startPhp(i), 1000);
    }
  });
  children[i] = child;
}

let worker = null;
function startWorker() {
  worker = spawn(PHP, [path.join('bin', 'worker.php')], { cwd: ROOT, stdio: ['ignore', 'inherit', 'inherit'] });
  worker.on('exit', code => {
    if (!shuttingDown) {
      console.error(`[worker] exited (${code}), restarting…`);
      setTimeout(startWorker, 2000);
    }
  });
}

function pick() {
  const alive = backends.filter(b => b.alive);
  if (!alive.length) return null;
  return alive.reduce((a, b) => (b.busy < a.busy ? b : a));
}

// php -S closes every connection after one response, so never reuse sockets (Node's default
// keep-alive agent would otherwise hit already-closed sockets → ECONNRESET).
const agent = new http.Agent({ keepAlive: false });

function forward(req, res, body, attempt) {
  const b = pick();
  if (!b) {
    res.writeHead(503, { 'Content-Type': 'text/plain' });
    return res.end('Studiepilot starter – genindlæs om et øjeblik.');
  }
  b.busy++;
  let done = false;
  const finish = () => { if (!done) { done = true; b.busy--; } };
  const headers = { ...req.headers, 'x-forwarded-for': req.socket.remoteAddress, connection: 'close' };
  if (body) headers['content-length'] = body.length;
  const proxy = http.request({ host: '127.0.0.1', port: b.port, method: req.method, path: req.url, headers, agent }, pres => {
    const h = { ...pres.headers };
    delete h.connection;
    res.writeHead(pres.statusCode, h);
    pres.pipe(res);
    pres.on('end', finish);
  });
  proxy.setTimeout(10 * 60 * 1000);
  proxy.on('error', err => {
    finish();
    // Connection problems before any response: retry once on another PHP process.
    if (attempt === 0 && !res.headersSent && ['ECONNRESET', 'ECONNREFUSED', 'EPIPE'].includes(err.code)) {
      return forward(req, res, body, 1);
    }
    if (!res.headersSent) res.writeHead(502, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: 'The local PHP server did not respond (' + err.code + '). Please try again.' }));
  });
  res.on('close', finish);
  proxy.end(body);
}

const server = http.createServer((req, res) => {
  // Buffer the request body so it can be re-sent if the first attempt fails.
  const chunks = [];
  req.on('data', c => chunks.push(c));
  req.on('end', () => forward(req, res, chunks.length ? Buffer.concat(chunks) : null, 0));
});
server.timeout = 0;
server.requestTimeout = 0;

let shuttingDown = false;
function shutdown() {
  shuttingDown = true;
  children.forEach(c => c && c.kill());
  worker && worker.kill();
  process.exit(0);
}
process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);

for (let i = 0; i < COUNT; i++) startPhp(i);
startWorker();
setTimeout(() => {
  server.listen(PORT, () => console.log(`Studiepilot running at http://localhost:${PORT}  (${COUNT} PHP processes + background worker). Ctrl+C to stop.`));
}, 600);
