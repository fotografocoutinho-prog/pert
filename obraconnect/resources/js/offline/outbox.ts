import { diaryApi } from '@/api';
import { ApiError } from '@/api/http';
import { isNetworkError } from './cache';
import { idb, offlineUser } from './db';

export interface PendingPhoto {
    clientUuid: string;
    name: string;
    type: string;
    blob: Blob;
    location_id: number | null;
    element_id: number | null;
}

/**
 * Registo do diário guardado no telemóvel à espera de rede. Um por obra e por dia.
 * O envio é idempotente (client_uuid): repetir depois de uma falha não duplica nada.
 */
export interface PendingEntry {
    key: string;
    userId: number;
    projectId: number;
    projectName: string;
    date: string;
    clientUuid: string;
    /** Já existe no servidor (criado antes, ou numa sincronização anterior interrompida). */
    entryId: number | null;
    /** Campos do registo (sem a data); null = só há fotografias para juntar. */
    payload: Record<string, unknown> | null;
    photos: PendingPhoto[];
    submit: boolean;
    status: 'pending' | 'conflict';
    error: string | null;
    updatedAt: string;
}

export const newUuid = (): string =>
    crypto.randomUUID?.() ??
    '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) => (Number(c) ^ (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (Number(c) / 4)))).toString(16));

const keyOf = (userId: number, projectId: number, date: string) => `${userId}:${projectId}:${date}`;

/** Cópia simples (sem proxies reativos do Vue, que o IndexedDB recusa). Só para dados JSON. */
const plain = <T>(value: T): T => (value === null || value === undefined ? value : JSON.parse(JSON.stringify(value)));

/** Os ecrãs guardam o registo num ref (proxy): reler sempre da base antes de alterar. */
const fresh = (record: Pick<PendingEntry, 'key'>) => idb.get<PendingEntry>('outbox', record.key);

export async function listPending(projectId?: number): Promise<PendingEntry[]> {
    const user = offlineUser();
    if (user === null) return [];
    const all = await idb.all<PendingEntry>('outbox').catch(() => [] as PendingEntry[]);
    return all
        .filter((p) => p.userId === user && (projectId === undefined || p.projectId === projectId))
        .sort((a, b) => a.date.localeCompare(b.date));
}

export async function getPending(projectId: number, date: string): Promise<PendingEntry | undefined> {
    const user = offlineUser();
    return user === null ? undefined : idb.get<PendingEntry>('outbox', keyOf(user, projectId, date));
}

/** Cria ou atualiza o pendente de (obra, dia), mantendo as fotografias já guardadas. */
export async function savePending(
    data: Pick<PendingEntry, 'projectId' | 'projectName' | 'date' | 'entryId' | 'payload'> & { submit?: boolean; addPhotos?: PendingPhoto[] },
): Promise<PendingEntry> {
    const user = offlineUser();
    if (user === null) throw new Error('Sem sessão.');
    const existing = await getPending(data.projectId, data.date);
    const record: PendingEntry = {
        key: keyOf(user, data.projectId, data.date),
        userId: user,
        projectId: data.projectId,
        projectName: data.projectName,
        date: data.date,
        clientUuid: existing?.clientUuid ?? newUuid(),
        entryId: data.entryId ?? existing?.entryId ?? null,
        payload: plain(data.payload ?? existing?.payload ?? null),
        photos: [...(existing?.photos ?? []), ...(data.addPhotos ?? [])],
        submit: data.submit ?? existing?.submit ?? false,
        status: 'pending',
        error: null,
        updatedAt: new Date().toISOString(),
    };
    await idb.put('outbox', record);
    return record;
}

export async function removePendingPhoto(record: Pick<PendingEntry, 'key'>, clientUuid: string): Promise<void> {
    const raw = await fresh(record);
    if (!raw) return;
    raw.photos = raw.photos.filter((p) => p.clientUuid !== clientUuid);
    await (raw.payload === null && raw.photos.length === 0 && !raw.submit && raw.entryId !== null
        ? idb.delete('outbox', raw.key)
        : idb.put('outbox', raw));
}

