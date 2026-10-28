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
        ];
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
