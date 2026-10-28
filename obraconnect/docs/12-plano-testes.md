# Plano de testes

## Níveis

| Nível | Ferramenta | Onde corre | Estado |
| --- | --- | --- | --- |
| Unitários (regras puras: NIF, cálculos) | PHPUnit | Local e CI | ✔ (NIF) |
| Funcionais da API (HTTP + BD) | PHPUnit + `RefreshDatabase` | CI em **SQLite e MySQL 8.4**; local também em MariaDB 10.11 | ✔ 74 testes |
| Tipos do frontend | `vue-tsc` | CI | ✔ |
| Estilo PHP | Laravel Pint | CI | ✔ |
| SQL de instalação | Geração + `diff` + importação em MySQL 8.4 + `migrate` sem pendentes | CI | ✔ |
| Ponta a ponta (browser) | Playwright | Manual nesta fase (scripts de sessão, não versionados) | ◐ |
| Instalação a partir do ZIP | Manual: SQL + `/instalar` + login | Feito localmente para a v0.1.0 | ◐ |
| Carga | k6 ou similar | — | ✗ (antes do lançamento) |
| Segurança | OWASP ZAP baseline + teste de intrusão externo | — | ✗ (antes do lançamento) |

## Cobertura funcional atual (fase 1)

| Ficheiro | Foco |
| --- | --- |
| `tests/Feature/Tenancy/TenantIsolationTest.php` | Falha fechada sem contexto; filtro por empresa; criação para outra empresa recusada; `tenant_id` imutável; FK composta; API e route binding sem fugas; validação de IDs; troca de empresa; cabeçalhos ignorados; contexto restaurado em fila real |
| `tests/Feature/Auth/RegistrationTest.php` | Registo com empresa e trial; NIF; campos obrigatórios; password fraca; registo por convite; convite para outro email; conta desativada; auditoria de login; email não verificado; recuperação de password neutra |
| `tests/Feature/Access/AccessControlTest.php` | Permissões em `/me`; 403 sem permissão; convites; escalada; limite do plano; duplicados; último administrador; despromoção; gestor sem privilégios suficientes; perfis personalizados; perfil bloqueado; perfil em uso; permissões desconhecidas; 2FA obrigatório por empresa |
| `tests/Feature/Platform/PlatformTest.php` | Acesso só para a plataforma; 2FA obrigatório; painel e lista; sem acesso a dados de empresa; suspensão/reativação; papéis; planos editáveis; mudança de plano com histórico |
| `tests/Feature/Platform/SupportAccessTest.php` | Fluxo completo de suporte; autorização expirada; duração máxima; papel de faturação sem suporte |
| `tests/Feature/Billing/SubscriptionLifecycleTest.php` | Trial → só leitura → suspensão; atraso → expiração; renovação reativa; suspensão manual mantém-se |
| `tests/Feature/DataExportTest.php` | Conteúdo restrito à empresa; download; expiração e limpeza |
| `tests/Feature/AuditLogTest.php` | Imutabilidade; auditoria por empresa; sem segredos |
| `tests/Feature/SetupTest.php` | Instalação desativada sem token, com token curto, e depois de criar o administrador |

## Como correr

```bash
php artisan test                                   # SQLite em memória
DB_CONNECTION=mariadb DB_DATABASE=obraconnect_test \
DB_USERNAME=... DB_PASSWORD=... php artisan test   # MySQL/MariaDB
npm run typecheck && npm run build
vendor/bin/pint --test
```

## Regras para as próximas fases

1. Cada modelo novo com `tenant_id` tem pelo menos um teste de isolamento (API não devolve dados de outra empresa; route binding devolve 404).
2. Cada permissão nova tem um teste de 403 para quem não a tem.
3. Cada limite de plano novo tem teste de 402.
4. Cálculos (medições, CPM/PERT, custos) com testes unitários e casos de referência verificados por um engenheiro.
5. Fluxos críticos no browser (registo, diário no telemóvel, inspeção remota) passam a Playwright versionado e a correr no CI.
