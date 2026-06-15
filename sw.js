/**
 * Water Billing System — Service Worker
 *
 * Strategy:
 *   - Static assets (CSS/JS/images): Cache-first with network fallback
 *   - All other requests (PHP pages): Network-first with cache fallback
 *
 * The service worker MUST be registered from the root scope so it can
 * intercept requests for all pages of the application.
 */

const SW_VERSION = new URL(self.location.href).searchParams.get('v') || 'dev';
const CACHE_NAME = `wbs-static-${SW_VERSION}`;
const OFFLINE_URL  = '/pages/offline.php';

const PRECACHE_URLS = [
    '/public/css/style.css',
    '/public/js/script.js',
    '/public/images/favicon-water.svg',
];

/* ── Install: pre-cache static assets ── */
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache =>
            // addAll failures are non-fatal — we gracefully skip missing files
            Promise.allSettled(PRECACHE_URLS.map(url => cache.add(url)))
        ).then(() => self.skipWaiting())
    );
});

/* ── Activate: clear out old caches ── */
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys
                    .filter(key => key !== CACHE_NAME)
                    .map(key  => caches.delete(key))
            )
        ).then(() => self.clients.claim())
    );
});

/* ── Fetch: route requests ── */
self.addEventListener('fetch', event => {
    const req = event.request;

    // Only handle same-origin GET requests
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    // Never cache dynamic admin/settings/API routes.
    if (
        url.pathname === '/settings' ||
        url.pathname.startsWith('/admin') ||
        url.pathname.startsWith('/api/')
    ) {
        event.respondWith(fetch(req).catch(() => caches.match(req)));
        return;
    }

    // Static assets — cache first
    if (url.pathname.startsWith('/public/')) {
        event.respondWith(
            caches.match(req).then(cached => {
                if (cached) return cached;
                return fetch(req).then(response => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then(c => c.put(req, clone));
                    }
                    return response;
                });
            })
        );
        return;
    }

    // Navigation / API requests — network first, fall back to cache
    event.respondWith(
        fetch(req)
            .then(response => {
                // Cache successful HTML responses for offline fallback
                if (
                    response.ok &&
                    response.headers.get('content-type') &&
                    response.headers.get('content-type').includes('text/html')
                ) {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then(c => c.put(req, clone));
                }
                return response;
            })
            .catch(() => caches.match(req))
    );
});
