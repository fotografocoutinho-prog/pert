<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import type { Project } from '@/api/types';
import { ApiError } from '@/api/http';
import { certificatesApi, formatPct, formatQty, measurementsApi, parseNumber, type CertificateSummary, type MeasurementProgress, type MeasurementRow } from '@/api/economics';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { formatDate, formatMoney } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';

const props = defineProps<{ project: Project }>();
const session = useSessionStore();
const toast = useToastStore();
const router = useRouter();
const open = computed(() => props.project.status !== 'archived' && !session.isReadOnly);
const canMeasure = computed(() => open.value && props.project.permissions.includes('measurements.manage'));

const loading = ref(true);
const budget = ref<{ id: number; code: string; version: number; name: string } | null>(null);
const progress = ref<MeasurementProgress[]>([]);
const measurements = ref<MeasurementRow[]>([]);
const certificates = ref<CertificateSummary[]>([]);

async function load(): Promise<void> {
    const [m, c] = await Promise.all([measurementsApi.list(props.project.id), certificatesApi.list(props.project.id)]);
    budget.value = m.budget;
    progress.value = m.progress;
    measurements.value = m.measurements;
    certificates.value = c.filter((x) => x.kind === 'owner');
    loading.value = false;
}
onMounted(load);

const today = () => new Date().toISOString().slice(0, 10);
const itemOptions = computed(() => progress.value.map((p) => ({ value: p.budget_item_id, label: `${p.code} · ${p.description} (${p.unit})` })));
const form = useForm({ budget_item_id: '' as number | '', measured_on: today(), description: '', count: '', length: '', width: '', height: '', quantity: '' });
const chosen = computed(() => progress.value.find((p) => p.budget_item_id === form.data.budget_item_id) ?? null);
const computedQty = computed(() => {
    const dims = [form.data.count, form.data.length, form.data.width, form.data.height].map(parseNumber).filter((v): v is number => v !== null);
    return dims.length ? Math.round(dims.reduce((a, b) => a * b, 1) * 1000) / 1000 : null;
});
async function save(): Promise<void> {
    const saved = await form.submit(
        () =>
            measurementsApi.create(props.project.id, {
                budget_item_id: form.data.budget_item_id === '' ? null : Number(form.data.budget_item_id),
                measured_on: form.data.measured_on,
                description: form.data.description || null,
                count: parseNumber(form.data.count),
                length: parseNumber(form.data.length),
                width: parseNumber(form.data.width),
                height: parseNumber(form.data.height),
                quantity: parseNumber(form.data.quantity),
            }),
        { success: 'Medição registada.' },
    );
    if (saved) {
        form.reset({ budget_item_id: form.data.budget_item_id, measured_on: form.data.measured_on });
        await load();
    }
}
async function remove(m: MeasurementRow): Promise<void> {
    if (!window.confirm('Apagar esta medição?')) return;
    try {
        await measurementsApi.remove(props.project.id, m.id);
        await load();
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível apagar.');
    }
}

// ---- Autos ----
const certOpen = ref(false);
const cert = useForm({ period_start: '', period_end: today(), notes: '' });
function newCertificate(): void {
    const last = certificates.value[0];
    const start = last ? new Date(new Date(last.period_end).getTime() + 86_400_000).toISOString().slice(0, 10) : props.project.starts_on ?? today();
    cert.reset({ period_start: start, period_end: today() });
    certOpen.value = true;
}
async function createCertificate(): Promise<void> {
    const saved = await cert.submit(() => certificatesApi.create(props.project.id, { ...cert.data, notes: cert.data.notes || null }));
    if (saved) router.push(`/app/obras/${props.project.id}/autos/${saved.id}`);
}
const certColor = (s: string) => (s === 'approved' ? 'green' : s === 'submitted' ? 'blue' : 'amber');
const pctColor = (pct: number | null) => (pct === null ? 'bg-slate-300' : pct > 100 ? 'bg-red-600' : pct >= 90 ? 'bg-amber-500' : 'bg-brand-600');
</script>

