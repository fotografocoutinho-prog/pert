<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { authApi } from '@/api';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { homeFor } from '@/router';
import AuthLayout from '@/components/layout/AuthLayout.vue';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCheckbox from '@/components/ui/AppCheckbox.vue';
import AppInput from '@/components/ui/AppInput.vue';

const route = useRoute();
const router = useRouter();
const session = useSessionStore();

const invitationToken = computed(() => (typeof route.query.convite === 'string' ? route.query.convite : null));
// Convite para o portal de subempreiteiros: só a conta, sem empresa.
const portalToken = computed(() => (typeof route.query.portal === 'string' && !invitationToken.value ? route.query.portal : null));
const invited = computed(() => invitationToken.value !== null || portalToken.value !== null);

const form = useForm({
    name: typeof route.query.nome === 'string' ? route.query.nome : '',
    email: typeof route.query.email === 'string' ? route.query.email : '',
    password: '',
    password_confirmation: '',
    company_name: '',
    nif: '',
    accept_terms: false,
});

async function submit(): Promise<void> {
    const ok = await form.submit((d) =>
        authApi.register({
            ...d,
            company_name: invited.value ? null : d.company_name,
            invitation_token: invitationToken.value,
            portal_token: portalToken.value,
        }).then(() => true),
    );
    if (!ok) return;

    await session.refresh();
    await router.push(session.user?.email_verified ? homeFor(session) : '/verificar-email');
}
</script>

<template>
    <AuthLayout
        :title="invited ? 'Criar conta e aceitar convite' : 'Registar empresa'"
        :subtitle="
            portalToken
                ? 'Crie a sua conta para entrar no portal de subempreiteiros.'
                : invitationToken
                  ? 'Crie a sua conta para entrar na empresa que o convidou.'
                  : 'Comece o período experimental gratuito. Não pedimos cartão.'
        "
    >
        <form class="space-y-4" novalidate @submit.prevent="submit">
            <AppAlert v-if="form.message.value" type="error">{{ form.message.value }}</AppAlert>
            <AppAlert v-if="form.errors.value.invitation_token" type="error">{{ form.errors.value.invitation_token }}</AppAlert>
            <AppAlert v-if="form.errors.value.portal_token" type="error">{{ form.errors.value.portal_token }}</AppAlert>

            <template v-if="!invited">
                <AppInput v-model="form.data.company_name" label="Nome da empresa" required autocomplete="organization" :error="form.errors.value.company_name" />
                <AppInput v-model="form.data.nif" label="NIF da empresa" inputmode="numeric" :maxlength="12" hint="Opcional. Pode preencher mais tarde." :error="form.errors.value.nif" />
            </template>

            <AppInput v-model="form.data.name" label="O seu nome" required autocomplete="name" :error="form.errors.value.name" />
            <AppInput v-model="form.data.email" label="Email" type="email" required autocomplete="email" inputmode="email" :error="form.errors.value.email" />
            <AppInput
                v-model="form.data.password"
                label="Password"
                type="password"
                required
                autocomplete="new-password"
                hint="Mínimo 10 caracteres, com letras e números."
                :error="form.errors.value.password"
            />
            <AppInput v-model="form.data.password_confirmation" label="Confirmar password" type="password" required autocomplete="new-password" />

            <AppCheckbox v-model="form.data.accept_terms" :error="form.errors.value.accept_terms">
                Aceito os termos de utilização e a política de privacidade.
            </AppCheckbox>

            <AppButton type="submit" block size="lg" :loading="form.processing.value">
                {{ invited ? 'Criar conta' : 'Criar empresa' }}
            </AppButton>
        </form>
        <template #footer>
            Já tem conta? <RouterLink to="/entrar" class="font-semibold text-brand-700 hover:underline">Entrar</RouterLink>
        </template>
    </AuthLayout>
</template>
