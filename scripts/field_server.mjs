// Serves the built app (frontend/dist, `npm run build:field`) for phones, and proxies /v1 to the
// API, so the page and the API share one origin: no CORS, no mixed content, the phone never needs
// to know where the API runs. No packages - Node's own http/https.
//
//   https://<this PC's LAN address>:5443   phones on the same Wi-Fi (needs certs/, `node scripts/make_cert.mjs`,
//                                           and the local CA installed on the phone: docs/FIELD_APP_SETUP.md)
//   http://localhost:5180                  this PC, and phones over USB port forwarding (chrome://inspect):
//                                           localhost is a secure context, so no certificate is needed
//   http://<LAN address>:5080/ca.crt       the local CA certificate, to install on a phone (nothing else)
//
//   node scripts/field_server.mjs [--no-https]     env: API_ORIGIN (default http://127.0.0.1:8080),
//                                                   FIELD_HTTPS_PORT, FIELD_HTTP_PORT, FIELD_CA_PORT
import { X509Certificate } from "node:crypto";
import { createReadStream, existsSync, readFileSync, statSync } from "node:fs";
import http from "node:http";
import https from "node:https";
import { networkInterfaces } from "node:os";
import { extname, join, normalize, resolve } from "node:path";

const ROOT = resolve(new URL(".", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1"), "..");
const DIST = join(ROOT, "frontend", "dist");
const CERTS = join(ROOT, "certs");
const API = new URL(process.env.API_ORIGIN ?? "http://127.0.0.1:8080");
const HTTPS_PORT = Number(process.env.FIELD_HTTPS_PORT ?? 5443);
const HTTP_PORT = Number(process.env.FIELD_HTTP_PORT ?? 5180);
const CA_PORT = Number(process.env.FIELD_CA_PORT ?? 5080);
const NO_HTTPS = process.argv.includes("--no-https");

const TYPES = {
  ".html": "text/html; charset=utf-8", ".js": "text/javascript; charset=utf-8", ".css": "text/css; charset=utf-8",
  ".json": "application/json", ".webmanifest": "application/manifest+json", ".svg": "image/svg+xml", ".png": "image/png",
  ".jpg": "image/jpeg", ".woff2": "font/woff2", ".woff": "font/woff", ".ico": "image/x-icon", ".txt": "text/plain; charset=utf-8",
};

if (!existsSync(join(DIST, "index.html"))) {
  console.error("frontend/dist is missing: run `npm run build:field` in frontend first.");
  process.exit(1);
}

function proxy(req, res) {
  const upstream = http.request({
    hostname: API.hostname, port: API.port || 80, method: req.method, path: req.url,
    headers: { ...req.headers, host: API.host, "x-forwarded-for": req.socket.remoteAddress ?? "", "x-forwarded-proto": req.socket.encrypted ? "https" : "http" },
  }, (answer) => {
    res.writeHead(answer.statusCode ?? 502, answer.headers);
    answer.pipe(res);
  });
  upstream.on("error", () => {
    if (!res.headersSent) res.writeHead(502, { "content-type": "application/json" });
    res.end(JSON.stringify({ error: { code: "API_UNREACHABLE" } }));
  });
  req.pipe(upstream);
}

// Phase 8 security pass (docs/SECURITY.md): the app loads only from this origin (plus map tiles),
// is never framed, and sends no referrer. Inline style attributes are allowed (React, Leaflet).
const CSP = [
  "default-src 'self'", "script-src 'self'", "style-src 'self' 'unsafe-inline'", "font-src 'self' data:",
  "img-src 'self' data: blob: https://*.tile.openstreetmap.org", "connect-src 'self'", "worker-src 'self'",
  "manifest-src 'self'", "frame-ancestors 'none'", "base-uri 'self'", "form-action 'self'", "object-src 'none'",
].join("; ");

function serveFile(res, path, cache) {
  res.writeHead(200, { "content-type": TYPES[extname(path)] ?? "application/octet-stream", "cache-control": cache,
    "x-content-type-options": "nosniff", "x-frame-options": "DENY", "referrer-policy": "no-referrer",
    "content-security-policy": CSP });
  createReadStream(path).pipe(res);
}

function app(req, res) {
  const url = new URL(req.url, "http://x");
  if (url.pathname.startsWith("/v1/") || url.pathname === "/v1") return proxy(req, res);
  if (req.method !== "GET" && req.method !== "HEAD") {
    res.writeHead(405).end();
    return;
  }
  const path = normalize(join(DIST, decodeURIComponent(url.pathname)));
  if (!path.startsWith(DIST)) {
    res.writeHead(400).end();
    return;
  }
  if (existsSync(path) && statSync(path).isFile()) {
    // Hashed assets never change; the shell, the manifest and the service worker must be re-checked.
    const immutable = url.pathname.startsWith("/assets/");
    return serveFile(res, path, immutable ? "public, max-age=31536000, immutable" : "no-cache");
  }
  if (extname(url.pathname)) {
    res.writeHead(404).end();
    return;
  }
  serveFile(res, join(DIST, "index.html"), "no-cache");   // a route of the single-page app
}

const lan = Object.values(networkInterfaces()).flat().filter((a) => a && a.family === "IPv4" && !a.internal).map((a) => a.address);

http.createServer(app).listen(HTTP_PORT, "127.0.0.1", () =>
  console.log(`app over http (this PC, USB forwarding):  http://localhost:${HTTP_PORT}/field`));

const keyFile = join(CERTS, "server.key");
const certFile = join(CERTS, "server.crt");
const caFile = join(CERTS, "ca.crt");
if (!NO_HTTPS && existsSync(keyFile) && existsSync(certFile)) {
  // The certificate names the LAN addresses it was made for; a new address (another Wi-Fi, DHCP) needs a new one.
  const san = new X509Certificate(readFileSync(certFile)).subjectAltName ?? "";
  const uncovered = lan.filter((ip) => !san.includes(`IP Address:${ip}`));
  if (uncovered.length) console.log(`warning: certs/server.crt does not cover ${uncovered.join(", ")} - run \`node scripts/make_cert.mjs\` again (phones keep the same CA)`);
  https.createServer({ key: readFileSync(keyFile), cert: readFileSync(certFile) }, app).listen(HTTPS_PORT, "0.0.0.0", () => {
    for (const ip of lan) console.log(`app over https (phones on this network):  https://${ip}:${HTTPS_PORT}/field`);
  });
  if (existsSync(caFile)) {
    http.createServer((req, res) => {
      if (req.url === "/ca.crt" || req.url === "/") {
        res.writeHead(200, { "content-type": "application/x-x509-ca-cert", "content-disposition": 'attachment; filename="smart-mine-local-ca.crt"' });
        createReadStream(caFile).pipe(res);
      } else {
        res.writeHead(404).end();
      }
    }).listen(CA_PORT, "0.0.0.0", () => {
      for (const ip of lan) console.log(`local CA certificate for phones:          http://${ip}:${CA_PORT}/ca.crt`);
    });
  }
} else if (!NO_HTTPS) {
  console.log("no certs/server.crt: HTTPS is off. Run `node scripts/make_cert.mjs` (docs/FIELD_APP_SETUP.md), or use USB forwarding.");
}
console.log(`API: ${API.origin}  (proxied at /v1)`);
