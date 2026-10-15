const CACHE_NAME = 'asistencia-shell-v2';
const SHELL_URL = '/offline.html';
const STATIC_URLS = [
    SHELL_URL,
    '/css/app.css',
    '/js/app.js',
    '/js/offline-queue.js',
    '/manifest.webmanifest',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(STATIC_URLS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys
                .filter((key) => key.startsWith('asistencia-shell-') && key !== CACHE_NAME)
                .map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(SHELL_URL)));
        return;
    }

    if (STATIC_URLS.includes(url.pathname)) {
        event.respondWith(caches.match(request).then((cached) => cached || fetch(request)));
    }
});
