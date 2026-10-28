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
    ],

    'exports' => [
        'retention_hours' => 48,
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

    // Página /instalar para alojamentos sem SSH. Desativa-se sozinha quando
    // existe um super administrador. Use um valor aleatório com 32+ caracteres.
    'setup_token' => env('SETUP_TOKEN'),

];
