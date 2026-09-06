/* Only the public shell and this explicit static asset list may enter CacheStorage. */
const CACHE = 'n45-field-shell-v1';
const ASSETS = ['/agent/field/shell.html','/agent/field/field.css','/agent/field/app.mjs','/agent/field/drafts.mjs','/agent/field/manifest.webmanifest','/agent/field/icon-192.png','/agent/field/icon-512.png','/assets/branding/n45-mark.svg'];
self.addEventListener('install', event => event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(ASSETS))));
self.addEventListener('activate', event => event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('n45-field-shell-') && key !== CACHE).map(key => caches.delete(key)))).then(() => self.clients.claim())));
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin || event.request.method !== 'GET') return;
  if (event.request.mode === 'navigate' && url.pathname.startsWith('/agent/field/') && !url.pathname.endsWith('/api.php')) {
    event.respondWith(fetch(event.request).catch(() => caches.match('/agent/field/shell.html')));
  } else if (!url.search && ASSETS.includes(url.pathname)) {
    event.respondWith(caches.match(event.request).then(cached => cached || fetch(event.request)));
  }
});
