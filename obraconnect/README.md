# ObraConnect

Plataforma SaaS de gestão integral de obras, engenharia e fiscalização, para empresas de construção em Portugal.
Laravel 13 + Vue 3, MySQL/MariaDB, pensada para correr em **cPanel** e migrar para VPS sem reescrever código.

**Versão 0.1.0 — Fase 1 (fundação SaaS multi-tenant).** O que está feito e o que falta: [`docs/PROGRESSO.md`](docs/PROGRESSO.md).

## Documentação

| | Documento |
| --- | --- |
| A | [Análise funcional e lacunas](docs/01-analise-funcional.md) |
| B | [Arquitetura técnica](docs/02-arquitetura.md) |
| C | [Modelo de dados e diagrama ER](docs/03-modelo-dados.md) |
| D | [Módulos e dependências](docs/04-modulos.md) |
| E | [Matriz de permissões](docs/05-matriz-permissoes.md) |
| F | [MVP e critérios de aceitação](docs/06-mvp.md) |
| G | [Plano de desenvolvimento e riscos](docs/08-plano-desenvolvimento.md) |
| H | Estrutura e instalação (este ficheiro) |
| | [Guia de instalação em cPanel](docs/07-guia-cpanel.md) |
| | [Migração para VPS/cloud](docs/09-migracao-vps.md) |
| | [Checklist de segurança](docs/10-checklist-seguranca.md) |
| | [Backup e restauro](docs/11-backup-restauro.md) |
| | [Plano de testes](docs/12-plano-testes.md) |
| | [API (OpenAPI 3.1)](docs/openapi.yaml) |
| | [Manual do administrador](docs/13-manual-administrador.md) · [Manual do utilizador](docs/14-manual-utilizador.md) |
| | [Plano comercial](docs/15-plano-comercial.md) |

## Estrutura

```
obraconnect/
├── app/
│   ├── Access/          catálogo de permissões, perfis-modelo, resolução de permissões
│   ├── Actions/         casos de uso (registar empresa, convidar, aceitar convite, gerir membros)
│   ├── Audit/           auditoria append-only e eventos de autenticação
│   ├── Billing/         limites e funcionalidades por plano
│   ├── Console/Commands obraconnect:* (permissões, admin, subscrições, exportações, SQL)
│   ├── Enums/           estados (empresa, subscrição, associação, papéis da plataforma)
│   ├── Http/            controladores API v1 (+ Platform/), middleware, resources
│   ├── Jobs/            trabalhos em fila (exportação de dados)
│   ├── Models/          Eloquent
│   ├── Support/         validador de NIF
│   └── Tenancy/         contexto da empresa, âmbito global, BelongsToTenant, exportador
├── config/obraconnect.php   configuração do produto
├── database/
│   ├── migrations/
│   ├── seeders/         permissões, planos, demo (só local)
│   └── sql/obraconnect-install.sql   SQL completo para phpMyAdmin
├── deploy/cpanel/       index.php adaptador, cron, php.ini recomendado
├── docs/
├── lang/pt_PT/          traduções (validação, autenticação, emails)
├── public/              raiz web (.htaccess, PWA, build/ compilado)
├── resources/js/        SPA Vue 3 + TypeScript (api, stores, router, components, pages)
├── resources/views/     app.blade.php (SPA), setup.blade.php (/instalar), emails
├── routes/              api.php (v1), web.php (SPA + /instalar), console.php (agendador)
├── scripts/             build-release.sh, generate-install-sql.sh
└── tests/               PHPUnit (isolamento, permissões, plataforma, subscrições, exportação...)
```

## Desenvolvimento local

Requisitos: PHP 8.3+, Composer, Node 20+, MySQL 8 / MariaDB 10.6+ (ou só SQLite para testes).

```bash
cd obraconnect
composer install
npm ci
cp .env.example .env
# Para desenvolvimento: APP_ENV=local, APP_DEBUG=true, FORCE_HTTPS=false,
# SESSION_SECURE_COOKIE=false, MAIL_MAILER=log, SANCTUM_STATEFUL_DOMAINS=localhost:8000, e dados da BD
php artisan key:generate
php artisan migrate --seed       # em APP_ENV=local cria dados de demonstração

npm run dev                      # Vite (terminal 1)
php artisan serve                # http://localhost:8000 (terminal 2)
php artisan queue:work           # emails e exportações (terminal 3)
```

Contas de demonstração (só com `APP_ENV=local`), password `ObraConnect2026`:
- `admin@demo.obraconnect.test` — administrador da empresa "Construções Demo, Lda."
- `plataforma@obraconnect.test` — super administrador da plataforma (pede para ativar 2FA)

## Testes e qualidade

```bash
php artisan test                 # 74 testes, SQLite em memória
DB_CONNECTION=mariadb DB_DATABASE=obraconnect_test DB_USERNAME=... DB_PASSWORD=... php artisan test
npm run typecheck
vendor/bin/pint --test
```

O CI (`.github/workflows/obraconnect.yml` na raiz do repositório) corre tudo isto em SQLite e MySQL 8.4, verifica o SQL de instalação e publica o ZIP de release como artefacto.

## Produção em cPanel

Resumo (detalhe em [`docs/07-guia-cpanel.md`](docs/07-guia-cpanel.md)):

1. Gerar o ZIP (`scripts/build-release.sh`) ou descarregá-lo do CI. Para uma subpasta de um site existente: `scripts/build-subfolder-package.sh https://www.coda.pt/obras/obraconnect`.
2. Extrair em `/home/UTILIZADOR/obraconnect` e apontar o domínio para `obraconnect/public`.
3. Importar `database/sql/obraconnect-install.sql` no phpMyAdmin (ou `php artisan migrate --force`).
4. Configurar `.env`.
5. Criar o super administrador em `/instalar` (com `SETUP_TOKEN`) ou `php artisan obraconnect:create-platform-admin`.
6. Cron a cada minuto: `php artisan schedule:run`.

## Comandos do produto

| Comando | Função |
| --- | --- |
| `obraconnect:sync-permissions` | Sincroniza permissões com o catálogo em código |
| `obraconnect:create-platform-admin {email} --role=` | Cria/promove equipa da plataforma (`super_admin`, `support`, `billing`) |
| `obraconnect:subscriptions-sweep` | Ciclo trial → só leitura → suspensão (diário) |
| `obraconnect:prune-exports` | Apaga exportações expiradas (de hora a hora) |
| `obraconnect:install-sql` | Regenera o SQL de instalação a partir das migrações |
