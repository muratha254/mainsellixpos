const APP_VERSION = '__APP_VERSION__';
const CACHE_NAME = 'sellix-app-cache-v4';

const PRECACHE_URLS = [
  './offline.html',
  './img/pwa/icon-192.png',
  './img/pwa/icon-512.png',
  './favicon.png',
];

const STATIC_EXT = /\.(?:css|js|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|eot)$/i;

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE_URLS))
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((key) => key.startsWith('sellix-app-cache-') && key !== CACHE_NAME)
          .map((key) => caches.delete(key))
      )
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) {
    return;
  }

  if (shouldBypass(url, request)) {
    return;
  }

  // Let the browser load HTML itself so page changes do not flash a blank white screen
  // while the service worker waits on the network.
  if (isNavigation(request)) {
    return;
  }

  if (STATIC_EXT.test(url.pathname) && !url.pathname.includes('/storage/')) {
    event.respondWith(cacheFirst(request));
  }
});

function shouldBypass(url, request) {
  const path = url.pathname.toLowerCase();
  if (path.includes('/livewire')) {
    return true;
  }
  if (path.includes('/api/')) {
    return true;
  }
  if (path.endsWith('/sw.js') || path.endsWith('/manifest.webmanifest')) {
    return true;
  }
  const dest = request.destination;
  if (dest === 'document') {
    return false;
  }
  const accept = request.headers.get('accept') || '';
  if (accept.includes('application/json') && !STATIC_EXT.test(path)) {
    return true;
  }
  return false;
}

function isNavigation(request) {
  return request.mode === 'navigate' || request.destination === 'document';
}

function networkOnlyWithOffline(request) {
  return fetch(request).catch(function () {
    return caches.match('./offline.html', { ignoreSearch: true }).then(function (cached) {
      return cached || new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain' } });
    });
  });
}

function cacheFirst(request) {
  return caches.match(request).then(function (cached) {
    if (cached) {
      return cached;
    }
    return fetch(request).then(function (response) {
      if (!response || response.status !== 200 || response.type !== 'basic') {
        return response;
      }
      const copy = response.clone();
      caches.open(CACHE_NAME).then(function (cache) {
        cache.put(request, copy);
      });
      return response;
    });
  });
}
