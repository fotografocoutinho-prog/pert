<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { platformApi } from '@/api';
import type { PaymentInfo, PlatformPaymentDetails } from '@/api/types';
import { useForm } from '@/composables/useForm';
import { formatDateTime } from '@/utils/format';
import PaymentInfoBlock from '@/components/billing/PaymentInfoBlock.vue';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import AppTextarea from '@/components/ui/AppTextarea.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const details = ref<PlatformPaymentDetails | null>(null);
const form = useForm({
    account_holder: '' as string | null,
    iban: '' as string | null,
    bank: '' as string | null,
    bic: '' as string | null,
    proof_email: '' as string | null,
    instructions: '' as string | null,
});

function fill(d: PlatformPaymentDetails): void {
    details.value = d;
    form.reset({
        account_holder: d.account_holder ?? '',
        iban: d.iban ?? '',
        bank: d.bank ?? '',
        bic: d.bic ?? '',
        proof_email: d.proof_email ?? '',
        instructions: d.instructions,
    });
}

onMounted(async () => fill(await platformApi.paymentDetails()));

const blank = (v: string | null) => (v ?? '').trim() === '';

/** Pré-visualização enquanto se escreve (o IBAN aparece como foi escrito; ao guardar fica em grupos de 4). */
const preview = computed<PaymentInfo>(() => {
    const d = form.data;
    return {
        transfer: !blank(d.iban),
        account_holder: blank(d.account_holder) ? null : (d.account_holder ?? '').trim(),
        iban: blank(d.iban) ? null : (d.iban ?? '').trim().toUpperCase(),
        bank: blank(d.bank) ? null : (d.bank ?? '').trim(),
        bic: blank(d.bic) ? null : (d.bic ?? '').trim().toUpperCase(),
        proof_email: blank(d.proof_email) ? null : (d.proof_email ?? '').trim(),
        instructions: blank(d.instructions) ? details.value?.default_instructions ?? '' : (d.instructions ?? '').trim(),
    };
});

async function save(): Promise<void> {
    const d = form.data;
    const payload = Object.fromEntries(Object.entries(d).map(([k, v]) => [k, blank(v as string | null) ? null : (v as string).trim()]));
    const saved = await form.submit(() => platformApi.updatePaymentDetails(payload), { success: 'Dados de pagamento guardados.' });
    if (saved) {
        fill(saved);
    }
}

function useDefaultInstructions(): void {
    form.data.instructions = details.value?.default_instructions ?? '';
}
</script>

<template>
    <div>
        <PageHeader
            title="Dados de pagamento"
            description="O que as empresas veem para pagar a subscrição: na página Subscrição, quando têm um pedido por confirmar, e no email do pedido."
        />

        <div v-if="!details" class="flex justify-center py-16"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
        <div v-else class="grid gap-4 lg:grid-cols-5">
            <AppCard title="Conta para transferências" class="min-w-0 lg:col-span-3">
                <AppAlert v-if="!details.can_edit" type="info" class="mb-4">Só o super administrador da plataforma pode alterar estes dados.</AppAlert>
                <form class="space-y-4" novalidate @submit.prevent="save">
                    <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>
                    <AppInput v-model="form.data.account_holder" label="Titular da conta" :maxlength="120" :disabled="!details.can_edit"
                        autocomplete="off" :error="form.errors.value.account_holder" />
                    <AppInput v-model="form.data.iban" label="IBAN (ou NIB)" :maxlength="42" :disabled="!details.can_edit" autocomplete="off"
                        placeholder="PT50 0000 0000 0000 0000 0000 0" hint="Pode escrever o NIB (21 algarismos): passa a IBAN (PT50 + NIB). Os algarismos de controlo são verificados."
                        :error="form.errors.value.iban" />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <AppInput v-model="form.data.bank" label="Banco (opcional)" :maxlength="120" :disabled="!details.can_edit" :error="form.errors.value.bank" />
                        <AppInput v-model="form.data.bic" label="BIC/SWIFT (opcional)" :maxlength="11" :disabled="!details.can_edit" hint="Para transferências do estrangeiro."
                            :error="form.errors.value.bic" />
                    </div>
                    <AppInput v-model="form.data.proof_email" type="email" label="Email para os comprovativos (opcional)" :maxlength="190" :disabled="!details.can_edit"
                        :error="form.errors.value.proof_email" />
                    <div>
                        <AppTextarea v-model="form.data.instructions" label="Indicações de pagamento" :rows="4" :maxlength="1000" :disabled="!details.can_edit"
                            hint="Aparece por baixo dos dados da conta (ex.: prazo, quando começa o período). Vazio = texto por omissão."
                            :error="form.errors.value.instructions" />
                        <AppButton v-if="details.can_edit && form.data.instructions !== details.default_instructions" type="button" size="sm" variant="ghost" class="mt-1"
                            @click="useDefaultInstructions">Repor o texto por omissão</AppButton>
                    </div>
                    <p class="text-xs text-slate-500">
                        Cada alteração fica nos logs de segurança. Se mudar o IBAN ou o titular, toda a equipa de faturação recebe um aviso por email.
                        <template v-if="details.updated_at"><br />Última alteração: {{ formatDateTime(details.updated_at) }}<template v-if="details.updated_by"> por {{ details.updated_by }}</template>.</template>
                    </p>
                    <div v-if="details.can_edit" class="flex justify-end">
                        <AppButton type="submit" :loading="form.processing.value">Guardar</AppButton>
                    </div>
                </form>
            </AppCard>

            <AppCard title="O que as empresas veem" class="min-w-0 lg:col-span-2">
                <p class="mb-3 text-sm">Pedido n.º 123 · Ativação do plano <strong>Professional</strong> (mensal): <strong>129,00 €</strong> sem IVA.</p>
                <PaymentInfoBlock :info="preview" reference="ObraConnect 123" />
                <AppAlert v-if="!preview.transfer" type="warning" class="mt-4">Sem IBAN, as empresas só veem as indicações: o pagamento fica combinado na fatura.</AppAlert>
            </AppCard>
        </div>
    </div>
</template>
