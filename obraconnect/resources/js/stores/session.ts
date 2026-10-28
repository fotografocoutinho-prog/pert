import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { authApi, meApi } from '@/api';
import { ApiError } from '@/api/http';
import type { MePayload } from '@/api/types';

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

    async function refresh(): Promise<void> {
        try {
            me.value = await meApi.get();
        } catch (error) {
            if (error instanceof ApiError && (error.status === 401 || error.status === 403)) {
                me.value = null;
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
        try {
            await authApi.logout();
        } finally {
            reset();
        }
    }

    function reset(): void {
        me.value = null;
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
        refresh,
        ensureLoaded,
        can,
        hasPlatformRole,
        logout,
        reset,
        switchTenant,
    };
});
