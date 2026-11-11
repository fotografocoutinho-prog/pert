<script setup lang="ts">
import type { PaymentInfo } from '@/api/types';
import { useToastStore } from '@/stores/toast';

/** Dados para pagar por transferência, como as empresas os veem (Subscrição e pré-visualização no backoffice). */
defineProps<{ info: PaymentInfo; reference?: string | null }>();

const toast = useToastStore();

async function copy(value: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value.replace(/\s+/g, ''));
        toast.success('IBAN copiado.');
    } catch {
        toast.error('Não foi possível copiar: selecione e copie à mão.');
    }
}
</script>

<template>
    <div class="space-y-3 text-sm">
        <dl v-if="info.transfer" class="space-y-1.5 rounded-lg bg-slate-50 p-3">
            <p class="mb-1 font-medium text-slate-800">Transferência bancária</p>
            <div v-if="info.account_holder" class="flex flex-wrap justify-between gap-x-3"><dt class="text-slate-500">Titular</dt><dd class="text-right">{{ info.account_holder }}</dd></div>
            <div class="flex flex-col gap-0.5 sm:flex-row sm:items-center sm:justify-between sm:gap-x-3">
                <dt class="text-slate-500">IBAN</dt>
                <dd class="flex items-center justify-between gap-2 sm:justify-end">
                    <!-- Em ecrãs estreitos parte só entre os grupos de 4. -->
                    <span class="font-mono tracking-tight">{{ info.iban }}</span>
                    <button type="button" class="shrink-0 rounded px-1.5 py-0.5 text-xs font-medium text-brand-700 hover:bg-brand-50" @click="copy(info.iban ?? '')">Copiar</button>
                </dd>
            </div>
            <div v-if="info.bank" class="flex flex-wrap justify-between gap-x-3"><dt class="text-slate-500">Banco</dt><dd class="text-right">{{ info.bank }}</dd></div>
            <div v-if="info.bic" class="flex flex-wrap justify-between gap-x-3"><dt class="text-slate-500">BIC/SWIFT</dt><dd class="font-mono text-right">{{ info.bic }}</dd></div>
            <div v-if="reference" class="flex flex-wrap justify-between gap-x-3"><dt class="text-slate-500">Descritivo da transferência</dt><dd class="font-medium text-right">{{ reference }}</dd></div>
            <div v-if="info.proof_email" class="flex flex-wrap justify-between gap-x-3">
                <dt class="text-slate-500">Comprovativo para</dt>
                <dd class="text-right"><a :href="`mailto:${info.proof_email}`" class="text-brand-700 hover:underline">{{ info.proof_email }}</a></dd>
            </div>
        </dl>
        <p class="whitespace-pre-line text-slate-600">{{ info.instructions }}</p>
    </div>
</template>