export async function updatePendingPhoto(
    record: Pick<PendingEntry, 'key'>,
    clientUuid: string,
    patch: Partial<Pick<PendingPhoto, 'location_id' | 'element_id'>>,
): Promise<void> {
    const raw = await fresh(record);
    if (!raw) return;
    raw.photos = raw.photos.map((p) => (p.clientUuid === clientUuid ? { ...p, ...patch } : p));
    await idb.put('outbox', raw);
}

/**
 * O texto acabou de ser guardado com rede: o pendente fica só com as fotografias (se houver),
 * já ligadas ao registo do servidor. Devolve o que sobra, ou null.
 */
export async function keepOnlyPhotos(record: Pick<PendingEntry, 'key'>, entryId: number): Promise<PendingEntry | null> {
    const raw = await fresh(record);
    if (!raw) return null;
    if (raw.photos.length === 0) {
        await idb.delete('outbox', raw.key);
        return null;
    }
    Object.assign(raw, { entryId, payload: null, submit: false, status: 'pending', error: null });
    await idb.put('outbox', raw);
    return raw;
}

/** Depois de um conflito resolvido (ou para insistir): volta à fila. */
export async function retryPending(record: Pick<PendingEntry, 'key'>): Promise<void> {
    const raw = await fresh(record);
    if (!raw) return;
    raw.status = 'pending';
    raw.error = null;
    await idb.put('outbox', raw);
}

export const discardPending = (record: Pick<PendingEntry, 'key'>) => idb.delete('outbox', record.key);

export interface SyncResult {
    sent: number;
    offline: boolean;
}

/**
 * Envia os pendentes, um a um e passo a passo, guardando o progresso depois de cada passo:
 * criar/atualizar o registo, juntar cada fotografia, submeter. Pára ao primeiro erro de rede.
 */
export async function syncPending(): Promise<SyncResult> {
    let sent = 0;

    for (const record of await listPending()) {
        if (record.status !== 'pending') continue;
        try {
            await syncOne(record);
            sent++;
        } catch (e) {
            if (isNetworkError(e)) return { sent, offline: true };
            record.status = 'conflict';
            record.error = describe(e);
            await idb.put('outbox', record);
        }
    }

    return { sent, offline: false };
}

async function syncOne(record: PendingEntry): Promise<void> {
    if (record.entryId === null) {
        const created = await diaryApi.create(record.projectId, { ...(record.payload ?? {}), entry_date: record.date, client_uuid: record.clientUuid });
        record.entryId = created.id;
        record.payload = null;
        await idb.put('outbox', record);
    } else if (record.payload !== null) {
        await diaryApi.update(record.projectId, record.entryId, record.payload);
        record.payload = null;
        await idb.put('outbox', record);
    }

    for (const photo of [...record.photos]) {
        const file = new File([photo.blob], photo.name, { type: photo.type });
        await diaryApi.uploadPhoto(record.projectId, record.entryId, file, undefined, {
            client_uuid: photo.clientUuid,
            ...(photo.element_id ? { element_id: photo.element_id } : photo.location_id ? { location_id: photo.location_id } : {}),
        });
        record.photos = record.photos.filter((p) => p.clientUuid !== photo.clientUuid);
        await idb.put('outbox', record);
    }

    if (record.submit) {
        try {
            await diaryApi.submit(record.projectId, record.entryId);
        } catch (e) {
            // Já submetido (por esta sincronização, antes de a resposta se perder, ou por outra pessoa).
            if (!(e instanceof ApiError && e.code === 'DIARY_SUBMITTED')) throw e;
        }
    }

    await idb.delete('outbox', record.key);
}

function describe(e: unknown): string {
    if (e instanceof ApiError) {
        if (e.errors.entry_date) return 'Já existe um registo deste dia (feito noutro aparelho). Abra-o e junte esta informação à mão.';
        if (e.code === 'DIARY_SUBMITTED') return 'O registo foi submetido entretanto e já não aceita alterações.';
        return Object.values(e.errors)[0]?.[0] ?? e.message;
    }
    return 'Não foi possível enviar.';
}
