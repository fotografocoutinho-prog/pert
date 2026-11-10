# Histórico de versões

## 0.21.0 — 2026-11-09

Fase 6, parte 2: **API pública e webhooks** (planos Business e Enterprise), para ligar o ObraConnect à faturação, contabilidade, ERP ou BI da empresa.

- **Integrações (API)** (permissão nova `integrations.manage`, só o administrador por omissão): chaves da API com nome, âmbitos (`projects:read`, `invoicing:read`, `supplier-documents:read`, `supplier-documents:write`) e validade; a chave aparece uma vez e só fica guardado o hash; revogar é imediato; último uso à vista.
- **API pública** em `/api/public/v1` com `Authorization: Bearer oc_…`: obras (com custos e previsões), autos, pré-faturas (formato da exportação JSON) e faturas/guias de fornecedores (ler e **enviar**, que entram e são lidas pelo DocIntel como pelo ecrã). 60 pedidos por minuto por chave. Empresa só leitura: só leituras; suspensa ou sem o plano: nada.
- **Webhooks**: até 10 endereços https por empresa, cada um com os seus eventos — `certificate.approved`, `certificate.proposed`, `invoice_draft.ready`, `supplier_document.read`, `supplier_document.posted`, `alert.opened`. Assinados (HMAC-SHA256 com o segredo do webhook), repetidos ao fim de 1 min, 5 min, 30 min, 2 h e 6 h; dez eventos falhados seguidos desligam o webhook. Envio de teste, histórico dos envios com reenvio, troca do segredo.
- Segurança: só endereços públicos (nada de localhost, redes privadas, CGNAT, metadados), confirmados ao gravar e a cada envio, e a ligação vai para o IP confirmado; segredos encriptados; tudo na auditoria.
- Tarefa nova de hora a hora `obraconnect:prune-webhooks` (apaga o registo de envios terminados com mais de 30 dias), também na Saúde do sistema.
- Documentação: `docs/16-api-publica.md`.

## 0.20.0 — 2026-11-09

Fecha a Fase 4: **portal de subempreiteiros**.

- Em **Fornecedores e subempreiteiros → Portal**, quem gere subempreiteiros convida por email as pessoas de um subempreiteiro (nome e email), reenvia o convite e retira o acesso. Convite válido 14 dias, guardado só como hash.
- Quem é convidado cria a conta (só a conta, sem empresa) ou entra com a que já tem e aceita. Não passa a ser membro da empresa: o portal é uma área à parte (`/portal`), sem nada da empresa para além do que é desse subempreiteiro.
- No portal: os contratos do subempreiteiro em curso e fechados (os em preparação não aparecem), com o valor, o autado e aprovado, o retido e, por artigo, o que falta; os autos (os rascunhos que a empresa está a preparar aparecem só como "em preparação", sem valores).
- **Propor o auto do período**: as quantidades por artigo (não pode passar o que falta do contrato; só um auto por decidir de cada vez). Entra como **submetido**, com "Proposto no portal por …", e avisa por email quem aprova autos na obra. Aprovar ou devolver avisa as pessoas do subempreiteiro.
- **Documentos**: o subempreiteiro envia seguro, alvará, declarações de não dívida… com a validade e o ficheiro (PDF ou fotografia, até 20 MB); a empresa vê-os na ficha do subempreiteiro ("Enviado no portal") e abre o ficheiro. A empresa também pode juntar o ficheiro aos documentos que regista.
- Quem é membro de uma empresa e também tem acesso a um portal vê "Portal de subempreiteiro" no menu.

## 0.19.1 — 2026-11-09

- Saúde do sistema: uma falha só gera email se ainda lá estiver na verificação seguinte (10 minutos depois); "voltou ao normal" só se se tinha avisado da falha. Uma falha passageira já não envia nada.
- Verificação do DocIntel com até 30 segundos e uma segunda tentativa: em cPanel o DocIntel adormece quando não é usado e o primeiro pedido demora a acordá-lo (em produção dava "não responde" falso).

## 0.19.0 — 2026-11-09

Fase 6, parte 1: **monitorização**. O ObraConnect depende do cron (emails, alertas, leituras de faturas); agora sabe-se quando para.

