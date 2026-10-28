<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { authApi } from '@/api';
import { ApiError } from '@/api/http';
import { useSessionStore } from '@/stores/session';
import { homeFor } from '@/router';
import AuthLayout from '@/components/layout/AuthLayout.vue';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';

const session = useSessionStore();
const router = useRouter();
const sending = ref(false);
const feedback = ref<{ type: 'success' | 'error'; text: string } | null>(null);

async function resend(): Promise<void> {
    sending.value = true;
    try {
        await authApi.resendVerification();
        feedback.value = { type: 'success', text: 'Enviámos um novo email de confirmação.' };
    } catch (error) {
        feedback.value = { type: 'error', text: error instanceof ApiError ? error.message : 'Não foi possível enviar.' };
    } finally {
        sending.value = false;
    }
}

async function check(): Promise<void> {
    await session.refresh();
    if (session.user?.email_verified) await router.push(homeFor(session));
    else feedback.value = { type: 'error', text: 'O email ainda não foi confirmado.' };
}

async function logout(): Promise<void> {
    await session.logout();
    await router.push('/entrar');
}
</script>

<template>
    <AuthLayout title="Confirme o seu email" :subtitle="`Enviámos uma ligação para ${session.user?.email ?? 'o seu email'}.`">
        <div class="space-y-4">
            <AppAlert v-if="feedback" :type="feedback.type">{{ feedback.text }}</AppAlert>
            <p class="text-sm text-slate-600">Abra o email e carregue em "Confirmar email". Verifique também a pasta de spam.</p>
            <AppButton block size="lg" @click="check">Já confirmei</AppButton>
            <AppButton block variant="secondary" :loading="sending" @click="resend">Reenviar email</AppButton>
        </div>
        <template #footer><button class="font-semibold text-brand-700 hover:underline" @click="logout">Sair</button></template>
    </AuthLayout>
</template>
