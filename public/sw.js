// Service worker mínimo: hace la app instalable y NO cachea nada.
// Es a propósito: las páginas son Inertia (la misma URL responde HTML o JSON
// según el header X-Inertia) y una caché mal puesta muestra JSON crudo.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {
    // sin respondWith: el navegador resuelve el pedido como si no hubiera SW
});
