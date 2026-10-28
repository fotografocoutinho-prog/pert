import axios, { AxiosError, type AxiosRequestConfig } from 'axios';
import type { ApiErrorBody } from './types';

/**
 * Cliente HTTP da SPA. Sessão por cookie (Sanctum) no mesmo domínio:
 * nenhum token é guardado no browser. O Laravel lê o cookie XSRF-TOKEN.
 */
export const http = axios.create({
    baseURL: '/',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
    timeout: 30000,
});

export class ApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly code?: string,
        public readonly errors: Record<string, string[]> = {},
        public readonly meta: Record<string, unknown> = {},
    ) {
        super(message);
    }

    firstError(field: string): string | undefined {
        return this.errors[field]?.[0];
    }
}

let csrfReady: Promise<unknown> | null = null;

export function ensureCsrfCookie(force = false): Promise<unknown> {
    if (force || !csrfReady) {
        csrfReady = http.get('/sanctum/csrf-cookie').catch((e) => {
            csrfReady = null;
            throw e;
        });
    }
    return csrfReady;
}

type Listener = (error: ApiError) => void;
const listeners: Listener[] = [];

/** Permite ao router reagir a 401 (sessão terminada) sem dependências circulares. */
export function onApiError(listener: Listener): void {
    listeners.push(listener);
}

function normalize(error: AxiosError<ApiErrorBody>): ApiError {
    if (!error.response) {
        return new ApiError('Sem ligação ao servidor. Verifique a rede e tente novamente.', 0, 'NETWORK');
    }

    const { status, data } = error.response;
    const fallback = status >= 500 ? 'Ocorreu um erro no servidor. Tente novamente.' : 'Pedido inválido.';

    return new ApiError(data?.message || fallback, status, data?.code, data?.errors ?? {}, data?.meta ?? {});
}

http.interceptors.response.use(
    (response) => response,
    async (error: AxiosError<ApiErrorBody>) => {
        const config = error.config as (AxiosRequestConfig & { _csrfRetried?: boolean }) | undefined;

        // 419: token CSRF expirado. Renova e repete uma vez.
        if (error.response?.status === 419 && config && !config._csrfRetried) {
            config._csrfRetried = true;
            await ensureCsrfCookie(true);
            return http.request(config);
        }

        const apiError = normalize(error);
        listeners.forEach((listener) => listener(apiError));
        return Promise.reject(apiError);
    },
);

/** Extrai `data` das respostas JSON da API. */
export async function getData<T>(url: string, params?: Record<string, unknown>): Promise<T> {
    const response = await http.get<{ data: T }>(url, { params });
    return response.data.data;
}
