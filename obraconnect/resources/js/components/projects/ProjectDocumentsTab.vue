<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { documentsApi, specialtiesApi } from '@/api';
import { ApiError } from '@/api/http';
import type { DocumentListing, Project, Specialty } from '@/api/types';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { appUrl } from '@/utils/base';
import { formatBytes, formatDate } from '@/utils/format';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import DocumentDetailModal from '@/components/projects/DocumentDetailModal.vue';

const props = defineProps<{ project: Project }>();

const session = useSessionStore();
const toast = useToastStore();
const route = useRoute();
const router = useRouter();

/** Igual a obraconnect.files.document_max_mb e document_extensions no servidor (que decide). */
const MAX_MB = 50;
const EXTENSIONS = ['pdf', 'dwg', 'dxf', 'ifc', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'txt', 'csv', 'jpg', 'jpeg', 'png', 'webp', 'zip'];

const listing = ref<DocumentListing | null>(null);
const loading = ref(true);
const search = ref('');
const folderId = computed(() => (route.query.pasta ? Number(route.query.pasta) : null));
const specialties = ref<Specialty[]>([]);
/** Filtro por especialidade (em todas as pastas). Os documentos enviados com o filtro ficam com ela. */
const specialtyId = ref<number | ''>('');
const everywhere = computed(() => search.value.trim() !== '' || specialtyId.value !== '');
const specialtyCode = (id: number | null | undefined) => specialties.value.find((s) => s.id === id)?.code ?? null;
specialtiesApi.list().then((list) => (specialties.value = list), () => undefined);

const writable = computed(() => props.project.status !== 'archived' && !session.isReadOnly);
const canUpload = computed(() => writable.value && props.project.permissions.includes('documents.upload'));
const canManage = computed(() => writable.value && props.project.permissions.includes('documents.manage'));

async function load(): Promise<void> {
    loading.value = true;
    try {
        listing.value = await documentsApi.list(props.project.id, {
            folder_id: folderId.value ?? undefined,
            search: search.value.trim() || undefined,
            specialty_id: specialtyId.value || undefined,
            tree: 1,
        });
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível carregar os documentos.');
    } finally {
        loading.value = false;
    }
}
watch(folderId, load, { immediate: true });

watch(specialtyId, load);

let timer: number | undefined;
watch(search, () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(load, 300);
});

function open(folder: number | null): void {
    search.value = '';
    specialtyId.value = '';
    router.replace({ query: { ...route.query, pasta: folder ?? undefined } });
}

// Envios
const queue = ref<{ key: number; name: string; progress: number; error: string | null }[]>([]);
let nextKey = 0;

async function onFiles(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const files = new Map<number, File>();
    for (const file of input.files ?? []) {
        const job = { key: ++nextKey, name: file.name, progress: 0, error: null as string | null };
        const extension = file.name.split('.').pop()?.toLowerCase() ?? '';
        if (!EXTENSIONS.includes(extension)) job.error = 'Tipo de ficheiro não aceite.';
        else if (file.size > MAX_MB * 1048576) job.error = `Tem ${formatBytes(file.size)}; o máximo é ${MAX_MB} MB.`;
        else files.set(job.key, file);
        queue.value.push(job);
    }
    input.value = '';

    for (const [key, file] of files) {
        try {
            await documentsApi.upload(
                props.project.id,
                file,
                folderId.value,
                (p) => {
                    const live = queue.value.find((q) => q.key === key);
                    if (live) live.progress = Math.max(p, 1);
                },
                specialtyId.value || null,
            );
            queue.value = queue.value.filter((q) => q.key !== key);
        } catch (e) {
            const live = queue.value.find((q) => q.key === key);
            if (live) live.error = e instanceof ApiError ? (e.firstError('file') ?? e.message) : 'Falhou o envio.';
        }
    }
    await load();
}

// Pastas
async function newFolder(): Promise<void> {
    const name = window.prompt('Nome da pasta');
    if (!name?.trim()) return;
    await folderAction(() => documentsApi.createFolder(props.project.id, name.trim(), folderId.value), 'Pasta criada.');
}

async function renameFolder(id: number, current: string): Promise<void> {
    const name = window.prompt('Novo nome da pasta', current);
    if (!name?.trim() || name.trim() === current) return;
    await folderAction(() => documentsApi.renameFolder(props.project.id, id, name.trim()), 'Pasta renomeada.');
}

async function removeFolder(id: number, name: string): Promise<void> {
    if (!window.confirm(`Apagar a pasta "${name}"? Só é possível se estiver vazia.`)) return;
    await folderAction(() => documentsApi.removeFolder(props.project.id, id), 'Pasta apagada.');
}

async function folderAction(action: () => Promise<unknown>, success: string): Promise<void> {
    try {
        await action();
        toast.success(success);
        await load();
    } catch (e) {
        toast.error(e instanceof ApiError ? (e.firstError('name') ?? e.message) : 'Operação falhou.');
    }
}

const detailId = ref<number | null>(null);
</script>

