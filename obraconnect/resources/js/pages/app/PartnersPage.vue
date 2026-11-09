<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { specialtiesApi } from '@/api';
import { ApiError } from '@/api/http';
import type { Specialty } from '@/api/types';
import { partnersApi, type Partner } from '@/api/economics';
import { partnerPortalApi, type PortalUserRow } from '@/api/portal';
import { appUrl } from '@/utils/base';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { formatDate, formatDateTime } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const session = useSessionStore();
const toast = useToastStore();
const canSupplier = computed(() => session.can('purchases.manage') && !session.isReadOnly);
const canSub = computed(() => session.can('subcontractors.manage') && !session.isReadOnly);
const canKind = (kind: string) => (kind === 'subcontractor' ? canSub.value : canSupplier.value);

const partners = ref<Partner[]>([]);
const specialties = ref<Specialty[]>([]);
const loading = ref(true);
const filter = ref<'all' | 'supplier' | 'subcontractor'>('all');

async function load(): Promise<void> {
    [partners.value, specialties.value] = await Promise.all([partnersApi.list(), specialtiesApi.list()]);
    loading.value = false;
}
onMounted(load);

const visible = computed(() => partners.value.filter((p) => filter.value === 'all' || p.kind === filter.value));
const documentBadge = (state: string) => (state === 'expired' ? 'red' : state === 'expiring' ? 'amber' : 'green');
const expiredCount = (p: Partner) => p.documents.filter((d) => d.state !== 'valid').length;

const open = ref(false);
const editing = ref<Partner | null>(null);
const form = useForm({ kind: 'supplier', name: '', nif: '', email: '', phone: '', contact_name: '', address: '', notes: '', specialty_ids: [] as number[] });
function edit(p: Partner | null, kind = 'supplier'): void {
    editing.value = p;
    form.reset(
        p
            ? {
                  kind: p.kind,
                  name: p.name,
                  nif: p.nif ?? '',
                  email: p.email ?? '',
                  phone: p.phone ?? '',
                  contact_name: p.contact_name ?? '',
                  address: p.address ?? '',
                  notes: p.notes ?? '',
                  specialty_ids: p.specialties.map((s) => s.id),
              }
            : { kind, specialty_ids: [] },
    );
    open.value = true;
}
async function save(): Promise<void> {
    const payload: Record<string, unknown> = { ...form.data };
    for (const k of ['nif', 'email', 'phone', 'contact_name', 'address', 'notes']) if (payload[k] === '') payload[k] = null;
    const id = editing.value?.id;
    if (id) delete payload.kind;
    const saved = await form.submit(() => (id ? partnersApi.update(id, payload) : partnersApi.create(payload)), { success: 'Guardado.' });
    if (saved) {
        open.value = false;
        await load();
    }
}
async function archive(p: Partner): Promise<void> {
    try {
        if (!p.contracts_count && !p.purchase_orders_count && window.confirm(`Apagar ${p.name}?`)) await partnersApi.remove(p.id);
        else await partnersApi.update(p.id, { archived: !p.archived });
        await load();
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível.');
    }
}

// Documentos com validade
const docsFor = ref<Partner | null>(null);
const docForm = useForm({ title: '', reference: '', expires_on: '' });
const docFile = ref<File | null>(null);
async function addDocument(): Promise<void> {
    if (!docsFor.value) return;
    const body = new FormData();
    body.append('title', docForm.data.title);
    body.append('reference', docForm.data.reference);
    body.append('expires_on', docForm.data.expires_on);
    if (docFile.value) body.append('file', docFile.value);
    const saved = await docForm.submit(() => partnersApi.addDocument(docsFor.value!.id, body));
    if (saved) {
        docsFor.value = saved;
        docForm.reset();
        docFile.value = null;
        await load();
    }
}

