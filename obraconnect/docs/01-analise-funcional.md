# A. Análise funcional e lacunas nos requisitos

Documento vivo. Última revisão: fase 1 (fundação SaaS multi-tenant).

## 1. Atores

| Ator | Contexto | Necessidade principal |
| --- | --- | --- |
| Administrador da plataforma | Backoffice global | Gerir empresas, planos, subscrições e incidentes sem ver dados privados dos clientes |
| Suporte técnico da plataforma | Backoffice global | Aceder a uma empresa apenas com autorização explícita, temporária e auditada |
| Administrador da empresa | Tenant | Configurar a empresa, utilizadores, perfis e subscrição |
| Diretor técnico / Diretor de obra | Tenant, várias obras | Controlo de prazos, custos, qualidade e equipas |
| Encarregado | Tenant, obra(s) atribuída(s) | Registo rápido no terreno (horas, fotos, ocorrências, diário) |
| Preparador, Medidor-orçamentista | Tenant | Orçamentos, medições, compras |
| Fiscal, Coordenador de segurança, Técnico de qualidade | Tenant ou entidade externa | Inspeções, aprovações, não conformidades |
| Administrativo | Tenant | Faturação, documentação, exportações |
| Trabalhador | Tenant, obra atribuída | Registar horas e consultar tarefas no telemóvel |
| Subempreiteiro, Fornecedor | Portal externo | Ver apenas os trabalhos, pedidos e valores autorizados |
| Dono de obra | Portal externo | Acompanhar progresso, autos e aprovações |

## 2. Processos de negócio cobertos (ciclo de vida da obra)

1. Angariação e gestão de clientes
2. Estudos preliminares
3. Orçamentação
4. Planeamento (Gantt, CPM, PERT)
5. Preparação de obra
6. Compras e contratação
7. Execução (diário, tarefas, tempos)
8. Gestão de recursos (trabalhadores, equipamentos, subempreiteiros)
9. Fiscalização e controlo de qualidade
10. Controlo financeiro
11. Autos de medição e faturação
12. Receção da obra
13. Garantias e assistência pós-obra

## 3. Requisitos funcionais por módulo (resumo)

A lista detalhada por módulo está no prompt mestre (secções 5 a 25). Aqui ficam apenas as regras de negócio que condicionam a arquitetura.

| Módulo | Regras que afetam a arquitetura |
| --- | --- |
| Multi-tenant | Isolamento rigoroso; tenant nunca vem do frontend; um utilizador pode pertencer a várias empresas |
| Administração global | O administrador global não acede livremente aos dados do cliente; suporte só com autorização auditada |
| Permissões | Configuráveis por módulo, ação, empresa e obra; perfis personalizados |
| Subscrições | Limites e preços configuráveis no backoffice, sem alterar código |
| Fiscalização | Pontos de paragem obrigatórios; histórico imutável de decisões; nunca aprovar automaticamente trabalhos críticos |
| Documentos/fotografias | Ficheiros privados por empresa; originais preservados para prova; versões otimizadas à parte |
| IA | Separação por tenant; custos por empresa; confirmação humana; desativável por cliente |
| Tempos | Geolocalização apenas proporcional, transparente e configurável |
| Financeiro | Previsões mostram fórmulas, pressupostos e dados usados |

## 4. Requisitos não funcionais

| Categoria | Requisito | Meta inicial |
| --- | --- | --- |
| Alojamento | Funcionar em cPanel partilhado ou revendedor sem Redis nem processos permanentes | Obrigatório na fase 1 |
| Desempenho | Resposta da API (p95) em listagens paginadas | < 500 ms com 100 utilizadores concorrentes num plano cPanel médio |
| Disponibilidade | Alvo em cPanel | 99,5% (dependente do alojamento) |
| Segurança | OWASP ASVS nível 2 como referência | Progressivo, checklist em `docs/10-checklist-seguranca.md` |
| Privacidade | RGPD: exportação, retenção, eliminação, registo de acessos | Base implementada na fase 1 |
| Idioma | Português europeu, preparado para internacionalização | pt-PT na fase 1 |
| Móvel | Interface utilizável em 360 px de largura; PWA instalável | Fase 1 (base), fase 2 (offline) |
| Manutenção | Atualizações sem perda de dados (migrações) | Obrigatório |
| Portabilidade | Migração para VPS/cloud sem reescrever código | Configuração por `.env` |

