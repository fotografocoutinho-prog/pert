<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router';
import { authApi } from '@/api';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { homeFor } from '@/router';
import { safeRedirect } from '@/utils/redirect';
import AuthLayout from '@/components/layout/AuthLayout.vue';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCheckbox from '@/components/ui/AppCheckbox.vue';
import AppInput from '@/components/ui/AppInput.vue';

const route = useRoute();
const router = useRouter();
const session = useSessionStore();
const form = useForm({ email: '', password: '', remember: false });

async function submit(): Promise<void> {
    const result = await form.submit((d) => authApi.login(d.email, d.password, d.remember));
    if (!result) return;

    const redirect = safeRedirect(route.query.redirect);

    if (result.two_factor) {
        await router.push({ path: '/2fa', query: redirect ? { redirect } : {} });
        return;
    }

    await session.refresh();
    if (redirect?.startsWith('/auth/')) {
        window.location.href = redirect; // rota do servidor (ex.: confirmação de email)
        return;
    }
    await router.push(redirect ?? homeFor(session));
}
</script>

<template>
    <AuthLayout title="Entrar" subtitle="Aceda à sua conta ObraConnect.">
        <form class="space-y-4" novalidate @submit.prevent="submit">
            <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>
            <AppInput v-model="form.data.email" label="Email" type="email" autocomplete="username" inputmode="email" required :error="form.errors.value.email" />
            <AppInput v-model="form.data.password" label="Password" type="password" autocomplete="current-password" required :error="form.errors.value.password" />
            <div class="flex items-center justify-between">
                <AppCheckbox v-model="form.data.remember" label="Manter sessão iniciada" />
                <RouterLink to="/recuperar-password" class="text-sm font-medium text-brand-700 hover:underline">Esqueceu-se?</RouterLink>
            </div>
            <AppButton type="submit" block size="lg" :loading="form.processing.value">Entrar</AppButton>
        </form>
        <template #footer>
            Ainda não tem conta? <RouterLink to="/registar" class="font-semibold text-brand-700 hover:underline">Registar empresa</RouterLink>
        </template>
    </AuthLayout>
</template>
