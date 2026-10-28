<?php

namespace App\Access;

/**
 * Perfis-modelo copiados para cada empresa nova. Cada empresa pode depois
 * editar os seus perfis ou repô-los a partir daqui.
 *
 * Os perfis externos (subempreiteiro, fornecedor, dono de obra) começam sem
 * permissões internas: o acesso deles é dado por obra a partir da fase 2.
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
                'permissions' => ['company.view', 'members.view', 'members.invite', 'roles.view', 'audit.view', 'subscription.view'],
            ],
            'diretor_obra' => [
                'name' => 'Diretor de obra',
                'description' => 'Gestão das obras atribuídas.',
                'permissions' => ['company.view', 'members.view', 'members.invite'],
            ],
            'encarregado' => [
                'name' => 'Encarregado',
                'description' => 'Coordenação das equipas no terreno.',
                'permissions' => ['company.view', 'members.view'],
            ],
            'preparador' => [
                'name' => 'Preparador de obra',
                'description' => 'Preparação, compras e planeamento.',
                'permissions' => ['company.view', 'members.view'],
            ],
            'medidor_orcamentista' => [
                'name' => 'Medidor-orçamentista',
                'description' => 'Medições e orçamentos.',
                'permissions' => ['company.view', 'members.view'],
            ],
            'fiscal' => [
                'name' => 'Fiscal',
                'description' => 'Fiscalização e aprovações.',
                'permissions' => ['company.view'],
            ],
            'coordenador_seguranca' => [
                'name' => 'Coordenador de segurança',
                'description' => 'Segurança e saúde em obra.',
                'permissions' => ['company.view'],
            ],
            'tecnico_qualidade' => [
                'name' => 'Técnico de qualidade',
                'description' => 'Controlo de qualidade e não conformidades.',
                'permissions' => ['company.view'],
            ],
            'administrativo' => [
                'name' => 'Administrativo',
                'description' => 'Gestão administrativa e documental.',
                'permissions' => ['company.view', 'company.update', 'members.view', 'subscription.view'],
            ],
            'trabalhador' => [
                'name' => 'Trabalhador',
                'description' => 'Registo de trabalho no terreno.',
                'permissions' => ['company.view'],
            ],
            'subempreiteiro' => [
                'name' => 'Subempreiteiro',
                'description' => 'Utilizador externo. Acesso dado por obra.',
                'permissions' => [],
            ],
            'fornecedor' => [
                'name' => 'Fornecedor',
                'description' => 'Utilizador externo. Acesso dado por obra.',
                'permissions' => [],
            ],
            'dono_obra' => [
                'name' => 'Dono de obra',
                'description' => 'Utilizador externo. Acesso dado por obra.',
                'permissions' => [],
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
