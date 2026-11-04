<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { costCentersApi, departmentsApi, membersApi } from '@/api';
import { ApiError } from '@/api/http';
import type { CostCenter, Department, Member } from '@/api/types';
import { useForm } from '@/composables/useForm';
import { useSessionStore } from '@/stores/session';
import { useToastStore } from '@/stores/toast';
import AppAlert from '@/components/ui/AppAlert.vue';
import AppBadge from '@/components/ui/AppBadge.vue';
import AppButton from '@/components/ui/AppButton.vue';
import AppCard from '@/components/ui/AppCard.vue';
import AppCheckbox from '@/components/ui/AppCheckbox.vue';
import AppInput from '@/components/ui/AppInput.vue';
import AppModal from '@/components/ui/AppModal.vue';
import AppSelect from '@/components/ui/AppSelect.vue';
import AppSpinner from '@/components/ui/AppSpinner.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';

const session = useSessionStore();
const toast = useToastStore();

const canDepartments = computed(() => session.can('departments.manage') && !session.isReadOnly);
const canCostCenters = computed(() => session.can('cost_centers.manage') && !session.isReadOnly);

const departments = ref<Department[]>([]);
const centers = ref<CostCenter[]>([]);
const loading = ref(true);

async function load(): Promise<void> {
    [departments.value, centers.value] = await Promise.all([departmentsApi.list(), costCentersApi.list()]);
    loading.value = false;
}

onMounted(load);

const fail = (e: unknown, fallback: string) => toast.error(e instanceof ApiError ? e.message : fallback);

// ---- Departamentos ----
const departmentOpen = ref(false);
const editingDepartment = ref<Department | null>(null);
const departmentForm = useForm({ name: '', description: '' });

function openDepartment(department: Department | null): void {
    editingDepartment.value = department;
    departmentForm.reset({ name: department?.name ?? '', description: department?.description ?? '' });
    departmentOpen.value = true;
}

async function saveDepartment(): Promise<void> {
    const payload = { name: departmentForm.data.name, description: departmentForm.data.description || null };
    const id = editingDepartment.value?.id;
    const saved = await departmentForm.submit(() => (id ? departmentsApi.update(id, payload) : departmentsApi.create(payload)), {
        success: id ? 'Departamento guardado.' : 'Departamento criado.',
    });
    if (saved) {
        departmentOpen.value = false;
        departments.value = [...departments.value.filter((d) => d.id !== saved.id), saved].sort((a, b) => a.name.localeCompare(b.name, 'pt'));
    }
}

async function removeDepartment(department: Department): Promise<void> {
    const people = department.members_count ? ` As ${department.members_count} pessoa(s) ficam sem departamento.` : '';
    if (!window.confirm(`Apagar o departamento “${department.name}”?${people}`)) return;
    try {
        await departmentsApi.remove(department.id);
        departments.value = departments.value.filter((d) => d.id !== department.id);
        toast.success('Departamento apagado.');
    } catch (e) {
        fail(e, 'Não foi possível apagar.');
    }
}

// Pessoas de um departamento
const peopleFor = ref<Department | null>(null);
const members = ref<Member[]>([]);
const chosen = ref<Set<number>>(new Set());
const loadingPeople = ref(false);
const savingPeople = ref(false);

async function openPeople(department: Department): Promise<void> {
    peopleFor.value = department;
    chosen.value = new Set(department.membership_ids);
    if (members.value.length) return;
    loadingPeople.value = true;
    try {
        const all: Member[] = [];
        for (let page = 1; ; page++) {
            const result = await membersApi.list({ status: 'active', per_page: 100, page });
            all.push(...result.data);
            if (page >= result.meta.last_page) break;
        }
        members.value = all.sort((a, b) => a.user.name.localeCompare(b.user.name, 'pt'));
    } finally {
        loadingPeople.value = false;
    }
}

