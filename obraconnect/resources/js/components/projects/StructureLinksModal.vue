<script setup lang="ts">
import { ref, watch } from 'vue';
import { structureApi } from '@/api';
import { ApiError } from '@/api/http';
import type { Project, StructureLinks } from '@/api/types';
import { appUrl } from '@/utils/base';
import { formatBytes } from '@/utils/format';
import { formatLongDate } from '@/utils/projects';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';

export interface LinksTarget {
    type: 'element' | 'location';
    id: number;
    title: string;
}

const props = defineProps<{ project: Project; target: LinksTarget | null; canEdit?: boolean }>();
const emit = defineEmits<{ close: []; edit: [] }>();

const links = ref<StructureLinks | null>(null);
const error = ref<string | null>(null);

watch(
    () => props.target,
    async (target) => {
        links.value = null;
        error.value = null;
        if (!target) return;
        try {
            links.value = await (target.type === 'element'
                ? structureApi.elementLinks(props.project.id, target.id)
                : structureApi.locationLinks(props.project.id, target.id));
        } catch (e) {
            error.value = e instanceof ApiError ? e.message : 'Não foi possível carregar o histórico.';
        }
    },
    { immediate: true },
);

const quantity = (q: number | null, unit: string | null) => (q === null ? '' : `${String(q).replace('.', ',')}${unit ? ` ${unit}` : ''}`);
const empty = (l: StructureLinks) => !l.diary_items?.length && !l.photos?.length && !l.documents?.length;
</script>

<template>
    <AppModal :open="target !== null" :title="target?.title ?? ''" size="lg" @close="emit('close')">
        <AppAlert v-if="error" type="error">{{ error }}</AppAlert>
        <div v-else-if="!links" class="flex justify-center py-8"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
        <div v-else class="space-y-5">
            <p v-if="empty(links)" class="text-sm text-slate-500">
                Ainda nada ligado{{ target?.type === 'location' ? ' a este local (nem ao que está dentro dele)' : ' a este elemento' }}.
                No diário, nas fotografias e nos documentos há um campo “Onde” para fazer a ligação.
            </p>

            <section v-if="links.diary_items?.length">
                <h3 class="mb-2 text-sm font-semibold text-slate-800">Diário de obra</h3>
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="item in links.diary_items" :key="item.id" class="flex flex-wrap justify-between gap-2 py-2">
                        <span>
                            <RouterLink :to="`/app/obras/${project.id}/diario/${item.entry_id}`" class="inline-block font-medium text-brand-700 hover:underline first-letter:uppercase" @click="emit('close')">
                                {{ formatLongDate(item.entry_date) }}
                            </RouterLink>
                            <span class="block text-slate-700">{{ item.kind_label }}: {{ item.description }}<template v-if="item.company"> · {{ item.company }}</template></span>
                        </span>
                        <span class="font-medium">{{ quantity(item.quantity, item.unit) }}</span>
                    </li>
                </ul>
            </section>

            <section v-if="links.photos?.length">
                <h3 class="mb-2 text-sm font-semibold text-slate-800">Fotografias</h3>
                <ul class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                    <li v-for="photo in links.photos" :key="photo.id">
                        <RouterLink :to="`/app/obras/${project.id}/diario/${photo.diary_entry_id}`" class="block" :title="photo.caption ?? photo.original_name" @click="emit('close')">
                            <img v-if="photo.urls.thumb" :src="appUrl(photo.urls.thumb)" :alt="photo.caption ?? photo.original_name" loading="lazy" class="aspect-[4/3] w-full rounded object-cover" />
                            <span v-else class="flex aspect-[4/3] items-center justify-center rounded bg-slate-100 text-xs text-slate-500">{{ photo.status_label }}</span>
                            <span v-if="photo.diary_date" class="mt-0.5 block text-xs text-slate-500">{{ photo.diary_date.split('-').reverse().join('/') }}</span>
                        </RouterLink>
                    </li>
                </ul>
            </section>

            <section v-if="links.documents?.length">
                <h3 class="mb-2 text-sm font-semibold text-slate-800">Documentos</h3>
                <ul class="divide-y divide-slate-100 text-sm">
                    <li v-for="doc in links.documents" :key="doc.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span>
                            <span class="font-medium">{{ doc.title }}</span>
                            <span v-if="doc.current_version" class="block text-xs text-slate-500">v{{ doc.current_version.number }} · {{ formatBytes(doc.current_version.size_bytes) }}</span>
                        </span>
                        <span v-if="doc.current_version" class="flex gap-3">
                            <a v-if="doc.current_version.urls.open" :href="appUrl(doc.current_version.urls.open)" target="_blank" rel="noopener" class="font-semibold text-brand-700 hover:underline">Abrir</a>
                            <a :href="appUrl(doc.current_version.urls.download)" class="font-semibold text-brand-700 hover:underline">Descarregar</a>
                        </span>
                    </li>
                </ul>
            </section>
        </div>
        <template #footer>
            <AppButton v-if="canEdit && target?.type === 'element'" variant="ghost" class="mr-auto" @click="emit('edit')">Editar elemento</AppButton>
            <AppButton variant="secondary" @click="emit('close')">Fechar</AppButton>
        </template>
    </AppModal>
</template>