<template>
    <div v-if="loading" class="flex justify-center py-10"><AppSpinner class="h-5 w-5 text-brand-700" /></div>
    <AppAlert v-else-if="!budget">Ainda não há orçamento aprovado. As medições e os autos fazem-se sobre os artigos do orçamento aprovado.</AppAlert>
    <div v-else class="space-y-6">
        <p class="text-sm text-slate-600">Sobre o orçamento <strong>{{ budget.code }} v{{ budget.version }}</strong>. Mede-se o que está feito; o auto junta as medições do período e passa a faturável quando aprovado.</p>

        <AppCard title="Autos de medição ao dono de obra">
            <template v-if="canMeasure">
                <div class="mb-3"><AppButton size="sm" @click="newCertificate">Novo auto</AppButton></div>
            </template>
            <EmptyState v-if="!certificates.length" title="Sem autos" />
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="c in certificates" :key="c.id">
                    <RouterLink :to="`/app/obras/${project.id}/autos/${c.id}`" class="flex flex-wrap items-center justify-between gap-2 py-2.5 hover:bg-slate-50">
                        <span>
                            <strong>Auto n.º {{ c.number }}</strong>
                            <span class="block text-xs text-slate-500">{{ formatDate(c.period_start) }} a {{ formatDate(c.period_end) }}<template v-if="c.approved_at"> · aprovado a {{ formatDate(c.approved_at) }}</template></span>
                        </span>
                        <span class="flex items-center gap-2">
                            <span class="font-semibold">{{ formatMoney(c.amount_cents) }}</span>
                            <AppBadge :color="certColor(c.status)">{{ c.status_label }}</AppBadge>
                        </span>
                    </RouterLink>
                </li>
            </ul>
        </AppCard>

        <div class="grid gap-6 lg:grid-cols-5">
            <AppCard v-if="canMeasure" class="min-w-0 lg:col-span-2" title="Registar medição" description="Escreva as dimensões (a quantidade é o produto) ou a quantidade diretamente.">
                <form class="space-y-3" novalidate @submit.prevent="save">
                    <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>
                    <AppSelect v-model="form.data.budget_item_id" label="Artigo" placeholder="Escolha o artigo" :options="itemOptions" :error="form.errors.value.budget_item_id" />
                    <p v-if="chosen" class="text-xs text-slate-500">Orçado {{ formatQty(chosen.budget_quantity) }} {{ chosen.unit }} · medido {{ formatQty(chosen.measured_quantity) }} {{ chosen.unit }}</p>
                    <div class="grid grid-cols-2 gap-3">
                        <AppInput v-model="form.data.measured_on" type="date" label="Data" :error="form.errors.value.measured_on" />
                        <AppInput v-model="form.data.description" label="Onde / o quê" placeholder="Laje piso 1" />
                    </div>
                    <div class="grid grid-cols-4 gap-2">
                        <AppInput v-model="form.data.count" label="N.º" inputmode="decimal" />
                        <AppInput v-model="form.data.length" label="Comp." inputmode="decimal" />
                        <AppInput v-model="form.data.width" label="Larg." inputmode="decimal" />
                        <AppInput v-model="form.data.height" label="Alt." inputmode="decimal" />
                    </div>
                    <AppInput
                        v-model="form.data.quantity"
                        label="Quantidade"
                        inputmode="decimal"
                        :placeholder="computedQty !== null ? formatQty(computedQty) : ''"
                        :hint="computedQty !== null && !form.data.quantity ? `Pelas dimensões: ${formatQty(computedQty)} ${chosen?.unit ?? ''}` : undefined"
                        :error="form.errors.value.quantity"
                    />
                    <AppButton type="submit" :loading="form.processing.value">Registar</AppButton>
                </form>
            </AppCard>

            <AppCard class="min-w-0" :class="canMeasure ? 'lg:col-span-3' : 'lg:col-span-5'" title="Avanço por artigo">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs text-slate-500">
                            <tr>
                                <th class="py-1.5 pr-2">Artigo</th>
                                <th class="py-1.5 pr-2 text-right">Orçado</th>
                                <th class="py-1.5 pr-2 text-right">Medido</th>
                                <th class="py-1.5 pr-2 text-right">Autado</th>
                                <th class="w-24 py-1.5">%</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="p in progress" :key="p.budget_item_id">
                                <td class="py-1.5 pr-2"><span class="font-mono text-xs">{{ p.code }}</span> {{ p.description }}</td>
                                <td class="py-1.5 pr-2 text-right whitespace-nowrap">{{ formatQty(p.budget_quantity) }} {{ p.unit }}</td>
                                <td class="py-1.5 pr-2 text-right whitespace-nowrap">{{ formatQty(p.measured_quantity) }}</td>
                                <td class="py-1.5 pr-2 text-right whitespace-nowrap">{{ formatQty(p.certified_quantity) }}</td>
                                <td class="py-1.5">
                                    <div class="h-1.5 rounded-full bg-slate-100"><div class="h-1.5 rounded-full" :class="pctColor(p.measured_pct)" :style="{ width: `${Math.min(100, p.measured_pct ?? 0)}%` }" /></div>
                                    <span class="text-xs" :class="(p.measured_pct ?? 0) > 100 ? 'font-semibold text-red-700' : 'text-slate-500'">{{ formatPct(p.measured_pct) }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>
        </div>

        <AppCard title="Medições registadas">
            <EmptyState v-if="!measurements.length" title="Sem medições" />
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-slate-500">
                        <tr>
                            <th class="py-1.5 pr-2">Data</th>
                            <th class="py-1.5 pr-2">Artigo</th>
                            <th class="py-1.5 pr-2">Descrição</th>
                            <th class="py-1.5 pr-2 text-right">Quantidade</th>
                            <th class="py-1.5 pr-2">Auto</th>
                            <th class="py-1.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="m in measurements" :key="m.id">
                            <td class="py-1.5 pr-2 whitespace-nowrap">{{ formatDate(m.measured_on) }}</td>
                            <td class="py-1.5 pr-2"><span class="font-mono text-xs">{{ m.item?.code }}</span> {{ m.item?.description }}</td>
                            <td class="py-1.5 pr-2">
                                {{ m.description }}
                                <span v-if="m.length || m.width || m.height || m.count" class="block text-xs text-slate-500">
                                    {{ [m.count, m.length, m.width, m.height].filter((v) => v !== null).map((v) => formatQty(v)).join(' × ') }}
                                </span>
                            </td>
                            <td class="py-1.5 pr-2 text-right whitespace-nowrap">{{ formatQty(m.quantity) }} {{ m.item?.unit }}</td>
                            <td class="py-1.5 pr-2">{{ m.certificate ? `n.º ${m.certificate.number}` : '—' }}</td>
                            <td class="py-1.5 text-right">
                                <button v-if="canMeasure && !m.certificate" type="button" class="text-xs text-red-700 hover:underline" @click="remove(m)">Apagar</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </AppCard>

        <AppModal :open="certOpen" title="Novo auto de medição" @close="certOpen = false">
            <form id="cert-form" class="space-y-4" novalidate @submit.prevent="createCertificate">
                <AppAlert v-if="cert.message.value" type="error">{{ cert.message.value }}</AppAlert>
                <p class="text-sm text-slate-600">O auto traz as medições ainda não autadas até ao fim do período. Pode acertar as quantidades antes de o submeter.</p>
                <div class="grid grid-cols-2 gap-3">
                    <AppInput v-model="cert.data.period_start" type="date" label="De" :error="cert.errors.value.period_start" />
                    <AppInput v-model="cert.data.period_end" type="date" label="Até" :error="cert.errors.value.period_end" />
                </div>
                <AppInput v-model="cert.data.notes" label="Notas" />
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="certOpen = false">Cancelar</AppButton>
                <AppButton type="submit" form="cert-form" :loading="cert.processing.value">Criar auto</AppButton>
            </template>
        </AppModal>
    </div>
</template>
