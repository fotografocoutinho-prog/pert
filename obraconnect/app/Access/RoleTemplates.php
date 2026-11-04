<?php

namespace App\Access;

/**
 * Perfis-modelo copiados para cada empresa nova. Cada empresa pode depois
 * editar os seus perfis ou repô-los a partir daqui.
 *
 * Permissões de obra (projects.*, diary.*) só valem em todas as obras para perfis
 * com projects.all; nos outros valem apenas nas obras de cuja equipa a pessoa faz
 * parte. Por isso os perfis externos (subempreiteiro, fornecedor, dono de obra)
 * podem ter "ver obras" sem verem nada além das obras para onde forem chamados.
 */
final class RoleTemplates
{
    /**
     * @return array<string, array{name: string, description: string, permissions: list<string>|'*'}>
     */
    public static function all(): array
    {
        return [
            'admin' => [
                'name' => 'Administrador da empresa',
                'description' => 'Acesso total à empresa. Não pode ser editado.',
                'permissions' => '*',
            ],
            'diretor_tecnico' => [
                'name' => 'Diretor técnico',
                'description' => 'Responsável técnico da empresa.',
                'permissions' => [
                    'company.view', 'members.view', 'members.invite', 'roles.view', 'audit.view', 'subscription.view',
                    'clients.view', 'clients.manage', 'calendars.manage', 'cost_centers.manage',
                    'projects.all', 'projects.view', 'projects.create', 'projects.update', 'projects.team', 'projects.archive', 'structure.manage',
                    'diary.view', 'diary.write', 'diary.submit',
                    'documents.view', 'documents.upload', 'documents.manage',
                ],
            ],
            'diretor_obra' => [
                'name' => 'Diretor de obra',
                'description' => 'Gestão das obras atribuídas.',
                'permissions' => [
                    'company.view', 'members.view', 'members.invite', 'clients.view',
                    'projects.view', 'projects.update', 'projects.team', 'structure.manage', 'diary.view', 'diary.write', 'diary.submit',
                    'documents.view', 'documents.upload', 'documents.manage',
                ],
            ],
            'encarregado' => [
                'name' => 'Encarregado',
                'description' => 'Coordenação das equipas no terreno.',
                'permissions' => ['company.view', 'members.view', 'projects.view', 'diary.view', 'diary.write', 'diary.submit', 'documents.view', 'documents.upload'],
            ],
            'preparador' => [
                'name' => 'Preparador de obra',
                'description' => 'Preparação, compras e planeamento.',
                'permissions' => ['company.view', 'members.view', 'clients.view', 'projects.view', 'projects.update', 'structure.manage', 'diary.view', 'documents.view', 'documents.upload', 'documents.manage'],
            ],
            'medidor_orcamentista' => [
                'name' => 'Medidor-orçamentista',
                'description' => 'Medições e orçamentos.',
                'permissions' => ['company.view', 'members.view', 'clients.view', 'projects.all', 'projects.view', 'structure.manage', 'documents.view', 'documents.upload'],
            ],
            'fiscal' => [
                'name' => 'Fiscal',
                'description' => 'Fiscalização e aprovações.',
                'permissions' => ['company.view', 'projects.view', 'diary.view', 'documents.view', 'documents.upload'],
            ],
            'coordenador_seguranca' => [
                'name' => 'Coordenador de segurança',
                'description' => 'Segurança e saúde em obra.',
                'permissions' => ['company.view', 'projects.view', 'diary.view', 'documents.view', 'documents.upload'],
            ],
            'tecnico_qualidade' => [
                'name' => 'Técnico de qualidade',
                'description' => 'Controlo de qualidade e não conformidades.',
                'permissions' => ['company.view', 'projects.view', 'diary.view', 'documents.view', 'documents.upload'],
            ],
            'administrativo' => [
                'name' => 'Administrativo',
                'description' => 'Gestão administrativa e documental.',
                'permissions' => ['company.view', 'company.update', 'members.view', 'subscription.view', 'clients.view', 'clients.manage', 'calendars.manage', 'departments.manage', 'cost_centers.manage', 'projects.all', 'projects.view', 'documents.view', 'documents.upload', 'documents.manage'],
            ],
            'trabalhador' => [
                'name' => 'Trabalhador',
                'description' => 'Registo de trabalho no terreno.',
                'permissions' => ['company.view', 'projects.view', 'documents.view'],
            ],
            'subempreiteiro' => [
                'name' => 'Subempreiteiro',
                'description' => 'Utilizador externo. Acesso dado por obra.',
                'permissions' => ['projects.view', 'documents.view', 'documents.upload'],
            ],
            'fornecedor' => [
                'name' => 'Fornecedor',
                'description' => 'Utilizador externo. Acesso dado por obra.',
                'permissions' => ['projects.view', 'documents.view', 'documents.upload'],
            ],
            'dono_obra' => [
                'name' => 'Dono de obra',
                'description' => 'Utilizador externo. Acesso dado por obra.',
                'permissions' => ['projects.view', 'diary.view', 'documents.view'],
            ],
        ];
    }

    /** @return list<string> */
    public static function permissionsFor(string $templateKey): array
    {
        $permissions = self::all()[$templateKey]['permissions'] ?? [];

        return $permissions === '*' ? PermissionRegistry::keys() : $permissions;
    }
}