- Cada tarefa agendada fica registada quando corre (`system_tasks`: início, fim, duração, erro, falhas seguidas), com um sinal de vida do cron a cada minuto.
- **Saúde do sistema** no backoffice da plataforma: cron, cada tarefa agendada, fila (trabalhos parados há mais de 10 minutos), trabalhos falhados (o super administrador pode repeti-los ou apagá-los), DocIntel (responde? documentos presos em leitura?), erros registados hoje, envio de emails, modo de depuração e disco.
- `obraconnect:system-check` a cada 10 minutos: quando uma verificação passa a falhar, email aos super administradores da plataforma e a `MONITORING_EMAILS` (enviado logo, sem passar pela fila); outro quando volta ao normal; enquanto durar, um lembrete por dia.
- `/api/v1/health` passa a dizer também `scheduler` (ok, stale, unknown) e `queue` (ok, stuck) e responde 503 quando o cron ou a fila param, para um serviço de monitorização externo (ex.: UptimeRobot) avisar quando o próprio cron morre.
- O registo de uma tarefa nunca a faz falhar (ex.: durante a atualização, antes da migração).

## 0.18.0 — 2026-11-09

Fase 5, parte 3: **histórico das previsões e alertas automáticos**, sem IA.

- Todos os dias às 7h (`obraconnect:forecast-watch`) a previsão de cada obra em preparação, em curso ou suspensa fica guardada como a da semana (tabela `forecast_snapshots`, uma linha por obra e por semana).
- Separador **Previsões**: cartão **Evolução semana a semana** com o custo previsto no fim (teto e orçamento marcados) e o fim previsto (prazo e plano marcados), e a tabela com a diferença de cada semana; os alertas abertos da obra aparecem no topo.
- **Alertas automáticos** (tabela `project_alerts`): custo previsto perto ou acima do teto, custo a subir numa semana, fim depois do prazo contratual, fim a atrasar numa semana, medições atrasadas, pontos de paragem por libertar, faturas de fornecedores por rever. Abrem e fecham-se sozinhos; cada um vai uma vez por email (um email por pessoa) ao diretor de obra que vê essa parte (sem diretor, aos administradores); volta a avisar se passar de aviso a grave.
- **Painel**: cartão **Alertas das obras**, cada pessoa só vê os alertas da parte da obra a que tem acesso.
- **Empresa → Alertas automáticos**: ligar/desligar, email, cada regra e o seu limite (company.update).
- **Painel financeiro**: diferença do custo previsto no fim e do fim previsto para a semana anterior.
- Empresa de demonstração: seis semanas de histórico fictício nas obras em curso e alertas abertos (sem emails).

## 0.17.0 — 2026-11-09

Fase 5, parte 2: **previsões explicadas**. Sem IA: as contas do controlo de obra, feitas com os dados que já estão no ObraConnect, e cada número com as razões escritas.

- Separador **Previsões** em cada obra (quem vê custos ou o planeamento da obra; cada parte só a quem tem acesso a ela).
- **Custo no fim da obra**: trabalho medido (quantidades medidas × custo unitário do orçamento aprovado), o que falta (quantidades por medir × custo unitário) e o custo até à última medição (realizado e material recebido ainda por faturar). Daí o **ritmo de custo** (quanto custa cada euro orçamentado). A previsão conta o que falta ao preço do orçamento enquanto houver menos de metade medida e ao ritmo de até aqui a partir daí, e nunca fica abaixo do realizado mais o comprometido. Três cenários lado a lado, margem prevista face à do orçamento, situação face ao teto e **confiança** (baixa, média, alta) conforme o que está medido e a idade da última medição.
- **Razões do custo**: teto passado ou perto, margem que desce ou sobe, ritmo de custo acima ou abaixo do orçamento, artigos medidos acima da quantidade orçamentada (com o custo a mais), naturezas (materiais, mão de obra…) acima do previsto, medições atrasadas com os custos que entraram depois, já gasto + comprometido acima da conta.
- **Fim da obra**: o planeamento (caminho crítico) recalculado a partir de hoje — o que está feito já não ocupa tempo, o que está em curso só o que lhe falta, nada do que falta começa antes de hoje. Comparado com o planeamento e com o prazo contratual; planeado para hoje contra feito.
- **Razões do prazo**: as atividades do caminho crítico atrasadas por si próprias (as que estão só à espera da anterior não contam como causa), pontos de paragem por libertar, atividades atrasadas com folga, prazo contratual passado ou com folga. Lista das atividades atrasadas ou empurradas.
- **Painel financeiro**: custo previsto no fim (com a confiança), margem prevista com ele, teto face à previsão e **fim previsto** de cada obra; o realizado e o comprometido ficam numa coluna. O separador **Custos** passa a chamar "realizado + comprometido" ao que antes chamava "previsão final" e aponta para as Previsões.
- Medições feitas numa versão anterior do orçamento contam no artigo com o mesmo código da versão aprovada.

