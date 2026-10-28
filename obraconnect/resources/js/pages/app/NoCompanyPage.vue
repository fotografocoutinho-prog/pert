<script setup lang="ts">
import { useSessionStore } from '@/stores/session';
import AppCard from '@/components/ui/AppCard.vue';

const session = useSessionStore();
</script>

<template>
    <AppCard title="Sem empresa ativa">
        <p class="text-slate-700">A sua conta não está associada a nenhuma empresa ativa.</p>
        <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-slate-600">
            <li>Se foi convidado, abra a ligação do email de convite.</li>
            <li>Se a empresa foi suspensa ou removeu o seu acesso, contacte o administrador da empresa.</li>
        </ul>
        <RouterLink v-if="session.isPlatformStaff" to="/plataforma" class="mt-4 inline-block font-semibold text-brand-700 hover:underline">Ir para o backoffice da plataforma</RouterLink>
    </AppCard>
</template>
