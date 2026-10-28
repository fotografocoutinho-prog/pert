/** Só aceita caminhos relativos da própria aplicação (evita redirecionamentos abertos). */
export function safeRedirect(value: unknown): string | null {
    if (typeof value !== 'string') return null;
    if (!value.startsWith('/') || value.startsWith('//') || value.startsWith('/\\')) return null;
    return value;
}
