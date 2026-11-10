<?php

namespace App\Integrations;

use App\Billing\PlanLimits;
use App\Exceptions\ApiException;
use App\Models\Tenant;

/**
 * Âmbitos das chaves de API e eventos dos webhooks. As integrações fazem parte dos planos com a
 * funcionalidade "integrations" (Business e Enterprise).
 */
final class Integrations
{
    public const FEATURE = 'integrations';

    /** Âmbito => descrição */
    public const SCOPES = [
        'projects:read' => 'Ler obras, custos, previsões e autos',
        'invoicing:read' => 'Ler pré-faturas (para o programa de faturação)',
        'supplier-documents:read' => 'Ler faturas e guias de fornecedores',
        'supplier-documents:write' => 'Enviar faturas e guias de fornecedores',
    ];

    /** Evento => descrição */
    public const EVENTS = [
        'certificate.approved' => 'Auto de medição aprovado',
        'certificate.proposed' => 'Auto proposto por um subempreiteiro no portal',
        'invoice_draft.ready' => 'Pré-fatura pronta para faturar',
        'supplier_document.read' => 'Fatura ou guia de fornecedor lida pelo DocIntel',
        'supplier_document.posted' => 'Fatura ou guia de fornecedor lançada',
        'alert.opened' => 'Alerta automático aberto numa obra',
    ];

    public static function enabledFor(Tenant $tenant): bool
    {
        return app(PlanLimits::class)->hasFeature($tenant, self::FEATURE);
    }

    public static function assertEnabled(Tenant $tenant): void
    {
        if (! self::enabledFor($tenant)) {
            throw new ApiException('A API e os webhooks fazem parte dos planos Business e Enterprise.', 'PLAN_FEATURE_REQUIRED', 402, ['feature' => self::FEATURE]);
        }
    }
}
