import { ApiError } from '@/api/http';
import { idb, offlineUser } from './db';

interface CacheRecord<T> {
    key: string;
    value: T;
    savedAt: string;
}

/** Sem resposta do servidor (sem rede, rede sem internet, servidor em baixo). */
export const isNetworkError = (e: unknown): boolean => e instanceof ApiError && e.status === 0;

/**
 * Pede à API e guarda a resposta; sem rede, devolve a última cópia guardada.
 * Só para leituras que fazem falta em obra (obra, estrutura, diário); limpo ao sair da conta.
 */
export async function cachedRead<T>(name: string, fetcher: () => Promise<T>): Promise<T> {
    const user = offlineUser();
    const key = `${user}:${name}`;
    try {
        const value = await fetcher();
        if (user !== null) idb.put<CacheRecord<T>>('cache', { key, value, savedAt: new Date().toISOString() }).catch(() => undefined);
        return value;
    } catch (e) {
        if (user !== null && isNetworkError(e)) {
            const saved = await idb.get<CacheRecord<T>>('cache', key).catch(() => undefined);
            if (saved) return saved.value;
        }
        throw e;
    }
}

/** A última sessão (/me) fica fora do prefixo: é ela que diz quem é o utilizador. */
const ME_KEY = 'me:last';
export const rememberMe = <T>(value: T) => idb.put<CacheRecord<T>>('cache', { key: ME_KEY, value, savedAt: new Date().toISOString() }).catch(() => undefined);
export const lastMe = <T>() => idb.get<CacheRecord<T>>('cache', ME_KEY).then((r) => r?.value).catch(() => undefined);

/** Ao sair da conta: apaga as cópias de leitura (a fila de envio fica, por utilizador). */
export async function forgetReadCache(userId: number | null): Promise<void> {
    await idb.delete('cache', ME_KEY).catch(() => undefined);
    if (userId !== null) await idb.deletePrefix('cache', `${userId}:`).catch(() => undefined);
}
