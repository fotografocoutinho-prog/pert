<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue';

const props = defineProps<{ open: boolean; title: string; size?: 'md' | 'lg' }>();
const emit = defineEmits<{ close: [] }>();

function onKey(event: KeyboardEvent): void {
    if (event.key === 'Escape') emit('close');
}

watch(
    () => props.open,
    (open) => {
        if (open) document.addEventListener('keydown', onKey);
        else document.removeEventListener('keydown', onKey);
    },
    { immediate: true },
);

onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 p-0 sm:items-center sm:p-4" @click.self="emit('close')">
            <div
                role="dialog"
                aria-modal="true"
                :aria-label="title"
                :class="['max-h-[92vh] w-full overflow-y-auto rounded-t-2xl bg-white shadow-xl sm:rounded-2xl', size === 'lg' ? 'sm:max-w-3xl' : 'sm:max-w-lg']"
            >
                <header class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                    <h2 class="text-lg font-semibold">{{ title }}</h2>
                    <button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100" aria-label="Fechar" @click="emit('close')">✕</button>
                </header>
                <div class="px-5 py-4"><slot /></div>
                <footer v-if="$slots.footer" class="flex flex-col-reverse gap-2 border-t border-slate-100 px-5 py-4 sm:flex-row sm:justify-end">
                    <slot name="footer" />
                </footer>
            </div>
        </div>
    </Teleport>
</template>