function toggle(id: number, on: boolean): void {
    const next = new Set(chosen.value);
    if (on) next.add(id);
    else next.delete(id);
    chosen.value = next;
}

async function savePeople(): Promise<void> {
    if (!peopleFor.value) return;
    savingPeople.value = true;
    try {
        await departmentsApi.setMembers(peopleFor.value.id, [...chosen.value]);
        // Quem mudou de departamento sai do anterior: recarrega todos e as pessoas.
        departments.value = await departmentsApi.list();
        members.value = [];
        peopleFor.value = null;
        toast.success('Pessoas do departamento guardadas.');
    } catch (e) {
        fail(e, 'Não foi possível guardar.');
    } finally {
        savingPeople.value = false;
    }
}

const departmentOf = (memberId: number) => departments.value.find((d) => d.membership_ids.includes(memberId));

// ---- Centros de custo ----
const showArchived = ref(false);

/** Árvore achatada por código, com a profundidade de cada um. Um arquivado esconde também os que tem dentro. */
const tree = computed(() => {
    const byParent = new Map<number | null, CostCenter[]>();
    for (const c of centers.value) byParent.set(c.parent_id, [...(byParent.get(c.parent_id) ?? []), c]);
    const out: { center: CostCenter; depth: number }[] = [];
    const walk = (parent: number | null, depth: number) => {
        for (const c of (byParent.get(parent) ?? []).sort((a, b) => a.code.localeCompare(b.code, 'pt', { numeric: true }))) {
            if (c.archived && !showArchived.value) continue;
            out.push({ center: c, depth });
            walk(c.id, depth + 1);
        }
    };
    walk(null, 0);
    return out;
});
const archivedCount = computed(() => centers.value.filter((c) => c.archived).length);

const centerOpen = ref(false);
const editingCenter = ref<CostCenter | null>(null);
const centerForm = useForm({ code: '', name: '', parent_id: '' as number | '', archived: false });

/** Não se escolhe o próprio centro, um descendente dele, nem um arquivado. */
const parentOptions = computed(() => {
    const blocked = new Set<number>();
    if (editingCenter.value) {
        const stack = [editingCenter.value.id];
        while (stack.length) {
            const id = stack.pop()!;
            blocked.add(id);
            centers.value.filter((c) => c.parent_id === id).forEach((c) => stack.push(c.id));
        }
    }
    return [
        { value: '', label: 'Nenhum (na raiz)' },
        ...centers.value
            .filter((c) => !blocked.has(c.id) && (!c.archived || c.id === editingCenter.value?.parent_id))
            .sort((a, b) => a.code.localeCompare(b.code, 'pt', { numeric: true }))
            .map((c) => ({ value: c.id, label: `${c.code} · ${c.name}` })),
    ];
});

function openCenter(center: CostCenter | null, parentId: number | null = null): void {
    editingCenter.value = center;
    centerForm.reset({
        code: center?.code ?? '',
        name: center?.name ?? '',
        parent_id: (center ? center.parent_id : parentId) ?? '',
        archived: center?.archived ?? false,
    });
    centerOpen.value = true;
}

async function saveCenter(): Promise<void> {
    const f = centerForm.data;
    const payload = { code: f.code.trim() || null, name: f.name, parent_id: f.parent_id || null };
    const id = editingCenter.value?.id;
    const saved = await centerForm.submit(
        () => (id ? costCentersApi.update(id, { ...payload, archived: f.archived }) : costCentersApi.create(payload)),
        { success: id ? 'Centro de custo guardado.' : 'Centro de custo criado.' },
    );
    if (saved) {
        centerOpen.value = false;
        centers.value = await costCentersApi.list();
    }
}

async function removeCenter(center: CostCenter): Promise<void> {
    if (!window.confirm(`Apagar o centro de custo ${center.code} · ${center.name}?`)) return;
    try {
        await costCentersApi.remove(center.id);
        centers.value = centers.value.filter((c) => c.id !== center.id);
        toast.success('Centro de custo apagado.');
    } catch (e) {
        fail(e, 'Não foi possível apagar.');
    }
}
</script>

