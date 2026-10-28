<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Instalação · ObraConnect</title>
    <style>
        :root { --brand: #c2410c; --ok: #15803d; --bad: #b91c1c; --warn: #a16207; --line: #e5e7eb; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #0f172a; }
        main { max-width: 720px; margin: 0 auto; padding: 32px 16px 64px; }
        h1 { font-size: 1.5rem; margin: 0 0 4px; }
        h2 { font-size: 1.1rem; margin: 0 0 12px; }
        p.muted { color: #475569; margin-top: 0; }
        section { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 20px; margin-top: 20px; }
        ul.checks { list-style: none; margin: 0; padding: 0; }
        ul.checks li { padding: 6px 0; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; gap: 12px; }
        ul.checks li:last-child { border-bottom: 0; }
        .ok { color: var(--ok); font-weight: 600; } .bad { color: var(--bad); font-weight: 600; } .warn { color: var(--warn); font-weight: 600; }
        label { display: block; font-size: .9rem; font-weight: 600; margin: 12px 0 4px; }
        input { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 1rem; }
        button { margin-top: 16px; background: var(--brand); color: #fff; border: 0; border-radius: 8px; padding: 11px 18px; font-size: 1rem; font-weight: 600; cursor: pointer; }
        .alert { border-radius: 8px; padding: 12px 14px; margin-top: 16px; }
        .alert.error { background: #fef2f2; color: var(--bad); border: 1px solid #fecaca; }
        .alert.success { background: #f0fdf4; color: var(--ok); border: 1px solid #bbf7d0; }
        pre { background: #0f172a; color: #e2e8f0; padding: 12px; border-radius: 8px; overflow-x: auto; font-size: .8rem; white-space: pre-wrap; }
        code { background: #f1f5f9; padding: 1px 5px; border-radius: 4px; }
    </style>
</head>
<body>
<main>
    <h1>ObraConnect · Instalação</h1>
    <p class="muted">Assistente para alojamentos sem acesso SSH.</p>

    @if (!empty($done))
        <div class="alert success">
            Super administrador criado. <strong>Remova agora a linha <code>SETUP_TOKEN</code> do ficheiro <code>.env</code></strong>
            e entre em <a href="{{ url('entrar') }}">{{ url('entrar') }}</a>. Na primeira entrada ser-lhe-á pedido que ative a autenticação de dois fatores.
        </div>
    @elseif (!empty($disabled))
        <div class="alert error">
            Instalação desativada: já existe um super administrador ou o <code>SETUP_TOKEN</code> não está definido (mínimo 32 caracteres).
        </div>
    @else
        @isset($error)<div class="alert error">{{ $error }}</div>@endisset
        @isset($message)<div class="alert success">{{ $message }}</div>@endisset
        @isset($errors_list)
            <div class="alert error"><ul>@foreach ($errors_list as $e)<li>{{ $e }}</li>@endforeach</ul></div>
        @endisset
        @isset($output)<pre>{{ $output }}</pre>@endisset

        <section>
            <h2>1. Requisitos</h2>
            <ul class="checks">
                @foreach ($checks as $check)
                    <li>
                        <span>{{ $check['label'] }}</span>
                        @if ($check['ok'])<span class="ok">OK</span>
                        @elseif (!empty($check['warning']))<span class="warn">Atenção</span>
                        @else<span class="bad">Falta</span>@endif
                    </li>
                @endforeach
                <li>
                    <span>Ligação à base de dados</span>
                    @if ($database['ok'])<span class="ok">OK</span>@else<span class="bad">Erro</span>@endif
                </li>
            </ul>
            @if (!empty($app_key_missing))
                <form method="post" action="{{ url('instalar') }}" style="margin-top: 12px">
                    <input type="hidden" name="action" value="key">
                    <label for="token-k">Token de instalação (SETUP_TOKEN)</label>
                    <input id="token-k" type="password" name="token" required autocomplete="off">
                    <button type="submit">Gerar APP_KEY</button>
                </form>
            @endif
            @if (!$database['ok'])
                <div class="alert error">Verifique DB_HOST, DB_DATABASE, DB_USERNAME e DB_PASSWORD no .env. Detalhe: {{ \Illuminate\Support\Str::limit((string) $database['error'], 300) }}</div>
            @endif
        </section>

        @if ($database['ok'])
            <section>
                <h2>2. Base de dados</h2>
                @if ($database['pending'] > 0)
                    <p>Há <strong>{{ $database['pending'] }}</strong> migração(ões) por aplicar.</p>
                @else
                    <p class="ok">Esquema atualizado.</p>
                @endif
                <form method="post" action="{{ url('instalar') }}">
                    <input type="hidden" name="action" value="migrate">
                    <label for="token-m">Token de instalação (SETUP_TOKEN)</label>
                    <input id="token-m" type="password" name="token" required autocomplete="off">
                    <button type="submit">Aplicar migrações e dados base</button>
                </form>
            </section>

            @if ($database['pending'] === 0)
                <section>
                    <h2>3. Super administrador da plataforma</h2>
                    <form method="post" action="{{ url('instalar') }}">
                        <input type="hidden" name="action" value="admin">
                        <label for="token-a">Token de instalação (SETUP_TOKEN)</label>
                        <input id="token-a" type="password" name="token" required autocomplete="off">
                        <label for="name">Nome</label>
                        <input id="name" name="name" required maxlength="255">
                        <label for="email">Email</label>
                        <input id="email" type="email" name="email" required>
                        <label for="password">Password (mín. 10 caracteres, letras e números)</label>
                        <input id="password" type="password" name="password" required autocomplete="new-password">
                        <label for="password_confirmation">Confirmar password</label>
                        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
                        <button type="submit">Criar super administrador</button>
                    </form>
                </section>
            @endif
        @endif
    @endif
</main>
</body>
</html>
