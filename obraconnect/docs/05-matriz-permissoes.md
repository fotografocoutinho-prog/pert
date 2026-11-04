# E. Matriz de permissões por perfil

Legenda: **●** permitido por omissão · **○** só nas obras atribuídas (âmbito de obra, fase 2+) · vazio = sem acesso.
Todos os perfis são editáveis pela empresa, exceto o **Administrador**, que tem sempre todas as permissões.

Abreviaturas: ADM Administrador · DT Diretor técnico · DO Diretor de obra · ENC Encarregado · PRE Preparador · MED Medidor-orçamentista · FIS Fiscal · CSO Coordenador de segurança · TQ Técnico de qualidade · ADV Administrativo · TRB Trabalhador · SUB Subempreiteiro · FOR Fornecedor · DON Dono de obra

## 1. Implementado

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

### Obras e diário (fase 2, implementado na 0.2.0)

**●** = no perfil da empresa com efeito em **todas** as obras (o perfil tem `projects.all`); **○** = no perfil, mas só vale nas obras de cuja equipa a pessoa faz parte.

| Permissão | Descrição | ADM | DT | DO | ENC | PRE | MED | FIS | CSO | TQ | ADV | TRB | SUB | FOR | DON |
| --- | --- | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: | :-: |
| `projects.all` | Acesso a todas as obras | ● | ● | | | | ● | | | | ● | | | | |
| `projects.view` | Ver obras | ● | ● | ○ | ○ | ○ | ● | ○ | ○ | ○ | ● | ○ | ○ | ○ | ○ |
| `projects.create` | Criar obras (de empresa) | ● | ● | | | | | | | | | | | | |
| `projects.update` | Editar dados da obra | ● | ● | ○ | | ○ | | | | | | | | | |
| `projects.team` | Gerir a equipa da obra | ● | ● | ○ | | | | | | | | | | | |
| `projects.archive` | Concluir, arquivar e reabrir | ● | ● | | | | | | | | | | | | |
| `diary.view` | Consultar o diário | ● | ● | ○ | ○ | ○ | | ○ | ○ | ○ | | | | | ○ |
| `diary.write` | Escrever rascunhos | ● | ● | ○ | ○ | | | | | | | | | | |
| `diary.submit` | Submeter (fecha o registo) | ● | ● | ○ | ○ | | | | | | | | | | |

| `documents.view` | Consultar e descarregar | ● | ● | ○ | ○ | ○ | ● | ○ | ○ | ○ | ● | ○ | ○ | ○ | ○ |
| `documents.upload` | Enviar (e versões dos seus) | ● | ● | ○ | ○ | ○ | ● | ○ | ○ | ○ | ● | | ○ | ○ | |
| `documents.manage` | Pastas, mover, apagar, versões de todos | ● | ● | ○ | | ○ | | | | | ● | | | | |

| `structure.manage` | Edifícios, pisos, zonas e elementos | ● | ● | ○ | | ○ | ● | | | | | | | | |

| `clients.view` | Ver clientes e contactos | ● | ● | ● | | ● | ● | | | | ● | | | | |
| `clients.manage` | Criar, editar, arquivar, apagar clientes | ● | ● | | | | | | | | ● | | | | |
| `calendars.manage` | Calendários, feriados e paragens | ● | ● | | | | | | | | ● | | | | |

| `departments.manage` | Departamentos e quem está em cada um | ● | | | | | | | | | ● | | | | |
| `cost_centers.manage` | Centros de custo | ● | ● | | | | | | | | ● | | | | |
| `specialties.manage` | Especialidades | ● | ● | | | | | | | | ● | | | | |

| `checklists.manage` | Listas de verificação (modelos) | ● | ● | | | | | | | ● | | | | | |
| `inspections.request` | Preparar, pedir, reinspeção, cancelar | ● | ● | ○ | ○ | ○ | | | | ○ | | | ○ | | |
| `inspections.perform` | Agendar e fazer (pontos e evidências) | ● | | | | | | ○ | ○ | ○ | | | | | |
| `inspections.approve` | Decidir, devolver, cancelar | ● | | | | | | ○ | ○ | | | | | | |

Clientes e calendários (0.8.0) valem para a empresa toda (não por obra), por isso aqui ● é só "tem a permissão". Escolher o cliente e o calendário de uma obra exige `projects.create`/`projects.update`, não `clients.manage`; consultar os calendários exige `company.view` (ou poder editar obras); departamentos e centros de custo (0.9.0) consultam-se com `company.view`; as especialidades (0.10.0) também com `projects.view`, porque subempreiteiros e fornecedores classificam os documentos que enviam (os centros também com `projects.create`/`projects.update`, para os escolher nas obras); o calendário de uma obra concreta vê-se com `projects.view` nessa obra.

Inspeções (0.11.0): vê as inspeções de uma obra quem tem lá qualquer uma das três permissões de inspeção (não há `inspections.view`); as fotografias de evidência seguem a mesma regra, e não `diary.view`. As listas de verificação consultam-se com `checklists.manage` ou com uma permissão de inspeção (para as escolher ao criar). `inspections.release_hold_point` chega com os pontos de paragem.

Estrutura (0.5.0): ver a estrutura só exige `projects.view`. O medidor-orçamentista gere a estrutura em todas as obras (tem `projects.all`).

Documentos (0.4.0): o medidor-orçamentista tem acesso a todas as obras, por isso consulta e envia em todas (a proposta tinha ○).

Diferenças em relação à proposta (secção 2): o preparador passa a ver só as obras da equipa (a proposta dava-lhe todas para ver e só as da equipa para editar, o que um único perfil não consegue exprimir); o diretor técnico escreve e submete o diário (sem isso deixaria de poder convidar diretores de obra, pela regra contra escalada); `projects.team` é nova.

Resolução numa obra concreta (`AccessResolver::permissionsForProject`): permissões do perfil da empresa, se este tiver `projects.all`, **mais** as do perfil na equipa dessa obra, se a pessoa estiver na equipa. Quem cria uma obra entra na equipa com o perfil da empresa. A gestão da equipa segue a regra contra escalada: só se atribui, altera ou retira um perfil contido nas próprias permissões.

## 2. Planeado (fases 2 a 6; as linhas de obras e diário já estão implementadas acima)

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
