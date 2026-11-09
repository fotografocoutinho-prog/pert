<script setup lang="ts">
import AppAlert from '@/components/ui/AppAlert.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import SupplierDocsList from '@/components/purchases/SupplierDocsList.vue';
</script>

<template>
    <div>
        <PageHeader
            title="Faturas de fornecedores"
            description="Faturas, notas de crédito, talões e guias que chegam dos fornecedores: lidos, revistos e lançados nas compras e nos custos das obras."
        />
        <AppAlert class="mb-4">
            Junte os PDF ou as fotografias. Quando a leitura automática está ligada, o fornecedor, o número, a data e os valores vêm preenchidos e é proposta a compra
            da obra a que a fatura corresponde. Confira sempre antes de <strong>lançar</strong>: com compra, a compra fica faturada; sem compra, entra como custo da obra.
        </AppAlert>
        <SupplierDocsList />
    </div>
</template>
