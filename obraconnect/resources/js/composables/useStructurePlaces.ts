import { computed, ref, type Ref } from 'vue';
import { structureApi } from '@/api';
import type { ProjectLocationItem, ProjectStructure } from '@/api/types';

/** Uma cópia por obra durante a sessão da página (a estrutura muda pouco). */
const cache = new Map<number, Promise<ProjectStructure>>();

/** Chamar depois de alterar a estrutura, para os outros ecrãs a voltarem a pedir. */
export function forgetStructure(projectId: number): void {
    cache.delete(projectId);
}

/**
 * Opções de "Onde" (local ou elemento da estrutura) para linhas do diário, fotografias e
 * documentos. O valor do <select> é "L:<id>" ou "E:<id>"; vazio = sem local.
 */
export function useStructurePlaces(projectId: Ref<number> | number, options: { fresh?: boolean } = {}) {
    const structure = ref<ProjectStructure | null>(null);
    const id = () => (typeof projectId === 'number' ? projectId : projectId.value);

    async function load(fresh = options.fresh ?? false): Promise<void> {
        if (fresh || !cache.has(id())) cache.set(id(), structureApi.get(id()));
        try {
            structure.value = await cache.get(id())!;
        } catch {
            cache.delete(id());
            structure.value = { locations: [], elements: [], element_types: [] };
        }
    }

    const pathOf = computed(() => {
        const byId = new Map((structure.value?.locations ?? []).map((l) => [l.id, l]));
        const path = (l: ProjectLocationItem): string => {
            const parent = l.parent_id ? byId.get(l.parent_id) : undefined;
            return parent ? `${path(parent)} › ${l.name}` : l.name;
        };
        return (locationId: number | null | undefined) => {
            const l = locationId ? byId.get(locationId) : undefined;
            return l ? path(l) : '';
        };
    });

    const placeOptions = computed(() => {
        const s = structure.value;
        if (!s) return [];
        const locations = s.locations
            .map((l) => ({ value: `L:${l.id}`, label: pathOf.value(l.id) }))
            .sort((a, b) => a.label.localeCompare(b.label, 'pt', { numeric: true }));
        const elements = s.elements
            .map((e) => ({ value: `E:${e.id}`, label: `${e.code} · ${e.name ?? e.element_type_label} — ${pathOf.value(e.location_id)}` }))
            .sort((a, b) => a.label.localeCompare(b.label, 'pt', { numeric: true }));
        return [...locations, ...elements];
    });

    const hasStructure = computed(() => placeOptions.value.length > 0);

    function toValue(locationId: number | null | undefined, elementId: number | null | undefined): string {
        return elementId ? `E:${elementId}` : locationId ? `L:${locationId}` : '';
    }

    function toIds(value: string): { location_id: number | null; element_id: number | null } {
        const [kind, raw] = value.split(':');
        const n = Number(raw);
        return { location_id: kind === 'L' ? n : null, element_id: kind === 'E' ? n : null };
    }

    /** Texto curto para mostrar ("P1 · Pilar — Bloco A › Piso 0" ou "Bloco A › Piso 0"). */
    function labelOf(locationId: number | null | undefined, elementId: number | null | undefined): string {
        return placeOptions.value.find((o) => o.value === toValue(locationId, elementId))?.label ?? '';
    }

    return { structure, load, placeOptions, hasStructure, toValue, toIds, labelOf };
}
