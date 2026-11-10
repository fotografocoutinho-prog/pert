<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { integrationsApi } from '@/api';
import { ApiError } from '@/api/http';
import type { ApiKeyInfo, IntegrationsOverview, WebhookDeliveryInfo, WebhookInfo } from '@/api/types';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { formatDate, formatDateTime } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppCheckbox from '@/components/ui/AppCheckbox.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const session = useSessionStore();
const toast = useToastStore();
const data = ref<IntegrationsOverview | null>(null);
const canWrite = computed(() => !session.isReadOnly && !!data.value?.enabled);
const scopeLabel = (key: string) => data.value?.scopes.find((s) => s.key === key)?.label ?? key;
const eventLabel = (key: string) => data.value?.events.find((e) => e.key === key)?.label ?? key;

async function load(): Promise<void> {
    data.value = await integrationsApi.overview();
}

onMounted(load);

function fail(e: unknown): void {
    toast.error(e instanceof ApiError ? e.message : 'Falhou.');
}

// --- segredo mostrado uma vez ---
const reveal = ref<{ title: string; value: string; hint: string } | null>(null);

async function copy(value: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        toast.success('Copiado.');
    } catch {
        toast.error('Não foi possível copiar: selecione e copie à mão.');
    }
}

// --- chaves ---
const keyModal = ref(false);
const keyForm = useForm({ name: '', expires_at: '' });
const keyScopes = ref<Record<string, boolean>>({});

function openKey(): void {
    keyForm.reset();
    keyScopes.value = Object.fromEntries((data.value?.scopes ?? []).map((s) => [s.key, s.key.endsWith(':read')]));
    keyModal.value = true;
}

async function createKey(): Promise<void> {
    const scopes = Object.entries(keyScopes.value).filter(([, on]) => on).map(([k]) => k);
    const created = await keyForm.submit((d) => integrationsApi.createKey({ name: d.name, scopes, expires_at: d.expires_at || null }));
    if (created?.plain_key) {
        keyModal.value = false;
        reveal.value = {
            title: `Chave «${created.name}»`,
            value: created.plain_key,
            hint: 'Copie a chave agora e guarde-a no outro programa: por segurança, não volta a aparecer. Se a perder, revogue-a e crie outra.',
        };
        await load();
    }
}

async function revokeKey(k: ApiKeyInfo): Promise<void> {
    if (!window.confirm(`Revogar a chave «${k.name}»? Os programas que a usam deixam de conseguir ligar-se.`)) return;
    try {
        await integrationsApi.revokeKey(k.id);
        toast.success('Chave revogada.');
        await load();
    } catch (e) {
        fail(e);
    }
}

function keyState(k: ApiKeyInfo): { label: string; color: 'green' | 'gray' | 'amber' } {
    if (k.revoked_at) return { label: 'Revogada', color: 'gray' };
    if (!k.usable) return { label: 'Expirada', color: 'amber' };
    return { label: 'Ativa', color: 'green' };
}

// --- webhooks ---
const hookModal = ref(false);
const editing = ref<WebhookInfo | null>(null);
const hookForm = useForm({ url: 'https://', description: '', active: true });
const hookEvents = ref<Record<string, boolean>>({});

function openHook(w: WebhookInfo | null): void {
    editing.value = w;
    hookForm.reset(w ? { url: w.url, description: w.description ?? '', active: w.active } : {});
    hookEvents.value = Object.fromEntries((data.value?.events ?? []).map((e) => [e.key, w ? w.events.includes(e.key) : false]));
    hookModal.value = true;
}

async function saveHook(): Promise<void> {
    const events = Object.entries(hookEvents.value).filter(([, on]) => on).map(([k]) => k);
    const payload = { url: hookForm.data.url.trim(), description: hookForm.data.description || null, events };
    const saved = await hookForm.submit(() =>
        editing.value ? integrationsApi.updateWebhook(editing.value.id, { ...payload, active: hookForm.data.active }) : integrationsApi.createWebhook(payload),
    );
    if (!saved) return;
    hookModal.value = false;
    if (saved.secret) {
        reveal.value = {
            title: 'Segredo do webhook',
            value: saved.secret,
            hint: 'Use este segredo no programa que recebe os eventos para confirmar a assinatura (cabeçalho X-ObraConnect-Signature). Não volta a aparecer.',
        };
    } else {
        toast.success('Webhook guardado.');
    }
    await load();
}

async function removeHook(w: WebhookInfo): Promise<void> {
    if (!window.confirm(`Apagar o webhook para ${w.url}? O histórico de envios também é apagado.`)) return;
    try {
        await integrationsApi.deleteWebhook(w.id);
        toast.success('Webhook apagado.');
        await load();
    } catch (e) {
        fail(e);
    }
}

