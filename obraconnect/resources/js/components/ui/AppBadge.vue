<script setup lang="ts">
withDefaults(defineProps<{ color?: 'gray' | 'green' | 'amber' | 'red' | 'blue' | 'brand' }>(), { color: 'gray' });

const styles = {
    gray: 'bg-slate-100 text-slate-700',
    green: 'bg-green-100 text-green-800',
    amber: 'bg-amber-100 text-amber-800',
    red: 'bg-red-100 text-red-800',
    blue: 'bg-sky-100 text-sky-800',
    brand: 'bg-brand-100 text-brand-800',
};
</script>

<template>
    <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap', styles[color]]">
        <slot />
    </span>
</template>
