/**
 * OrbitDesk Workspace — Service Worker (minimal)
 *
 * This SW handles ONLY push notifications and notification clicks.
 * It does NOT cache any assets or intercept any fetch requests.
 * CSS / JS are always loaded fresh from the CDN and server so the
 * layout is never broken by stale cached responses.
 */

const SW_VERSION = 'orbitdesk-v3'; // bump to force-replace all old SWs

// ── Install: activate immediately, clear ALL old caches ──────────────────────
self.addEventListener('install', event => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(keys.map(k => caches.delete(k))))
            .then(() => self.skipWaiting())
    );
});

// ── Activate: claim all clients so they get the clean SW right away ──────────
self.addEventListener('activate', event => {
    event.waitUntil(self.clients.claim());
});

// ── Fetch: pass everything straight through — no caching ─────────────────────
// (intentionally no fetch handler — browser uses its own HTTP cache normally)

// ── Allow the page to force an immediate SW update ───────────────────────────
self.addEventListener('message', event => {
    if (event.data?.type === 'SKIP_WAITING') self.skipWaiting();
});

// ── Push notifications ────────────────────────────────────────────────────────
self.addEventListener('push', event => {
    let data = {};
    try { data = event.data?.json() ?? {}; } catch (e) {}
    const title = data.title ?? 'OrbitDesk Workspace';
    const options = {
        body:    data.body  ?? '',
        icon:    data.icon  ?? '/assets/images/favicon.svg',
        badge:   data.badge ?? '/assets/images/favicon.svg',
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
