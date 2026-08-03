/*
 * Transoria Online — service worker.
 *
 * Goals: make the game installable as a standalone app and let the shell open
 * instantly (even offline), WITHOUT ever serving stale game data.
 *
 *  - /api/*        → never touched (always live network; MMO data must be fresh)
 *  - navigations   → network-first, fall back to the cached shell when offline
 *  - /assets/*     → cache-first (Vite filenames are content-hashed, so safe)
 *  - cross-origin  → ignored (map tiles etc. behave normally)
 *
 * Bump CACHE to invalidate old shells on a breaking change.
 */
const CACHE = 'transoria-shell-v1'
const SHELL = ['/', '/index.html', '/manifest.webmanifest', '/icon-192.png', '/favicon.svg']

self.addEventListener('install', (event) => {
  self.skipWaiting()
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(SHELL).catch(() => {})),
  )
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  )
})

self.addEventListener('message', (event) => {
  if (event.data === 'skipWaiting') self.skipWaiting()
})

self.addEventListener('fetch', (event) => {
  const req = event.request
  if (req.method !== 'GET') return

  let url
  try {
    url = new URL(req.url)
  } catch {
    return
  }

  // Only handle our own origin; let map tiles / CDNs pass through untouched.
  if (url.origin !== self.location.origin) return
  // Never cache or intercept the live API.
  if (url.pathname.startsWith('/api')) return

  // App navigations: network-first so new deploys are picked up immediately;
  // fall back to the cached shell when the network is unavailable.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req)
        .then((res) => {
          const copy = res.clone()
          caches.open(CACHE).then((c) => c.put('/index.html', copy)).catch(() => {})
          return res
        })
        .catch(() => caches.match('/index.html').then((r) => r || caches.match('/'))),
    )
    return
  }

  // Content-hashed build assets: cache-first for instant repeat loads.
  if (url.pathname.startsWith('/assets/')) {
    event.respondWith(
      caches.match(req).then(
        (hit) =>
          hit ||
          fetch(req).then((res) => {
            const copy = res.clone()
            caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => {})
            return res
          }),
      ),
    )
    return
  }

  // Other same-origin GETs (icons, manifest): network, fall back to cache.
  event.respondWith(fetch(req).catch(() => caches.match(req)))
})
