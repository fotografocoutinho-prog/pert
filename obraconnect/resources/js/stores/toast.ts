import { defineStore } from 'pinia';
import { ref } from 'vue';

export interface Toast {
    id: number;
    type: 'success' | 'error' | 'info';
    message: string;
}

export const useToastStore = defineStore('toast', () => {
    const items = ref<Toast[]>([]);
    let next = 1;

    function push(type: Toast['type'], message: string, timeout = 5000): void {
        const id = next++;
        items.value.push({ id, type, message });
        window.setTimeout(() => dismiss(id), timeout);
    }

    function dismiss(id: number): void {
        items.value = items.value.filter((t) => t.id !== id);
    }

    return {
        items,
        dismiss,
        success: (message: string) => push('success', message),
        error: (message: string) => push('error', message, 8000),
        info: (message: string) => push('info', message),
    };
});