## 0.16.0 — 2026-11-08

Fase 5, parte 1: leitura de faturas e guias de fornecedores pelo **CODA DocIntel** (o motor de leitura próprio, já instalado em coda.pt/docintel). Sem IA paga: o DocIntel lê o código QR das faturas portuguesas e o texto dos PDF; a ligação a um modelo de IA fica para quando houver clientes.

- **Faturas de fornecedores** (menu, para quem tem a permissão nova `supplier_docs.manage`) e, em cada obra, no separador **Compras**: juntar faturas, notas de crédito, talões e guias em PDF ou fotografia (vários de uma vez, ou arrastando).
- **Leitura automática** (quando `DOCINTEL_URL` e `DOCINTEL_KEY` estão definidos): tipo, fornecedor e NIF, número, ATCUD, datas, valores sem IVA/IVA/total, referência da encomenda e linhas. O fornecedor é reconhecido pelo NIF; a **compra** é proposta quando o número da encomenda ou o valor sem IVA batem, e com ela vem a obra. Sem DocIntel, ou se a leitura falhar, preenche-se à mão (o erro fica à vista).
- **Rever e lançar**: o original ao lado dos dados. Fatura ou talão com compra → a compra fica **faturada** pelo valor sem IVA; sem compra → **custo realizado** da obra (com a natureza escolhida); nota de crédito → custo negativo; guia com compra → a compra fica **recebida**. **Desfazer lançamento** repõe a compra ou retira o custo.
- **Avisos**: o mesmo ficheiro não entra duas vezes; a mesma fatura (fornecedor + número) é avisada e não se lança duas vezes; NIF do cliente diferente do da empresa; totais que não batem; fornecedor que é subempreiteiro (o custo pode já estar nos autos); documento anulado segundo o QR.
- Lançar numa compra exige `purchases.manage` na obra; como custo, `costs.manage`. Quem gere compras ou custos numa obra trata os documentos dessa obra; a caixa da empresa (documentos ainda sem obra) é de `supplier_docs.manage` (diretor técnico e administrativo).
- Custos que vieram de uma fatura não se apagam no mapa de custos (desfaz-se na fatura); fornecedores com faturas não se apagam.
- Empresa de demonstração com cinco documentos: uma fatura lançada na compra, um talão lançado como custo, uma fatura e uma guia por rever (com a compra proposta) e um duplicado rejeitado.

## 0.15.0 — 2026-11-08

Pré-faturação: documentos internos, sem valor fiscal, para exportar para o programa de faturação certificado. A ligação direta a um programa de faturação (Fase 6) fica em espera.

- **Faturação** (menu): pré-faturas com cliente (copiado do cliente da obra ou escrito), data, vencimento, referência, linhas com quantidade, preço, desconto e taxa de IVA, motivo de isenção (com a menção "IVA - autoliquidação" por omissão quando o orçamento é em autoliquidação) e notas. Totais com o quadro de IVA por taxa.
- **A partir de um auto**: nos autos ao dono de obra aprovados, **Criar pré-fatura** traz o cliente, a obra, a referência do auto e as linhas deste período aos preços de venda e com o IVA do orçamento. Um auto só tem uma pré-fatura ativa; o auto mostra a ligação.
- Estados: rascunho → **pronta a faturar** (recebe o número interno PF AAAA/NNNN, por ano, sem buracos) → **faturada** (com o número e a data da fatura emitida no programa certificado) ou anulada. Pronta pode voltar a rascunho; faturada já não se altera.
- **Exportação** para importar no programa de faturação: **Excel** (números e datas a sério), **CSV** (";" e vírgula decimal, para o Excel em português) e **JSON** (documentos com linhas, para importações por API). Uma linha por linha de pré-fatura, com os dados do cliente repetidos. Por omissão exportam-se as prontas a faturar; também uma só pré-fatura.
- **PDF** da pré-fatura, sempre com o aviso "documento interno sem valor fiscal — não serve de fatura".
- **Painel financeiro**: coluna e total **Faturado** (pré-faturas com a fatura oficial registada, sem IVA).
- Permissões novas: `invoicing.view` (diretor técnico, administrativo) e `invoicing.manage` (administrativo); valem para a empresa toda. Clientes com pré-faturas não se apagam.
- Empresa de demonstração com pré-faturas: três faturadas, uma pronta a exportar e um adiantamento em rascunho.

