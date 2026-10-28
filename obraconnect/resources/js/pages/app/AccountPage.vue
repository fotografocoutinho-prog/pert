<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { authApi } from '@/api';
import { ApiError } from '@/api/http';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const session = useSessionStore();
const toast = useToastStore();

// Perfil
const profile = useForm({ name: session.user?.name ?? '', email: session.user?.email ?? '' });

async function saveProfile(): Promise<void> {
    const emailChanged = profile.data.email !== session.user?.email;
    const ok = await profile.submit((d) => authApi.updateProfile(d).then(() => true), { success: 'Perfil atualizado.' });
    if (ok) {
        await session.refresh();
        if (emailChanged) toast.info('Enviámos um email para confirmar o novo endereço.');
    }
}

// Password
const password = useForm({ current_password: '', password: '', password_confirmation: '' });

async function savePassword(): Promise<void> {
    const ok = await password.submit((d) => authApi.updatePassword(d).then(() => true), { success: 'Password alterada.' });
    if (ok) password.reset();
}

// Confirmação de password (exigida pelo servidor antes de mexer na 2FA)
const confirmOpen = ref(false);
const confirmForm = useForm({ password: '' });
let afterConfirm: (() => Promise<void>) | null = null;

async function withConfirmedPassword(action: () => Promise<void>): Promise<void> {
    if (await authApi.passwordConfirmed()) {
        await action();
        return;
    }
    afterConfirm = action;
    confirmForm.reset();
    confirmOpen.value = true;
}

async function submitConfirm(): Promise<void> {
    const ok = await confirmForm.submit((d) => authApi.confirmPassword(d.password).then(() => true));
    if (ok && afterConfirm) {
        confirmOpen.value = false;
        await afterConfirm();
        afterConfirm = null;
    }
}

// Autenticação de dois fatores
const qrSvg = ref<string | null>(null);
const secretKey = ref<string | null>(null);
const recoveryCodes = ref<string[]>([]);
const twoFactorCode = ref('');
const twoFactorError = ref<string | null>(null);
const busy = ref(false);

async function loadSetup(): Promise<void> {
    const [qr, secret] = await Promise.all([authApi.twoFactorQrCode(), authApi.twoFactorSecretKey()]);
    qrSvg.value = qr.svg;
    secretKey.value = secret;
}

async function guarded(action: () => Promise<void>): Promise<void> {
    busy.value = true;
    try {
        await withConfirmedPassword(action);
    } catch (e) {
        toast.error(e instanceof ApiError ? e.message : 'Operação falhou.');
    } finally {
        busy.value = false;
    }
}

function enable(): Promise<void> {
    return guarded(async () => {
        await authApi.enableTwoFactor();
        await loadSetup();
        await session.refresh();
    });
}

function resumeSetup(): Promise<void> {
    return guarded(loadSetup);
}

async function confirmSetup(): Promise<void> {
    twoFactorError.value = null;
    busy.value = true;
    try {
        await authApi.confirmTwoFactor(twoFactorCode.value.replace(/\s/g, ''));
        recoveryCodes.value = await authApi.recoveryCodes();
        qrSvg.value = null;
        secretKey.value = null;
        twoFactorCode.value = '';
        await session.refresh();
        toast.success('Autenticação de dois fatores ativada.');
    } catch (e) {
        twoFactorError.value = e instanceof ApiError ? (e.firstError('code') ?? e.message) : 'Código inválido.';
    } finally {
        busy.value = false;
    }
}

function showRecoveryCodes(): Promise<void> {
    return guarded(async () => {
        recoveryCodes.value = await authApi.recoveryCodes();
    });
}

function regenerateCodes(): Promise<void> {
    return guarded(async () => {
        await authApi.regenerateRecoveryCodes();
        recoveryCodes.value = await authApi.recoveryCodes();
        toast.success('Novos códigos gerados. Os anteriores deixaram de funcionar.');
    });
}

function disable(): Promise<void> {
    if (!window.confirm('Desativar a autenticação de dois fatores torna a sua conta menos segura. Continuar?')) {
        return Promise.resolve();
    }
    return guarded(async () => {
        await authApi.disableTwoFactor();
        recoveryCodes.value = [];
        await session.refresh();
        toast.info('Autenticação de dois fatores desativada.');
    });
}

onMounted(() => session.refresh());
</script>