async function rotate(w: WebhookInfo): Promise<void> {
    if (!window.confirm('Trocar o segredo? O programa que recebe os eventos tem de passar a usar o novo para as assinaturas baterem certo.')) return;
    try {
        const r = await integrationsApi.rotateSecret(w.id);
        if (r.secret) reveal.value = { title: 'Novo segredo do webhook', value: r.secret, hint: 'Não volta a aparecer. O segredo antigo deixou de valer.' };
    } catch (e) {
        fail(e);
    }
}

const testing = ref<number | null>(null);
async function test(w: WebhookInfo): Promise<void> {
    testing.value = w.id;
    try {
        const d = await integrationsApi.test(w.id);
        if (d.status === 'delivered') toast.success(`Teste entregue (HTTP ${d.response_status}).`);
        else toast.error(`O teste falhou: ${d.error ?? 'sem resposta'}.`);
        await load();
    } catch (e) {
        fail(e);
    } finally {
        testing.value = null;
    }
}

// --- envios ---
const log = ref<{ hook: WebhookInfo; items: WebhookDeliveryInfo[] | null } | null>(null);
const open = ref<number | null>(null);

async function showLog(w: WebhookInfo): Promise<void> {
    log.value = { hook: w, items: null };
    open.value = null;
    try {
        log.value.items = await integrationsApi.deliveries(w.id);
    } catch (e) {
        log.value = null;
        fail(e);
    }
}

async function retry(d: WebhookDeliveryInfo): Promise<void> {
    if (!log.value) return;
    try {
        await integrationsApi.retry(log.value.hook.id, d.id);
        toast.success('Envio posto na fila (segue no próximo minuto).');
        await showLog(log.value.hook);
    } catch (e) {
        fail(e);
    }
}

const deliveryState = (d: WebhookDeliveryInfo): { label: string; color: 'green' | 'red' | 'amber' } =>
    d.status === 'delivered' ? { label: 'Entregue', color: 'green' } : d.status === 'failed' ? { label: 'Falhou', color: 'red' } : { label: 'A tentar', color: 'amber' };

const curl = computed(() => `curl -H "Authorization: Bearer oc_…" ${data.value?.base_url ?? ''}/projects`);
</script>