## 0.14.0 — 2026-11-08

Fase 4 completa: gestão económica da obra. E uma empresa de demonstração com dados fictícios em todas as áreas.

- **Biblioteca de preços** (menu **Biblioteca de preços**): recursos (mão de obra, materiais, equipamentos, subempreitadas) com custo unitário e **artigos compostos** (recurso × rendimento). Usados não se apagam: arquivam-se.
- **Orçamentos** (separador **Orçamento** da obra): capítulos e subcapítulos, artigos da biblioteca ou escritos à mão, com a composição do preço. Preço de venda = custo × (1 + encargos) × (1 + margem), ou escrito à mão por artigo. IVA à taxa, autoliquidação ou isento. Totais por capítulo e custo por natureza. **Importar Excel/CSV** (Código, Descrição, Unidade, Quantidade, Custo unitário; linhas sem unidade são capítulos) e **Exportar CSV**. Rascunho → **aprovado** (passa a ser a base dos autos e do custo previsto) → **revisão** (nova versão em rascunho; ao aprovar, a anterior fica substituída).
- **Medições e autos** (separador **Medições e autos**): medições por dimensões (n.º × comprimento × largura × altura) ou quantidade, sobre os artigos do orçamento aprovado, com o avanço por artigo. **Auto de medição** ao dono de obra: junta as medições ainda não autadas até ao fim do período, aos preços de venda; quantidades acertáveis em rascunho; rascunho → submetido → aprovado (`certificates.approve`); aviso quando o acumulado passa o contratado.
- **Fornecedores e subempreiteiros** (menu): especialidades e documentos com validade (alvará, seguros, não dívida) com aviso de "a expirar" e "expirado".
- **Compras** (separador **Compras e subempreitadas**): encomendas com linhas (ligadas a recursos e artigos do orçamento), natureza e centro de custo. Rascunho → aprovada (custo **comprometido**) → recebida → **faturada** (custo **realizado**, com n.º e valor da fatura) ou cancelada.
- **Subempreitadas**: contrato com trabalhos e preços acordados e retenção de garantia. Rascunho → adjudicado (comprometido) → fechado. **Autos de subempreitada** com a retenção calculada; aprovados contam como realizado.
- **Trabalhadores e horas**: trabalhadores da empresa ou de subempreiteiros, com custo/hora e ligação opcional a um utilizador (que regista as próprias horas). Separador **Horas e equipamentos**: grelha da semana, **folha do dia** (horas normais e extra por pessoa), aprovação em bloco, e **exportação mensal para salários** (CSV). O custo usa o custo/hora do dia do registo; as horas extra contam a 150%.
- **Equipamentos** (menu): onde estão, custo/hora, situação, manutenções com próxima data (aviso de atraso), horas por obra (entram no custo) e **etiqueta QR** para imprimir, que abre a ficha no telemóvel.
- **Custos** (separador **Custos**): previsto (orçamento aprovado), comprometido, realizado e previsão final por natureza, por centro de custo e por mês; lançamentos manuais (taxas, seguros, ajustes); **teto de custo** (por omissão, o custo do orçamento) com alerta perto (≥ 90%) e acima do teto; margem prevista; exportação CSV.
- **Painel financeiro** (menu, `finance.view`): por obra, contrato, autado, realizado, comprometido, previsão, margem e situação face ao teto.
- **Planeamento** (separador **Planeamento**): atividades e resumos, duração em dias úteis (calendário da obra, com feriados), precedências fim→início, início→início, fim→fim e início→fim com desfasamento, data "não antes de", **caminho crítico** (CPM), estimativa **PERT** (otimista / mais provável / pessimista), avanço e **gráfico de Gantt**. Uma atividade com **ponto de paragem** por libertar não se dá como concluída; as inspeções podem ligar-se a uma atividade.
- Permissões novas: `workforce.manage` e as da Fase 4 que estavam só planeadas passam a existir (valores por omissão na matriz).
- **Empresa de demonstração**: `php artisan obraconnect:demo --admin=email` cria a "Atlântico Construções (demonstração)" com equipa fictícia de 15 pessoas (emails `@atlantico-demo.test`), departamentos, centros de custo, clientes, biblioteca de preços, fornecedores e subempreiteiros, trabalhadores, equipamentos e quatro obras (em curso, em curso acima do teto, em preparação e concluída) com estrutura, diário com fotografias, documentos, inspeções com pontos de paragem, ocorrências e NC, orçamentos, autos, compras, subempreitadas, horas, custos e planeamento. As datas são relativas ao dia em que se corre. `--fresh` apaga e volta a criar. O utilizador indicado em `--admin` fica administrador e muda de empresa no menu.
- **Emails para domínios reservados** (`.test`, `.example`, `.invalid`, `.localhost`, `example.com/.net/.org`) nunca saem: são retirados dos destinatários e, se não sobrar ninguém, o envio é cancelado.
- Corrigido: ao mudar de empresa, a página aberta (por exemplo o painel) continuava com os dados da empresa anterior até se recarregar; e ao passar de uma obra para outra, o separador aberto mostrava os dados da obra anterior.
- Os separadores novos da obra só se carregam quando são abertos (a página da obra ficou com metade do tamanho).

