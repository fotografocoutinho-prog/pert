/*
 * Service worker do ObraConnect (fase 1).
 * Guarda apenas o "shell" da aplicação e os ficheiros compilados para arranque rápido.
 * NUNCA guarda respostas da API, autenticação ou ficheiros das empresas.
 * O registo offline de dados (fotografias, horas, diário) chega na fase 2.
 */
const CACHE = 'obraconnect-shell-v1';
const SHELL = ['/app', '/manifest.webmanifest', '/icons/icon.svg', '/icons/icon-192.png'];
const NEVER_CACHE = ['/api/', '/auth/', '/sanctum/', '/instalar', '/up'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) return;
    if (NEVER_CACHE.some((prefix) => url.pathname.startsWith(prefix))) return;

    // Ficheiros compilados têm hash no nome: cache primeiro.
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.match(request).then((cached) => cached || fetch(request).then((response) => {
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(CACHE).then((cache) => cache.put(request, copy));
                }
                return response;
            })),
        );
        return;
    }

    // Navegação: rede primeiro; sem rede, devolve o shell guardado.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match('/app')));
    }
});