<template>
    <div>
        <PageHeader title="A minha conta" description="Dados pessoais e segurança de acesso." />

        <AppAlert v-if="session.current?.tenant.requires_mfa && !session.user?.two_factor_enabled" type="warning" class="mb-4">
            A empresa {{ session.current.tenant.name }} exige autenticação de dois fatores. Ative-a abaixo para continuar a usar a plataforma.
        </AppAlert>
        <AppAlert v-else-if="session.isPlatformStaff && !session.user?.two_factor_enabled" type="warning" class="mb-4">
            O acesso ao backoffice da plataforma exige autenticação de dois fatores.
        </AppAlert>

        <div class="grid gap-4 lg:grid-cols-2">
            <AppCard title="Dados pessoais">
                <form class="space-y-4" novalidate @submit.prevent="saveProfile">
                    <AppAlert v-if="profile.message.value" type="error">{{ profile.message.value }}</AppAlert>
                    <AppInput v-model="profile.data.name" label="Nome" required :error="profile.errors.value.name" />
                    <AppInput v-model="profile.data.email" label="Email" type="email" required :error="profile.errors.value.email" />
                    <AppButton type="submit" :loading="profile.processing.value">Guardar</AppButton>
                </form>
            </AppCard>

            <AppCard title="Alterar password">
                <form class="space-y-4" novalidate @submit.prevent="savePassword">
                    <AppAlert v-if="password.message.value" type="error">{{ password.message.value }}</AppAlert>
                    <AppInput v-model="password.data.current_password" label="Password atual" type="password" autocomplete="current-password" required :error="password.errors.value.current_password" />
                    <AppInput v-model="password.data.password" label="Nova password" type="password" autocomplete="new-password" required hint="Mínimo 10 caracteres, com letras e números." :error="password.errors.value.password" />
                    <AppInput v-model="password.data.password_confirmation" label="Confirmar nova password" type="password" autocomplete="new-password" required />
                    <AppButton type="submit" :loading="password.processing.value">Alterar password</AppButton>
                </form>
            </AppCard>

            <AppCard title="Autenticação de dois fatores (2FA)" class="lg:col-span-2">
                <template #actions>
                    <AppBadge :color="session.user?.two_factor_enabled ? 'green' : 'amber'">
                        {{ session.user?.two_factor_enabled ? 'Ativa' : 'Desativada' }}
                    </AppBadge>
                </template>

                <p class="text-sm text-slate-600">
                    Além da password, é pedido um código de 6 dígitos gerado por uma aplicação autenticadora (Google Authenticator, Microsoft Authenticator, Authy, etc.).
                </p>

                <div v-if="qrSvg" class="mt-4 grid gap-6 md:grid-cols-2">
                    <div>
                        <p class="text-sm font-medium">1. Leia o código QR com a aplicação</p>
                        <!-- SVG gerado pelo servidor (Fortify), não por dados do utilizador. -->
                        <div class="mt-2 inline-block rounded-lg border border-slate-200 bg-white p-3" v-html="qrSvg" />
                        <p v-if="secretKey" class="mt-2 text-xs text-slate-500">Ou introduza a chave: <code class="break-all">{{ secretKey }}</code></p>
                    </div>
                    <form class="space-y-3" @submit.prevent="confirmSetup">
                        <p class="text-sm font-medium">2. Introduza o código apresentado</p>
                        <AppInput v-model="twoFactorCode" inputmode="numeric" autocomplete="one-time-code" :maxlength="8" :error="twoFactorError ?? undefined" />
                        <AppButton type="submit" :loading="busy">Confirmar e ativar</AppButton>
                    </form>
                </div>

                <div v-if="recoveryCodes.length" class="mt-4">
                    <AppAlert type="warning" title="Guarde estes códigos de recuperação num local seguro">
                        Cada código permite entrar uma vez se perder o acesso à aplicação autenticadora.
                    </AppAlert>
                    <ul class="mt-3 grid grid-cols-2 gap-2 font-mono text-sm sm:grid-cols-4">
                        <li v-for="code in recoveryCodes" :key="code" class="rounded bg-slate-100 px-2 py-1">{{ code }}</li>
                    </ul>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <template v-if="!session.user?.two_factor_enabled">
                        <AppButton v-if="!qrSvg && session.user?.two_factor_pending_confirmation" :loading="busy" @click="resumeSetup">Continuar configuração</AppButton>
                        <AppButton v-else-if="!qrSvg" :loading="busy" @click="enable">Ativar 2FA</AppButton>
                    </template>
                    <template v-else>
                        <AppButton variant="secondary" :loading="busy" @click="showRecoveryCodes">Ver códigos de recuperação</AppButton>
                        <AppButton variant="secondary" :loading="busy" @click="regenerateCodes">Gerar novos códigos</AppButton>
                        <AppButton variant="ghost" class="text-red-700" :loading="busy" @click="disable">Desativar 2FA</AppButton>
                    </template>
                </div>
            </AppCard>
        </div>

        <AppModal :open="confirmOpen" title="Confirme a sua password" @close="confirmOpen = false">
            <form id="confirm-password" class="space-y-3" @submit.prevent="submitConfirm">
                <p class="text-sm text-slate-600">Por segurança, confirme a password antes de alterar a autenticação de dois fatores.</p>
                <AppAlert v-if="confirmForm.message.value" type="error">{{ confirmForm.message.value }}</AppAlert>
                <AppInput v-model="confirmForm.data.password" label="Password" type="password" autocomplete="current-password" required :error="confirmForm.errors.value.password" />
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="confirmOpen = false">Cancelar</AppButton>
                <AppButton type="submit" form="confirm-password" :loading="confirmForm.processing.value">Confirmar</AppButton>
            </template>
        </AppModal>
    </div>
</template>
