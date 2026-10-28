<script setup lang="ts">
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { authApi } from '@/api';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { homeFor } from '@/router';
import { safeRedirect } from '@/utils/redirect';
import { appUrl } from '@/utils/base';
import AuthLayout from '@/components/layout/AuthLayout.vue';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppInput from '@/components/ui/AppInput.vue';

const route = useRoute();
const router = useRouter();
const session = useSessionStore();
const useRecovery = ref(false);
const form = useForm({ code: '', recovery_code: '' });

async function submit(): Promise<void> {
    const ok = await form.submit((d) =>
        authApi.twoFactorChallenge(useRecovery.value ? { recovery_code: d.recovery_code.trim() } : { code: d.code.replace(/\s/g, '') }).then(() => true),
    );
    if (!ok) return;

    await session.refresh();
    const redirect = safeRedirect(route.query.redirect);
    if (redirect?.startsWith('/auth/')) {
        window.location.href = appUrl(redirect);
        return;
    }
    await router.push(redirect ?? homeFor(session));
}
</script>

<template>
    <AuthLayout title="Verificação em dois passos" :subtitle="useRecovery ? 'Introduza um dos códigos de recuperação.' : 'Introduza o código de 6 dígitos da aplicação autenticadora.'">
        <form class="space-y-4" novalidate @submit.prevent="submit">
            <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>
            <AppInput
                v-if="!useRecovery"
                v-model="form.data.code"
                label="Código"
                inputmode="numeric"
                autocomplete="one-time-code"
                :maxlength="8"
                required
                :error="form.errors.value.code"
            />
            <AppInput v-else v-model="form.data.recovery_code" label="Código de recuperação" autocomplete="off" required :error="form.errors.value.recovery_code" />
            <AppButton type="submit" block size="lg" :loading="form.processing.value">Confirmar</AppButton>
            <button type="button" class="w-full text-center text-sm font-medium text-brand-700 hover:underline" @click="useRecovery = !useRecovery">
                {{ useRecovery ? 'Usar código da aplicação' : 'Usar código de recuperação' }}
            </button>
        </form>
        <template #footer>
            A sessão de verificação expira se demorar muito.
            <RouterLink to="/entrar" class="font-semibold text-brand-700 hover:underline">Recomeçar</RouterLink>
        </template>
    </AuthLayout>
</template>
