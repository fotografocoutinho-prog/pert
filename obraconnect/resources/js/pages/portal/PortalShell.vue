<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useSessionStore } from '@/stores/session';
import BrandMark from '@/components/layout/BrandMark.vue';

/** Moldura do portal de subempreiteiros: sem menus da empresa, só os contratos e os documentos. */
const session = useSessionStore();
const route = useRoute();
const router = useRouter();

const accessId = computed(() => Number(route.params.access ?? route.query.acesso ?? session.portal[0]?.id ?? 0) || null);
const access = computed(() => session.portal.find((a) => a.id === accessId.value) ?? null);

async function logout(): Promise<void> {
    await session.logout();
    await router.push('/entrar');
}
</script>

<template>
    <div class="min-h-screen bg-slate-50">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 px-4 py-3">
                <div class="flex items-center gap-3">
                    <RouterLink to="/portal"><BrandMark /></RouterLink>
                    <div class="text-sm leading-tight">
                        <p class="font-semibold text-slate-900">Portal de subempreiteiros</p>
                        <p v-if="access" class="text-slate-500">{{ access.partner }} · para {{ access.company }}</p>
                    </div>
                </div>
                <nav class="flex flex-wrap items-center gap-1 text-sm">
                    <RouterLink to="/portal" class="rounded-lg px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-100">Contratos</RouterLink>
                    <RouterLink v-if="access" :to="`/portal/${access.id}/documentos`" class="rounded-lg px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-100">Documentos</RouterLink>
                    <RouterLink v-if="session.current" to="/app" class="rounded-lg px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-100">A minha empresa</RouterLink>
                    <button type="button" class="rounded-lg px-3 py-1.5 font-medium text-slate-500 hover:bg-slate-100" @click="logout">Sair</button>
                </nav>
            </div>
        </header>
        <main class="mx-auto max-w-5xl px-4 py-6">
            <div v-if="!session.portal.length" class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-600">
                Esta conta não tem acesso ao portal de nenhum subempreiteiro. O acesso é dado pela empresa de construção, por convite enviado por email.
            </div>
            <RouterView v-else />
        </main>
    </div>
</template>
