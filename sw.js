/* Draft 20 - Service Worker
 * Shell en network-first (evita servir versiones viejas en desarrollo) con
 * fallback a caché cuando no hay red. La API siempre va a red.
 */
const CACHE = 'draft20-v2';
const SHELL = [
    './',
    './index.php',
    './juego.php',
    './css/style.css',
    './js/app.js',
    './manifest.webmanifest',
    './icons/icon-192.png',
    './icons/icon-512.png',
];

self.addEventListener('install', function (e) {
    e.waitUntil(
        caches.open(CACHE)
            .then(function (c) { return c.addAll(SHELL); })
            .then(function () { return self.skipWaiting(); })
    );
});

self.addEventListener('activate', function (e) {
    e.waitUntil(
        caches.keys()
            .then(function (keys) {
                return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
            })
            .then(function () { return self.clients.claim(); })
    );
});

self.addEventListener('fetch', function (e) {
    const url = new URL(e.request.url);
    if (e.request.method !== 'GET') return;
    if (url.pathname.indexOf('/api/') !== -1) return; // API: siempre red

    e.respondWith(
        // 'reload' ignora la caché HTTP (los assets van con ?v=filemtime).
        fetch(e.request, { cache: 'reload' })
            .then(function (res) {
                const copy = res.clone();
                caches.open(CACHE).then(function (c) { c.put(e.request, copy); }).catch(function () {});
                return res;
            })
            .catch(function () {
                return caches.match(e.request).then(function (r) {
                    return r || caches.match('./index.php');
                });
            })
    );
});