<template>
    <div>
        <PageHeader
            title="Integrações"
            description="Ligue o ObraConnect a outros programas da empresa (faturação, contabilidade, BI): chaves para a API e webhooks que avisam quando algo acontece."
        />

        <div v-if="!data" class="flex justify-center py-16"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
        <template v-else>
            <AppAlert v-if="!data.enabled" type="info" class="mb-4">
                A API e os webhooks fazem parte dos planos Business e Enterprise.
                <RouterLink to="/app/subscricao" class="font-medium underline">Ver a subscrição</RouterLink>
            </AppAlert>

            <div class="grid gap-4 xl:grid-cols-2">
                <AppCard title="Chaves da API" class="min-w-0">
                    <template v-if="canWrite" #actions>
                        <AppButton size="sm" @click="openKey">Nova chave</AppButton>
                    </template>
                    <EmptyState v-if="data.api_keys.length === 0" title="Nenhuma chave" description="Crie uma chave para cada programa que se vai ligar ao ObraConnect." />
                    <ul v-else class="divide-y divide-slate-100">
                        <li v-for="k in data.api_keys" :key="k.id" class="flex flex-wrap items-start justify-between gap-3 py-3">
                            <div class="min-w-0">
                                <p class="font-medium">
                                    {{ k.name }} <code class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">{{ k.prefix }}…</code>
                                </p>
                                <p class="mt-1 flex flex-wrap gap-1">
                                    <AppBadge v-for="s in k.scopes" :key="s" color="blue" :title="scopeLabel(s)">{{ s }}</AppBadge>
                                </p>
                                <p class="mt-1 text-sm text-slate-500">
                                    Criada {{ formatDate(k.created_at) }}<template v-if="k.created_by"> por {{ k.created_by }}</template>
                                    · {{ k.last_used_at ? `último uso ${formatDateTime(k.last_used_at)}` : 'nunca usada' }}
                                    <template v-if="k.expires_at"> · válida até {{ formatDate(k.expires_at) }}</template>
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <AppBadge :color="keyState(k).color">{{ keyState(k).label }}</AppBadge>
                                <AppButton v-if="!k.revoked_at && !session.isReadOnly" size="sm" variant="danger" @click="revokeKey(k)">Revogar</AppButton>
                            </div>
                        </li>
                    </ul>
                </AppCard>

                <AppCard title="Webhooks" class="min-w-0">
                    <template v-if="canWrite" #actions>
                        <AppButton size="sm" @click="openHook(null)">Novo webhook</AppButton>
                    </template>
                    <EmptyState v-if="data.webhooks.length === 0" title="Nenhum webhook" description="Um webhook é um endereço do outro programa que recebe um aviso (POST com JSON) quando acontece um evento." />
                    <ul v-else class="divide-y divide-slate-100">
                        <li v-for="w in data.webhooks" :key="w.id" class="py-3">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="break-all font-medium">{{ w.url }}</p>
                                    <p v-if="w.description" class="text-sm text-slate-600">{{ w.description }}</p>
                                    <p class="mt-1 text-sm text-slate-500">{{ w.events.map(eventLabel).join(' · ') }}</p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        Últimas 24 h: {{ w.last_24h.delivered }} entregues · {{ w.last_24h.failed }} falhados
                                        <template v-if="w.last_24h.pending"> · {{ w.last_24h.pending }} a tentar</template>
                                    </p>
                                    <p v-if="w.disabled_reason" class="mt-1 text-sm text-red-700">{{ w.disabled_reason }}</p>
                                </div>
                                <AppBadge :color="w.active ? 'green' : 'gray'">{{ w.active ? 'Ligado' : 'Desligado' }}</AppBadge>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <AppButton size="sm" variant="secondary" @click="showLog(w)">Envios</AppButton>
                                <template v-if="canWrite">
                                    <AppButton size="sm" variant="secondary" :loading="testing === w.id" :disabled="!w.active" @click="test(w)">Enviar teste</AppButton>
                                    <AppButton size="sm" variant="secondary" @click="openHook(w)">Editar</AppButton>
                                    <AppButton size="sm" variant="ghost" @click="rotate(w)">Trocar segredo</AppButton>
                                </template>
                                <AppButton v-if="!session.isReadOnly" size="sm" variant="ghost" @click="removeHook(w)">Apagar</AppButton>
                            </div>
                        </li>
                    </ul>
                </AppCard>
            </div>

            <AppCard title="Como ligar" class="mt-4">
                <div class="space-y-3 text-sm text-slate-700">
                    <p>
                        Endereço da API: <code class="break-all rounded bg-slate-100 px-1.5 py-0.5">{{ data.base_url }}</code>. Cada pedido leva a chave no cabeçalho
                        <code class="rounded bg-slate-100 px-1.5 py-0.5">Authorization: Bearer oc_…</code>; a chave só vê os dados desta empresa e só o que os seus âmbitos deixam.
                    </p>
                    <pre class="overflow-x-auto rounded-lg bg-slate-900 p-3 text-xs text-slate-100">{{ curl }}</pre>
                    <p>
                        Os webhooks chegam por POST com JSON e os cabeçalhos <code class="rounded bg-slate-100 px-1">X-ObraConnect-Event</code>,
                        <code class="rounded bg-slate-100 px-1">X-ObraConnect-Delivery</code> (id do evento, igual nas repetições),
                        <code class="rounded bg-slate-100 px-1">X-ObraConnect-Timestamp</code> e <code class="rounded bg-slate-100 px-1">X-ObraConnect-Signature</code>
                        = <code class="rounded bg-slate-100 px-1">sha256=</code>HMAC-SHA256 de «timestamp.corpo» com o segredo. Responda com 2xx em menos de 10 segundos; sem isso,
                        repete-se ao fim de 1 min, 5 min, 30 min, 2 h e 6 h. Dez eventos falhados seguidos desligam o webhook.
                    </p>
                    <p>
                        Âmbitos: <span v-for="(s, i) in data.scopes" :key="s.key"><code class="rounded bg-slate-100 px-1">{{ s.key }}</code> {{ s.label.toLowerCase() }}<template v-if="i < data.scopes.length - 1">; </template></span>.
                    </p>
                </div>
            </AppCard>
        </template>

        <!-- Nova chave -->
        <AppModal :open="keyModal" title="Nova chave da API" @close="keyModal = false">
            <form id="key-form" class="space-y-4" novalidate @submit.prevent="createKey">
                <AppAlert v-if="keyForm.message.value" type="error">{{ keyForm.message.value }}</AppAlert>
                <AppInput v-model="keyForm.data.name" label="Nome" required :maxlength="100" hint="Para saber que programa a usa. Ex.: Faturação PHC" :error="keyForm.errors.value.name" />
                <fieldset>
                    <legend class="mb-2 text-sm font-medium text-slate-700">O que a chave pode fazer</legend>
                    <div class="space-y-2">
                        <AppCheckbox v-for="s in data?.scopes ?? []" :key="s.key" v-model="keyScopes[s.key]" :label="s.label" :description="s.key" />
                    </div>
                    <p v-if="keyForm.errors.value.scopes" class="mt-1 text-sm text-red-700">{{ keyForm.errors.value.scopes }}</p>
                </fieldset>
                <AppInput v-model="keyForm.data.expires_at" type="date" label="Válida até (opcional)" :error="keyForm.errors.value.expires_at" />
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="keyModal = false">Cancelar</AppButton>
                <AppButton type="submit" form="key-form" :loading="keyForm.processing.value">Criar chave</AppButton>
            </template>
        </AppModal>

        <!-- Webhook -->
        <AppModal :open="hookModal" :title="editing ? 'Editar webhook' : 'Novo webhook'" @close="hookModal = false">
            <form id="hook-form" class="space-y-4" novalidate @submit.prevent="saveHook">
                <AppAlert v-if="hookForm.message.value" type="error">{{ hookForm.message.value }}</AppAlert>
                <AppInput v-model="hookForm.data.url" type="url" inputmode="url" label="Endereço (https)" required :maxlength="500" :error="hookForm.errors.value.url" />
                <AppInput v-model="hookForm.data.description" label="Descrição (opcional)" :maxlength="150" :error="hookForm.errors.value.description" />
                <fieldset>
                    <legend class="mb-2 text-sm font-medium text-slate-700">Eventos</legend>
                    <div class="space-y-2">
                        <AppCheckbox v-for="e in data?.events ?? []" :key="e.key" v-model="hookEvents[e.key]" :label="e.label" :description="e.key" />
                    </div>
                    <p v-if="hookForm.errors.value.events" class="mt-1 text-sm text-red-700">{{ hookForm.errors.value.events }}</p>
                </fieldset>
                <AppCheckbox v-if="editing" v-model="hookForm.data.active" label="Ligado" description="Ao voltar a ligar, a contagem de falhas recomeça." />
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="hookModal = false">Cancelar</AppButton>
                <AppButton type="submit" form="hook-form" :loading="hookForm.processing.value">Guardar</AppButton>
            </template>
        </AppModal>

        <!-- Chave ou segredo, uma vez -->
        <AppModal :open="!!reveal" :title="reveal?.title ?? ''" @close="reveal = null">
            <template v-if="reveal">
                <AppAlert type="warning">{{ reveal.hint }}</AppAlert>
                <div class="mt-3 flex items-center gap-2">
                    <code class="min-w-0 flex-1 break-all rounded-lg bg-slate-100 p-3 text-sm">{{ reveal.value }}</code>
                    <AppButton size="sm" variant="secondary" @click="copy(reveal.value)">Copiar</AppButton>
                </div>
            </template>
            <template #footer>
                <AppButton @click="reveal = null">Já guardei</AppButton>
            </template>
        </AppModal>

        <!-- Envios -->
        <AppModal :open="!!log" :title="log ? `Envios para ${log.hook.url}` : ''" size="lg" @close="log = null">
            <div v-if="log && !log.items" class="flex justify-center py-8"><AppSpinner class="h-5 w-5 text-brand-700" /></div>
            <EmptyState v-else-if="log && log.items?.length === 0" title="Ainda não houve envios" description="Use «Enviar teste» para experimentar." />
            <ul v-else-if="log" class="divide-y divide-slate-100">
                <li v-for="d in log.items" :key="d.id" class="py-2">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <button type="button" class="min-w-0 text-left" @click="open = open === d.id ? null : d.id">
                            <span class="font-medium">{{ d.event === 'ping' ? 'Teste' : eventLabel(d.event) }}</span>
                            <span class="ml-2 text-sm text-slate-500">{{ formatDateTime(d.created_at) }} · {{ d.attempts }} tentativa(s)</span>
                            <span v-if="d.response_status" class="ml-2 text-sm text-slate-500">· HTTP {{ d.response_status }}</span>
                        </button>
                        <div class="flex items-center gap-2">
                            <AppBadge :color="deliveryState(d).color">{{ deliveryState(d).label }}</AppBadge>
                            <AppButton v-if="canWrite && d.status !== 'pending' && log.hook.active" size="sm" variant="ghost" @click="retry(d)">Reenviar</AppButton>
                        </div>
                    </div>
                    <p v-if="d.error" class="mt-1 text-sm text-red-700">{{ d.error }}</p>
                    <p v-if="d.status === 'pending' && d.next_attempt_at" class="mt-1 text-xs text-slate-500">Próxima tentativa: {{ formatDateTime(d.next_attempt_at) }}</p>
                    <pre v-if="open === d.id" class="mt-2 overflow-x-auto rounded-lg bg-slate-900 p-3 text-xs text-slate-100">{{ JSON.stringify(d.payload, null, 2) }}</pre>
                </li>
            </ul>
        </AppModal>
    </div>
</template>
