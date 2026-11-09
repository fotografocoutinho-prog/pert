<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { ApiError } from '@/api/http';
import { alertsApi, type AlertRules, type AlertSettings } from '@/api/economics';
import { useToastStore } from '@/stores/toast';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppCheckbox from '@/components/ui/AppCheckbox.vue';

/** Regras dos alertas automáticos das obras (Empresa). */
const props = defineProps<{ canEdit: boolean }>();
const toast = useToastStore();
const settings = ref<AlertSettings | null>(null);
const saving = ref(false);
const error = ref<string | null>(null);

type RuleKey = keyof AlertRules;
interface RuleRow {
    key: RuleKey;
    label: string;
    field?: 'pct' | 'days';
    before?: string;
    after?: string;
    min?: number;
    max?: number;
    step?: number;
}
const rows: RuleRow[] = [
    { key: 'cost_ceiling', label: 'Custo previsto perto do teto', field: 'pct', before: 'a partir de', after: '% do teto (acima do teto é sempre grave)', min: 50, max: 100 },
    { key: 'cost_worse', label: 'Custo previsto subiu numa semana', field: 'pct', before: 'em', after: '% ou mais', min: 0.5, max: 50, step: 0.5 },
    { key: 'finish_contract', label: 'Fim previsto depois do prazo contratual' },
    { key: 'finish_worse', label: 'Fim previsto atrasou numa semana', field: 'days', before: '', after: 'dias ou mais', min: 1, max: 60 },
    { key: 'measurements_stale', label: 'Medições atrasadas', field: 'days', before: 'última medição há mais de', after: 'dias (obras em curso)', min: 7, max: 120 },
    { key: 'hold_point', label: 'Ponto de paragem por libertar', field: 'days', before: 'há mais de', after: 'dias', min: 1, max: 60 },
    { key: 'supplier_docs', label: 'Faturas de fornecedores por rever', field: 'days', before: 'há mais de', after: 'dias', min: 1, max: 60 },
];

onMounted(async () => {
    settings.value = await alertsApi.settings();
});

function rule(key: RuleKey): Record<string, number | boolean> {
    return settings.value!.rules[key] as unknown as Record<string, number | boolean>;
}

async function save(): Promise<void> {
    if (!settings.value) return;
    saving.value = true;
    error.value = null;
    try {
        settings.value = await alertsApi.saveSettings(settings.value);
        toast.success('Regras dos alertas guardadas.');
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Não foi possível guardar.';
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppCard title="Alertas automáticos" description="Todos os dias às 7h o ObraConnect revê as obras e abre (ou fecha) alertas. Cada alerta novo vai uma vez por email.">
        <div v-if="!settings" class="text-sm text-slate-500">A carregar…</div>
        <div v-else class="space-y-4" data-test="alert-settings">
            <div class="grid gap-3 sm:grid-cols-2">
                <AppCheckbox v-model="settings.enabled" :disabled="!props.canEdit" label="Alertas ligados" description="Desligados, os alertas abertos fecham-se no dia seguinte." />
                <AppCheckbox
                    v-model="settings.email"
                    :disabled="!props.canEdit || !settings.enabled"
                    label="Enviar por email"
                    description="Ao diretor de obra (sem diretor, aos administradores); os de faturas a quem as revê."
                />
            </div>

            <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                <li v-for="r in rows" :key="r.key" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2.5 text-sm" :class="!settings.enabled ? 'opacity-60' : ''">
                    <label class="flex items-center gap-2 font-medium text-slate-800">
                        <input
                            v-model="settings.rules[r.key].on"
                            type="checkbox"
                            :disabled="!props.canEdit || !settings.enabled"
                            class="h-4 w-4 rounded border-slate-300 accent-brand-700"
                        />
                        {{ r.label }}
                    </label>
                    <span v-if="r.field" class="flex items-center gap-1.5 text-slate-600">
                        <span v-if="r.before">{{ r.before }}</span>
                        <input
                            v-model.number="rule(r.key)[r.field]"
                            type="number"
                            :min="r.min"
                            :max="r.max"
                            :step="r.step ?? 1"
                            :aria-label="`${r.label}: ${r.after}`"
                            :disabled="!props.canEdit || !settings.enabled || !settings.rules[r.key].on"
                            class="w-20 rounded-md border border-slate-300 px-2 py-1 text-right text-sm disabled:bg-slate-50"
                        />
                        <span>{{ r.after }}</span>
                    </span>
                </li>
            </ul>

            <p v-if="error" class="text-sm text-red-700">{{ error }}</p>
            <AppButton v-if="props.canEdit" size="sm" :loading="saving" @click="save">Guardar regras</AppButton>
        </div>
    </AppCard>
</template>
