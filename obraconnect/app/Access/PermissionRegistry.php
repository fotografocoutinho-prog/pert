<?php

namespace App\Access;

/**
 * Catálogo de permissões implementadas, no formato "modulo.acao".
 *
 * Só entram aqui permissões de funcionalidades que existem no código. A matriz
 * completa do produto (fases futuras) está em docs/05-matriz-permissoes.md.
 * Depois de alterar este ficheiro: php artisan obraconnect:sync-permissions
 */
final class PermissionRegistry
{
    /** Dá às permissões de obra do perfil da empresa efeito em todas as obras. */
    public const ALL_PROJECTS = 'projects.all';

    /** Módulos cujas permissões valem obra a obra. */
    private const PROJECT_SCOPED_MODULES = [
        'projects', 'diary', 'documents', 'structure', 'inspections', 'issues',
        'budgets', 'measurements', 'costs', 'purchases', 'workforce', 'equipment', 'planning', 'reports',
    ];

    /** Exceções nesses módulos: valem para a empresa. */
    private const COMPANY_WIDE = [self::ALL_PROJECTS, 'projects.create'];

    /**
     * @return array<string, array{label: string, permissions: array<string, string>}>
     */
    public static function modules(): array
    {
        return [
            'company' => [
                'label' => 'Empresa',
                'permissions' => [
                    'company.view' => 'Ver dados da empresa',
                    'company.update' => 'Editar dados e logótipo da empresa',
                    'security.manage' => 'Definir políticas de segurança (ex.: 2FA obrigatório)',
                ],
            ],
            'members' => [
                'label' => 'Utilizadores',
                'permissions' => [
                    'members.view' => 'Ver utilizadores e convites',
                    'members.invite' => 'Convidar utilizadores',
                    'members.manage' => 'Alterar perfil, suspender e remover utilizadores',
                ],
            ],
            'roles' => [
                'label' => 'Perfis e permissões',
                'permissions' => [
                    'roles.view' => 'Ver perfis e permissões',
                    'roles.manage' => 'Criar e editar perfis',
                ],
            ],
            'audit' => [
                'label' => 'Auditoria',
                'permissions' => [
                    'audit.view' => 'Consultar o registo de auditoria',
                ],
            ],
            'subscription' => [
                'label' => 'Subscrição',
                'permissions' => [
                    'subscription.view' => 'Ver plano, limites e utilização',
                    'subscription.manage' => 'Mudar de plano, pedir a ativação ou a renovação e usar cupões',
                ],
            ],
            'support' => [
                'label' => 'Suporte técnico',
                'permissions' => [
                    'support.manage' => 'Autorizar e revogar acesso do suporte técnico',
                ],
            ],
            'data' => [
                'label' => 'Dados e privacidade',
                'permissions' => [
                    'data.export' => 'Exportar todos os dados da empresa',
                ],
            ],
            'integrations' => [
                'label' => 'Integrações',
                'permissions' => [
                    'integrations.manage' => 'Criar chaves da API e webhooks para ligar outros programas',
                ],
            ],
            'organization' => [
                'label' => 'Organização da empresa',
                'permissions' => [
                    'departments.manage' => 'Gerir departamentos e as pessoas de cada um',
                    'cost_centers.manage' => 'Gerir centros de custo',
                    'specialties.manage' => 'Gerir especialidades (Arquitetura, Estruturas, AVAC...)',
                ],
            ],
            'clients' => [
                'label' => 'Clientes',
                'permissions' => [
                    'clients.view' => 'Ver clientes e os seus contactos',
                    'clients.manage' => 'Criar, editar, arquivar e apagar clientes',
                ],
            ],
            'calendars' => [
                'label' => 'Calendários de trabalho',
                'permissions' => [
                    'calendars.manage' => 'Gerir calendários de trabalho, feriados e paragens',
                ],
            ],
            'projects' => [
                'label' => 'Obras',
                'permissions' => [
                    self::ALL_PROJECTS => 'Acesso a todas as obras (sem esta permissão, só às obras de cuja equipa faz parte)',
                    'projects.view' => 'Ver obras',
                    'projects.create' => 'Criar obras',
                    'projects.update' => 'Editar os dados da obra',
                    'projects.team' => 'Gerir a equipa da obra',
                    'projects.archive' => 'Concluir, arquivar e reabrir obras',
                ],
            ],
            'structure' => [
                'label' => 'Estrutura da obra',
                'permissions' => [
                    'structure.manage' => 'Gerir edifícios, pisos, zonas e elementos construtivos',
                ],
            ],
            'diary' => [
                'label' => 'Diário de obra',
                'permissions' => [
                    'diary.view' => 'Consultar o diário de obra',
                    'diary.write' => 'Escrever registos do diário (rascunho)',
                    'diary.submit' => 'Submeter registos do diário (deixam de poder ser alterados)',
                ],
            ],
            'documents' => [
                'label' => 'Documentos da obra',
                'permissions' => [
                    'documents.view' => 'Consultar e descarregar documentos',
                    'documents.upload' => 'Enviar documentos (e novas versões dos que enviou)',
                    'documents.manage' => 'Gerir pastas, mover e apagar documentos, novas versões de qualquer documento',
                ],
            ],
            'checklists' => [
                'label' => 'Listas de verificação',
                'permissions' => [
                    'checklists.manage' => 'Gerir os modelos de listas de verificação da empresa',
                ],
            ],
            'inspections' => [
                'label' => 'Inspeções',
                'permissions' => [
                    'inspections.request' => 'Preparar e pedir inspeções, pedir reinspeções e cancelar as que ainda não foram decididas',
                    'inspections.perform' => 'Agendar e fazer inspeções: verificar os pontos e juntar evidências',
                    'inspections.approve' => 'Decidir inspeções: aprovar, aprovar com reservas, rejeitar ou devolver para completar',
                    'inspections.release_hold_point' => 'Libertar pontos de paragem (depois de a inspeção ser aprovada)',
                ],
            ],
            'budgets' => [
                'label' => 'Orçamentos',
                'permissions' => [
                    'budgets.view' => 'Consultar orçamentos e a biblioteca de preços',
                    'budgets.manage' => 'Fazer, aprovar e rever orçamentos; gerir a biblioteca de preços',
                ],
            ],
            'measurements' => [
                'label' => 'Medições e autos',
                'permissions' => [
                    'measurements.manage' => 'Registar medições e preparar autos de medição',
                    'certificates.approve' => 'Aprovar autos de medição',
                ],
            ],
            'purchases' => [
                'label' => 'Compras e subempreitadas',
                'permissions' => [
                    'purchases.manage' => 'Gerir fornecedores e compras',
                    'subcontractors.manage' => 'Gerir subempreiteiros e contratos de subempreitada',
                ],
            ],
            'workforce' => [
                'label' => 'Trabalhadores e horas',
                'permissions' => [
                    'workforce.view' => 'Consultar trabalhadores e horas',
                    'workforce.manage' => 'Gerir a lista de trabalhadores e o custo/hora',
                    'time.register_own' => 'Registar as próprias horas',
                    'time.register_team' => 'Registar horas da equipa',
                    'time.approve' => 'Aprovar e rejeitar horas',
                    'time.export_payroll' => 'Exportar horas para processamento salarial',
                ],
            ],
            'equipment' => [
                'label' => 'Equipamentos',
                'permissions' => [
                    'equipment.manage' => 'Gerir equipamentos, utilização em obra e manutenções',
                ],
            ],
            'costs' => [
                'label' => 'Custos e finanças',
                'permissions' => [
                    'costs.view' => 'Consultar o mapa de custos da obra',
                    'costs.manage' => 'Lançar custos e definir o teto de custo',
                    'finance.view' => 'Ver o painel financeiro (faturado, custo e margem)',
                ],
            ],
            'supplier_docs' => [
                'label' => 'Faturas e guias de fornecedores',
                'permissions' => [
                    'supplier_docs.manage' => 'Receber, rever e lançar as faturas e guias de fornecedores de todas as obras (caixa da empresa)',
                ],
            ],
            'invoicing' => [
                'label' => 'Pré-faturação',
                'permissions' => [
                    'invoicing.view' => 'Consultar pré-faturas (documentos internos, sem valor fiscal)',
                    'invoicing.manage' => 'Preparar e exportar pré-faturas e registar a fatura oficial',
                ],
            ],
            'planning' => [
                'label' => 'Planeamento',
                'permissions' => [
                    'planning.view' => 'Consultar o planeamento (Gantt, caminho crítico)',
                    'planning.manage' => 'Gerir atividades, precedências e avanço',
                ],
            ],
            'reports' => [
                'label' => 'Relatórios',
                'permissions' => [
                    'reports.export' => 'Exportar relatórios (orçamento, custos, horas) em CSV',
                ],
            ],
            'issues' => [
                'label' => 'Ocorrências e não conformidades',
                'permissions' => [
                    'issues.view' => 'Consultar ocorrências e não conformidades',
                    'issues.create' => 'Registar ocorrências e não conformidades, comentar e juntar fotografias',
                    'issues.resolve' => 'Resolver as ocorrências de que é responsável',
                    'issues.validate' => 'Validar e fechar, reabrir e anular ocorrências; mudar responsável e prazo de qualquer uma',
                ],
            ],
        ];
    }

    /**
     * Permissão de obra: decidida pelo perfil na equipa da obra ou, com projects.all,
     * pelo perfil da empresa (ver AccessResolver::permissionsForProject).
     */
    public static function isProjectScoped(string $key): bool
    {
        return in_array(self::moduleOf($key), self::PROJECT_SCOPED_MODULES, true)
            && ! in_array($key, self::COMPANY_WIDE, true);
    }

    /** @return array<string, string> chave => descrição */
    public static function all(): array
    {
        return array_merge(...array_column(self::modules(), 'permissions'));
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    public static function moduleOf(string $key): string
    {
        foreach (self::modules() as $module => $definition) {
            if (array_key_exists($key, $definition['permissions'])) {
                return $module;
            }
        }

        return explode('.', $key)[0];
    }
}