// Portal de subempreiteiros: quem do subempreiteiro tem acesso
const portalFor = ref<Partner | null>(null);
const portalUsers = ref<PortalUserRow[]>([]);
const portalForm = useForm({ name: '', email: '' });
const portalStatus = (s: PortalUserRow['status']) => (s === 'active' ? 'green' : s === 'pending' ? 'amber' : 'gray');
async function openPortal(p: Partner): Promise<void> {
    portalFor.value = p;
    portalForm.reset({ name: p.contact_name ?? '', email: p.email ?? '' });
    portalUsers.value = await partnerPortalApi.list(p.id);
}
async function invitePortal(): Promise<void> {
    if (!portalFor.value) return;
    const rows = await portalForm.submit(() => partnerPortalApi.invite(portalFor.value!.id, { ...portalForm.data }), { success: 'Convite enviado por email.' });
    if (rows) {
        portalUsers.value = rows;
        portalForm.reset();
        await load();
    }
}
async function portalAction(row: PortalUserRow, action: 'resend' | 'revoke'): Promise<void> {
    if (!portalFor.value) return;
    if (action === 'revoke' && !window.confirm(`Retirar o acesso ao portal a ${row.name}?`)) return;
    try {
        portalUsers.value = action === 'resend' ? await partnerPortalApi.resend(portalFor.value.id, row.id) : await partnerPortalApi.revoke(portalFor.value.id, row.id);
        toast.success(action === 'resend' ? 'Convite reenviado.' : 'Acesso retirado.');
        await load();
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível.');
    }
}
async function removeDocument(id: number): Promise<void> {
    if (!docsFor.value) return;
    docsFor.value = await partnersApi.removeDocument(docsFor.value.id, id);
    await load();
}
function toggleSpecialty(id: number, on: boolean): void {
    form.data.specialty_ids = on ? [...form.data.specialty_ids, id] : form.data.specialty_ids.filter((x) => x !== id);
}
</script>

