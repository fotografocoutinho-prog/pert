<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ApiError } from '@/api/http';
import { portalApi, type PortalDocument } from '@/api/portal';
import { useToastStore } from '@/stores/toast';
import { appUrl } from '@/utils/base';
import { formatDate } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';

const route = useRoute();
const toast = useToastStore();
const access = String(route.params.access);
const docs = ref<PortalDocument[] | null>(null);
const form = reactive({ title: '', reference: '', expires_on: '' });
const file = ref<File | null>(null);
const saving = ref(false);
const error = ref<string | null>(null);

onMounted(async () => (docs.value = await portalApi.documents(access)));

const stateBadge = (d: PortalDocument) =>
    d.state === 'expired' ? { color: 'red' as const, label: 'Caducado' } : d.state === 'expiring' ? { color: 'amber' as const, label: 'A caducar' } : { color: 'green' as const, label: 'Válido' };

async function upload(): Promise<void> {
    if (!file.value) return;
    saving.value = true;
    error.value = null;
    try {
        const body = new FormData();
        body.append('title', form.title);
        if (form.reference) body.append('reference', form.reference);
        if (form.expires_on) body.append('expires_on', form.expires_on);
        body.append('file', file.value);
        await portalApi.uploadDocument(access, body);
        docs.value = await portalApi.documents(access);
        toast.success('Documento enviado à empresa.');
        Object.assign(form, { title: '', reference: '', expires_on: '' });
        file.value = null;
    } catch (e) {
        error.value = e instanceof ApiError ? (e.firstError('file') ?? e.firstError('title') ?? e.message) : 'Não foi possível enviar.';
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-xl font-bold text-slate-900">Documentos</h1>
            <p class="text-sm text-slate-600">Seguro, alvará, declarações de não dívida e outros documentos que a empresa lhe pede, com a data de validade.</p>
        </div>

        <AppCard title="Enviar um documento">
            <form class="grid gap-3 sm:grid-cols-2" data-test="portal-upload" @submit.prevent="upload">
                <AppInput v-model="form.title" label="Documento" placeholder="Ex.: Seguro de acidentes de trabalho" required class="sm:col-span-2" />
                <AppInput v-model="form.reference" label="Referência (opcional)" placeholder="N.º da apólice, alvará…" />
                <AppInput v-model="form.expires_on" label="Válido até (opcional)" type="date" />
                <label class="text-sm sm:col-span-2">
                    <span class="font-medium text-slate-700">Ficheiro (PDF ou fotografia, até 20 MB)</span>
                    <input type="file" accept="application/pdf,image/jpeg,image/png" required class="mt-1 block w-full text-sm" @change="file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                </label>
                <AppAlert v-if="error" type="error" class="sm:col-span-2">{{ error }}</AppAlert>
                <div class="sm:col-span-2"><AppButton type="submit" :loading="saving" :disabled="!file || !form.title">Enviar</AppButton></div>
            </form>
        </AppCard>

        <AppCard title="Documentos na empresa">
            <p v-if="docs && !docs.length" class="text-sm text-slate-600">Ainda não há documentos.</p>
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="d in docs ?? []" :key="d.id" class="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                    <div class="min-w-0">
                        <p class="font-medium">{{ d.title }} <span v-if="d.reference" class="font-normal text-slate-500">· {{ d.reference }}</span></p>
                        <p class="text-xs text-slate-500">{{ d.expires_on ? `válido até ${formatDate(d.expires_on)}` : 'sem validade' }} · {{ d.from_portal ? 'enviado no portal' : 'registado pela empresa' }}</p>
                    </div>
                    <span class="flex items-center gap-2">
                        <AppBadge :color="stateBadge(d).color">{{ stateBadge(d).label }}</AppBadge>
                        <a v-if="d.has_file" :href="appUrl(portalApi.documentUrl(access, d.id))" target="_blank" rel="noopener" class="font-medium text-brand-700 hover:underline">Abrir</a>
                    </span>
                </li>
            </ul>
        </AppCard>
    </div>
</template>
