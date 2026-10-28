<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { companyApi } from '@/api';
import { ApiError } from '@/api/http';
import type { Company } from '@/api/types';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import { formatBytes } from '@/utils/format';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppCheckbox from '@/components/ui/AppCheckbox.vue';
import AppInput from '@/components/ui/AppInput.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const session = useSessionStore();
const toast = useToastStore();
const company = ref<Company | null>(null);
const canEdit = computed(() => session.can('company.update') && !session.isReadOnly);
const canSecurity = computed(() => session.can('security.manage') && !session.isReadOnly);
const uploading = ref(false);
const requireMfa = ref(false);
const savingSecurity = ref(false);
const logoVersion = ref(0);

const form = useForm({
    name: '',
    legal_name: '',
    nif: '',
    email: '',
    phone: '',
    website: '',
    address_line1: '',
    address_line2: '',
    postal_code: '',
    city: '',
    country_code: 'PT',
    timezone: 'Europe/Lisbon',
});

function fill(c: Company): void {
    company.value = c;
    requireMfa.value = c.settings.require_mfa;
    form.reset({
        name: c.name,
        legal_name: c.legal_name ?? '',
        nif: c.nif ?? '',
        email: c.email ?? '',
        phone: c.phone ?? '',
        website: c.website ?? '',
        address_line1: c.address_line1 ?? '',
        address_line2: c.address_line2 ?? '',
        postal_code: c.postal_code ?? '',
        city: c.city ?? '',
        country_code: c.country_code,
        timezone: c.settings.timezone,
    });
}

onMounted(async () => fill(await companyApi.get()));

async function save(): Promise<void> {
    const payload = Object.fromEntries(Object.entries(form.data).map(([k, v]) => [k, v === '' ? null : v]));
    const updated = await form.submit(() => companyApi.update(payload), { success: 'Dados da empresa guardados.' });
    if (updated) {
        fill(updated);
        await session.refresh();
    }
}

async function onLogo(event: Event): Promise<void> {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    uploading.value = true;
    try {
        fill(await companyApi.uploadLogo(file));
        logoVersion.value++;
        toast.success('Logótipo atualizado.');
    } catch (e) {
        toast.error(e instanceof ApiError ? (e.firstError('logo') ?? e.message) : 'Falha no envio.');
    } finally {
        uploading.value = false;
        (event.target as HTMLInputElement).value = '';
    }
}

async function removeLogo(): Promise<void> {
    fill(await companyApi.deleteLogo());
    toast.success('Logótipo removido.');
}

async function saveSecurity(): Promise<void> {
    savingSecurity.value = true;
    try {
        fill(await companyApi.updateSecurity(requireMfa.value));
        await session.refresh();
        toast.success('Política de segurança guardada.');
    } catch (e) {
        requireMfa.value = company.value?.settings.require_mfa ?? false;
        toast.error(e instanceof ApiError ? e.message : 'Não foi possível guardar.');
    } finally {
        savingSecurity.value = false;
    }
}
</script>

<template>
    <div v-if="company">
        <PageHeader title="Empresa" description="Identificação, contactos e políticas da empresa." />

        <div class="grid gap-4 lg:grid-cols-3">
            <AppCard title="Dados da empresa" class="lg:col-span-2">
                <form class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
                    <AppAlert v-if="form.message.value" type="error" class="sm:col-span-2">{{ form.message.value }}</AppAlert>
                    <AppInput v-model="form.data.name" label="Nome comercial" required :disabled="!canEdit" :error="form.errors.value.name" class="sm:col-span-2" />
                    <AppInput v-model="form.data.legal_name" label="Denominação social" :disabled="!canEdit" :error="form.errors.value.legal_name" />
                    <AppInput v-model="form.data.nif" label="NIF" inputmode="numeric" :disabled="!canEdit" :error="form.errors.value.nif" />
                    <AppInput v-model="form.data.email" label="Email" type="email" :disabled="!canEdit" :error="form.errors.value.email" />
                    <AppInput v-model="form.data.phone" label="Telefone" type="tel" inputmode="tel" :disabled="!canEdit" :error="form.errors.value.phone" />
                    <AppInput v-model="form.data.website" label="Site" placeholder="https://" :disabled="!canEdit" :error="form.errors.value.website" class="sm:col-span-2" />
                    <AppInput v-model="form.data.address_line1" label="Morada" :disabled="!canEdit" :error="form.errors.value.address_line1" class="sm:col-span-2" />
                    <AppInput v-model="form.data.address_line2" label="Morada (cont.)" :disabled="!canEdit" class="sm:col-span-2" />
                    <AppInput v-model="form.data.postal_code" label="Código postal" placeholder="0000-000" :disabled="!canEdit" :error="form.errors.value.postal_code" />
                    <AppInput v-model="form.data.city" label="Localidade" :disabled="!canEdit" :error="form.errors.value.city" />
                    <div v-if="canEdit" class="sm:col-span-2">
                        <AppButton type="submit" :loading="form.processing.value">Guardar alterações</AppButton>
                    </div>
                </form>
            </AppCard>

            <div class="space-y-4">
                <AppCard title="Logótipo" description="PNG, JPG ou WebP até 2 MB.">
                    <div class="flex h-28 items-center justify-center rounded-lg border border-dashed border-slate-300 bg-slate-50">
                        <img v-if="company?.logo_url" :src="`${company?.logo_url}&r=${logoVersion}`" alt="Logótipo da empresa" class="max-h-24 max-w-full object-contain" />
                        <span v-else class="text-sm text-slate-400">Sem logótipo</span>
                    </div>
                    <div v-if="canEdit" class="mt-3 flex flex-wrap gap-2">
                        <label class="inline-flex cursor-pointer items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold hover:bg-slate-50">
                            {{ uploading ? 'A enviar…' : 'Escolher imagem' }}
                            <input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" :disabled="uploading" @change="onLogo" />
                        </label>
                        <AppButton v-if="company?.has_logo" size="sm" variant="ghost" @click="removeLogo">Remover</AppButton>
                    </div>
                </AppCard>

                <AppCard title="Segurança">
                    <AppCheckbox
                        v-model="requireMfa"
                        :disabled="!canSecurity || savingSecurity"
                        label="Exigir autenticação de dois fatores"
                        description="Todos os utilizadores terão de ativar a 2FA para aceder à empresa."
                    />
                    <AppButton v-if="canSecurity" class="mt-3" size="sm" variant="secondary" :loading="savingSecurity" @click="saveSecurity">Guardar política</AppButton>
                </AppCard>

                <AppCard title="Armazenamento">
                    <p class="text-sm text-slate-600">Utilizado: <strong>{{ formatBytes(company?.storage_used_bytes) }}</strong></p>
                </AppCard>
            </div>
        </div>
    </div>
</template>
