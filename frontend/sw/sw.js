// Service worker (Phase 7B), generated at build time by vite.config.js, which fills in the
// version and the list of files from the bundle. Cache-first for the app itself - the shell, scripts,
// styles, fonts and icons - so the field app opens underground; the API (/v1/...) is never cached
// here (the app keeps what it needs offline in IndexedDB). A new build is a new version: it
// installs alongside, takes over at once and removes the old cache.
const VERSION = "__VERSION__";
const CACHE = `smg-${VERSION}`;
const PRECACHE = __PRECACHE__;

self.addEventListener("install", (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith("smg-") && k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener("fetch", (event) => {
  const request = event.request;
  const url = new URL(request.url);
  if (request.method !== "GET" || url.origin !== self.location.origin || url.pathname.startsWith("/v1/")) return;
  if (request.mode === "navigate") {
    // Every page of the single-page app is index.html.
    event.respondWith(caches.match("/index.html").then((hit) => hit || fetch(request)));
    return;
  }
  event.respondWith(caches.match(request).then((hit) => hit || fetch(request)));
});
