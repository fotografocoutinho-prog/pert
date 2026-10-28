<script setup lang="ts">
import { computed } from 'vue';
import AppSpinner from './AppSpinner.vue';

const props = withDefaults(
    defineProps<{
        variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
        size?: 'sm' | 'md' | 'lg';
        type?: 'button' | 'submit';
        loading?: boolean;
        disabled?: boolean;
        block?: boolean;
    }>(),
    { variant: 'primary', size: 'md', type: 'button', loading: false, disabled: false, block: false },
);

const classes = computed(() => [
    'inline-flex items-center justify-center gap-2 rounded-lg font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60',
    {
        sm: 'px-3 py-1.5 text-sm',
        md: 'px-4 py-2.5 text-sm',
        lg: 'px-5 py-3.5 text-base',
    }[props.size],
    {
        primary: 'bg-brand-700 text-white hover:bg-brand-800',
        secondary: 'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50',
        danger: 'bg-red-700 text-white hover:bg-red-800',
        ghost: 'text-slate-700 hover:bg-slate-100',
    }[props.variant],
    props.block ? 'w-full' : '',
]);
</script>

<template>
    <button :type="type" :class="classes" :disabled="disabled || loading">
        <AppSpinner v-if="loading" class="h-4 w-4" />
        <slot />
    </button>
</template>
