<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { structureApi } from "@/api";
import { ApiError } from "@/api/http";
import type {
    ConstructionElementItem,
    LocationKind,
    Project,
    ProjectLocationItem,
    ProjectStructure,
} from "@/api/types";
import { useForm } from "@/composables/useForm";
import { forgetStructure } from "@/composables/useStructurePlaces";
import { useSessionStore } from "@/stores/session";
import { useToastStore } from "@/stores/toast";
import AppAlert from "@/components/ui/AppAlert.vue";
import AppButton from "@/components/ui/AppButton.vue";
import AppCard from "@/components/ui/AppCard.vue";
import AppInput from "@/components/ui/AppInput.vue";
import AppModal from "@/components/ui/AppModal.vue";
import AppSelect from "@/components/ui/AppSelect.vue";
import AppSpinner from "@/components/ui/AppSpinner.vue";
import AppTextarea from "@/components/ui/AppTextarea.vue";
import EmptyState from "@/components/ui/EmptyState.vue";
import StructureLinksModal, {
    type LinksTarget,
} from "@/components/projects/StructureLinksModal.vue";

const props = defineProps<{ project: Project }>();

const session = useSessionStore();
const toast = useToastStore();

const structure = ref<ProjectStructure | null>(null);
const canManage = computed(
    () =>
        props.project.permissions.includes("structure.manage") &&
        props.project.status !== "archived" &&
        !session.isReadOnly,
);

async function load(): Promise<void> {
    try {
        structure.value = await structureApi.get(props.project.id);
        forgetStructure(props.project.id);
    } catch (e) {
        toast.error(
            e instanceof ApiError
                ? e.message
                : "Não foi possível carregar a estrutura.",
        );
    }
}
onMounted(load);

/** Árvore em lista: cada localização com a sua profundidade, pela ordem de leitura. */
const rows = computed(() => {
    const all = structure.value?.locations ?? [];
    const out: { loc: ProjectLocationItem; depth: number }[] = [];
    const walk = (parent: number | null, depth: number) => {
        for (const loc of all.filter((l) => l.parent_id === parent)) {
            out.push({ loc, depth });
            walk(loc.id, depth + 1);
        }
    };
    walk(null, 0);
    return out;
});
const elementsOf = (id: number) =>
    (structure.value?.elements ?? []).filter((e) => e.location_id === id);

/** "Bloco A › Piso 0 › Cozinha" para escolher onde fica um elemento. */
const locationOptions = computed(() => {
    const byId = new Map(
        (structure.value?.locations ?? []).map((l) => [l.id, l]),
    );
    const label = (l: ProjectLocationItem): string => {
        const parent = l.parent_id ? byId.get(l.parent_id) : undefined;
        return parent ? `${label(parent)} › ${l.name}` : l.name;
    };
    return rows.value.map(({ loc }) => ({ value: loc.id, label: label(loc) }));
});
const typeOptions = computed(() =>
    (structure.value?.element_types ?? []).map((t) => ({
        value: t.value,
        label: `${t.label} (${t.prefix})`,
    })),
);

async function act(
    action: () => Promise<unknown>,
    success?: string,
): Promise<boolean> {
    try {
        await action();
        if (success) toast.success(success);
        return true;
    } catch (e) {
        toast.error(
            e instanceof ApiError
                ? (Object.values(e.errors)[0]?.[0] ?? e.message)
                : "Operação falhou.",
        );
        return false;
    } finally {
        await load();
    }
}

const KIND_LABEL: Record<LocationKind, string> = {
    building: "edifício",
    floor: "piso",
    zone: "zona",
};

async function addLocation(
    kind: LocationKind,
    parent: ProjectLocationItem | null,
): Promise<void> {
    const name = window.prompt(
        `Nome do ${KIND_LABEL[kind]}${parent ? ` em ${parent.name}` : ""}`,
        kind === "building" ? "Edifício" : "",
    );
    if (!name?.trim()) return;
    await act(
        () =>
            structureApi.createLocation(props.project.id, {
                kind,
                name: name.trim(),
                parent_id: parent?.id ?? null,
            }),
        "Criado.",
    );
}

