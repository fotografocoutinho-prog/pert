/**
 * Caminho base da instalação, lido da meta "app-base" gerada pelo servidor.
 * Vazio na raiz do domínio; por exemplo "/obras/obraconnect" numa subpasta.
 */
export const BASE_PATH = (document.querySelector('meta[name="app-base"]')?.getAttribute('content') ?? '').replace(/\/+$/, '');

/** URL absoluto no servidor para um caminho da aplicação (ex.: "/api/v1/me"). */
export function appUrl(path: string): string {
    return BASE_PATH + (path.startsWith('/') ? path : `/${path}`);
}
