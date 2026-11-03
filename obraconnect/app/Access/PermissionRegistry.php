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
    private const PROJECT_SCOPED_MODULES = ['projects', 'diary', 'documents'];

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
