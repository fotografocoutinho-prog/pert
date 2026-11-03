<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { diaryApi } from '@/api';
import { ApiError } from '@/api/http';
import { isNetworkError } from '@/offline/cache';
import type { MediaItem } from '@/api/types';
import { useStructurePlaces } from '@/composables/useStructurePlaces';
import { useToastStore } from '@/stores/toast';
import { appUrl } from '@/utils/base';
import { formatBytes, formatDateTime } from '@/utils/format';
import AppButton from '@/components/ui/AppButton.vue';
import AppModal from '@/components/ui/AppModal.vue';

const props = defineProps<{ projectId: number; entryId: number; photos: MediaItem[]; editable: boolean }>();
// Sem rede: as fotografias passam para a fila do telemóvel (ver DiaryEntryPage).
const emit = defineEmits<{ queue: [files: File[]] }>();

const toast = useToastStore();
const { placeOptions, hasStructure, toValue, toIds, labelOf, load: loadPlaces } = useStructurePlaces(props.projectId);
loadPlaces();

/** Igual a obraconnect.files.photo_max_mb no servidor (que é quem decide). */
const MAX_MB = 20;
const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

// A lista é deste componente: atualizar as fotografias não mexe no resto do formulário.
const list = ref<MediaItem[]>([...props.photos]);
watch(() => props.photos, (photos) => (list.value = [...photos]));

// Envios, um de cada vez (rede móvel).
const queue = ref<{ key: number; name: string; progress: number; error: string | null }[]>([]);
const uploading = computed(() => queue.value.some((q) => q.error === null));
let nextKey = 0;

async function onFiles(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const files = new Map<number, File>();
    for (const file of input.files ?? []) {
        const job = { key: ++nextKey, name: file.name, progress: 0, error: null as string | null };
        if (!TYPES.includes(file.type)) {
            job.error = 'Só JPEG, PNG ou WebP.';
        } else if (file.size > MAX_MB * 1048576) {
            job.error = `Tem ${formatBytes(file.size)}; o máximo é ${MAX_MB} MB.`;
        } else {
            files.set(job.key, file);
        }
        queue.value.push(job);
    }
    input.value = '';

    const offline: File[] = [];
    for (const [key, file] of files) {
        const job = { key };
        if (offline.length) {
            // Já se viu que não há rede: as restantes vão diretamente para a fila.
            offline.push(file);
            queue.value = queue.value.filter((q) => q.key !== key);
            continue;
        }
        try {
            const item = await diaryApi.uploadPhoto(props.projectId, props.entryId, file, (p) => {
                const live = queue.value.find((q) => q.key === job.key);
                if (live) live.progress = Math.max(p, 1);
            });
            list.value.push(item);
            queue.value = queue.value.filter((q) => q.key !== job.key);
        } catch (e) {
            if (isNetworkError(e)) {
                offline.push(file);
                queue.value = queue.value.filter((q) => q.key !== job.key);
                continue;
            }
            const live = queue.value.find((q) => q.key === job.key);
            if (live) live.error = e instanceof ApiError ? (e.firstError('photo') ?? e.message) : 'Falhou o envio.';
        }
    }
    if (offline.length) emit('queue', offline);
    schedulePoll();
}

// As versões reduzidas são geradas na fila do servidor (até cerca de um minuto).
let timer: number | undefined;
let polls = 0;
function schedulePoll(): void {
    window.clearTimeout(timer);
    if (!list.value.some((p) => p.status === 'processing') || polls > 36) return;
    timer = window.setTimeout(async () => {
        polls++;
        try {
            const entry = await diaryApi.get(props.projectId, props.entryId);
            list.value = entry.photos ?? list.value;
        } finally {
            schedulePoll();
        }
    }, 5000);
}
schedulePoll();
onBeforeUnmount(() => window.clearTimeout(timer));

async function saveCaption(photo: MediaItem, caption: string): Promise<void> {
    const value = caption.trim() || null;
    if (value === photo.caption) return;
    try {
        Object.assign(photo, await diaryApi.updatePhoto(props.projectId, props.entryId, photo.id, { caption: value }));
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível guardar a legenda.');
    }
}

/** "Onde" foi tirada (local ou elemento da estrutura). */
async function savePlace(photo: MediaItem, value: string): Promise<void> {
    try {
        Object.assign(photo, await diaryApi.updatePhoto(props.projectId, props.entryId, photo.id, toIds(value)));
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível guardar o local.');
    }
}

async function remove(photo: MediaItem): Promise<void> {
    if (!window.confirm('Remover esta fotografia?')) return;
    try {
        await diaryApi.removePhoto(props.projectId, props.entryId, photo.id);
        list.value = list.value.filter((p) => p.id !== photo.id);
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível remover.');
    }
}

const viewing = ref<MediaItem | null>(null);
</script>

