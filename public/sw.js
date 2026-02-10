// Service worker intentionally disabled to avoid caching during rollout.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', () => self.clients.claim());
