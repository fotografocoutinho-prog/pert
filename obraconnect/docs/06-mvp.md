# F. MVP comercial e critérios de aceitação

## Definição do MVP vendável

O primeiro produto que pode ser cobrado a uma empresa de construção corresponde às **fases 1 a 3**: fundação SaaS, gestão operacional e fiscalização. É aí que está o diferencial (fiscalização digital e remota) e o que reduz deslocações e burocracia.

| Fase | Conteúdo | Estado |
| --- | --- | --- |
| 1 | Registo de empresas, utilizadores, perfis, permissões, subscrições manuais, auditoria, backoffice, suporte autorizado, exportação RGPD | **Implementada (v0.1.0)** |
| 2 | Obras e estrutura, diário de obra, documentos e fotografias, tarefas, modo obra (telemóvel), offline básico | Por fazer |
| 3 | Inspeções e checklists, evidências, pontos de paragem, ocorrências/NC, aprovações, notificações por email | Por fazer |

Fases 4 a 6 (orçamentação, custos, tempos, IA, pagamentos, integrações) entram como upgrades dos planos Professional e Business.

## Critérios de aceitação — fase 1

Cada critério tem o teste automatizado que o verifica (pasta `tests/`).

| # | Critério | Verificação |
| --- | --- | --- |
| A1 | Uma empresa regista-se, recebe 14 perfis, fica como administradora e em período experimental | `RegistrationTest::test_registration_creates_company_admin_and_trial` |
| A2 | NIF português inválido é recusado | `RegistrationTest::test_invalid_nif_is_rejected`, `PortugueseNifTest` |
| A3 | Utilizador convidado cria conta e entra na empresa certa, sem criar empresa nova | `RegistrationTest::test_registration_with_invitation_joins_existing_company` |
| A4 | Nenhum utilizador vê dados de outra empresa, nem forçando IDs ou cabeçalhos | `TenantIsolationTest` (13 testes) |
| A5 | A base de dados recusa associações entre empresas diferentes | `TenantIsolationTest::test_database_rejects_membership_with_role_from_another_tenant` |
| A6 | Consultas sem empresa no contexto falham em vez de devolver tudo | `TenantIsolationTest::test_tenant_models_fail_closed_without_context` |
| A7 | Trabalhos em fila correm na empresa correta | `TenantIsolationTest::test_queued_jobs_restore_the_tenant_context` |
| A8 | Permissões respeitadas na API; sem escalada de privilégios | `AccessControlTest` |
| A9 | Limite de utilizadores do plano aplicado | `AccessControlTest::test_plan_user_limit_is_enforced` |
| A10 | Empresa nunca fica sem administrador | `AccessControlTest::test_company_cannot_lose_its_last_admin` |
| A11 | Equipa da plataforma não acede a dados das empresas sem autorização | `PlatformTest::test_platform_admin_does_not_get_access_to_company_data` |
| A12 | Backoffice exige 2FA | `PlatformTest::test_platform_staff_must_have_two_factor_enabled` |
| A13 | Suporte só entra com autorização da empresa, só lê, e fica auditado | `SupportAccessTest` |
| A14 | Trial expirado → só leitura (com exportação) → suspensão | `SubscriptionLifecycleTest` |
| A15 | Exportação contém só dados da empresa e expira | `DataExportTest` |
| A16 | Auditoria imutável e sem segredos | `AuditLogTest` |
| A17 | Recuperação de password não revela que emails existem | `RegistrationTest::test_password_reset_does_not_reveal_unknown_emails` |
| A18 | Instalação sem SSH desativa-se depois de criar o administrador | `SetupTest` |
| A19 | SQL de instalação equivale às migrações e importa em MySQL 8.4 | Passo de CI "SQL de instalação atualizado e importável" |
| A20 | Interface utilizável em telemóvel (360 px) | Verificado manualmente com Playwright (capturas no relatório da sessão) |

## Critérios de aceitação propostos — fases 2 e 3

- Encarregado regista o diário do dia em menos de 2 minutos no telemóvel, com fotografias, mesmo com rede intermitente (fila offline sincroniza quando volta a rede).
- Fotografia original guardada com hash SHA-256 e data; a versão reduzida nunca substitui o original.
- Fiscal recebe pedido de inspeção, aprova ou rejeita remotamente com evidências; a decisão fica numa cadeia imutável (hash encadeado) e gera PDF.
- Atividade com ponto de paragem não pode ser marcada como concluída sem libertação autorizada.
- Ocorrência com prazo ultrapassado notifica o responsável e escala ao diretor de obra.
- Limite `max_active_projects` do plano aplicado na criação de obras.