## 0.13.0 — 2026-11-06

Fase 3, terceira parte: aprovações em vários passos, relatório PDF e emails. Fecha a Fase 3.

- **Aprovações em vários passos**: uma lista de verificação (e cada inspeção) diz quantas aprovações de **pessoas diferentes** são precisas (1 a 3). A inspeção fica em apreciação até as juntar todas; cada aprovação fica no histórico encadeado ("Aprovada (1 de 2)"); quem já aprovou não aprova outra vez; uma rejeição decide logo; devolver e voltar a submeter recomeça a contagem. Com reservas em qualquer uma, a decisão final é "aprovada com reservas", com a nota de cada pessoa.
- **Relatório da inspeção em PDF** (botão **Relatório PDF**): empresa e obra, estado, local, especialidade, lista e versão, datas e pessoas, decisão e aprovações, índice de evidências explicado, pontos com resultados, medições, observações, miniaturas das fotografias e NC ligadas, histórico com o hash de cada passo, verificação da cadeia e código de verificação. Antes de decidida sai marcado como provisório. Gerado no momento (dompdf), não fica guardado.
- **Emails de aviso** às pessoas da equipa da obra (nunca a quem fez a ação): inspeção pedida (a quem faz inspeções), submetida (a quem decide), decidida ou devolvida (a quem pediu e a quem fez), ponto de paragem libertado (a quem pediu, a quem fez e aos diretores de obra), ocorrência atribuída (ao responsável), resolvida (a quem a registou e aos diretores de obra) e reaberta (ao responsável). O texto escrito pelos utilizadores é escapado no email.
- Segurança: bibliotecas Symfony atualizadas dentro da série 7.4 (http-foundation, http-kernel, mime, routing, serializer) para versões sem falhas conhecidas.

## 0.12.0 — 2026-11-04

Fase 3, segunda parte: pontos de paragem e ocorrências / não conformidades.

- **Pontos de paragem**: uma inspeção (ou, por omissão, todas as de uma lista de verificação) pode ser ponto de paragem. Os trabalhos nesse local ou elemento ficam **parados** até a inspeção ser aprovada e alguém com `inspections.release_hold_point` (por omissão, o fiscal) a **libertar**; a libertação fica no histórico encadeado. A estrutura da obra marca os elementos parados; o separador Inspeções tem o filtro **Pontos de paragem**. A reinspeção herda o ponto de paragem; uma inspeção cancelada não para nada.
- **Ocorrências e não conformidades** (separador **Ocorrências e NC**): tipo, gravidade (menor, maior, crítica), local ou elemento, especialidade, responsável (da equipa da obra), prazo, fotografias, comentários e historial. Estados: aberta, em resolução, resolvida (por validar), fechada e anulada. Quem resolve é o responsável (`issues.resolve`); quem valida, reabre e anula tem `issues.validate`. Resolver, reabrir e anular pedem texto.
- **NC a partir de uma inspeção**: num ponto não conforme, **Abrir não conformidade** cria-a já com o texto do ponto, a observação, o local e a especialidade; a inspeção mostra a NC ligada a cada ponto.
- **Escalonamento**: todos os dias às 7h30, as ocorrências por resolver com o prazo ultrapassado são escalonadas uma vez — email ao responsável e aos diretores de obra da equipa (sem nenhum, aos administradores da empresa), um email por pessoa com todas as suas. Mudar o prazo ou reabrir volta a permitir escalonar.
- Permissões novas: `inspections.release_hold_point`, `issues.view`, `issues.create`, `issues.resolve`, `issues.validate` (valores por omissão da matriz). Locais, elementos e especialidades com ocorrências não se apagam. Ocorrências na exportação de dados e na auditoria.

