<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router';
import { authApi } from '@/api';
import { useForm } from '@/composables/useForm';
import { useToastStore } from '@/stores/toast';
import AuthLayout from '@/components/layout/AuthLayout.vue';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppInput from '@/components/ui/AppInput.vue';

const route = useRoute();
const router = useRouter();
const form = useForm({
    email: typeof route.query.email === 'string' ? route.query.email : '',
    password: '',
    password_confirmation: '',
});

async function submit(): Promise<void> {
    const ok = await form.submit((d) => authApi.resetPassword({ ...d, token: String(route.params.token) }).then(() => true));
    if (ok) {
        useToastStore().success('Password reposta. Já pode entrar.');
        await router.push('/entrar');
    }
}
</script>

<template>
    <AuthLayout title="Nova password">
        <form class="space-y-4" novalidate @submit.prevent="submit">
            <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>
            <AppInput v-model="form.data.email" label="Email" type="email" required autocomplete="email" :error="form.errors.value.email" />
            <AppInput v-model="form.data.password" label="Nova password" type="password" required autocomplete="new-password" hint="Mínimo 10 caracteres, com letras e números." :error="form.errors.value.password" />
            <AppInput v-model="form.data.password_confirmation" label="Confirmar password" type="password" required autocomplete="new-password" />
            <AppButton type="submit" block size="lg" :loading="form.processing.value">Guardar password</AppButton>
        </form>
    </AuthLayout>
</template>