/** Vários pisos de uma vez: "-1 a 3" cria Piso -1, Piso 0, ..., Piso 3. */
async function addFloors(building: ProjectLocationItem): Promise<void> {
    const answer = window.prompt("Criar pisos de… a… (ex.: -1 a 3)", "0 a 2");
    const match = answer?.match(/(-?\d+)\s*(?:a|até|-|–)\s*(-?\d+)/i);
    if (!match) return;
    const [from, to] = [Number(match[1]), Number(match[2])].sort(
        (a, b) => a - b,
    );
    if (to - from > 60)
        return void toast.error("No máximo 60 pisos de cada vez.");
    for (let n = from; n <= to; n++) {
        const ok = await act(() =>
            structureApi.createLocation(props.project.id, {
                kind: "floor",
                name: `Piso ${n}`,
                parent_id: building.id,
            }),
        );
        if (!ok) return;
    }
    toast.success(`${to - from + 1} pisos criados.`);
}

async function rename(loc: ProjectLocationItem): Promise<void> {
    const name = window.prompt("Novo nome", loc.name);
    if (!name?.trim() || name.trim() === loc.name) return;
    await act(
        () =>
            structureApi.updateLocation(props.project.id, loc.id, {
                name: name.trim(),
            }),
        "Nome alterado.",
    );
}

async function removeLocation(loc: ProjectLocationItem): Promise<void> {
    if (
        !window.confirm(
            `Apagar ${loc.kind_label.toLowerCase()} "${loc.name}"? Só é possível se estiver vazio.`,
        )
    )
        return;
    await act(
        () => structureApi.removeLocation(props.project.id, loc.id),
        "Apagado.",
    );
}

// Histórico (o que está ligado a um local ou elemento)
const history = ref<LinksTarget | null>(null);
const historyElement = ref<{
    loc: ProjectLocationItem;
    el: ConstructionElementItem;
} | null>(null);

function openHistory(
    loc: ProjectLocationItem,
    el: ConstructionElementItem | null = null,
): void {
    historyElement.value = el ? { loc, el } : null;
    history.value = el
        ? {
              type: "element",
              id: el.id,
              title: `${el.code} · ${el.name ?? el.element_type_label}`,
          }
        : {
              type: "location",
              id: loc.id,
              title: `${loc.kind_label} ${loc.name}`,
          };
}

function editFromHistory(): void {
    const target = historyElement.value;
    history.value = null;
    if (target) openElement(target.loc, target.el);
}

// Elementos
const elementOpen = ref(false);
const editing = ref<ConstructionElementItem | null>(null);
const form = useForm({
    location_id: null as number | null,
    element_type: "column" as string | null,
    code: "",
    name: "",
    notes: "",
});

function openElement(
    location: ProjectLocationItem | null,
    element: ConstructionElementItem | null = null,
): void {
    editing.value = element;
    form.reset(
        element
            ? {
                  location_id: element.location_id,
                  element_type: element.element_type,
                  code: element.code,
                  name: element.name ?? "",
                  notes: element.notes ?? "",
              }
            : { location_id: location?.id ?? null },
    );
    elementOpen.value = true;
}

async function saveElement(): Promise<void> {
    const payload = {
        location_id: form.data.location_id,
        element_type: form.data.element_type,
        code:
            form.data.code.trim() ||
            (editing.value ? editing.value.code : null),
        name: form.data.name.trim() || null,
        notes: form.data.notes.trim() || null,
    };
    const saved = await form.submit(
        () =>
            editing.value
                ? structureApi.updateElement(
                      props.project.id,
                      editing.value.id,
                      payload,
                  )
                : structureApi.createElement(props.project.id, payload),
        {
            success: editing.value
                ? "Elemento atualizado."
                : "Elemento criado.",
        },
    );
    if (saved) {
        elementOpen.value = false;
        await load();
    }
}

async function removeElement(): Promise<void> {
    if (
        !editing.value ||
        !window.confirm(`Apagar o elemento ${editing.value.code}?`)
    )
        return;
    if (
        await act(
            () =>
                structureApi.removeElement(props.project.id, editing.value!.id),
            "Elemento apagado.",
        )
    )
        elementOpen.value = false;
}
</script>

