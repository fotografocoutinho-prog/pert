import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { listPending, syncPending, type PendingEntry } from '@/offline/outbox';

/**
 * Modo sem rede: o que está guardado no telemóvel e quando se envia.
 * Tenta enviar ao abrir a aplicação, quando a rede volta e de minuto a minuto enquanto houver pendentes.
 */
export const useSyncStore = defineStore('sync', () => {
    const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
    const pending = ref<PendingEntry[]>([]);
    const syncing = ref(false);
    let started = false;

    const waiting = computed(() => pending.value.filter((p) => p.status === 'pending'));
    const conflicts = computed(() => pending.value.filter((p) => p.status === 'conflict'));

    async function refresh(): Promise<void> {
        pending.value = await listPending();
    }

    async function sync(): Promise<void> {
        if (syncing.value) return;
        await refresh();
        if (waiting.value.length === 0) return;
        syncing.value = true;
        try {
            const result = await syncPending();
            // navigator.onLine diz "ligado" numa rede sem internet: confia no resultado do envio.
            online.value = !result.offline;
        } finally {
            syncing.value = false;
            await refresh();
        }
    }

    function start(): void {
        if (started) return;
        started = true;
        window.addEventListener('online', () => {
            online.value = true;
            sync();
        });
        window.addEventListener('offline', () => (online.value = false));
        window.setInterval(() => waiting.value.length && sync(), 60_000);
        sync();
    }

    /** Um pedido falhou por falta de rede (chamado pelos ecrãs). */
    function markOffline(): void {
        online.value = false;
    }

    return { online, pending, syncing, waiting, conflicts, refresh, sync, start, markOffline };
});
