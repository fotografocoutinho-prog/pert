<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Planos iniciais. Os preços são VALORES DE EXEMPLO, sem IVA, e devem ser
 * revistos no backoffice antes do lançamento comercial. Nulo = sob consulta.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'start',
                'name' => 'Start',
                'description' => 'Para pequenas empresas: até 3 obras ativas e funcionalidades essenciais.',
                'price_monthly_cents' => 4900,
                'price_yearly_cents' => 49000,
                'trial_days' => 30,
                'limits' => ['max_users' => 5, 'max_active_projects' => 3, 'storage_mb' => 5120],
                'features' => ['diary', 'documents'],
                'sort_order' => 10,
            ],
            [
                'code' => 'professional',
                'name' => 'Professional',
                'description' => 'Até 15 obras ativas, fiscalização, controlo de custos e relatórios avançados.',
                'price_monthly_cents' => 12900,
                'price_yearly_cents' => 129000,
                'trial_days' => 30,
                'limits' => ['max_users' => 25, 'max_active_projects' => 15, 'storage_mb' => 51200],
                'features' => ['diary', 'documents', 'inspections', 'costs', 'advanced_reports'],
                'sort_order' => 20,
            ],
            [
                'code' => 'business',
                'name' => 'Business',
                'description' => 'Mais obras, inteligência artificial, integrações e automações.',
                'price_monthly_cents' => 29900,
                'price_yearly_cents' => 299000,
                'trial_days' => 30,
                'limits' => ['max_users' => 100, 'max_active_projects' => 60, 'storage_mb' => 204800],
                'features' => ['diary', 'documents', 'inspections', 'costs', 'advanced_reports', 'ai', 'integrations', 'automations'],
                'sort_order' => 30,
            ],
            [
                'code' => 'enterprise',
                'name' => 'Enterprise',
                'description' => 'Preço personalizado, integrações dedicadas, suporte prioritário e requisitos específicos de segurança.',
                'price_monthly_cents' => null,
                'price_yearly_cents' => null,
                'trial_days' => 0,
                'limits' => ['max_users' => null, 'max_active_projects' => null, 'storage_mb' => null],
                'features' => ['diary', 'documents', 'inspections', 'costs', 'advanced_reports', 'ai', 'integrations', 'automations', 'dedicated_database'],
                'sort_order' => 40,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->firstOrCreate(['code' => $plan['code']], $plan + ['currency' => 'EUR', 'is_public' => true, 'is_active' => true]);
        }
    }
}
