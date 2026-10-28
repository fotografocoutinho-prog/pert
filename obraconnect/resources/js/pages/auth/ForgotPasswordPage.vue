<script setup lang="ts">
import { ref } from 'vue';
import { authApi } from '@/api';
import { useForm } from '@/composables/useForm';
import AuthLayout from '@/components/layout/AuthLayout.vue';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppInput from '@/components/ui/AppInput.vue';

const form = useForm({ email: '' });
const sent = ref<string | null>(null);

async function submit(): Promise<void> {
    const message = await form.submit((d) => authApi.forgotPassword(d.email));
    if (message !== undefined) sent.value = message || 'Se o email existir, enviámos uma ligação para repor a password.';
}
</script>

<template>
    <AuthLayout title="Recuperar password" subtitle="Enviamos uma ligação para o seu email.">
        <AppAlert v-if="sent" type="success">{{ sent }}</AppAlert>
        <form v-else class="space-y-4" novalidate @submit.prevent="submit">
            <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>
            <AppInput v-model="form.data.email" label="Email" type="email" required inputmode="email" autocomplete="email" :error="form.errors.value.email" />
            <AppButton type="submit" block size="lg" :loading="form.processing.value">Enviar ligação</AppButton>
        </form>
        <template #footer><RouterLink to="/entrar" class="font-semibold text-brand-700 hover:underline">Voltar a entrar</RouterLink></template>
    </AuthLayout>
</template>
