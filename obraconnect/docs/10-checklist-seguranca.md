# Checklist de segurança

Referências: OWASP ASVS 4.0 (nível 2 como alvo) e OWASP Top 10. **✔** implementado e testado · **◐** implementado parcialmente / depende da configuração · **✗** por fazer.

## Implementação (fase 1)

| Área | Controlo | Estado | Onde |
| --- | --- | :-: | --- |
| Controlo de acesso (A01) | Isolamento por empresa com âmbito global que falha fechado | ✔ | `app/Tenancy/*`, `TenantIsolationTest` |
| | FKs compostas impedem relações entre empresas na BD | ✔ | migrações `access_control`, `invitations` |
| | Empresa nunca vem do cliente (cabeçalhos/parâmetros ignorados) | ✔ | `ResolveTenant`, teste dedicado |
| | Permissões verificadas no servidor em todas as rotas | ✔ | `Gate::before`, `authorize()` |
| | Proteção contra escalada de privilégios | ✔ | `AccessResolver::canAssignRole` |
| | Suporte só com autorização temporária, só leitura, auditado | ✔ | `SupportAccessTest` |
| Criptografia (A02) | HTTPS forçado e HSTS | ◐ | `FORCE_HTTPS=true` no `.env`; certificado do alojamento |
| | Passwords bcrypt (12 rondas) | ✔ | Laravel |
| | Segredos 2FA encriptados com `APP_KEY` | ✔ | Fortify |
| | Sessão encriptada, cookies `Secure`/`HttpOnly`/`SameSite=Lax` | ◐ | `.env.example` (produção) |
| | Tokens de convite guardados só como SHA-256 | ✔ | `Invitation::hashToken` |
| Injeção (A03) | Eloquent/Query Builder com parâmetros; sem SQL concatenado com dados | ✔ | revisão de código |
| | Pesquisas `LIKE` com escape de `%` e `_` | ✔ | `MemberController`, `TenantController` |
| | Vue escapa por omissão; único `v-html` é o QR SVG gerado pelo servidor | ✔ | `AccountPage.vue` |
| Design inseguro (A04) | Limites de plano no servidor, não na interface | ✔ | `PlanLimits` |
| | Empresa não pode perder o último administrador | ✔ | `ManageMembership` |
| Configuração (A05) | Cabeçalhos: CSP, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy, COOP | ✔ | `SecurityHeaders` |
| | `.env` e pastas internas fora da pasta pública; `.htaccess` de proteção | ✔ | `public/.htaccess`, `.htaccess` |
| | `APP_DEBUG=false` em produção | ◐ | `.env`; aviso na página `/instalar` |
| | Erros de programação não expõem detalhes | ✔ | `bootstrap/app.php` |
| Componentes (A06) | Versões suportadas (PHP 8.3, Laravel 13) | ✔ | `composer.json` |
| | `composer audit` / `npm audit` | ◐ | `npm audit` corre no `npm ci`; `composer audit` corre no `composer require` — acrescentar ao CI |
| | `ignore-scripts=true` no npm | ✔ | `.npmrc` |
| Autenticação (A07) | Rate limiting no login (5/min) e 2FA (5/min) | ✔ | `FortifyServiceProvider` |
| | 2FA TOTP com códigos de recuperação | ✔ | Fortify |
| | 2FA obrigatório para a plataforma; opcional obrigatório por empresa | ✔ | `EnsurePlatformStaff`, `ResolveTenant` |
| | Password mín. 10, letras e números; verificação HIBP opcional | ✔ | `AppServiceProvider` |
| | Recuperação de password não revela emails existentes | ✔ | `NeutralPasswordResetLinkResponse` |
| | Contas desativadas não entram | ✔ | `Fortify::authenticateUsing` |
| | Confirmação de password antes de alterar 2FA | ✔ | Fortify `password.confirm` |
| Integridade (A08) | CSRF via cookie XSRF-TOKEN (Sanctum) | ✔ | Sanctum |
| | Página de instalação protegida por token e autodesativada | ✔ | `SetupController` |
| | ZIP de release com SHA-256 | ✔ | `build-release.sh` |
| Registo (A09) | Auditoria append-only de autenticação, permissões, empresa, suporte, exportações | ✔ | `Auditor`, `AuthEventSubscriber` |
| | `X-Request-Id` em logs e auditoria | ✔ | `InitializeRequestContext` |
| | Segredos nunca escritos na auditoria | ✔ | `AuditLogTest` |
| SSRF (A10) | Sem pedidos HTTP a URLs fornecidas por utilizadores | ✔ | (a rever quando houver webhooks/integrações) |
| Uploads | Tipos permitidos (PNG/JPG/WebP), tamanho e dimensões máximas; SVG recusado | ✔ | `CompanyController::uploadLogo` |
| | Nome de ficheiro aleatório; disco privado; download via controlador | ✔ | idem |
| | Antivírus | ✗ | Fase 2 (ClamAV opcional) |
| RGPD | Exportação completa por empresa | ✔ | `ExportTenantData` |
| | Eliminação/retenção automática | ✗ | Procedimento definido, comando por fazer |
| | Geolocalização | — | Não existe na fase 1 |

## Operação (antes de entrar em produção)

- [ ] Certificado SSL ativo e `FORCE_HTTPS=true`.
- [ ] `APP_DEBUG=false`, `APP_ENV=production`, `LOG_LEVEL=warning`.
- [ ] `APP_KEY` gerada e guardada em local seguro (sem ela os segredos 2FA não podem ser lidos).
- [ ] `SETUP_TOKEN` removido do `.env` depois da instalação.
- [ ] Utilizador MySQL com privilégios só na base da aplicação.
- [ ] Permissões: `.env` com `600` ou `640`.
- [ ] Cron configurado (sem ele, emails e ciclo de subscrições param).
- [ ] Backups automáticos e um teste de restauro feito (ver `11-backup-restauro.md`).
- [ ] `PASSWORD_CHECK_UNCOMPROMISED=true` se o servidor tiver saída HTTPS.
- [ ] `TRUSTED_PROXIES` só se houver Cloudflare/proxy à frente.
- [ ] Contas da plataforma com 2FA ativa e emails pessoais (não partilhados).
- [ ] Revisão jurídica: termos, política de privacidade, acordo de tratamento de dados (RGPD art. 28.º), procedimento de violação de dados (72 h para a CNPD).

## Resposta a incidentes (resumo)

1. Conter: suspender a empresa afetada (backoffice) ou colocar a aplicação em manutenção (`php artisan down`).
2. Preservar: copiar `storage/logs` e exportar `audit_logs` do período.
3. Avaliar: âmbito (empresas, dados, utilizadores) com base na auditoria e `X-Request-Id`.
4. Rodar segredos se necessário: passwords da BD, `MAIL_PASSWORD`, chaves S3. (`APP_KEY` só com plano de re-encriptação.)
5. Notificar: clientes afetados e, se houver dados pessoais em risco, a CNPD em 72 h.
6. Corrigir, testar, documentar.

## Por fazer (próximas fases)

- `composer audit` e `npm audit --omit=dev` no CI.
- Antivírus em uploads; quarentena.
- Comando de eliminação definitiva de empresas com registo.
- Rate limiting por empresa em uploads.
- Testes de intrusão externos antes do lançamento comercial.
- Política de rotação de logs de auditoria (retenção legal a definir).