## 5. Lacunas e ambiguidades identificadas

Cada lacuna tem o pressuposto adotado. Os que precisam de decisão do cliente estão marcados com **[DECIDIR]**.

| # | Lacuna | Pressuposto adotado |
| --- | --- | --- |
| L1 | O prompt pede Laravel + Vue mas o alojamento-alvo é cPanel, onde normalmente não existe Node.js nem supervisor | O frontend é compilado antes do envio (ZIP de release com `public/build`); filas correm através do agendador por cron (`queue:work --stop-when-empty`) |
| L2 | Não está definido se o utilizador escolhe a empresa por subdomínio (`empresa.obraconnect.pt`) ou dentro da conta | Empresa ativa guardada na conta e trocada dentro da aplicação. Subdomínios exigem DNS curinga e SSL curinga, que muitos cPanel não oferecem |
| L3 | Perfis "por obra" exigem obras, que só chegam na fase 2 | O motor de permissões já aceita um âmbito de obra; a tabela `project_members` entra na fase 2 |
| L4 | Não há regra para o que acontece quando o período experimental termina | Trial expirado → empresa em **só leitura** durante N dias (configurável, 30 por omissão) → **suspensa**. Dados nunca são apagados automaticamente sem pedido |
| L5 | Não está definido quem pode autorizar acesso do suporte | Apenas o administrador da empresa, com motivo, duração máxima de 72 h e acesso só de leitura na fase 1 |
| L6 | Regime fiscal (IVA, retenções, faturação certificada AT) | Os preços dos planos são guardados sem IVA. Faturação certificada pela AT **não** será feita pela plataforma; integra-se com software certificado (Moloni, PHC, Primavera, Jasmin). **[DECIDIR]** confirmar com contabilista |
| L7 | Assinatura eletrónica "quando adequada" | Fase 3 decide o fornecedor (Chave Móvel Digital/Autenticação.gov, DocuSign, etc.). **[DECIDIR]** |
| L8 | Livro de obra legal vs diário digital | O diário digital é registo operacional interno. Não substitui o livro de obra exigido por lei; isso fica explícito na interface |
| L9 | Antivírus em cPanel | ClamAV raramente está disponível em alojamento partilhado. Fica opcional (socket configurável) e obrigatório em VPS |
| L10 | Volume de fotografias/vídeos pode esgotar a quota de um cPanel | Armazenamento S3 compatível (ex.: Wasabi, Backblaze B2, AWS) suportado desde o início por configuração |
| L11 | Pagamentos de subscrições | Fase 6. Gateway por decidir (Stripe, Easypay, ifthenpay com Multibanco/MB WAY). **[DECIDIR]** |
| L12 | IA: fornecedor, custos, residência dos dados | Fase 5. Requer fornecedor com contrato de tratamento de dados e opção UE. **[DECIDIR]** |
| L13 | Cálculo estrutural | Fora do âmbito das ferramentas de medição. Só seria desenvolvido como módulo separado validado por engenheiro e normas (Eurocódigos) |
| L14 | Retenção legal de dados (faturação, segurança no trabalho) | Por definir com jurista. A arquitetura permite políticas de retenção por tipo de registo. **[DECIDIR]** |
| L15 | Identificador público das empresas | Chaves numéricas internas; nunca se confia no ID enviado pelo cliente, o acesso é sempre filtrado pela empresa ativa |
| L16 | Utilizadores externos (subempreiteiro, dono de obra) | Na fase 1 são utilizadores normais com perfis restritos. O portal dedicado chega na fase 4 |

## 6. Decisões que precisam de confirmação

Só estas afetam significativamente custos, legislação ou funcionamento comercial:

1. Faturação das subscrições: emitir faturas através de software certificado (recomendado) ou por processo manual inicial.
2. Gateway de pagamentos para a fase 6.
3. Fornecedor de IA e local de processamento dos dados.
4. Política de retenção de dados após cancelamento (proposta: 90 dias em só leitura/exportável, depois eliminação).
5. Domínio comercial e se querem subdomínios por empresa no futuro.
