<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { inspectionsApi } from '@/api';
import { ApiError } from '@/api/http';
import type { EvidenceIndex, InspectionItem, InspectionResultValue, MediaItem } from '@/api/types';
import { useToastStore } from '@/stores/toast';
import { appUrl } from '@/utils/base';
import { formatBytes, formatDateTime } from '@/utils/format';
import { formatMeasure, requirementsText, RESULT_OPTIONS } from '@/utils/inspections';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppModal from '@/components/ui/AppModal.vue';

const props = defineProps<{ projectId: number; inspectionId: number; item: InspectionItem; editable: boolean; missing?: string[] }>();
const emit = defineEmits<{ evidence: [evidence: EvidenceIndex]; changed: [item: InspectionItem] }>();

const toast = useToastStore();
const MAX_MB = 20;
const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

const notes = ref(props.item.notes ?? '');
const measure = ref(props.item.measure_value === null ? '' : String(props.item.measure_value).replace('.', ','));
watch(
    () => props.item.notes,
    (v) => (notes.value = v ?? ''),
);
watch(
    () => props.item.measure_value,
    (v) => (measure.value = v === null ? '' : String(v).replace('.', ',')),
);

const saving = ref(false);
async function save(payload: { result?: InspectionResultValue | null; measure_value?: number | null; notes?: string | null }): Promise<void> {
    saving.value = true;
    try {
        const { item, evidence } = await inspectionsApi.updateItem(props.projectId, props.inspectionId, props.item.id, payload);
        emit('changed', { ...props.item, ...item });
        emit('evidence', evidence);
    } catch (e) {
        toast.error(e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Não foi possível guardar.');
    } finally {
        saving.value = false;
    }
}

const notesField = ref<HTMLTextAreaElement | null>(null);
function setResult(value: InspectionResultValue): void {
    const next = props.item.result === value ? null : value;
    save({ result: next });
    // Uma não conformidade tem de ser descrita.
    if (next === 'nok' && !notes.value.trim()) window.setTimeout(() => notesField.value?.focus(), 0);
}

function saveNotes(): void {
    const value = notes.value.trim() || null;
    if (value !== (props.item.notes ?? null)) save({ notes: value });
}

function saveMeasure(): void {
    const raw = measure.value.trim().replace(',', '.');
    const value = raw === '' ? null : Number(raw);
    if (value !== null && Number.isNaN(value)) {
        toast.error('O valor medido tem de ser um número.');
        return;
    }
    if (value !== props.item.measure_value) save({ measure_value: value });
}

const limits = computed(() => {
    const unit = props.item.measure_unit ? ` ${props.item.measure_unit}` : '';
    const { measure_min: min, measure_max: max } = props.item;
    if (min !== null && max !== null) return `${formatMeasure(min)} a ${formatMeasure(max)}${unit}`;
    if (min !== null) return `mínimo ${formatMeasure(min)}${unit}`;
    if (max !== null) return `máximo ${formatMeasure(max)}${unit}`;
    return '';
});

// ---- Fotografias ----
const queue = ref<{ key: number; name: string; progress: number; error: string | null }[]>([]);
let nextKey = 0;

async function onFiles(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const files = [...(input.files ?? [])];
    input.value = '';
    for (const file of files) {
        const key = ++nextKey;
        queue.value.push({ key, name: file.name, progress: 0, error: null });
        // Sempre pelo array reativo (o objeto original não atualiza o ecrã).
        const job = () => queue.value.find((q) => q.key === key);
        if (!TYPES.includes(file.type)) {
            job()!.error = 'Só JPEG, PNG ou WebP.';
            continue;
        }
        if (file.size > MAX_MB * 1048576) {
            job()!.error = `Tem ${formatBytes(file.size)}; o máximo é ${MAX_MB} MB.`;
            continue;
        }
        try {
            const { photo, evidence } = await inspectionsApi.uploadPhoto(props.projectId, props.inspectionId, props.item.id, file, (p) => {
                const live = job();
                if (live) live.progress = Math.max(p, 1);
            });
            emit('changed', { ...props.item, photos: [...props.item.photos, photo] });
            emit('evidence', evidence);
            queue.value = queue.value.filter((q) => q.key !== key);
        } catch (e) {
            const live = job();
            if (live) live.error = e instanceof ApiError ? (e.firstError('photo') ?? e.message) : 'Falhou o envio.';
        }
    }
}

async function removePhoto(photo: MediaItem): Promise<void> {
    if (!window.confirm('Retirar esta fotografia?')) return;
    try {
        const { evidence } = await inspectionsApi.removePhoto(props.projectId, props.inspectionId, props.item.id, photo.id);
        emit('changed', { ...props.item, photos: props.item.photos.filter((p) => p.id !== photo.id) });
        emit('evidence', evidence);
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível retirar.');
    }
}

const viewing = ref<MediaItem | null>(null);
const resultBadge = computed(() => ({ ok: 'green', nok: 'red', na: 'gray' })[props.item.result ?? 'na'] as 'green' | 'red' | 'gray');
</script>

<template>
    <li class="rounded-lg border bg-white p-3 sm:p-4" :class="item.result === 'nok' ? 'border-red-200' : 'border-slate-200'">
        <div class="flex items-start gap-2">
            <span class="w-6 shrink-0 text-right text-sm font-semibold text-slate-500">{{ item.number }}.</span>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-medium text-slate-900">{{ item.text }}</p>
                        <p v-if="item.guidance" class="text-xs text-slate-500">{{ item.guidance }}</p>
                    </div>
                    <AppBadge v-if="!editable && item.result" :color="resultBadge">{{ item.result_label }}</AppBadge>
                </div>
                <p v-if="requirementsText(item).length" class="mt-1 flex flex-wrap gap-1">
                    <AppBadge v-for="r in requirementsText(item)" :key="r" color="blue">{{ r }}</AppBadge>
                </p>
                <p v-if="missing?.length" class="mt-1 text-xs font-medium text-amber-700">Falta: {{ missing.join(', ') }}</p>

                <!-- Resultado -->
                <div v-if="editable" class="mt-3 flex flex-wrap gap-2" role="group" :aria-label="`Resultado do ponto ${item.number}`">
                    <button
                        v-for="o in RESULT_OPTIONS"
                        :key="o.value"
                        type="button"
                        class="min-w-24 rounded-lg border px-3 py-2 text-sm font-semibold disabled:opacity-60"
                        :class="item.result === o.value ? o.active : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'"
                        :aria-pressed="item.result === o.value"
                        :disabled="saving"
                        @click="setResult(o.value)"
                    >
                        {{ o.label }}
                    </button>
                </div>

                <!-- Medição -->
                <div v-if="item.requires_measure || item.measure_value !== null" class="mt-3 text-sm">
                    <label v-if="editable" class="inline-flex flex-wrap items-center gap-2">
                        <span class="text-slate-700">Valor medido</span>
                        <input
                            v-model="measure"
                            inputmode="decimal"
                            class="w-28 rounded-lg border border-slate-300 bg-white px-3 py-1.5"
                            :aria-label="`Valor medido no ponto ${item.number}`"
                            @blur="saveMeasure"
                            @keydown.enter.prevent="($event.target as HTMLInputElement).blur()"
                        />
                        <span v-if="item.measure_unit" class="text-slate-500">{{ item.measure_unit }}</span>
                    </label>
                    <p v-else-if="item.measure_value !== null" class="text-slate-700">
                        Medido: <strong>{{ formatMeasure(item.measure_value) }} {{ item.measure_unit }}</strong>
                    </p>
                    <p v-if="limits" class="mt-1 text-xs" :class="item.out_of_tolerance ? 'font-semibold text-red-700' : 'text-slate-500'">
                        {{ item.out_of_tolerance ? 'Fora dos limites' : 'Limites' }}: {{ limits }}
                    </p>
                </div>

                <!-- Observações -->
                <div v-if="editable" class="mt-3">
                    <textarea
                        ref="notesField"
                        v-model="notes"
                        rows="2"
                        maxlength="2000"
                        class="block w-full rounded-lg border px-3 py-2 text-sm"
                        :class="item.result === 'nok' && !notes.trim() ? 'border-red-400' : 'border-slate-300'"
                        :placeholder="item.result === 'nok' ? 'Descreva a não conformidade (obrigatório)' : 'Observações (opcional)'"
                        :aria-label="`Observações do ponto ${item.number}`"
                        @blur="saveNotes"
                    />
                </div>
                <p v-else-if="item.notes" class="mt-2 text-sm whitespace-pre-line text-slate-700">{{ item.notes }}</p>
                <p v-if="!editable && item.answered_by" class="mt-1 text-xs text-slate-500">{{ item.answered_by }}, {{ formatDateTime(item.answered_at) }}</p>

                <!-- Fotografias -->
                <div v-if="editable || item.photos.length" class="mt-3">
                    <div v-if="editable" class="mb-2 flex flex-wrap gap-2">
                        <label class="inline-flex cursor-pointer items-center rounded-lg bg-brand-700 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-800">
                            Tirar fotografia
                            <input type="file" accept="image/jpeg,image/png,image/webp" capture="environment" class="sr-only" @change="onFiles" />
                        </label>
                        <label class="inline-flex cursor-pointer items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
                            Escolher
                            <input type="file" accept="image/jpeg,image/png,image/webp" multiple class="sr-only" @change="onFiles" />
                        </label>
                    </div>
                    <ul v-if="queue.length" class="mb-2 space-y-1 text-xs" aria-live="polite">
                        <li v-for="job in queue" :key="job.key" class="flex justify-between gap-2 rounded border px-2 py-1" :class="job.error ? 'border-red-200 bg-red-50 text-red-800' : 'border-slate-200'">
                            <span class="truncate">{{ job.name }}<template v-if="job.error"> — {{ job.error }}</template></span>
                            <span v-if="!job.error">{{ job.progress ? `${job.progress}%` : 'à espera' }}</span>
                            <button v-else type="button" class="underline" @click="queue = queue.filter((q) => q.key !== job.key)">Fechar</button>
                        </li>
                    </ul>
                    <ul v-if="item.photos.length" class="grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-6">
                        <li v-for="photo in item.photos" :key="photo.id" class="relative">
                            <button type="button" class="block aspect-[4/3] w-full overflow-hidden rounded bg-slate-100" :aria-label="`Ver ${photo.original_name}`" @click="viewing = photo">
                                <img v-if="photo.urls.thumb" :src="appUrl(photo.urls.thumb)" :alt="photo.original_name" loading="lazy" class="h-full w-full object-cover" />
                                <span v-else class="flex h-full items-center justify-center px-1 text-center text-xs text-slate-500">{{ photo.status_label }}…</span>
                            </button>
                            <button
                                v-if="editable"
                                type="button"
                                class="absolute top-1 right-1 rounded bg-white/90 px-1.5 text-xs font-semibold text-red-700 shadow"
                                :aria-label="`Retirar ${photo.original_name}`"
                                @click="removePhoto(photo)"
                            >
                                ✕
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <AppModal :open="viewing !== null" :title="`Ponto ${item.number}: ${viewing?.original_name ?? ''}`" size="lg" @close="viewing = null">
            <template v-if="viewing">
                <img v-if="viewing.urls.preview" :src="appUrl(viewing.urls.preview)" :alt="viewing.original_name" class="mx-auto max-h-[70vh] rounded" />
                <p v-else class="text-sm text-slate-600">Ainda não há pré-visualização. Pode descarregar o original.</p>
                <p class="mt-3 text-xs break-all text-slate-500">
                    {{ formatBytes(viewing.size_bytes) }}<template v-if="viewing.taken_at"> · tirada a {{ formatDateTime(viewing.taken_at) }}</template>
                    <template v-if="viewing.uploaded_by"> · enviada por {{ viewing.uploaded_by }}</template> · SHA-256 {{ viewing.sha256 }}
                </p>
            </template>
            <template #footer>
                <a v-if="viewing" :href="appUrl(viewing.urls.original)" class="text-sm font-semibold text-brand-700 hover:underline">Descarregar original</a>
                <AppButton variant="secondary" @click="viewing = null">Fechar</AppButton>
            </template>
        </AppModal>
    </li>
</template>
