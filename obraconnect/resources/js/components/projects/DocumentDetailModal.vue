<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { documentsApi } from '@/api';
import { ApiError } from '@/api/http';
import type { DocumentFolderRef, Project, ProjectDocument } from '@/api/types';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { appUrl } from '@/utils/base';
import { formatBytes, formatDateTime } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';

const props = defineProps<{ project: Project; documentId: number | null; tree: DocumentFolderRef[] }>();
const emit = defineEmits<{ close: []; changed: [] }>();

const session = useSessionStore();
const toast = useToastStore();

const doc = ref<ProjectDocument | null>(null);
const error = ref<string | null>(null);

const writable = computed(() => props.project.status !== 'archived' && !session.isReadOnly);
const canManage = computed(() => writable.value && props.project.permissions.includes('documents.manage'));
const canEdit = computed(
    () => canManage.value || (writable.value && props.project.permissions.includes('documents.upload') && Boolean(doc.value?.created_by_me)),
);

/** "Projeto › Estabilidade" para cada pasta da árvore. */
const folderOptions = computed(() => {
    const byId = new Map(props.tree.map((f) => [f.id, f]));
    const label = (f: DocumentFolderRef): string => {
        const parent = f.parent_id ? byId.get(f.parent_id) : undefined;
        return parent ? `${label(parent)} › ${f.name}` : f.name;
    };
    return props.tree.map((f) => ({ id: f.id, label: label(f) })).sort((a, b) => a.label.localeCompare(b.label, 'pt'));
});

const title = ref('');
async function load(): Promise<void> {
    error.value = null;
    if (props.documentId === null) return;
    try {
        doc.value = await documentsApi.get(props.project.id, props.documentId);
        title.value = doc.value.title;
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Não foi possível abrir o documento.';
    }
}
watch(() => props.documentId, (id) => {
    doc.value = null;
    note.value = '';
    if (id !== null) load();
}, { immediate: true });

async function act(action: () => Promise<unknown>, success: string): Promise<boolean> {
    try {
        await action();
        toast.success(success);
        emit('changed');
        return true;
    } catch (e) {
        toast.error(e instanceof ApiError ? (e.firstError('file') ?? e.firstError('title') ?? e.message) : 'Operação falhou.');
        return false;
    }
}

async function saveTitle(): Promise<void> {
    if (!doc.value || title.value.trim() === '' || title.value.trim() === doc.value.title) return;
    if (await act(async () => (doc.value = await documentsApi.update(props.project.id, doc.value!.id, { title: title.value.trim() })), 'Título guardado.')) {
        title.value = doc.value!.title;
    }
}

async function move(event: Event): Promise<void> {
    if (!doc.value) return;
    const value = (event.target as HTMLSelectElement).value;
    await act(async () => (doc.value = await documentsApi.update(props.project.id, doc.value!.id, { folder_id: value ? Number(value) : null })), 'Documento movido.');
}

// Nova versão
const note = ref('');
const progress = ref<number | null>(null);
async function addVersion(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file || !doc.value) return;
    progress.value = 0;
    await act(async () => {
        doc.value = await documentsApi.addVersion(props.project.id, doc.value!.id, file, note.value.trim() || null, (p) => (progress.value = p));
        note.value = '';
    }, 'Nova versão enviada.');
    progress.value = null;
}

async function remove(): Promise<void> {
    if (!doc.value || !window.confirm(`Apagar "${doc.value.title}" e todas as suas versões? Não pode ser desfeito.`)) return;
    if (await act(() => documentsApi.remove(props.project.id, doc.value!.id), 'Documento apagado.')) emit('close');
}
</script>

<template>
    <AppModal :open="documentId !== null" :title="doc?.title ?? 'Documento'" size="lg" @close="emit('close')">
        <AppAlert v-if="error" type="error">{{ error }}</AppAlert>
        <div v-else-if="doc" class="space-y-5">
            <div v-if="canEdit" class="grid gap-3 sm:grid-cols-2">
                <AppInput v-model="title" label="Título" :maxlength="200" @blur="saveTitle" @keydown.enter.prevent="saveTitle" />
                <div v-if="canManage">
                    <label class="mb-1 block text-sm font-medium text-slate-700" for="doc-folder">Pasta</label>
                    <select id="doc-folder" class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm" :value="doc.folder_id ?? ''" @change="move">
                        <option value="">(raiz da obra)</option>
                        <option v-for="f in folderOptions" :key="f.id" :value="f.id">{{ f.label }}</option>
                    </select>
                </div>
            </div>
            <p v-if="doc.description" class="text-sm whitespace-pre-line text-slate-700">{{ doc.description }}</p>

            <div>
                <h3 class="mb-2 text-sm font-semibold text-slate-800">Versões</h3>
                <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                    <li v-for="v in doc.versions" :key="v.id" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5 text-sm">
                        <div class="min-w-0">
                            <p class="font-medium">
                                v{{ v.number }} <span v-if="v.number === doc.current_version?.number" class="text-xs font-semibold text-green-700">atual</span>
                                <span class="font-normal text-slate-600"> · {{ v.original_name }}</span>
                            </p>
                            <p class="text-xs text-slate-500">
                                {{ formatBytes(v.size_bytes) }} · {{ formatDateTime(v.created_at) }}<template v-if="v.uploaded_by"> · {{ v.uploaded_by }}</template>
                                · {{ v.downloads_count ?? 0 }} {{ v.downloads_count === 1 ? 'descarga' : 'descargas' }}
                            </p>
                            <p v-if="v.note" class="text-xs text-slate-700">“{{ v.note }}”</p>
                        </div>
                        <div class="flex gap-3">
                            <a v-if="v.urls.open" :href="appUrl(v.urls.open)" target="_blank" rel="noopener" class="font-semibold text-brand-700 hover:underline">Abrir</a>
                            <a :href="appUrl(v.urls.download)" class="font-semibold text-brand-700 hover:underline">Descarregar</a>
                        </div>
                    </li>
                </ul>
                <p class="mt-1 text-xs text-slate-500">As versões anteriores ficam guardadas tal como foram enviadas.</p>
            </div>

            <div v-if="canEdit" class="rounded-lg border border-slate-200 p-3">
                <h3 class="mb-2 text-sm font-semibold text-slate-800">Enviar nova versão</h3>
                <AppInput v-model="note" placeholder="O que mudou (opcional)" aria-label="O que mudou nesta versão" :maxlength="255" />
                <label class="mt-2 inline-flex cursor-pointer items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50" :class="progress !== null ? 'pointer-events-none opacity-60' : ''">
                    {{ progress !== null ? `A enviar… ${progress}%` : 'Escolher ficheiro' }}
                    <input type="file" class="sr-only" @change="addVersion" />
                </label>
            </div>
        </div>
        <template #footer>
            <AppButton v-if="canManage && doc" variant="ghost" class="mr-auto text-red-700" @click="remove">Apagar documento</AppButton>
            <AppButton variant="secondary" @click="emit('close')">Fechar</AppButton>
        </template>
    </AppModal>
</template>
