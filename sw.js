/* Draft 20 - Service Worker (v4)
 * - Navegación: network-first con fallback a la home cacheada.
 * - Assets: stale-while-revalidate (los assets van versionados con ?v=filemtime,
 *   así que la caché antigua se sustituye sola al cambiar el archivo).
 * - API: siempre red.
 */
const CACHE = 'draft20-v4';
const SHELL = ['/', '/index.php'];

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
    const req = e.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;
    if (url.pathname.indexOf('/api/') === 0) return;

    // Navegación: red primero (HTML siempre fresco), caché como respaldo offline.
    if (req.mode === 'navigate') {
        e.respondWith(
            fetch(req)
                .then(function (res) {
                    // Solo se guarda como fallback offline la home real, nunca
                    // la respuesta de otra ruta (p. ej. una ficha o guía).
                    const p = url.pathname;
                    if (res && res.ok && (p === '/' || p === '/index.php')) {
                        const copy = res.clone();
                        caches.open(CACHE).then(function (c) { c.put('/', copy); }).catch(function () {});
                    }
                    return res;
                })
                .catch(function () {
                    return caches.match('/', { ignoreSearch: true })
                        .then(function (r) { return r || caches.match('/index.php'); });
                })
        );
        return;
    }

    // Assets: sirve de caché y revalida en segundo plano.
    // Match EXACTO (sin ignoreSearch): los assets van con ?v=filemtime, así una
    // versión nueva nunca reutiliza la entrada antigua de la caché.
    e.respondWith(
        caches.match(req).then(function (cached) {
            const red = fetch(req)
                .then(function (res) {
                    if (res && res.ok) {
                        const copy = res.clone();
                        caches.open(CACHE).then(function (c) { c.put(req, copy); }).catch(function () {});
                    }
                    return res;
                })
                .catch(function () { return cached; });
            return cached || red;
        })
    );
});
