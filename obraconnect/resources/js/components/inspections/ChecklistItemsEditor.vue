<script setup lang="ts">
import type { ChecklistItemDef } from '@/api/types';
import { emptyChecklistItem } from '@/utils/inspections';

/** Lista de pontos a verificar, com as evidências que cada um exige. Usado nos modelos e nos rascunhos de inspeção. */
const items = defineModel<ChecklistItemDef[]>({ required: true });
defineProps<{ errors?: Record<string, string> }>();

function add(): void {
    items.value = [...items.value, emptyChecklistItem()];
}

function remove(index: number): void {
    items.value = items.value.filter((_, i) => i !== index);
}

function move(index: number, delta: number): void {
    const next = [...items.value];
    const [item] = next.splice(index, 1);
    next.splice(index + delta, 0, item!);
    items.value = next;
}

/** Campo numérico vazio fica nulo (o servidor não aceita texto vazio como número). */
function toNumber(value: string): number | null {
    const n = Number(value.replace(',', '.'));
    return value.trim() === '' || Number.isNaN(n) ? null : n;
}

const field = 'block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm';
const small = 'w-20 rounded border border-slate-300 bg-white px-2 py-1 text-sm';
</script>

<template>
    <div>
        <ol class="space-y-3">
            <li v-for="(item, i) in items" :key="i" class="rounded-lg border border-slate-200 p-3">
                <div class="flex items-start gap-2">
                    <span class="mt-2 w-6 shrink-0 text-right text-sm font-semibold text-slate-500">{{ i + 1 }}.</span>
                    <div class="min-w-0 flex-1 space-y-2">
                        <textarea
                            v-model="item.text"
                            rows="2"
                            maxlength="500"
                            :class="field"
                            placeholder="O que se verifica (ex.: Armaduras conforme projeto)"
                            :aria-label="`Ponto ${i + 1}`"
                        />
                        <p v-if="errors?.[`items.${i}.text`]" class="text-xs text-red-700">{{ errors[`items.${i}.text`] }}</p>
                        <input
                            :value="item.guidance ?? ''"
                            maxlength="500"
                            :class="field"
                            placeholder="Indicações para quem inspeciona (opcional)"
                            :aria-label="`Indicações do ponto ${i + 1}`"
                            @input="item.guidance = ($event.target as HTMLInputElement).value || null"
                        />
                        <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                            <label class="inline-flex items-center gap-2">
                                <input v-model="item.requires_photo" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-700" :aria-label="`Ponto ${i + 1}: exige fotografia`" />
                                Exige fotografia
                            </label>
                            <label v-if="item.requires_photo" class="inline-flex items-center gap-2 text-slate-600">
                                mínimo
                                <input v-model.number="item.min_photos" type="number" min="1" max="20" :class="small" :aria-label="`Fotografias mínimas do ponto ${i + 1}`" />
                            </label>
                            <label class="inline-flex items-center gap-2">
                                <input v-model="item.requires_measure" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-700" :aria-label="`Ponto ${i + 1}: exige medição`" />
                                Exige medição
                            </label>
                        </div>
                        <div v-if="item.requires_measure" class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-slate-600">
                            <label class="inline-flex items-center gap-2">
                                unidade
                                <input
                                    :value="item.measure_unit ?? ''"
                                    maxlength="20"
                                    :class="small"
                                    placeholder="mm"
                                    :aria-label="`Unidade do ponto ${i + 1}`"
                                    @input="item.measure_unit = ($event.target as HTMLInputElement).value || null"
                                />
                            </label>
                            <label class="inline-flex items-center gap-2">
                                mínimo
                                <input
                                    :value="item.measure_min ?? ''"
                                    inputmode="decimal"
                                    :class="small"
                                    :aria-label="`Mínimo do ponto ${i + 1}`"
                                    @input="item.measure_min = toNumber(($event.target as HTMLInputElement).value)"
                                />
                            </label>
                            <label class="inline-flex items-center gap-2">
                                máximo
                                <input
                                    :value="item.measure_max ?? ''"
                                    inputmode="decimal"
                                    :class="small"
                                    :aria-label="`Máximo do ponto ${i + 1}`"
                                    @input="item.measure_max = toNumber(($event.target as HTMLInputElement).value)"
                                />
                            </label>
                            <span class="text-xs text-slate-500">Limites opcionais: um valor fora deles fica assinalado.</span>
                        </div>
                        <p v-if="errors?.[`items.${i}.measure_max`]" class="text-xs text-red-700">{{ errors[`items.${i}.measure_max`] }}</p>
                    </div>
                    <div class="flex shrink-0 flex-col gap-1">
                        <button type="button" class="rounded px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 disabled:opacity-30" :disabled="i === 0" :aria-label="`Subir o ponto ${i + 1}`" @click="move(i, -1)">↑</button>
                        <button
                            type="button"
                            class="rounded px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 disabled:opacity-30"
                            :disabled="i === items.length - 1"
                            :aria-label="`Descer o ponto ${i + 1}`"
                            @click="move(i, 1)"
                        >
                            ↓
                        </button>
                        <button type="button" class="rounded px-2 py-1 text-xs text-red-700 hover:bg-red-50" :aria-label="`Retirar o ponto ${i + 1}`" @click="remove(i)">✕</button>
                    </div>
                </div>
            </li>
        </ol>
        <p v-if="errors?.items" class="mt-2 text-sm text-red-700">{{ errors.items }}</p>
        <button type="button" class="mt-3 text-sm font-semibold text-brand-700 hover:underline" @click="add">+ Juntar ponto</button>
    </div>
</template>
