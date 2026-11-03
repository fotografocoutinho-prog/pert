/**
 * Base local do modo sem rede (IndexedDB), sem dependências.
 * - "outbox": registos do diário e fotografias por enviar (sobrevivem a fechar a aplicação);
 * - "cache": últimas cópias lidas da API (obra, estrutura, diário) para abrir sem rede.
 * Tudo com a chave começada pelo ID do utilizador, para nunca misturar contas no mesmo aparelho.
 */
const DB_NAME = 'obraconnect';
const VERSION = 1;
export type StoreName = 'outbox' | 'cache';

let opening: Promise<IDBDatabase> | null = null;

function open(): Promise<IDBDatabase> {
    opening ??= new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, VERSION);
        request.onupgradeneeded = () => {
            const db = request.result;
            if (!db.objectStoreNames.contains('outbox')) db.createObjectStore('outbox', { keyPath: 'key' });
            if (!db.objectStoreNames.contains('cache')) db.createObjectStore('cache', { keyPath: 'key' });
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => {
            opening = null;
            reject(request.error);
        };
    });
    return opening;
}

function run<T>(store: StoreName, mode: IDBTransactionMode, action: (s: IDBObjectStore) => IDBRequest<T>): Promise<T> {
    return open().then(
        (db) =>
            new Promise<T>((resolve, reject) => {
                const tx = db.transaction(store, mode);
                const request = action(tx.objectStore(store));
                tx.oncomplete = () => resolve(request.result);
                tx.onerror = () => reject(tx.error);
                tx.onabort = () => reject(tx.error);
            }),
    );
}

export const idb = {
    get: <T>(store: StoreName, key: string) => run<T | undefined>(store, 'readonly', (s) => s.get(key) as IDBRequest<T | undefined>),
    put: <T extends { key: string }>(store: StoreName, value: T) => run(store, 'readwrite', (s) => s.put(value)).then(() => undefined),
    delete: (store: StoreName, key: string) => run(store, 'readwrite', (s) => s.delete(key)).then(() => undefined),
    all: <T>(store: StoreName) => run<T[]>(store, 'readonly', (s) => s.getAll() as IDBRequest<T[]>),
    /** Apaga as entradas cuja chave começa por prefix (ex.: as de um utilizador). */
    async deletePrefix(store: StoreName, prefix: string): Promise<void> {
        const all = await idb.all<{ key: string }>(store);
        await Promise.all(all.filter((r) => r.key.startsWith(prefix)).map((r) => idb.delete(store, r.key)));
    },
};

/** Utilizador da sessão (definido pelo store da sessão), prefixo de todas as chaves. */
let currentUser: number | null = null;
export const setOfflineUser = (id: number | null) => (currentUser = id);
export const offlineUser = () => currentUser;