<template>
    <div>
        <PageHeader title="Fornecedores e subempreiteiros" description="Entidades externas da empresa, com especialidades e documentos com validade (alvará, seguros, declarações de não dívida).">
            <AppButton v-if="canSupplier" variant="secondary" @click="edit(null, 'supplier')">Novo fornecedor</AppButton>
            <AppButton v-if="canSub" @click="edit(null, 'subcontractor')">Novo subempreiteiro</AppButton>
        </PageHeader>

        <div class="mb-4 flex flex-wrap gap-1">
            <button
                v-for="f in [
                    { key: 'all', label: 'Todos' },
                    { key: 'supplier', label: 'Fornecedores' },
                    { key: 'subcontractor', label: 'Subempreiteiros' },
                ]"
                :key="f.key"
                type="button"
                class="rounded-full border px-3 py-1.5 text-sm font-medium"
                :class="filter === f.key ? 'border-brand-700 bg-brand-700 text-white' : 'border-slate-300 bg-white text-slate-700'"
                @click="filter = f.key as 'all'"
            >
                {{ f.label }}
            </button>
        </div>

        <div v-if="loading" class="flex justify-center py-16"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
        <AppCard v-else>
            <EmptyState v-if="visible.length === 0" title="Sem entidades" />
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="p in visible" :key="p.id" class="flex flex-wrap items-start justify-between gap-2 py-3">
                    <div class="min-w-0">
                        <p class="font-medium" :class="p.archived ? 'text-slate-400' : 'text-slate-900'">{{ p.name }}</p>
                        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                            <AppBadge :color="p.kind === 'subcontractor' ? 'brand' : 'blue'">{{ p.kind_label }}</AppBadge>
                            <AppBadge v-if="p.archived">Arquivado</AppBadge>
                            <span v-if="p.nif">NIF {{ p.nif }}</span>
                            <span v-if="p.contact_name">· {{ p.contact_name }}</span>
                            <span v-if="p.phone">· {{ p.phone }}</span>
                            <span v-for="s in p.specialties" :key="s.id" class="font-mono font-semibold">{{ s.code }}</span>
                            <AppBadge v-if="expiredCount(p)" color="red">{{ expiredCount(p) }} documento(s) a rever</AppBadge>
                        </p>
                    </div>
                    <div v-if="canKind(p.kind)" class="flex flex-wrap gap-1">
                        <AppButton size="sm" variant="ghost" @click="docsFor = p">Documentos ({{ p.documents.length }})</AppButton>
                        <AppButton v-if="p.kind === 'subcontractor' && canSub" size="sm" variant="ghost" @click="openPortal(p)">Portal ({{ p.portal_users_count ?? 0 }})</AppButton>
                        <AppButton size="sm" variant="ghost" @click="edit(p)">Editar</AppButton>
                        <AppButton size="sm" variant="ghost" class="text-red-700" @click="archive(p)">{{ p.archived ? 'Reativar' : p.contracts_count || p.purchase_orders_count ? 'Arquivar' : 'Apagar' }}</AppButton>
                    </div>
                </li>
            </ul>
        </AppCard>

        <AppModal :open="open" :title="editing ? `Editar ${editing.name}` : form.data.kind === 'subcontractor' ? 'Novo subempreiteiro' : 'Novo fornecedor'" size="lg" @close="open = false">
            <form id="partner-form" class="space-y-4" novalidate @submit.prevent="save">
                <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppInput v-model="form.data.name" label="Nome" required :maxlength="200" :error="form.errors.value.name" />
                    <AppInput v-model="form.data.nif" label="NIF" inputmode="numeric" :error="form.errors.value.nif" />
                    <AppInput v-model="form.data.contact_name" label="Contacto" :error="form.errors.value.contact_name" />
                    <AppInput v-model="form.data.phone" label="Telefone" :error="form.errors.value.phone" />
                    <AppInput v-model="form.data.email" type="email" label="Email" :error="form.errors.value.email" />
                    <AppInput v-model="form.data.address" label="Morada" :error="form.errors.value.address" />
                </div>
                <div v-if="specialties.length">
                    <p class="mb-1 text-sm font-medium text-slate-700">Especialidades</p>
                    <div class="flex flex-wrap gap-2">
                        <label v-for="s in specialties.filter((x) => !x.archived)" :key="s.id" class="inline-flex items-center gap-1.5 rounded border border-slate-200 px-2 py-1 text-sm">
                            <input type="checkbox" :checked="form.data.specialty_ids.includes(s.id)" @change="toggleSpecialty(s.id, ($event.target as HTMLInputElement).checked)" />
                            <span class="font-mono text-xs font-semibold">{{ s.code }}</span> {{ s.name }}
                        </label>
                    </div>
                </div>
                <AppTextarea v-model="form.data.notes" label="Notas" :rows="2" :maxlength="2000" />
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="open = false">Cancelar</AppButton>
                <AppButton type="submit" form="partner-form" :loading="form.processing.value">Guardar</AppButton>
            </template>
        </AppModal>

        <AppModal :open="docsFor !== null" :title="`Documentos de ${docsFor?.name ?? ''}`" size="lg" @close="docsFor = null">
            <template v-if="docsFor">
                <ul class="mb-4 divide-y divide-slate-100 text-sm">
                    <li v-for="d in docsFor.documents" :key="d.id" class="flex items-center justify-between gap-2 py-2">
                        <span>
                            <strong>{{ d.title }}</strong><template v-if="d.reference"> · {{ d.reference }}</template>
                            <AppBadge class="ml-2" :color="documentBadge(d.state)">{{ d.expires_on ? `válido até ${formatDate(d.expires_on)}` : 'sem validade' }}</AppBadge>
                            <AppBadge v-if="d.from_portal" class="ml-1" color="brand">Enviado no portal</AppBadge>
                        </span>
                        <span class="flex shrink-0 items-center gap-3">
                            <a v-if="d.has_file" :href="appUrl(partnerPortalApi.documentUrl(docsFor.id, d.id))" target="_blank" rel="noopener" class="text-xs font-medium text-brand-700 hover:underline">Abrir</a>
                            <button type="button" class="text-xs font-medium text-red-700 hover:underline" @click="removeDocument(d.id)">Retirar</button>
                        </span>
                    </li>
                    <li v-if="!docsFor.documents.length" class="py-2 text-slate-500">Sem documentos.</li>
                </ul>
                <form class="grid gap-3 sm:grid-cols-4" novalidate @submit.prevent="addDocument">
                    <AppSelect
                        v-model="docForm.data.title"
                        class="sm:col-span-2"
                        label="Documento"
                        :options="[
                            { value: '', label: 'Escolha ou escreva…' },
                            ...['Alvará / certificado de empreiteiro', 'Seguro de acidentes de trabalho', 'Seguro de responsabilidade civil', 'Declaração de não dívida à AT', 'Declaração de não dívida à Segurança Social', 'Certidão permanente'].map((t) => ({ value: t, label: t })),
                        ]"
                        :error="docForm.errors.value.title"
                    />
                    <AppInput v-model="docForm.data.reference" label="N.º / apólice" />
                    <AppInput v-model="docForm.data.expires_on" type="date" label="Válido até" :error="docForm.errors.value.expires_on" />
                    <label class="text-sm sm:col-span-4">
                        <span class="font-medium text-slate-700">Ficheiro (opcional, PDF ou fotografia até 20 MB)</span>
                        <input type="file" accept="application/pdf,image/jpeg,image/png" class="mt-1 block w-full text-sm" @change="docFile = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                        <span v-if="docForm.errors.value.file" class="mt-1 block text-red-700">{{ docForm.errors.value.file }}</span>
                    </label>
                    <div class="sm:col-span-4"><AppButton type="submit" size="sm" :loading="docForm.processing.value" :disabled="!docForm.data.title">Juntar documento</AppButton></div>
                </form>
            </template>
            <template #footer><AppButton variant="secondary" @click="docsFor = null">Fechar</AppButton></template>
        </AppModal>

        <AppModal :open="portalFor !== null" :title="`Portal de ${portalFor?.name ?? ''}`" size="lg" @close="portalFor = null">
            <template v-if="portalFor">
                <p class="mb-3 text-sm text-slate-600">
                    No portal, as pessoas do subempreiteiro veem os contratos dele com a empresa (em curso e fechados), propõem os autos de cada período e enviam documentos.
                    Não veem mais nada da empresa. Os autos propostos chegam como submetidos para aprovar ou devolver.
                </p>
                <ul class="mb-4 divide-y divide-slate-100 text-sm" data-test="portal-users">
                    <li v-for="u in portalUsers" :key="u.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span class="min-w-0">
                            <strong>{{ u.name }}</strong> · {{ u.email }}
                            <AppBadge class="ml-2" :color="portalStatus(u.status)">{{ u.status_label }}</AppBadge>
                            <span v-if="u.last_seen_at" class="block text-xs text-slate-500">última visita {{ formatDateTime(u.last_seen_at) }}</span>
                        </span>
                        <span class="flex gap-3">
                            <button v-if="u.status === 'pending' || u.status === 'expired'" type="button" class="text-xs font-medium text-brand-700 hover:underline" @click="portalAction(u, 'resend')">Reenviar convite</button>
                            <button v-if="u.status !== 'revoked'" type="button" class="text-xs font-medium text-red-700 hover:underline" @click="portalAction(u, 'revoke')">Retirar acesso</button>
                        </span>
                    </li>
                    <li v-if="!portalUsers.length" class="py-2 text-slate-500">Ainda ninguém tem acesso.</li>
                </ul>
                <form class="grid gap-3 sm:grid-cols-3" novalidate @submit.prevent="invitePortal">
                    <AppAlert v-if="portalForm.message.value" type="error" class="sm:col-span-3">{{ portalForm.message.value }}</AppAlert>
                    <AppInput v-model="portalForm.data.name" label="Nome" :error="portalForm.errors.value.name" />
                    <AppInput v-model="portalForm.data.email" type="email" label="Email" class="sm:col-span-2" :error="portalForm.errors.value.email" />
                    <div class="sm:col-span-3">
                        <AppButton type="submit" size="sm" :loading="portalForm.processing.value" :disabled="!portalForm.data.name || !portalForm.data.email">Enviar convite</AppButton>
                    </div>
                </form>
            </template>
            <template #footer><AppButton variant="secondary" @click="portalFor = null">Fechar</AppButton></template>
        </AppModal>
    </div>
</template>
