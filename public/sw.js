const APP_VERSION = '__APP_VERSION__';
const CACHE_NAME = 'sellix-app-cache-v5';
const PAGE_CACHE = 'sellix-pages-v1';

const PRECACHE_URLS = [
  './offline.html',
  './js/sellix-offline.js',
  './js/pwa.js',
  './css/pwa.css',
  './img/pwa/icon-192.png',
  './img/pwa/icon-512.png',
  './favicon.png',
];

const STATIC_EXT = /\.(?:css|js|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|eot)$/i;
const POS_JSON = /\/pos\/(?:catalog|search|holds|offline-snapshot)(?:\/|$)/;

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE_URLS)).catch(function () {})
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
  if (!event.data) {
    return;
  }
  if (event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
  if (event.data.type === 'CLEAR_PAGES') {
    event.waitUntil(caches.delete(PAGE_CACHE));
  }
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  if (request.method !== 'GET') {
    if (isNavigation(request)) {
      event.respondWith(fetch(request).catch(() => offlinePage()));
    }
    return;
  }

  if (url.origin !== self.location.origin) {
    if (isAllowedCdn(url)) {
      event.respondWith(cacheFirst(request));
    }
    return;
  }

  if (shouldBypass(url, request)) {
    return;
  }

  if (isNavigation(request)) {
    event.respondWith(networkFirstPage(request));
    return;
  }

  if (POS_JSON.test(url.pathname)) {
    event.respondWith(networkFirstData(request));
    return;
  }

  if (STATIC_EXT.test(url.pathname) && !url.pathname.includes('/storage/')) {
    event.respondWith(cacheFirst(request));
  }
});

function shouldBypass(url, request) {
  const path = url.pathname.toLowerCase();
  if (path.includes('/livewire') || path.includes('/api/')) {
    return true;
  }
  if (path.endsWith('/sw.js') || path.endsWith('/manifest.webmanifest') || path.endsWith('/pwa/version') || path.endsWith('/offline/ping')) {
    return true;
  }
  if (path.includes('/logout')) {
    return true;
  }
  const accept = request.headers.get('accept') || '';
  if (accept.includes('application/json') && !POS_JSON.test(path) && !STATIC_EXT.test(path)) {
    return true;
  }
  return false;
}

function isNavigation(request) {
  return request.mode === 'navigate' || request.destination === 'document';
}

function isAllowedCdn(url) {
  return url.hostname === 'cdn.jsdelivr.net'
    || url.hostname === 'fonts.googleapis.com'
    || url.hostname === 'fonts.gstatic.com';
}

function offlinePage() {
  return caches.match('./offline.html', { ignoreSearch: true }).then(function (cached) {
    return cached || new Response('You are offline. Open a screen you used while online, or reconnect.', {
      status: 503,
      headers: { 'Content-Type': 'text/plain; charset=UTF-8' },
    });
  });
}

function networkFirstPage(request) {
  return fetch(request).then(function (response) {
    if (shouldStorePage(request, response)) {
      const copy = response.clone();
      caches.open(PAGE_CACHE).then(function (cache) {
        cache.put(request, copy);
      });
    }
    return response;
  }).catch(function () {
    return caches.match(request).then(function (cached) {
      return cached || offlinePage();
    });
  });
}

function shouldStorePage(request, response) {
  if (!response || response.status !== 200 || response.type !== 'basic') {
    return false;
  }
  if (response.redirected) {
    const finalUrl = response.url || '';
    if (finalUrl.indexOf('/login') !== -1) {
      return false;
    }
  }
  const type = response.headers.get('content-type') || '';
  if (type && type.indexOf('text/html') === -1) {
    return false;
  }
  const path = new URL(request.url).pathname.toLowerCase();
  if (path.indexOf('/logout') !== -1) {
    return false;
  }
  return true;
}

function networkFirstData(request) {
  return fetch(request).then(function (response) {
    if (response && response.status === 200) {
      const copy = response.clone();
      caches.open(PAGE_CACHE).then(function (cache) {
        cache.put(request, copy);
      });
    }
    return response;
  }).catch(function () {
    return caches.match(request).then(function (cached) {
      return cached || new Response(JSON.stringify({ message: 'Offline' }), {
        status: 503,
        headers: { 'Content-Type': 'application/json' },
      });
    });
  });
}

function cacheFirst(request) {
  return caches.match(request).then(function (cached) {
    if (cached) {
      return cached;
    }
    return fetch(request).then(function (response) {
      if (!response) {
        return response;
      }
      const storable = response.status === 200 || response.type === 'opaque';
      if (!storable) {
        return response;
      }
      const copy = response.clone();
      caches.open(CACHE_NAME).then(function (cache) {
        cache.put(request, copy);
      });
      return response;
    }).catch(function () {
      return caches.match(request);
    });
  });
}