## 0.11.0 — 2026-11-04

Fase 3, primeira parte: listas de verificação e inspeções.

- **Listas de verificação** da empresa (ex.: Betonagem de lajes): pontos a verificar, cada um com as evidências que exige — fotografia (com número mínimo) e/ou medição (unidade e limites opcionais) — e indicações para quem inspeciona. Mudar os pontos sobe a versão; usadas em inspeções não se apagam, arquivam-se. Permissão `checklists.manage`.
- **Inspeções** na obra (separador **Inspeções**), criadas a partir de uma lista (os pontos são copiados: mudar a lista depois não altera a inspeção) ou com pontos escritos à mão; local ou elemento da estrutura, especialidade, data pretendida, inspeção remota.
- **9 estados**: rascunho, pedida, agendada, em curso, em apreciação, aprovada, aprovada com reservas, rejeitada e cancelada. Quem pede (`inspections.request`), quem faz (`inspections.perform`) e quem decide (`inspections.approve`) são permissões separadas; com pontos não conformes não se aprova sem reservas; reservas, rejeição, devolução e cancelamento exigem justificação. Depois de rejeitada (ou aprovada com reservas) pede-se uma **reinspeção** com os mesmos pontos.
- **Evidências obrigatórias**: não se submete sem resultado em todos os pontos, as fotografias e medições exigidas e a descrição de cada não conformidade.
- **Índice de suficiência das evidências** (0–100), explicado critério a critério: os obrigatórios e os recomendados (não conformidades com fotografia, medições conformes dentro dos limites, fotografia em cada ponto numa inspeção remota). Fica registado na submissão; nunca aprova nada sozinho.
- **Histórico encadeado**: cada passo (criada, pedida, agendada, iniciada, submetida, devolvida, decidida, cancelada, reinspeção) fica com o SHA-256 do passo anterior; o ecrã mostra se a cadeia está íntegra e aponta o primeiro passo alterado fora da aplicação. A submissão guarda os resultados e o SHA-256 de cada fotografia.
- Fotografias de evidência com o original intacto e versões reduzidas, como as do diário; só as vê quem vê as inspeções da obra.
- Locais, elementos e especialidades com inspeções não se apagam. Listas e inspeções (com o histórico completo) entram na exportação de dados e na auditoria.
- Corrigidas as datas das versões 0.3.0 a 0.10.0 neste histórico (estavam adiantadas).

## 0.10.0 — 2026-11-04

Fase 2, nona parte: especialidades. Fecha a Fase 2.

- **Especialidades** (Arquitetura, Estruturas, Águas e esgotos, Instalações elétricas, ITED, AVAC, SCIE…): código curto único na empresa, sem distinguir maiúsculas. As empresas novas começam com a lista habitual de 13; as anteriores juntam-na com **Usar a lista habitual**. Com elementos ou documentos não se apagam: arquivam-se. Permissão `specialties.manage`.
- **Elementos construtivos** com especialidade (o código aparece junto ao elemento na estrutura; também sem rede).
- **Documentos** com especialidade: escolhe-se na janela do documento; filtro por especialidade em todas as pastas; com o filtro ativo, os documentos enviados ficam com essa especialidade.
- O ecrã "Departamentos e centros de custo" passa a **Organização** (departamentos, centros de custo e especialidades).
- Corrigido: a janela de um documento registava um erro na consola ao abrir o separador Documentos (variável usada antes de declarada); não tinha efeito visível.
- Especialidades entram na exportação de dados e na auditoria.

## 0.9.0 — 2026-11-04

Fase 2, oitava parte: departamentos e centros de custo.

- **Departamentos** (ex.: Produção, Orçamentação, Compras): cada pessoa está num departamento ou em nenhum. Escolhem-se as pessoas de cada departamento (quem é escolhido sai do anterior); apagar um departamento deixa as pessoas sem departamento. Permissão `departments.manage`.
- Em **Utilizadores**, o departamento aparece por baixo do perfil e há filtro por departamento (ou "sem departamento").
- **Centros de custo** em árvore, com código automático (1, 2… na raiz; 1.01, 1.02… dentro de 1) ou escrito à mão, único na empresa sem distinguir maiúsculas. Não ficam dentro de si próprios; com centros dentro ou obras não se apagam, arquivam-se. Permissão `cost_centers.manage`.
- Cada **obra** pode ter um centro de custo (nos dados da obra). Os custos que lá vão cair chegam na fase 4.
- Departamentos e centros de custo entram na exportação de dados e na auditoria.

