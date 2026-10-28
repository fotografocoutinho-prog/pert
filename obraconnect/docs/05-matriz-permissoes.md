# E. Matriz de permissões por perfil

Legenda: **●** permitido por omissão · **○** só nas obras atribuídas (âmbito de obra, fase 2+) · vazio = sem acesso.
Todos os perfis são editáveis pela empresa, exceto o **Administrador**, que tem sempre todas as permissões.

Abreviaturas: ADM Administrador · DT Diretor técnico · DO Diretor de obra · ENC Encarregado · PRE Preparador · MED Medidor-orçamentista · FIS Fiscal · CSO Coordenador de segurança · TQ Técnico de qualidade · ADV Administrativo · TRB Trabalhador · SUB Subempreiteiro · FOR Fornecedor · DON Dono de obra

## 1. Implementado na fase 1

Estas são as permissões reais em `app/Access/PermissionRegistry.php` e os valores por omissão em `app/Access/RoleTemplates.php`.

| Permissão | Descrição | ADM | DT | DO | ENC | PRE | MED | FIS | CSO | TQ | ADV | TRB | SUB | FOR | DON |
| --- | --- | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: |
| `company.view` | Ver dados da empresa | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | | | |
| `company.update` | Editar dados e logótipo | ● | | | | | | | | | ● | | | | |
| `security.manage` | Políticas de segurança (2FA obrigatório) | ● | | | | | | | | | | | | | |
| `members.view` | Ver utilizadores e convites | ● | ● | ● | ● | ● | ● | | | | ● | | | | |
| `members.invite` | Convidar utilizadores | ● | ● | ● | | | | | | | | | | | |
| `members.manage` | Alterar perfil, suspender, remover | ● | | | | | | | | | | | | | |
| `roles.view` | Ver perfis e permissões | ● | ● | | | | | | | | | | | | |
| `roles.manage` | Criar e editar perfis | ● | | | | | | | | | | | | | |
| `audit.view` | Consultar auditoria | ● | ● | | | | | | | | | | | | |
| `subscription.view` | Ver plano e utilização | ● | ● | | | | | | | | ● | | | | |
| `support.manage` | Autorizar acesso do suporte | ● | | | | | | | | | | | | | |
| `data.export` | Exportar todos os dados | ● | | | | | | | | | | | | | |

Regras adicionais aplicadas pelo servidor (não configuráveis):

- Ninguém atribui um perfil, nem altera um utilizador, com permissões que não tem (`ROLE_ESCALATION`).
- Ninguém concede ou retira de um perfil permissões que não tem.
- A empresa mantém sempre pelo menos um administrador ativo (`LAST_ADMIN`).
- Ninguém suspende a própria conta.
- Suporte técnico autorizado: só permissões `*.view`, só pedidos de leitura.
- Utilizadores externos (SUB, FOR, DON) começam sem permissões; o acesso deles é dado por obra.

## 2. Planeado (fases 2 a 6)

Proposta de valores por omissão. Cada linha passa a existir no catálogo quando o módulo for implementado.

| Permissão | ADM | DT | DO | ENC | PRE | MED | FIS | CSO | TQ | ADV | TRB | SUB | FOR | DON |
| --- | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: |
| `projects.view` | ● | ● | ○ | ○ | ● | ● | ○ | ○ | ○ | ● | ○ | ○ | ○ | ○ |
| `projects.create` | ● | ● | | | | | | | | | | | | |
| `projects.update` | ● | ● | ○ | | ○ | | | | | | | | | |
| `projects.archive` | ● | ● | | | | | | | | | | | | |
| `structure.manage` (edifícios, pisos, zonas, elementos) | ● | ● | ○ | | ○ | ○ | | | | | | | | |
| `diary.view` | ● | ● | ○ | ○ | ○ | | ○ | ○ | ○ | | | | | ○ |
| `diary.write` | ● | | ○ | ○ | | | | | | | | | | |
| `diary.submit` | ● | | ○ | ○ | | | | | | | | | | |
| `documents.view` | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ● | ○ | ○ | ○ | ○ |
| `documents.upload` | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ● | | ○ | ○ | |
| `documents.manage` (pastas, versões, apagar) | ● | ● | ○ | | ○ | | | | | ● | | | | |
| `media.upload` (fotografias, vídeos) | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | | ○ | ○ | | |
| `tasks.view` | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | | ○ | ○ | | ○ |
| `tasks.manage` | ● | ● | ○ | ○ | ○ | | | | | | | | | |
| `inspections.request` | ● | ● | ○ | ○ | ○ | | | | ○ | | | ○ | | |
| `inspections.perform` | ● | | | | | | ○ | ○ | ○ | | | | | |
| `inspections.approve` | ● | | | | | | ○ | ○ | | | | | | |
| `inspections.release_hold_point` | ● | | | | | | ○ | | | | | | | |
| `checklists.manage` | ● | ● | | | | | | | ● | | | | | |
| `issues.view` | ● | ● | ○ | ○ | ○ | | ○ | ○ | ○ | | ○ | ○ | | ○ |
| `issues.create` | ● | ● | ○ | ○ | ○ | | ○ | ○ | ○ | | ○ | ○ | | ○ |
| `issues.resolve` | ● | ● | ○ | ○ | | | | | | | | ○ | | |
| `issues.validate` | ● | ● | ○ | | | | ○ | ○ | ○ | | | | | |
| `budgets.view` | ● | ● | ○ | | ● | ● | | | | ● | | | | |
| `budgets.manage` | ● | ● | | | | ● | | | | | | | | |
| `measurements.manage` | ● | ● | ○ | | | ● | | | | | | | | |
| `certificates.approve` (autos) | ● | ● | ○ | | | | ○ | | | | | | | ○ |
| `costs.view` | ● | ● | ○ | | ○ | ○ | | | | ● | | | | |
| `costs.manage` | ● | ● | | | | | | | | ● | | | | |
| `purchases.manage` | ● | ● | ○ | | ○ | | | | | ● | | | | |
| `subcontractors.manage` | ● | ● | ○ | | ○ | | | | | ● | | | | |
| `workforce.view` | ● | ● | ○ | ○ | | | | | | ● | | | | |
| `time.register_own` | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | ○ | | |
| `time.register_team` | ● | | ○ | ○ | | | | | | | | ○ | | |
| `time.approve` | ● | ● | ○ | | | | | | | ● | | | | |
| `time.export_payroll` | ● | | | | | | | | | ● | | | | |
| `equipment.manage` | ● | ● | ○ | ○ | ○ | | | | | ● | | | | |
| `planning.view` | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | | | ○ | | ○ |
| `planning.manage` | ● | ● | ○ | | ○ | | | | | | | | | |
| `finance.view` (dashboards) | ● | ● | ○ | | | | | | | ● | | | | |
| `reports.export` | ● | ● | ○ | | ○ | ○ | ○ | ○ | ○ | ● | | | | ○ |
| `ai.use` | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ | ○ | ● | | | | |
| `automations.manage` | ● | ● | | | | | | | | | | | | |
| `integrations.manage` | ● | | | | | | | | | | | | | |

### Âmbito por obra (fase 2)

`project_members` associa um utilizador a uma obra com um perfil específico para essa obra. A resolução de permissões passa a ser:

1. Permissão sem âmbito de obra (ex.: `projects.create`) → perfil da empresa.
2. Permissão com âmbito de obra (ex.: `diary.write`) → perfil na obra, se existir; senão, perfil da empresa **apenas** se este tiver acesso a todas as obras (DT, ADM).
3. Utilizadores externos só veem obras onde estão associados.
