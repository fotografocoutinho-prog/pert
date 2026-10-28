import { reactive, ref } from 'vue';
import { ApiError } from '@/api/http';
import { useToastStore } from '@/stores/toast';

/**
 * Estado de um formulário: valores, erros de validação (422) por campo e
 * indicador de envio. Erros sem campo aparecem em `message`.
 */
export function useForm<T extends Record<string, unknown>>(initial: T) {
    const data = reactive({ ...initial }) as T;
    const errors = ref<Record<string, string>>({});
    const message = ref<string | null>(null);
    const processing = ref(false);

    function reset(values: Partial<T> = {}): void {
        Object.assign(data, initial, values);
        errors.value = {};
        message.value = null;
    }

    async function submit<R>(action: (payload: T) => Promise<R>, options: { success?: string } = {}): Promise<R | undefined> {
        processing.value = true;
        errors.value = {};
        message.value = null;

        try {
            const result = await action(data);
            if (options.success) useToastStore().success(options.success);
            return result;
        } catch (error) {
            if (error instanceof ApiError) {
                errors.value = Object.fromEntries(Object.entries(error.errors).map(([k, v]) => [k, v[0] ?? '']));
                if (Object.keys(errors.value).length === 0 || error.status !== 422) {
                    message.value = error.message;
                }
            } else {
                message.value = 'Ocorreu um erro inesperado.';
            }
            return undefined;
        } finally {
            processing.value = false;
        }
    }

    return { data, errors, message, processing, submit, reset };
}
