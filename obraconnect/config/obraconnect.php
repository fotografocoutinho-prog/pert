<?php

return [

    /*
    | Versão da aplicação. Lida do ficheiro VERSION na raiz para o ZIP de release
    | e a API reportarem sempre o mesmo valor.
    */
    'version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: 'dev',

    'trial' => [
        // Plano atribuído no registo de uma nova empresa.
        'plan_code' => env('OBRA_TRIAL_PLAN', 'professional'),
        // Usado se o plano não tiver trial_days definido.
        'fallback_days' => (int) env('OBRA_TRIAL_DAYS', 30),
    ],

    // Ciclo de vida quando a subscrição deixa de estar válida.
    'lifecycle' => [
        'past_due_grace_days' => (int) env('OBRA_PAST_DUE_GRACE_DAYS', 14),
        'read_only_days_before_suspension' => (int) env('OBRA_READ_ONLY_DAYS', 30),
    ],

    'invitations' => [
        'expires_days' => 7,
    ],

    'support' => [
        'max_grant_hours' => 72,
    ],

    'files' => [
        'disk' => env('OBRA_PRIVATE_DISK', 'private'),
        'logo_max_kb' => 2048,
        // Fotografias de obra: o original fica intacto; as versões reduzidas são geradas em fila.
        'photo_max_mb' => (int) env('OBRA_PHOTO_MAX_MB', 20),
        'photos_per_entry' => 30,
        'photo_preview_px' => 1600,
        'photo_thumb_px' => 400,
        // Acima disto o GD não cabe na memória de um alojamento partilhado: fica sem miniatura.
        'photo_max_megapixels' => 50,
        // Documentos da obra (projetos, peças desenhadas, cadernos de encargos...).
        'document_max_mb' => (int) env('OBRA_DOCUMENT_MAX_MB', 50),
        'document_extensions' => [
            'pdf', 'dwg', 'dxf', 'ifc', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods',
            'txt', 'csv', 'jpg', 'jpeg', 'png', 'webp', 'zip',
        ],
    ],

    'exports' => [
        'retention_hours' => 48,
    ],

    // Leitura de faturas e guias de fornecedores pelo CODA DocIntel. Sem endereço ou sem chave, os
    // documentos entram na mesma e preenchem-se à mão. A chave cria-se no DocIntel (POST /api/v1/auth/api-keys com o administrador).
    'docintel' => [
        'url' => rtrim((string) env('DOCINTEL_URL', ''), '/'),
        'key' => env('DOCINTEL_KEY'),
        'timeout' => (int) env('DOCINTEL_TIMEOUT', 120),
        // O DocIntel em cPanel aceita até MAX_UPLOAD_MB = 25.
        'max_mb' => (int) env('DOCINTEL_MAX_MB', 25),
    ],

    // API pública (chaves "oc_…") e webhooks. Os webhooks só vão para endereços https públicos; as duas
    // opções abaixo existem para testes locais e nunca se ligam em produção.
    'integrations' => [
        'allow_http' => (bool) env('WEBHOOKS_ALLOW_HTTP', false),
        'block_private_networks' => (bool) env('WEBHOOKS_BLOCK_PRIVATE_NETWORKS', true),
        'rate_per_minute' => (int) env('PUBLIC_API_RATE_PER_MINUTE', 60),
    ],

    'security' => [
        'force_https' => (bool) env('FORCE_HTTPS', false),
        'password_min_length' => 10,
        // Verifica passwords contra fugas conhecidas (Have I Been Pwned, k-anonymity).
        'password_check_uncompromised' => (bool) env('PASSWORD_CHECK_UNCOMPROMISED', false),
        'platform_requires_mfa' => (bool) env('PLATFORM_REQUIRES_MFA', true),
        'content_security_policy' => (bool) env('CSP_ENABLED', true),
        // Proxies de confiança (ex.: Cloudflare): "*" ou lista separada por vírgulas.
        'trusted_proxies' => env('TRUSTED_PROXIES'),
    ],

    'queue' => [
        // Em cPanel não há supervisor: o agendador lança um worker por minuto.
        'run_via_scheduler' => (bool) env('QUEUE_RUN_VIA_SCHEDULER', true),
    ],

    // Saúde do sistema (backoffice → Saúde do sistema). Quando uma verificação falha, avisa por email os
    // super administradores da plataforma e estes endereços (separados por vírgulas).
    'monitoring' => [
        'emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('MONITORING_EMAILS', ''))))),
    ],

    // Página /instalar para alojamentos sem SSH. Desativa-se sozinha quando
    // existe um super administrador. Use um valor aleatório com 32+ caracteres.
    'setup_token' => env('SETUP_TOKEN'),

];
