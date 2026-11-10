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
    'department.created': 'Departamento criado',
    'department.updated': 'Departamento alterado',
    'department.deleted': 'Departamento apagado',
    'department.members_changed': 'Pessoas do departamento alteradas',
    'cost_center.created': 'Centro de custo criado',
    'cost_center.updated': 'Centro de custo alterado',
    'cost_center.archived': 'Centro de custo arquivado',
    'cost_center.restored': 'Centro de custo reativado',
    'cost_center.deleted': 'Centro de custo apagado',
    'specialty.created': 'Especialidade criada',
    'specialty.updated': 'Especialidade alterada',
    'specialty.archived': 'Especialidade arquivada',
    'specialty.restored': 'Especialidade reativada',
    'specialty.deleted': 'Especialidade apagada',
    'specialty.defaults_added': 'Lista habitual de especialidades juntada',
    'checklist.created': 'Lista de verificação criada',
    'checklist.updated': 'Lista de verificação alterada',
    'checklist.archived': 'Lista de verificação arquivada',
    'checklist.restored': 'Lista de verificação reativada',
    'checklist.deleted': 'Lista de verificação apagada',
    'inspection.created': 'Inspeção criada',
    'inspection.deleted': 'Rascunho de inspeção apagado',
    'inspection.request': 'Inspeção pedida',
    'inspection.schedule': 'Inspeção agendada',
    'inspection.start': 'Inspeção iniciada',
    'inspection.submit': 'Inspeção submetida para apreciação',
    'inspection.return': 'Inspeção devolvida para completar',
    'inspection.approve': 'Inspeção aprovada',
    'inspection.approve_with_reservations': 'Inspeção aprovada com reservas',
    'inspection.reject': 'Inspeção rejeitada',
    'inspection.cancel': 'Inspeção cancelada',
    'inspection.reinspection': 'Reinspeção pedida',
    'inspection.photo_added': 'Evidência junta a uma inspeção',
    'inspection.hold_released': 'Ponto de paragem libertado',
    'inspection.report': 'Relatório de inspeção gerado (PDF)',
    'issue.created': 'Ocorrência registada',
    'issue.updated': 'Ocorrência alterada',
    'issue.start': 'Ocorrência em resolução',
    'issue.resolve': 'Ocorrência resolvida',
    'issue.close': 'Ocorrência validada e fechada',
    'issue.reopen': 'Ocorrência reaberta',
    'issue.cancel': 'Ocorrência anulada',
    'issue.escalated': 'Ocorrência escalonada (prazo ultrapassado)',
    'issue.photo_added': 'Fotografia junta a uma ocorrência',
    'issue.photo_removed': 'Fotografia retirada de uma ocorrência',
    'inspection.photo_removed': 'Evidência retirada de uma inspeção',
    'client.created': 'Cliente criado',
    'client.updated': 'Cliente alterado',
    'client.archived': 'Cliente arquivado',
    'client.restored': 'Cliente reativado',
    'client.deleted': 'Cliente apagado',
    'calendar.created': 'Calendário criado',
    'calendar.updated': 'Calendário alterado',
    'calendar.made_default': 'Calendário por omissão mudado',
    'calendar.deleted': 'Calendário apagado',
    'calendar.days_off_added': 'Feriado ou paragem marcados',
    'calendar.days_off_removed': 'Feriado ou paragem retirados',
    'calendar.national_holidays_added': 'Feriados nacionais juntados',
    'support.granted': 'Acesso de suporte autorizado',
    'support.revoked': 'Acesso de suporte revogado',
    'support.session_started': 'Suporte entrou na empresa',
    'support.session_ended': 'Suporte saiu da empresa',
    'support.request': 'Consulta pelo suporte',
    'integrations.key_created': 'Chave da API criada',
    'integrations.key_revoked': 'Chave da API revogada',
    'integrations.webhook_created': 'Webhook criado',
    'integrations.webhook_updated': 'Webhook alterado',
    'integrations.webhook_deleted': 'Webhook apagado',
    'integrations.webhook_secret_rotated': 'Segredo do webhook trocado',
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
    'price.resource_created': 'Recurso criado na biblioteca de preços',
    'price.article_created': 'Artigo criado na biblioteca de preços',
    'budget.created': 'Orçamento criado',
    'budget.imported': 'Orçamento importado de folha de cálculo',
    'budget.approved': 'Orçamento aprovado',
    'budget.revised': 'Revisão de orçamento criada',
    'budget.deleted': 'Rascunho de orçamento apagado',
    'measurement.created': 'Medição registada',
    'certificate.created': 'Auto de medição criado',
    'certificate.submitted': 'Auto de medição submetido',
    'certificate.approved': 'Auto de medição aprovado',
    'partner.created': 'Fornecedor/subempreiteiro criado',
    'contract.created': 'Subempreitada criada',
    'contract.activate': 'Subempreitada adjudicada',
    'contract.close': 'Subempreitada fechada',
    'purchase.created': 'Compra criada',
    'purchase.approve': 'Compra aprovada',
    'purchase.receive': 'Compra recebida',
    'purchase.invoice': 'Fatura de compra registada',
    'purchase.cancel': 'Compra cancelada',
    'worker.created': 'Trabalhador criado',
    'worker.updated': 'Trabalhador alterado',
    'time.approve': 'Horas aprovadas',
    'time.reject': 'Horas rejeitadas',
    'time.payroll_exported': 'Horas exportadas para salários',
    'equipment.created': 'Equipamento criado',
    'costs.ceiling_updated': 'Teto de custo alterado',
    'costs.entry_created': 'Lançamento de custo registado',
    'costs.entry_deleted': 'Lançamento de custo apagado',
    'planning.progress': 'Avanço de atividade registado',
    'invoice_draft.created': 'Pré-fatura criada',
    'invoice_draft.ready': 'Pré-fatura pronta a faturar',
    'invoice_draft.reopen': 'Pré-fatura de volta a rascunho',
    'invoice_draft.invoice': 'Fatura oficial registada na pré-fatura',
    'invoice_draft.cancel': 'Pré-fatura anulada',
    'invoice_draft.exported': 'Pré-faturas exportadas',
    'supplier_document.uploaded': 'Documento de fornecedor recebido',
    'supplier_document.updated': 'Documento de fornecedor alterado',
    'supplier_document.read': 'Documento de fornecedor mandado ler de novo',
    'supplier_document.posted': 'Documento de fornecedor lançado',
    'supplier_document.unposted': 'Lançamento de documento de fornecedor desfeito',
    'supplier_document.rejected': 'Documento de fornecedor rejeitado',
    'supplier_document.reopened': 'Documento de fornecedor reaberto',
    'supplier_document.deleted': 'Documento de fornecedor apagado',
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
