# G. Plano de desenvolvimento, riscos e prioridades

## Fases e entregáveis

Cada fase termina com: código, migrações, testes automatizados a passar em CI (SQLite e MySQL 8.4), SQL de instalação regenerado, ZIP de release e documentação atualizada.

| Fase | Âmbito | Dependências | Estado |
| --- | --- | --- | --- |
| **1 · Fundação** | Laravel 13 + Vue 3, multi-tenant, autenticação com 2FA, RBAC, planos e subscrições manuais, backoffice, suporte auditado, exportação RGPD, instalação cPanel | — | **Concluída (v0.1.0)** |
| **2 · Operacional** | Departamentos, centros de custo, calendários e feriados; clientes; obras e estrutura (edifício → piso → zona → elemento → atividade); equipas por obra; diário de obra; documentos com versões; fotografias com original preservado; modo obra no telemóvel; fila offline para fotografias e diário | Fase 1 | **Concluída (v0.10.0)** |
| **3 · Fiscalização** | Checklists configuráveis, inspeções com 9 estados, evidências obrigatórias, índice de suficiência explicável, pontos de paragem, ocorrências/NC com escalonamento, aprovações com cadeia imutável, relatório PDF, notificações por email | Fase 2 | **Concluída (v0.13.0)** |
| **4 · Económica** | Orçamentos (capítulos, artigos, preços compostos, importação Excel), medições, autos, custos previsto/comprometido/realizado, compras, subempreiteiros e portal, trabalhadores e tempos, equipamentos com QR, planeamento Gantt/CPM/PERT | Fases 2–3 | **Concluída (v0.14.0)** (sem portal próprio de subempreiteiros) |
| **5 · Inteligência** | Assistente documental com referências, apoio à fiscalização (sem aprovar), resumos e atas, previsões explicadas, voz em pt-PT, automações | Fases 2–4, fornecedor IA | Próxima — falta escolher o fornecedor de IA (contrato de tratamento de dados, alojamento na UE) |
| **6 · Comercial e escala** | Pagamentos e faturação via software certificado, cupões, upgrades/downgrades self-service, integrações ERP/BIM/assinatura, API pública e webhooks, monitorização, bases dedicadas, internacionalização | Fases 1–4 | Planeada. Faturação certificada **em espera** (decisão do cliente); feita a pré-faturação interna com exportação para o programa existente (v0.15.0) |

## Próximos passos (fases 5 e 6)

Antes de começar, são precisas duas decisões do cliente:

1. **Fase 5 — fornecedor de IA**: com contrato de tratamento de dados (RGPD) e alojamento/processamento na UE; definir o limite de custo mensal por empresa.
2. **Fase 6 — faturação**: em espera. Entretanto (0.15.0) o ObraConnect prepara pré-faturas sem valor fiscal e exporta-as (Excel, CSV, JSON) para o programa de faturação certificado que a empresa já usa; com o nome desse programa pode juntar-se um formato próprio de exportação.

## Riscos

| Risco | Prob. | Impacto | Mitigação |
| --- | --- | --- | --- |
| Limites do alojamento partilhado (CPU, processos, quota de disco) com fotografias e vídeos | Alta | Alto | S3 desde o início; processamento de imagens em fila; plano de migração para VPS documentado |
| Fila via cron atrasa emails até 1 minuto e pode ser morta pelo alojamento | Média | Médio | `--max-time=50`, `withoutOverlapping`, trabalhos idempotentes, tentativas |
| Fuga de dados entre empresas por erro de programação | Baixa | Crítico | Âmbito global que falha fechado, FKs compostas, testes de isolamento em CI, revisão obrigatória de qualquer `withoutGlobalScope` |
| Requisitos legais (livro de obra, assinatura eletrónica, faturação AT, retenção) | Média | Alto | Decisões assinaladas como **[DECIDIR]** em `01-analise-funcional.md`; revisão jurídica antes do lançamento |
| Custos e privacidade de IA | Média | Alto | Fase 5 só com fornecedor com contrato de tratamento de dados e opção UE; limites de custo por empresa |
| Complexidade do planeamento CPM/PERT e cálculos | Média | Médio | Motor isolado com testes unitários extensivos e casos de referência |
| Dependência de uma pessoa/equipa pequena | Alta | Médio | Código convencional Laravel, documentação, CI, testes |
| Adoção no terreno (resistência a novas ferramentas) | Alta | Alto | Modo obra com poucos campos, botões grandes, offline, pilotos com 2–3 empresas antes da venda alargada |

## Prioridades

1. **Segurança e isolamento** acima de funcionalidade nova.
2. **Valor no terreno** (fase 2 e 3) antes de módulos de gestão pesada (fase 4).
3. **Funcionar em cPanel** sem comprometer a migração para VPS.
4. Nunca apresentar funcionalidade incompleta como pronta na interface.
