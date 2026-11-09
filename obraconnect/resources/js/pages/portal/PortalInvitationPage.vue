<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ApiError } from '@/api/http';
import { portalApi } from '@/api/portal';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { formatDateTime } from '@/utils/format';
import AuthLayout from '@/components/layout/AuthLayout.vue';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';

const route = useRoute();
const router = useRouter();
const session = useSessionStore();
const token = String(route.params.token);

const invitation = ref<Awaited<ReturnType<typeof portalApi.lookupInvitation>> | null>(null);
const error = ref<string | null>(null);
const loading = ref(true);
const accepting = ref(false);

onMounted(async () => {
    await session.ensureLoaded();
    try {
        invitation.value = await portalApi.lookupInvitation(token);
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Convite inválido.';
    } finally {
        loading.value = false;
    }
});

async function accept(): Promise<void> {
    accepting.value = true;
    error.value = null;
    try {
        const { access_id } = await portalApi.acceptInvitation(token);
        await session.refresh();
        useToastStore().success(`Já tem acesso ao portal da ${invitation.value?.company}.`);
        await router.push({ path: '/portal', query: { acesso: String(access_id) } });
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Não foi possível aceitar o convite.';
    } finally {
        accepting.value = false;
    }
}
</script>

<template>
    <AuthLayout title="Portal de subempreiteiros">
        <div v-if="loading" class="flex justify-center py-6"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
        <div v-else class="space-y-4">
            <AppAlert v-if="error" type="error">{{ error }}</AppAlert>
            <template v-if="invitation">
                <p class="text-slate-700">
                    A <strong>{{ invitation.company }}</strong> deu-lhe acesso ao portal de subempreiteiros em nome de <strong>{{ invitation.partner }}</strong>: vê os contratos,
                    propõe os autos e envia documentos.
                </p>
                <p class="text-sm text-slate-500">Convite para {{ invitation.email }} · válido até {{ formatDateTime(invitation.expires_at) }}</p>

                <AppButton v-if="session.isAuthenticated" block size="lg" :loading="accepting" @click="accept">Aceitar o acesso</AppButton>
                <RouterLink
                    v-else-if="invitation.account_exists"
                    :to="{ path: '/entrar', query: { redirect: route.fullPath } }"
                    class="block rounded-lg bg-brand-700 px-4 py-3 text-center font-semibold text-white"
                >
                    Entrar para aceitar
                </RouterLink>
                <RouterLink
                    v-else
                    :to="{ path: '/registar', query: { portal: token, email: invitation.email, nome: invitation.name } }"
                    class="block rounded-lg bg-brand-700 px-4 py-3 text-center font-semibold text-white"
                >
                    Criar conta e aceitar
                </RouterLink>
            </template>
        </div>
    </AuthLayout>
</template>
