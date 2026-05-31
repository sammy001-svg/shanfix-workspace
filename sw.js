/**
 * OrbitDesk Workspace — Service Worker
 * Provides offline shell + static asset caching for PWA install.
 */
const CACHE_NAME    = 'orbitdesk-v1';
const OFFLINE_URL   = '/client/index.php';

// Static assets to pre-cache on install
const PRECACHE = [
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css',
    '/assets/css/style.css',
    '/assets/css/mobile.css',
    '/assets/css/dark-mode.css',
];

// ── Install: pre-cache static assets ──────────────────────────────────────────
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => cache.addAll(PRECACHE).catch(() => {}))
            .then(() => self.skipWaiting())
    );
});

// ── Activate: clean up old caches ────────────────────────────────────────────
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(
                keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
            ))
            .then(() => self.clients.claim())
    );
});

// ── Fetch: network-first for HTML, cache-first for static assets ──────────────
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);

    // Only handle same-origin or CDN requests
    if (request.method !== 'GET') return;

    // HTML navigation: network-first, fall back to cached shell
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then(resp => {
                    if (resp.ok) {
                        const clone = resp.clone();
                        caches.open(CACHE_NAME).then(c => c.put(request, clone));
                    }
                    return resp;
                })
                .catch(() => caches.match(OFFLINE_URL) || caches.match(request))
        );
        return;
    }

    // CSS/JS/fonts: cache-first
    const isStatic = /\.(css|js|woff2?|ttf|svg|png|jpg|webp|ico)(\?|$)/.test(url.pathname);
    if (isStatic) {
        event.respondWith(
            caches.match(request).then(cached => {
                if (cached) return cached;
                return fetch(request).then(resp => {
                    if (resp.ok) {
                        caches.open(CACHE_NAME).then(c => c.put(request, resp.clone()));
                    }
                    return resp;
                });
            })
        );
        return;
    }

    // API / AJAX: network-only
    event.respondWith(fetch(request).catch(() => new Response('{"error":"offline"}', {
        status: 503,
        headers: { 'Content-Type': 'application/json' }
    })));
});

// ── Push notifications ────────────────────────────────────────────────────────
self.addEventListener('push', event => {
    let data = {};
    try { data = event.data?.json() ?? {}; } catch (e) {}
    const title   = data.title   ?? 'OrbitDesk';
    const options = {
        body:    data.body    ?? '',
        icon:    data.icon    ?? '/assets/images/icon-192.png',
        badge:   data.badge   ?? '/assets/images/icon-192.png',
        data:    { url: data.url ?? '/client/index.php' },
        vibrate: [100, 50, 100],
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const target = event.notification.data?.url ?? '/client/index.php';
    event.waitUntil(clients.openWindow(target));
});
