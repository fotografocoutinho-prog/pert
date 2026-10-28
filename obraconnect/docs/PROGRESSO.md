# Registo de progresso

## v0.1.0 — Fase 1: fundação SaaS multi-tenant (2026-10-28)

### Implementado e verificado

| Área | Verificação |
| --- | --- |
| Laravel 13 (PHP 8.3) + Vue 3/TypeScript/Tailwind 4, numa só aplicação | Build de produção sem erros de tipos |
| Multi-tenancy (base partilhada, `tenant_id`, âmbito que falha fechado, FKs compostas, contexto em filas) | 13 testes de isolamento |
| Autenticação: registo de empresa, convites, verificação de email, recuperação de password, 2FA TOTP | Testes + fluxo real no browser (Playwright), incluindo ativar 2FA e entrar com código |
| RBAC com 14 perfis-modelo editáveis, proteção contra escalada, último administrador protegido | Testes |
| Planos e subscrições configuráveis, limites, ciclo trial → só leitura → suspensão | Testes |
| Backoffice da plataforma (painel, empresas, planos, suporte, logs de segurança) com 2FA obrigatória | Testes + browser |
| Acesso do suporte autorizado pela empresa, temporário, só leitura, auditado | Testes + browser |
| Auditoria append-only | Testes |
| Exportação de dados por empresa (ZIP/JSON) em fila | Testes |
| Página `/instalar` para cPanel sem SSH | Testes + instalação a partir do ZIP |
| Regras `.htaccess` (raiz em `public/`, app dentro da pasta pública, `index.php` adaptador) | Apache 2.4 real: segredos dão 403, registo pela SPA funciona nos 3 cenários |
| SQL de instalação para phpMyAdmin gerado das migrações | Importado em MariaDB 10.11 (local) e MySQL 8.4 (CI); esquema igual ao das migrações |
| ZIP de release | Gerado localmente e no CI; instalado e testado localmente |
| PWA (manifesto, service worker do shell) | Service worker regista no build de produção |
| Interface em pt-PT, responsiva | Capturas em 1280 px e 390 px |
| CI GitHub Actions | Verde: Pint, tipos, build, testes SQLite + MySQL 8.4, SQL, ZIP |

Total: **74 testes, 221 asserções**, a passar em SQLite, MariaDB 10.11 e MySQL 8.4.

### Não feito nesta fase (honestamente)

- Instalação num servidor cPanel real: testei com Apache 2.4 + mod_php 8.3 + MariaDB a partir do ZIP, nos três cenários de raiz web (bloqueio de `.env`/pastas internas, registo pela interface). Falta confirmar num alojamento cPanel concreto.
- Envio real de email: só verificado com o driver `log`/`array`.
- Testes Playwright não estão versionados nem no CI (foram scripts da sessão).
- Mudança de plano/pagamento self-service, cupões, faturação (fase 6).
- Departamentos, centros de custo, calendários, feriados, clientes (passaram para a fase 2).
- Eliminação definitiva de empresas e políticas de retenção automáticas.
- Antivírus nos uploads.
- Notificações globais da plataforma, gestão de incidentes e de versões no backoffice.
- `composer audit` no CI.
- Passkeys (WebAuthn): o Fortify suporta, mas foi desativado para manter a fase 1 simples.

### Pressupostos adotados (decisões técnicas menores)

- Aplicação colocada em `obraconnect/` dentro deste repositório, sem tocar no projeto existente (digital signage).
- Empresa ativa guardada na conta (`current_tenant_id`), não em subdomínios.
- IDs numéricos sequenciais; o isolamento não depende de IDs imprevisíveis.
- Perfis copiados para cada empresa (editáveis); modelos vivem em código.
- Filas em base de dados lançadas pelo agendador (cPanel sem supervisor).
- Preços dos planos são exemplos, sem IVA.
- Fontes do sistema (sem Google Fonts) por RGPD e desempenho.
- Erros de negócio (403/409/422) não são registados como erros no log.

### Decisões que precisam de confirmação

Ver `01-analise-funcional.md`, secção 6 (faturação, pagamentos, IA, retenção, domínio).

### Próximo passo

Fase 2: obras e estrutura, equipas por obra (ativa permissões por obra), diário de obra, documentos e fotografias, modo obra no telemóvel com fila offline.
