const dateFormatter = new Intl.DateTimeFormat('pt-PT', { day: '2-digit', month: '2-digit', year: 'numeric' });
const dateTimeFormatter = new Intl.DateTimeFormat('pt-PT', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

export function formatDate(value: string | null | undefined): string {
    return value ? dateFormatter.format(new Date(value)) : '—';
}

export function formatDateTime(value: string | null | undefined): string {
    return value ? dateTimeFormatter.format(new Date(value)) : '—';
}

export function formatBytes(bytes: number | null | undefined): string {
    if (bytes === null || bytes === undefined) return '—';
    if (bytes < 1024) return `${bytes} B`;
    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes / 1024;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }
    return `${value.toLocaleString('pt-PT', { maximumFractionDigits: 1 })} ${units[unit]}`;
}

export function formatMoney(cents: number | null | undefined, currency = 'EUR'): string {
    if (cents === null || cents === undefined) return 'Sob consulta';
    return new Intl.NumberFormat('pt-PT', { style: 'currency', currency }).format(cents / 100);
}

export function daysUntil(value: string | null | undefined): number | null {
    if (!value) return null;
    return Math.ceil((new Date(value).getTime() - Date.now()) / 86_400_000);
}

/** Descrição legível dos eventos de auditoria mais comuns. */
const EVENT_LABELS: Record<string, string> = {
    'auth.login': 'Entrada na plataforma',
    'auth.logout': 'Saída da plataforma',
    'auth.failed': 'Tentativa de entrada falhada',
    'auth.lockout': 'Bloqueio por excesso de tentativas',
    'auth.password_reset': 'Password reposta',
    'auth.email_verified': 'Email confirmado',
    'auth.2fa_enabled': '2FA ativada',
    'auth.2fa_disabled': '2FA desativada',
    'auth.2fa_failed': 'Código 2FA errado',
    'auth.2fa_recovery_code_used': 'Código de recuperação usado',
    'auth.tenant_switched': 'Mudança de empresa',
    'tenant.registered': 'Empresa registada',
    'tenant.read_only': 'Empresa em só leitura',
    'tenant.suspended': 'Empresa suspensa',
    'company.updated': 'Dados da empresa alterados',
    'company.security_updated': 'Política de segurança alterada',
    'company.logo_updated': 'Logótipo alterado',
    'company.logo_removed': 'Logótipo removido',
    'membership.updated': 'Utilizador alterado',
    'membership.removed': 'Utilizador removido',
    'invitation.created': 'Convite enviado',
    'invitation.resent': 'Convite reenviado',
    'invitation.revoked': 'Convite anulado',
    'invitation.accepted': 'Convite aceite',
    'role.created': 'Perfil criado',
    'role.updated': 'Perfil alterado',
    'role.reset': 'Perfil reposto',
    'role.deleted': 'Perfil apagado',
    'support.granted': 'Acesso de suporte autorizado',
    'support.revoked': 'Acesso de suporte revogado',
    'support.session_started': 'Suporte entrou na empresa',
    'support.session_ended': 'Suporte saiu da empresa',
    'support.request': 'Consulta pelo suporte',
    'data.export_requested': 'Exportação pedida',
    'data.export_completed': 'Exportação concluída',
    'data.export_downloaded': 'Exportação descarregada',
    'subscription.trial_expired': 'Período experimental terminado',
    'subscription.past_due': 'Pagamento em atraso',
    'subscription.expired': 'Subscrição expirada',
    'platform.tenant_suspended': 'Suspensa pela plataforma',
    'platform.tenant_reactivated': 'Reativada pela plataforma',
    'platform.subscription_updated': 'Subscrição alterada pela plataforma',
    'platform.plan_created': 'Plano criado',
    'platform.plan_updated': 'Plano alterado',
    'user.profile_updated': 'Perfil pessoal alterado',
    'user.password_changed': 'Password alterada',
};

export function eventLabel(event: string): string {
    return EVENT_LABELS[event] ?? event;
}

const SUBSCRIPTION_STATUS: Record<string, string> = {
    trialing: 'Período experimental',
    active: 'Ativa',
    past_due: 'Pagamento em atraso',
    canceled: 'Cancelada',
    expired: 'Expirada',
};

export function subscriptionStatusLabel(status: string | null | undefined): string {
    return status ? (SUBSCRIPTION_STATUS[status] ?? status) : '—';
}
