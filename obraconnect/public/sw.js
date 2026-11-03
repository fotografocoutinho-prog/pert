/*
 * Service worker do ObraConnect.
 * Guarda o "shell" da aplicação e TODOS os ficheiros compilados (lidos de build/manifest.json),
 * para qualquer ecrã abrir sem rede. NUNCA guarda respostas da API, autenticação nem ficheiros
 * das empresas: os dados para trabalhar sem rede ficam no IndexedDB, controlados pela aplicação
 * (resources/js/offline) e apagados ao sair da conta.
 * Os caminhos são relativos ao âmbito do service worker, para funcionar tanto na raiz
 * de um domínio como numa subpasta (ex.: /obraconnect/).
 * Ícones em pwa/ (não icons/: o Apache do cPanel tem um alias global para /icons/).
 */
const CACHE = 'obraconnect-shell-v4';
const SCOPE = new URL(self.registration.scope).pathname; // termina em "/"
const SHELL = ['app', 'manifest.webmanifest', 'pwa/icon.svg', 'pwa/icon-192.png'].map((p) => SCOPE + p);
const NEVER_CACHE = ['api/', 'auth/', 'sanctum/', 'instalar', 'up'].map((p) => SCOPE + p);
const PRECACHE_EVERY_MS = 60 * 60 * 1000;
let lastPrecache = 0;

/** Todos os ficheiros do build atual (JS e CSS de todos os ecrãs), que ainda não estejam guardados. */
async function precacheBuild() {
    lastPrecache = Date.now();
    const response = await fetch(SCOPE + 'build/manifest.json', { cache: 'no-store' });
    if (!response.ok) return;
    const manifest = await response.json();
    const files = new Set();
    for (const chunk of Object.values(manifest)) {
        if (chunk.file) files.add(SCOPE + 'build/' + chunk.file);
        for (const css of chunk.css ?? []) files.add(SCOPE + 'build/' + css);
    }
    const cache = await caches.open(CACHE);
    for (const url of files) {
        if (!(await cache.match(url))) await cache.add(url).catch(() => undefined);
    }
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            .then((cache) => cache.addAll(SHELL))
            .then(() => precacheBuild().catch(() => undefined))
            .then(() => self.skipWaiting()),
    );
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
    if (!url.pathname.startsWith(SCOPE)) return;
    if (NEVER_CACHE.some((prefix) => url.pathname.startsWith(prefix))) return;

    // Ficheiros compilados têm hash no nome: cache primeiro.
    if (url.pathname.startsWith(SCOPE + 'build/') && !url.pathname.endsWith('/manifest.json')) {
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

    // Navegação: rede primeiro (e atualiza o shell guardado, que aponta para o build atual);
    // sem rede, devolve o shell guardado. Depois de uma versão nova, guarda os ficheiros dela.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok && response.headers.get('content-type')?.includes('text/html')) {
                        const copy = response.clone();
                        event.waitUntil(caches.open(CACHE).then((cache) => cache.put(SCOPE + 'app', copy)));
                        if (Date.now() - lastPrecache > PRECACHE_EVERY_MS) event.waitUntil(precacheBuild().catch(() => undefined));
                    }
                    return response;
                })
                .catch(() => caches.match(SCOPE + 'app')),
        );
    }
});
