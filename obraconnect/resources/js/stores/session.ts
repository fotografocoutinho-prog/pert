import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { authApi, meApi } from '@/api';
import { ApiError } from '@/api/http';
import type { MePayload } from '@/api/types';
import { forgetReadCache, isNetworkError, lastMe, rememberMe } from '@/offline/cache';
import { setOfflineUser } from '@/offline/db';

/**
 * Estado da sessão. A fonte de verdade é sempre o servidor (/api/v1/me):
 * as permissões aqui servem só para mostrar ou esconder elementos da interface.
 */
export const useSessionStore = defineStore('session', () => {
    const me = ref<MePayload | null>(null);
    const loaded = ref(false);
    let pending: Promise<void> | null = null;

    const user = computed(() => me.value?.user ?? null);
    const current = computed(() => me.value?.current ?? null);
    const tenants = computed(() => me.value?.tenants ?? []);
    const supportSession = computed(() => me.value?.support_session ?? null);
    const isAuthenticated = computed(() => user.value !== null);
    const isPlatformStaff = computed(() => Boolean(user.value?.platform_role));
    const isReadOnly = computed(() => current.value?.tenant.status === 'read_only' || supportSession.value !== null);

    /** Sem rede, a sessão vem da última cópia guardada (modo sem rede); o servidor volta a decidir ao sincronizar. */
    const offlineSession = ref(false);

    async function refresh(): Promise<void> {
        try {
            // A resposta tal como veio: o IndexedDB não aceita os proxies reativos do Vue.
            const fresh = await meApi.get();
            me.value = fresh;
            offlineSession.value = false;
            setOfflineUser(fresh?.user.id ?? null);
            if (fresh) rememberMe(fresh);
        } catch (error) {
            if (error instanceof ApiError && (error.status === 401 || error.status === 403)) {
                me.value = null;
                setOfflineUser(null);
            } else if (isNetworkError(error) && (await lastMe<MePayload>())) {
                me.value = (await lastMe<MePayload>())!;
                offlineSession.value = true;
                setOfflineUser(me.value.user.id);
            } else {
                throw error;
            }
        } finally {
            loaded.value = true;
        }
    }

    function ensureLoaded(): Promise<void> {
        if (loaded.value) return Promise.resolve();
        pending ??= refresh().finally(() => (pending = null));
        return pending;
    }

    function can(permission: string): boolean {
        if (supportSession.value) {
            return permission.endsWith('.view');
        }
        return current.value?.permissions.includes(permission) ?? false;
    }

    function hasPlatformRole(...roles: string[]): boolean {
        const role = user.value?.platform_role;
        return role === 'super_admin' || (role !== null && role !== undefined && roles.includes(role));
    }

    async function logout(): Promise<void> {
        const userId = user.value?.id ?? null;
        try {
            await authApi.logout();
        } finally {
            // As cópias de leitura saem com a sessão; a fila de envio fica (por utilizador).
            await forgetReadCache(userId);
            reset();
        }
    }

    function reset(): void {
        me.value = null;
        setOfflineUser(null);
        loaded.value = true;
    }

    async function switchTenant(tenantId: number): Promise<void> {
        await meApi.switchTenant(tenantId);
        await refresh();
    }

    return {
        me,
        loaded,
        user,
        current,
        tenants,
        supportSession,
        isAuthenticated,
        isPlatformStaff,
        isReadOnly,
        offlineSession,
        refresh,
        ensureLoaded,
        can,
        hasPlatformRole,
        logout,
        reset,
        switchTenant,
    };
});