<template>
    <div>
        <div v-if="editable" class="mb-4 flex flex-wrap gap-2">
            <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg bg-brand-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-800">
                Tirar fotografia
                <input type="file" accept="image/jpeg,image/png,image/webp" capture="environment" class="sr-only" @change="onFiles" />
            </label>
            <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                Escolher fotografias
                <input type="file" accept="image/jpeg,image/png,image/webp" multiple class="sr-only" @change="onFiles" />
            </label>
        </div>

        <ul v-if="queue.length" class="mb-4 space-y-2 text-sm" aria-live="polite">
            <li v-for="job in queue" :key="job.key" class="rounded-lg border px-3 py-2" :class="job.error ? 'border-red-200 bg-red-50 text-red-800' : 'border-slate-200'">
                <div class="flex justify-between gap-2">
                    <span class="truncate">{{ job.name }}</span>
                    <span v-if="!job.error" class="text-slate-500">{{ job.progress ? `${job.progress}%` : 'à espera' }}</span>
                    <button v-else type="button" class="font-medium underline" @click="queue = queue.filter((q) => q.key !== job.key)">Fechar</button>
                </div>
                <p v-if="job.error">{{ job.error }}</p>
                <div v-else class="mt-1 h-1.5 rounded-full bg-slate-100"><div class="h-1.5 rounded-full bg-brand-600" :style="{ width: `${job.progress}%` }" /></div>
            </li>
        </ul>

        <p v-if="list.length === 0 && !uploading" class="text-sm text-slate-500">Sem fotografias.</p>
        <ul v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            <li v-for="photo in list" :key="photo.id" class="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <button type="button" class="block aspect-[4/3] w-full bg-slate-100" :aria-label="`Ver ${photo.caption || photo.original_name}`" @click="viewing = photo">
                    <img v-if="photo.urls.thumb" :src="appUrl(photo.urls.thumb)" :alt="photo.caption || photo.original_name" loading="lazy" class="h-full w-full object-cover" />
                    <span v-else class="flex h-full items-center justify-center px-2 text-center text-xs text-slate-500">{{ photo.status_label }}…</span>
                </button>
                <div class="p-2">
                    <input
                        v-if="editable"
                        :value="photo.caption ?? ''"
                        placeholder="Legenda"
                        maxlength="255"
                        class="w-full rounded border border-slate-200 px-2 py-1 text-sm"
                        :aria-label="`Legenda de ${photo.original_name}`"
                        @blur="saveCaption(photo, ($event.target as HTMLInputElement).value)"
                        @keydown.enter.prevent="($event.target as HTMLInputElement).blur()"
                    />
                    <p v-else class="truncate text-sm" :title="photo.caption ?? ''">{{ photo.caption || ' ' }}</p>
                    <select
                        v-if="editable && hasStructure"
                        :value="toValue(photo.location_id, photo.element_id)"
                        class="mt-1 w-full rounded border border-slate-200 bg-white px-1.5 py-1 text-xs"
                        :aria-label="`Onde foi tirada ${photo.original_name}`"
                        @change="savePlace(photo, ($event.target as HTMLSelectElement).value)"
                    >
                        <option value="">Onde? (opcional)</option>
                        <option v-for="o in placeOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                    <p v-else-if="labelOf(photo.location_id, photo.element_id)" class="truncate text-xs text-slate-500" :title="labelOf(photo.location_id, photo.element_id)">
                        {{ labelOf(photo.location_id, photo.element_id) }}
                    </p>
                    <button v-if="editable" type="button" class="mt-1 text-xs font-medium text-red-700 hover:underline" @click="remove(photo)">Remover</button>
                </div>
            </li>
        </ul>

        <AppModal :open="viewing !== null" :title="viewing?.caption || viewing?.original_name || ''" size="lg" @close="viewing = null">
            <template v-if="viewing">
                <img v-if="viewing.urls.preview" :src="appUrl(viewing.urls.preview)" :alt="viewing.caption || viewing.original_name" class="mx-auto max-h-[70vh] rounded" />
                <p v-else class="text-sm text-slate-600">Ainda não há pré-visualização. Pode descarregar o original.</p>
                <p class="mt-3 text-xs text-slate-500">
                    {{ viewing.original_name }} · {{ formatBytes(viewing.size_bytes) }}<template v-if="viewing.width"> · {{ viewing.width }}×{{ viewing.height }}</template>
                    <template v-if="viewing.taken_at"> · tirada a {{ formatDateTime(viewing.taken_at) }}</template>
                    <template v-if="viewing.uploaded_by"> · enviada por {{ viewing.uploaded_by }}</template>
                </p>
            </template>
            <template #footer>
                <a v-if="viewing" :href="appUrl(viewing.urls.original)" class="text-sm font-semibold text-brand-700 hover:underline">Descarregar original</a>
                <AppButton variant="secondary" @click="viewing = null">Fechar</AppButton>
            </template>
        </AppModal>
    </div>
</template>