<template>
    <div>
        <PageHeader
            title="Departamentos e centros de custo"
            description="Como a empresa está organizada: em que departamento está cada pessoa e para que centro de custo vai cada obra."
        />

        <div v-if="loading" class="flex justify-center py-16"><AppSpinner class="h-6 w-6 text-brand-700" /></div>
        <div v-else class="grid items-start gap-4 lg:grid-cols-2">
            <AppCard title="Departamentos" description="Cada pessoa está num departamento, ou em nenhum.">
                <template #actions>
                    <AppButton v-if="canDepartments" size="sm" @click="openDepartment(null)">Novo departamento</AppButton>
                </template>
                <EmptyState v-if="departments.length === 0" title="Sem departamentos" :description="canDepartments ? 'Ex.: Produção, Orçamentação, Compras, Administrativo.' : undefined" />
                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="d in departments" :key="d.id" class="flex flex-wrap items-center justify-between gap-2 py-3">
                        <div class="min-w-0">
                            <p class="font-medium text-slate-900">{{ d.name }}</p>
                            <p class="text-sm text-slate-500">
                                {{ [d.description, d.members_count === 1 ? '1 pessoa' : `${d.members_count} pessoas`].filter(Boolean).join(' · ') }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-1">
                            <RouterLink
                                v-if="session.can('members.view')"
                                :to="{ path: '/app/utilizadores', query: { departamento: d.id } }"
                                class="rounded-lg px-2 py-1.5 text-sm font-medium text-brand-700 hover:bg-slate-50"
                            >
                                Ver pessoas
                            </RouterLink>
                            <template v-if="canDepartments">
                                <AppButton size="sm" variant="ghost" @click="openPeople(d)">Escolher pessoas</AppButton>
                                <AppButton size="sm" variant="ghost" @click="openDepartment(d)">Editar</AppButton>
                                <AppButton size="sm" variant="ghost" class="text-red-700" @click="removeDepartment(d)">Apagar</AppButton>
                            </template>
                        </div>
                    </li>
                </ul>
            </AppCard>

            <AppCard title="Centros de custo" description="Em árvore. Cada obra pode ter um, nos dados da obra.">
                <template #actions>
                    <AppButton v-if="canCostCenters" size="sm" @click="openCenter(null)">Novo centro de custo</AppButton>
                </template>
                <EmptyState
                    v-if="tree.length === 0"
                    title="Sem centros de custo"
                    :description="canCostCenters ? 'Ex.: 1 Obras (com um por obra dentro), 2 Estrutura (Administração, Armazém, Viaturas).' : undefined"
                />
                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="{ center, depth } in tree" :key="center.id" class="flex flex-wrap items-center justify-between gap-2 py-2.5" :style="{ paddingLeft: `${depth * 1.25}rem` }">
                        <div class="min-w-0">
                            <p :class="center.archived ? 'text-slate-400' : 'text-slate-900'">
                                <span class="font-mono text-sm font-semibold">{{ center.code }}</span>
                                <span class="ml-2 font-medium">{{ center.name }}</span>
                            </p>
                            <p class="flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
                                <AppBadge v-if="center.archived">Arquivado</AppBadge>
                                <span v-if="center.projects_count">{{ center.projects_count === 1 ? '1 obra' : `${center.projects_count} obras` }}</span>
                            </p>
                        </div>
                        <div v-if="canCostCenters" class="flex flex-wrap gap-1">
                            <AppButton v-if="!center.archived" size="sm" variant="ghost" @click="openCenter(null, center.id)">+ Dentro</AppButton>
                            <AppButton size="sm" variant="ghost" @click="openCenter(center)">Editar</AppButton>
                            <AppButton v-if="!center.projects_count && !center.children_count" size="sm" variant="ghost" class="text-red-700" @click="removeCenter(center)">
                                Apagar
                            </AppButton>
                        </div>
                    </li>
                </ul>
                <div v-if="archivedCount" class="mt-3 border-t border-slate-100 pt-3">
                    <AppCheckbox v-model="showArchived" :label="`Mostrar arquivados (${archivedCount})`" />
                </div>
            </AppCard>
        </div>

        <AppModal :open="departmentOpen" :title="editingDepartment ? 'Editar departamento' : 'Novo departamento'" @close="departmentOpen = false">
            <form id="department-form" class="space-y-4" novalidate @submit.prevent="saveDepartment">
                <AppAlert v-if="departmentForm.message.value" type="error">{{ departmentForm.message.value }}</AppAlert>
                <AppInput v-model="departmentForm.data.name" label="Nome" required :maxlength="100" placeholder="Ex.: Produção" :error="departmentForm.errors.value.name" />
                <AppInput v-model="departmentForm.data.description" label="Descrição" :maxlength="255" :error="departmentForm.errors.value.description" />
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="departmentOpen = false">Cancelar</AppButton>
                <AppButton type="submit" form="department-form" :loading="departmentForm.processing.value">Guardar</AppButton>
            </template>
        </AppModal>

        <AppModal :open="peopleFor !== null" :title="`Pessoas em ${peopleFor?.name ?? ''}`" @close="peopleFor = null">
            <div v-if="loadingPeople" class="flex justify-center py-8"><AppSpinner class="h-5 w-5 text-brand-700" /></div>
            <template v-else>
                <p class="mb-3 text-sm text-slate-600">Quem escolher aqui sai do departamento onde estava.</p>
                <ul class="max-h-96 space-y-2 overflow-y-auto">
                    <li v-for="m in members" :key="m.id">
                        <AppCheckbox
                            :model-value="chosen.has(m.id)"
                            :label="m.user.name"
                            :description="[m.role.name, departmentOf(m.id) && departmentOf(m.id)!.id !== peopleFor?.id ? `está em ${departmentOf(m.id)!.name}` : null].filter(Boolean).join(' · ')"
                            @update:model-value="(on: boolean | undefined) => toggle(m.id, !!on)"
                        />
                    </li>
                </ul>
            </template>
            <template #footer>
                <AppButton variant="secondary" @click="peopleFor = null">Cancelar</AppButton>
                <AppButton :loading="savingPeople" :disabled="loadingPeople" @click="savePeople">Guardar</AppButton>
            </template>
        </AppModal>

        <AppModal :open="centerOpen" :title="editingCenter ? 'Editar centro de custo' : 'Novo centro de custo'" @close="centerOpen = false">
            <form id="center-form" class="space-y-4" novalidate @submit.prevent="saveCenter">
                <AppAlert v-if="centerForm.message.value" type="error">{{ centerForm.message.value }}</AppAlert>
                <AppSelect v-model="centerForm.data.parent_id" label="Dentro de" :options="parentOptions" :error="centerForm.errors.value.parent_id" />
                <div class="grid gap-4 sm:grid-cols-3">
                    <AppInput
                        v-model="centerForm.data.code"
                        label="Código"
                        :maxlength="30"
                        :placeholder="editingCenter ? '' : 'Automático'"
                        :error="centerForm.errors.value.code"
                    />
                    <AppInput v-model="centerForm.data.name" label="Nome" required :maxlength="150" class="sm:col-span-2" :error="centerForm.errors.value.name" />
                </div>
                <AppCheckbox
                    v-if="editingCenter"
                    v-model="centerForm.data.archived"
                    label="Arquivado"
                    description="Deixa de aparecer para escolher nas obras; as obras que já o têm mantêm-no."
                />
            </form>
            <template #footer>
                <AppButton variant="secondary" @click="centerOpen = false">Cancelar</AppButton>
                <AppButton type="submit" form="center-form" :loading="centerForm.processing.value">Guardar</AppButton>
            </template>
        </AppModal>
    </div>
</template>