<template>
    <AppCard>
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <AppInput v-model="search" placeholder="Pesquisar em todas as pastas" aria-label="Pesquisar documentos" class="w-full max-w-xs" />
            <select
                v-if="specialties.length"
                v-model="specialtyId"
                aria-label="Filtrar por especialidade"
                class="rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-base text-slate-900 shadow-sm sm:text-sm"
            >
                <option value="">Todas as especialidades</option>
                <option v-for="s in specialties.filter((x) => !x.archived || x.id === specialtyId)" :key="s.id" :value="s.id">{{ s.code }} · {{ s.name }}</option>
            </select>
            <div class="ml-auto flex flex-wrap gap-2">
                <AppButton v-if="canManage && !everywhere" variant="secondary" @click="newFolder">Nova pasta</AppButton>
                <label v-if="canUpload" class="inline-flex cursor-pointer items-center rounded-lg bg-brand-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-800">
                    Enviar documentos
                    <input type="file" multiple class="sr-only" :accept="EXTENSIONS.map((e) => `.${e}`).join(',')" @change="onFiles" />
                </label>
            </div>
        </div>

        <p v-if="specialtyId && canUpload" class="mb-3 text-xs text-slate-500">
            Os documentos enviados agora ficam com a especialidade {{ specialtyCode(specialtyId) }}{{ folderId ? ' e na pasta aberta' : '' }}.
        </p>
        <nav v-if="!everywhere" class="mb-3 flex flex-wrap items-center gap-1 text-sm" aria-label="Pasta atual">
            <button type="button" class="font-medium text-brand-700 hover:underline" @click="open(null)">Documentos</button>
            <template v-for="p in listing?.path ?? []" :key="p.id">
                <span class="text-slate-400">›</span>
                <button type="button" class="font-medium text-brand-700 hover:underline" @click="open(p.id)">{{ p.name }}</button>
            </template>
        </nav>

        <ul v-if="queue.length" class="mb-4 space-y-2 text-sm" aria-live="polite">
            <li v-for="job in queue" :key="job.key" class="rounded-lg border px-3 py-2" :class="job.error ? 'border-red-200 bg-red-50 text-red-800' : 'border-slate-200'">
                <div class="flex justify-between gap-2">
                    <span class="truncate">{{ job.name }}</span>
                    <span v-if="!job.error" class="text-slate-500">{{ job.progress ? `${job.progress}%` : 'à espera' }}</span>
                    <button v-else type="button" class="font-medium underline" @click="queue = queue.filter((q) => q.key !== job.key)">Fechar</button>
                </div>
                <p v-if="job.error">{{ job.error }}</p>
            </li>
        </ul>

        <div v-if="loading && !listing" class="flex justify-center py-10"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
        <EmptyState
            v-else-if="listing && listing.folders.length === 0 && listing.documents.length === 0"
            :title="everywhere ? 'Nenhum documento encontrado' : 'Pasta vazia'"
            :description="canUpload && !everywhere ? 'Envie projetos, desenhos, cadernos de encargos ou outros documentos da obra.' : undefined"
        />
        <ul v-else-if="listing" class="divide-y divide-slate-100">
            <li v-for="folder in listing.folders" :key="`f${folder.id}`" class="flex items-center justify-between gap-3 py-2.5">
                <button type="button" class="flex min-w-0 items-center gap-2 text-left" @click="open(folder.id)">
                    <span aria-hidden="true" class="text-lg">📁</span>
                    <span class="truncate font-medium text-slate-900">{{ folder.name }}</span>
                    <span class="text-xs text-slate-500">{{ folder.items_count }}</span>
                </button>
                <div v-if="canManage" class="flex shrink-0 gap-1">
                    <AppButton size="sm" variant="ghost" @click="renameFolder(folder.id, folder.name)">Renomear</AppButton>
                    <AppButton size="sm" variant="ghost" class="text-red-700" @click="removeFolder(folder.id, folder.name)">Apagar</AppButton>
                </div>
            </li>
            <li v-for="doc in listing.documents" :key="`d${doc.id}`" class="flex flex-wrap items-center justify-between gap-3 py-2.5">
                <button type="button" class="flex min-w-0 flex-1 items-start gap-2 text-left" @click="detailId = doc.id">
                    <span aria-hidden="true" class="text-lg">📄</span>
                    <span class="min-w-0">
                        <span class="block truncate font-medium text-slate-900">
                            {{ doc.title }}
                            <span v-if="specialtyCode(doc.specialty_id)" class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600">{{
                                specialtyCode(doc.specialty_id)
                            }}</span>
                        </span>
                        <span v-if="doc.current_version" class="block text-xs text-slate-500">
                            v{{ doc.current_version.number }} · {{ formatBytes(doc.current_version.size_bytes) }} · {{ formatDate(doc.current_version.created_at) }}<template v-if="doc.current_version.uploaded_by"> · {{ doc.current_version.uploaded_by }}</template>
                        </span>
                    </span>
                </button>
                <div v-if="doc.current_version" class="flex shrink-0 gap-3 text-sm">
                    <a v-if="doc.current_version.urls.open" :href="appUrl(doc.current_version.urls.open)" target="_blank" rel="noopener" class="font-semibold text-brand-700 hover:underline">Abrir</a>
                    <a :href="appUrl(doc.current_version.urls.download)" class="font-semibold text-brand-700 hover:underline">Descarregar</a>
                </div>
            </li>
        </ul>

        <DocumentDetailModal
            :project="project"
            :document-id="detailId"
            :tree="listing?.tree ?? []"
            :specialties="specialties"
            @close="detailId = null"
            @changed="load"
        />
    </AppCard>
</template>