## 0.8.0 — 2026-11-03

Fase 2, sétima parte: clientes e calendários de trabalho.

- **Clientes** (particulares, empresas, entidades públicas): NIF português validado (dígito de controlo) e único na empresa, ou n.º fiscal estrangeiro; contactos, morada, notas. Com obras não se apagam: arquivam-se (saem das escolhas, as obras continuam a mostrá-los). Permissões `clients.view` e `clients.manage`.
- Cada obra pode ter **cliente** (escolhido na lista ou criado ali mesmo, no formulário da obra) e continua a ter **dono de obra** em texto, para quando a empresa trabalha como subempreiteira. A ficha do cliente mostra as obras dele a que quem consulta tem acesso.
- **Calendários de trabalho**: horas por dia da semana e dias sem trabalho (feriados nacionais, municipais, facultativos e paragens da empresa, um dia ou um período). Cada empresa tem um calendário por omissão (segunda a sexta, 8 h, com os feriados nacionais deste ano e do seguinte); cada obra pode escolher outro. Permissão `calendars.manage`.
- Feriados nacionais calculados para qualquer ano desde 2016 (art. 234.º do Código do Trabalho, com Páscoa, Sexta-feira Santa e Corpo de Deus móveis); Carnaval opcional. Aviso quando falta o ano seguinte.
- **Prazo da obra em dias úteis** (no separador Dados): dias úteis e de calendário entre o início e o fim previsto, decorridos e por fazer, com aviso se o calendário não tiver os feriados de algum ano do prazo.
- **Diário**: os feriados aparecem junto ao dia; "Ainda não há registo de hoje" não aparece em dias sem trabalho; numa obra em curso, lista dos dias úteis do mês já passados sem registo (cada um abre o registo desse dia).
- Clientes e calendários entram na exportação de dados e na auditoria.

## 0.7.0 — 2026-11-03

Fase 2, sexta parte: modo sem rede no diário de obra.

- Sem rede, o registo do diário (texto, linhas, "onde") e as fotografias ficam guardados no telemóvel (IndexedDB) e são enviados sozinhos quando houver rede: ao abrir a aplicação, quando a rede volta e de minuto a minuto. "Guardar e submeter" sem rede submete depois de enviar.
- Envio passo a passo e retomável (registo, cada fotografia, submissão), idempotente: o servidor aceita `client_uuid` no registo e em cada fotografia e devolve o que já existe num reenvio — nada fica duplicado nem conta duas vezes na quota.
- Conflitos (o mesmo dia criado noutro aparelho, registo entretanto submetido) não perdem nada: ficam no telemóvel com o motivo, "Tentar outra vez" e "Descartar".
- A aplicação abre sem rede: o service worker guarda todos os ecrãs compilados (lidos de `build/manifest.json`) e atualiza o shell a cada versão; a última sessão, obra, estrutura e diário vistos ficam numa cópia local, apagada ao sair da conta. O service worker continua a não guardar respostas da API.
- Indicadores "Sem rede", "N por enviar" e "N com problema"; registos só no telemóvel aparecem na lista do diário.

## 0.6.0 — 2026-11-03

Fase 2, quinta parte: ligar o diário, as fotografias e os documentos à estrutura.

- Cada linha do diário, fotografia e documento pode indicar "onde": um edifício, piso, zona ou elemento (P1, L1...). Com elemento, o local vem dele e acompanha-o se o elemento mudar de sítio.
- Na Estrutura, o histórico de um elemento ou local mostra as linhas do diário (com ligação ao dia), as fotografias e os documentos ligados; num local inclui o que está dentro dele.
- O histórico respeita as permissões: diário e fotografias só para quem consulta o diário; documentos só para quem os consulta.
- Não se apagam locais nem elementos com ligações (para não perder histórico).
- Ligações sempre dentro da mesma obra (validação) e da mesma empresa (chaves estrangeiras compostas).

## 0.5.0 — 2026-11-03

Fase 2, quarta parte: estrutura da obra.