<template>
    <AppCard
        title="Estrutura da obra"
        description="Edifícios, pisos e zonas, e os elementos construtivos de cada um."
    >
        <div v-if="canManage" class="mb-4">
            <AppButton @click="addLocation('building', null)"
                >+ Edifício</AppButton
            >
        </div>

        <div v-if="!structure" class="flex justify-center py-10">
            <AppSpinner class="h-6 w-6 text-brand-700" />
        </div>
        <EmptyState
            v-else-if="rows.length === 0"
            title="Sem estrutura"
            :description="
                canManage
                    ? 'Comece por criar um edifício (ou, numa obra de um só corpo, um edifício com o nome da obra).'
                    : undefined
            "
        />
        <ul v-else class="divide-y divide-slate-100">
            <li
                v-for="{ loc, depth } in rows"
                :key="loc.id"
                class="py-2.5"
                :style="{ paddingLeft: `${depth * 1.25}rem` }"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="min-w-0">
                        <span
                            class="mr-1 text-xs font-semibold tracking-wide text-slate-500 uppercase"
                            >{{ loc.kind_label }}</span
                        >
                        <span class="font-medium text-slate-900">{{
                            loc.name
                        }}</span>
                    </p>
                    <div class="flex flex-wrap gap-1">
                        <AppButton
                            size="sm"
                            variant="ghost"
                            @click="openHistory(loc)"
                            >Histórico</AppButton
                        >
                        <template v-if="canManage">
                            <AppButton
                                v-if="loc.kind === 'building'"
                                size="sm"
                                variant="ghost"
                                @click="addFloors(loc)"
                                >+ Pisos</AppButton
                            >
                            <AppButton
                                v-if="loc.kind !== 'zone'"
                                size="sm"
                                variant="ghost"
                                @click="addLocation('zone', loc)"
                                >+ Zona</AppButton
                            >
                            <AppButton
                                size="sm"
                                variant="ghost"
                                @click="openElement(loc)"
                                >+ Elemento</AppButton
                            >
                            <AppButton
                                size="sm"
                                variant="ghost"
                                @click="rename(loc)"
                                >Renomear</AppButton
                            >
                            <AppButton
                                size="sm"
                                variant="ghost"
                                class="text-red-700"
                                @click="removeLocation(loc)"
                                >Apagar</AppButton
                            >
                        </template>
                    </div>
                </div>
                <ul
                    v-if="elementsOf(loc.id).length"
                    class="mt-1.5 flex flex-wrap gap-1.5"
                >
                    <li v-for="el in elementsOf(loc.id)" :key="el.id">
                        <button
                            type="button"
                            class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-0.5 text-xs text-slate-700 hover:border-brand-300 disabled:cursor-default"
                            :title="
                                [el.element_type_label, el.name, el.notes]
                                    .filter(Boolean)
                                    .join(' · ')
                            "
                            @click="openHistory(loc, el)"
                        >
                            <strong>{{ el.code }}</strong>
                            {{ el.name ?? el.element_type_label }}
                        </button>
                    </li>
                </ul>
            </li>
        </ul>

        <StructureLinksModal
            :project="project"
            :target="history"
            :can-edit="canManage"
            @close="history = null"
            @edit="editFromHistory"
        />

        <AppModal
            :open="elementOpen"
            :title="editing ? `Elemento ${editing.code}` : 'Novo elemento'"
            @close="elementOpen = false"
        >
            <form
                id="element-form"
                class="space-y-4"
                novalidate
                @submit.prevent="saveElement"
            >
                <AppAlert v-if="form.message.value" type="error">{{
                    form.message.value
                }}</AppAlert>
                <AppSelect
                    v-model="form.data.element_type"
                    label="Tipo"
                    :options="typeOptions"
                    required
                    :error="form.errors.value.element_type"
                />
                <AppSelect
                    v-model="form.data.location_id"
                    label="Localização"
                    :options="locationOptions"
                    required
                    :error="form.errors.value.location_id"
                />
                <AppInput
                    v-model="form.data.code"
                    label="Código"
                    :maxlength="30"
                    :placeholder="editing ? '' : 'Automático (ex.: P1, V3)'"
                    :error="form.errors.value.code"
                />
                <AppInput
                    v-model="form.data.name"
                    label="Designação"
                    :maxlength="150"
                    placeholder="Opcional"
                    :error="form.errors.value.name"
                />
                <AppTextarea
                    v-model="form.data.notes"
                    label="Notas"
                    :rows="2"
                    :maxlength="2000"
                    :error="form.errors.value.notes"
                />
            </form>
            <template #footer>
                <AppButton
                    v-if="editing"
                    variant="ghost"
                    class="mr-auto text-red-700"
                    @click="removeElement"
                    >Apagar</AppButton
                >
                <AppButton variant="secondary" @click="elementOpen = false"
                    >Cancelar</AppButton
                >
                <AppButton
                    type="submit"
                    form="element-form"
                    :loading="form.processing.value"
                    >Guardar</AppButton
                >
            </template>
        </AppModal>
    </AppCard>
</template>