- Edifícios, pisos e zonas numa hierarquia (piso dentro de edifício; zona dentro de piso ou diretamente no edifício).
- Criar vários pisos de uma vez ("-1 a 3").
- Elementos construtivos (sapata, pilar, viga, laje, parede, escada, muro de suporte, cobertura, outro) em cada localização, com código automático por tipo (S1, P1, V1, L1, PA1...) e único na obra.
- Permissão nova `structure.manage` (diretor técnico, diretor de obra, preparador, medidor-orçamentista); quem vê a obra vê a estrutura.
- Só se apaga uma localização vazia; elementos podem mudar de localização. Estrutura na exportação de dados.

## 0.4.0 — 2026-11-03

Fase 2, terceira parte: documentos da obra.

- Pastas e subpastas por obra; pesquisa em todas as pastas (título e nome do ficheiro).
- Documentos com versões imutáveis (v1, v2, …) e nota do que mudou; SHA-256 de cada versão; a versão atual é a mais recente.
- PDF e imagens abrem no browser; os restantes tipos são sempre descarregados como binário. Cada abertura e descarga fica registada.
- Tipos aceites: PDF, DWG, DXF, IFC, Office, OpenDocument, TXT, CSV, imagens e ZIP, até 50 MB.
- Permissões novas `documents.view`, `documents.upload` (inclui versões dos documentos que a própria pessoa enviou) e `documents.manage` (pastas, mover, apagar, versões de qualquer documento), obra a obra.
- Quota de armazenamento; apagar um documento apaga todas as versões e liberta o espaço. Lista na exportação de dados.

## 0.3.0 — 2026-11-03

Fase 2, segunda parte: fotografias no diário de obra.

- Fotografias em cada registo do diário (tirar com a câmara do telemóvel ou escolher da galeria), com legenda.
- O original é guardado sem alterações, com SHA-256; pré-visualização (1600 px) e miniatura (400 px) geradas em fila, com a orientação do telemóvel corrigida e a data da fotografia lida do EXIF. As versões reduzidas não levam metadados (nem GPS).
- Só JPEG, PNG e WebP, verificados pelo conteúdo; até 20 MB e 30 fotografias por registo.
- Quota de armazenamento do plano (original + versões reduzidas); ficheiros servidos só a quem pode consultar o diário da obra.
- Fotografias seguem o registo: só se juntam ou apagam em rascunho; apagar o rascunho apaga os ficheiros.
- Lista das fotografias (com SHA-256) na exportação de dados.

## 0.2.0 — 2026-11-03

Fase 2, primeira parte: obras e diário de obra.

- Obras: criar, editar, código automático (OB-AAAA-NNN), estados (em preparação, em curso, suspensa, concluída, arquivada) e limite de obras ativas do plano.
- Equipa da obra com perfil próprio em cada obra; permissões por obra (`projects.all` dá acesso a todas, sem ela só às da equipa).
- Diário de obra: um registo por dia com tempo, trabalhos executados, mão de obra, equipamentos, materiais, ocorrências, visitas e observações; rascunho → submetido (fechado). Copiar mão de obra e equipamentos do registo anterior.
- Obras e diário incluídos na exportação de dados; auditoria de todas as alterações.
- Agendador sem `proc_open` (tarefas com `Schedule::call()`), necessário em alojamentos cPanel que o desativam.
- Correções: redirecionamento HTTPS numa subpasta repetia a pasta; a auditoria guardava o valor novo também como valor antigo.

## 0.1.0 — 2026-10-28

Fase 1: fundação SaaS multi-tenant.

- Multi-tenancy em base partilhada com isolamento que falha fechado, FKs compostas e contexto em filas.
- Autenticação (registo de empresa, convites, verificação de email, recuperação de password, 2FA).
- Perfis e permissões por empresa (14 perfis-modelo), proteção contra escalada de privilégios.
- Planos e subscrições configuráveis no backoffice; ciclo trial → só leitura → suspensão.
- Backoffice da plataforma; acesso do suporte autorizado, temporário e auditado.
- Auditoria append-only; exportação de dados por empresa.
- SPA Vue 3 + TypeScript + Tailwind em pt-PT, PWA instalável.
- Instalação em cPanel: SQL para phpMyAdmin, página /instalar, ZIP de release, cron único.
- Instalação em subpasta de um site existente (ex.: www.coda.pt/obras/obraconnect) e pacote pronto a extrair para esse caso.
- /instalar gera a APP_KEY quando falta (instalação sem SSH).
