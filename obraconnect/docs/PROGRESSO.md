# Registo de progresso

## v0.20.0 — portal de subempreiteiros (2026-11-09)

| Área | Verificação |
| --- | --- |
| Convite, conta nova sem empresa, aceitação com conta existente, revogação | Testes (`PartnerPortalTest`) |
| Isolamento: só os contratos do próprio subempreiteiro (ativos e fechados), nada das rotas da empresa, acesso de outra conta ou revogado = 404 | Testes |
| Proposta de auto (período futuro, artigo de outro contrato, acima do contrato, vazia, um por decidir de cada vez), emails à empresa e ao subempreiteiro, rascunhos internos escondidos | Testes + browser |
| Documentos com ficheiro (portal e empresa), só PDF/JPG/PNG até 20 MB, download só do próprio | Testes + browser |
| Portal, contrato, documentos e janela do portal na empresa a 360 px | Browser |

Decisão: um auto proposto entra como submetido (a empresa decide como em qualquer auto) e não pode passar o que falta do contrato; trabalhos a mais pedem revisão do contrato pela empresa.

## v0.19.1 — correção da monitorização (2026-11-09)

Visto em produção logo a seguir à 0.19.0: a verificação do DocIntel falhava com 5 segundos porque o DocIntel (Passenger) adormece e demora a acordar. Agora 30 segundos com nova tentativa, e nenhuma falha gera email sem ser confirmada na verificação seguinte (teste `test_a_failure_that_comes_and_goes_never_sends_email`).

## v0.19.0 — Fase 6, parte 1: monitorização (2026-11-09)

| Área | Verificação |
| --- | --- |
| Registo das tarefas (êxito, código diferente de 0, exceção relançada) | Testes (`SystemHealthTest`) |
| `/health` com o cron e a fila: desconhecido antes do primeiro sinal, 503 com o cron parado há mais de 5 minutos ou um trabalho parado há mais de 10 | Testes |
| Avisos por email: ao falhar, lembrete diário, de volta ao normal; só super administradores e MONITORING_EMAILS | Testes |
| Página Saúde do sistema (equipa da plataforma) e trabalhos falhados (repetir/apagar só super administrador) | Testes + browser |

Sem tabelas por empresa: `system_tasks` e `system_checks` são da plataforma. O cron morto não consegue avisar ninguém: para isso serve o monitor externo no `/health`.

## v0.18.0 — Fase 5, parte 3: histórico e alertas automáticos (2026-11-09)

| Área | Verificação |
| --- | --- |
| Fotografia semanal (uma por obra e semana, atualizada todos os dias), histórico no separador Previsões e diferença no painel financeiro | Testes (`ForecastWatchTest`) + browser com a demonstração |
| Alertas: abrem, passam de aviso a grave (novo email), fecham-se; um email por pessoa; regras da empresa (limites validados); permissões por parte da obra | Testes |
| Pontos de paragem e faturas por rever há demasiado tempo | Testes |
| Painel, Previsões, Empresa e painel financeiro a 360 px sem nada a sair do ecrã | Browser |

186 testes (2196 verificações). Limitações: o histórico começa quando a versão é instalada (o avanço do planeamento não guarda datas, por isso não se reconstrói o passado); os alertas não se podem silenciar à mão (fecham quando a situação passa ou quando a regra é desligada).

## v0.17.0 — Fase 5, parte 2: previsões explicadas (2026-11-09)

Continua a decisão de não usar IA paga: as previsões são as contas do controlo de obra (valor ganho e caminho crítico), com as razões escritas em português e ligadas ao separador onde se resolve cada uma.

| Área | Verificação |
| --- | --- |
| Custo no fim: trabalho medido, o que falta, custo até à última medição com o material recebido por faturar, ritmo de custo, cenário escolhido (orçamento até 50 % medido, ritmo depois), mínimo realizado + comprometido, teto, margem, confiança | Testes (`ForecastTest`, valores exatos: 12 720 € pelo ritmo de 1,20 €) + browser com a demonstração |
| Razões do custo: teto, margem, ritmo, quantidades acima do orçamento, naturezas acima do previsto, medições com mais de 30 dias numa obra em curso, já gasto + comprometido acima da conta | Testes + browser |
| Fim da obra: CPM recalculado a partir de hoje (feito = 0 dias, em curso = o que falta, nada antes de hoje), atraso face ao plano em dias úteis e ao prazo contratual em dias, planeado para hoje contra feito (pesado pela duração) | Testes (datas exatas em novembro de 2026) + browser |
| Razões do prazo: causas no caminho crítico (sem as que só esperam pela anterior), pontos de paragem, atrasadas com folga | Testes + demonstração (Moradia: alvenarias por começar e laje do piso 1 com ponto de paragem) |
| Permissões: custo para costs.view/costs.manage/finance.view, prazo para planning.view/planning.manage; isolamento entre empresas | Testes |
| Painel financeiro com custo previsto no fim, margem, teto e fim previsto; 360 px sem nada a sair do ecrã | Testes + browser |

Decisões: com menos de metade do trabalho medido a previsão conta o que falta ao preço do orçamento (as compras feitas de uma vez no início fazem o ritmo parecer muito pior do que é) e mostra o ritmo como cenário; o material recebido por faturar conta como gasto ao comparar com o trabalho medido; a margem prevista usa a venda do orçamento (trabalhos a mais faturáveis não entram); o mapa de custos mantém o "realizado + comprometido" (o que já se sabe), agora com esse nome.

### Limitações

- Sem histórico das previsões (não se vê como a previsão mudou de semana para semana); calcula-se sempre com os dados de hoje.
- O avanço das atividades não guarda as datas reais de início: o que está feito deixa de ocupar tempo, mas não se compara a duração real com a planeada.
- O prazo não sabe de recursos (equipas, equipamentos): uma atividade em curso acaba no proporcional à duração planeada.

## v0.16.0 — Fase 5, parte 1: faturas e guias de fornecedores lidas pelo DocIntel (2026-11-08)

Decisão do cliente: "fico para já com o DocIntel" e, se funcionar, a possibilidade de ligar a Claude, Gemini ou ChatGPT; **sem IA paga enquanto não houver clientes**. O DocIntel (Python/FastAPI, em produção em coda.pt/docintel, Passenger) lê o QR das faturas portuguesas e o texto dos PDF; não lê fotografias de papel no alojamento (não há Tesseract), mas lê o QR de muitas fotografias.

| Área | Verificação |
| --- | --- |
| Leitura pelo DocIntel (pedido síncrono `/documents/analyze` com `X-API-Key`), preenchimento dos campos e das linhas, fornecedor pelo NIF, compra proposta pelo n.º da encomenda ou pelo valor | Testes (`SupplierDocumentsTest`, DocIntel simulado com `Http::fake`) + browser com o DocIntel local |
| Lançar: compra faturada pelo valor sem IVA, custo realizado, nota de crédito negativa, guia → compra recebida; desfazer lançamento | Testes + browser |
| Duplicados (mesmo ficheiro, mesma fatura), NIF do cliente, totais, documento anulado, leitura falhada (chave recusada) com preenchimento à mão | Testes |
| Permissões: caixa da empresa (supplier_docs.manage), documentos da obra para quem gere compras/custos, lançar exige purchases.manage ou costs.manage na obra; obra arquivada só consulta; isolamento entre empresas | Testes |
| Custos vindos de faturas não se apagam no mapa; fornecedores com faturas não se apagam; espaço ocupado da empresa | Testes |

Decisões: o custo é sempre o valor **sem IVA** (como as compras e o orçamento); um recibo não é custo (é a prova de pagamento de uma fatura); uma nota de crédito não se lança numa compra; o DocIntel guarda uma cópia do ficheiro com a referência `obraconnect:<empresa>:<documento>`.

### Limitações

- Sem leitura de fotografias de papel no servidor (sem OCR de imagem no alojamento); o QR de muitas fotografias lê-se na mesma.
- Sem conciliação linha a linha (quantidades da guia contra a encomenda): a guia dá a compra como recebida por inteiro, como o botão "recebida".
- A IA generativa (resumos, perguntas aos documentos) fica para depois, desligada por omissão; o DocIntel já tem adaptadores para Claude e OpenAI.

## v0.15.0 — Pré-faturação para o programa de faturação certificado (2026-11-08)

Decisão do cliente: a ligação a um programa de faturação certificado (Fase 6) fica em espera; entretanto o ObraConnect prepara pré-faturas sem valor fiscal e exporta-as para importar no programa que a empresa já usa.

| Área | Verificação |
| --- | --- |
| Pré-fatura a partir de um auto aprovado (cliente, obra, referência, linhas do período, IVA do orçamento; autoliquidação com a menção), só uma ativa por auto, auto por aprovar ou de subempreitada recusado | Testes (`InvoicingTest`) + browser |
| Rascunho editável, pronta com número interno por ano, voltar a rascunho, fatura oficial registada (número e data), anular; faturada não se altera | Testes + browser (linha juntada com "1.250,50", total 81 488,12 €, PF 2026/0005, FT A/2026/0500) |
| Exportação Excel, CSV (";" e vírgula decimal, BOM) e JSON; PDF com o aviso de documento interno | Testes + browser (os três ficheiros descarregados) + PDF aberto e conferido |
| Permissões (diretor técnico consulta, administrativo gere, encarregado sem acesso), isolamento entre empresas, cliente com pré-faturas não se apaga | Testes |
| Painel financeiro com o faturado; 360 px sem deslocamento horizontal | Browser |

Total: **178 testes, 1818 asserções**.

Decisões: a numeração PF é interna (não fiscal) e só é dada quando a pré-fatura fica pronta, para não haver buracos; o IVA é arredondado linha a linha (como sai na exportação; o programa certificado faz as contas finais); o motivo de isenção é obrigatório para ficar pronta quando há linhas a 0%; os códigos de isenção da AT escrevem-se à mão (confirmar com o contabilista).

### Limitações

- Não há formato próprio de nenhum programa de faturação: a folha tem colunas genéricas para mapear no importador. Com o nome do programa usado, junta-se um formato específico.
- A pré-fatura não é para entregar ao cliente como fatura: confirmar com o contabilista se e como pode ser enviada como documento de conferência.
- Não há notas de crédito nem recibos (fazem-se no programa certificado).

## v0.14.0 — Fase 4: gestão económica + empresa de demonstração (2026-11-08)

| Área | Verificação |
| --- | --- |
| Biblioteca de preços, orçamentos com composição, encargos e margem, IVA (taxa, autoliquidação, isento), capítulos, importação XLSX/CSV, exportação, aprovação e revisão | Testes (`BudgetTest`) + browser |
| Medições por dimensões, autos ao dono de obra (anteriores, período, acumulado), aprovação, autos de subempreitada com retenção | Testes (`CostsTest`) + browser (medição 1 × 12,5 × 2,8 = 35 m2 registada; auto n.º 2 aberto) |
| Compras (aprovada → comprometido, faturada → realizado), subempreitadas, lançamentos, teto e alertas (ok, perto, acima), painel financeiro | Testes + browser (as três situações de teto na demonstração) |
| Horas (folha do dia, só as próprias sem `time.register_team`, aprovação, salários CSV), equipamentos com QR e manutenções | Testes + browser (aprovação de 1 registo, QR carregado) |
| Planeamento CPM com FS/SS/FF/SF e desfasamentos, ciclos recusados, PERT, ponto de paragem impede concluir | Testes + browser (Gantt com caminho crítico e "PP") |
| Empresa de demonstração: todas as áreas, cadeias de hash das inspeções íntegras, fotografias processadas, `--fresh` apaga tudo (dados, ficheiros, equipa fictícia) sem tocar nas outras empresas | Teste (`DemoCompanyTest`) + corrida local (~30 s, 2,8 MB) |
| Emails para domínios reservados nunca saem | Teste (`ReservedRecipientsTest`) |
| 360 px sem deslocamento horizontal em todos os ecrãs novos | Browser (corrigidos medições e trabalhadores) |

Total: **175 testes, 1698 asserções**.

Com esta versão, a **Fase 4 fica concluída**.

Decisões: o custo previsto é o do orçamento aprovado (por natureza, a partir da composição dos preços); a "previsão final" é realizado + comprometido (o que falta contratar não entra); o teto, se não for escrito, é o custo do orçamento; as horas extra contam a 150%; o custo das horas e dos equipamentos usa o custo/hora do dia do registo (mudar o custo/hora não reescreve o passado); a demonstração usa um domínio reservado (`.test`) e um filtro no envio de emails garante que nada sai para esses endereços.

### Limitações

- Não há portal próprio de subempreiteiros: entram como utilizadores com o perfil Subempreiteiro e veem a obra conforme as permissões dele.
- A biblioteca guarda só o custo atual (sem histórico de preços); os orçamentos guardam a cópia do dia.
- As horas registam-se por dia (normais e extra), sem hora de entrada e saída nem localização.
- O Gantt mostra e o editor altera, mas não se arrastam barras; não há nivelamento de recursos.
- Os autos imprimem-se pelo browser (ainda não há PDF próprio).
- Não há faturação: emitir faturas exige software certificado pela AT (Fase 6).

## v0.13.0 — Fase 3, parte 3: aprovações em vários passos, relatório PDF e emails (2026-11-06)

| Área | Verificação |
| --- | --- |
| Aprovações de 1 a 3 pessoas diferentes (herdadas da lista), "1 de 2" no histórico, quem já aprovou não repete, rejeição decide logo, devolução recomeça, reservas de qualquer uma passam à decisão com o nome de cada um | Testes + browser (1 de 2, botões de aprovar escondidos para quem já aprovou) |
| Relatório PDF (decidida e provisória), só para quem vê inspeções, miniaturas embutidas, código de verificação | Testes + PDF aberto e conferido página a página |
| Emails: pedido → quem faz; submissão → quem decide; decisão → quem pediu; ocorrência atribuída/resolvida/reaberta; nunca a quem fez a ação; texto dos utilizadores escapado | Testes (Mail::fake) |
| Bibliotecas Symfony sem falhas conhecidas (`composer audit` limpo) | composer audit |

Total: **166 testes, 1419 asserções**.

Com esta parte, a **Fase 3 fica concluída**: listas de verificação, inspeções com 9 estados, evidências obrigatórias, índice de suficiência explicado, pontos de paragem, ocorrências/NC com escalonamento, aprovações em vários passos com cadeia imutável, relatório PDF e avisos por email.

Decisões: "aprovações em vários passos" = número de aprovações de pessoas diferentes por inspeção (não um circuito de perfis fixos); o relatório é gerado a pedido e não guardado (o que conta como prova é o histórico encadeado, cujo último hash vai impresso); os avisos só vão para a equipa da obra.

### Limitações

- Não há forma de cada pessoa desligar os avisos por email.
- O relatório mostra até 6 miniaturas por ponto (indica o total).
- A ordem das aprovações não é imposta (qualquer pessoa com permissão pode ser a primeira).

## v0.12.0 — Fase 3, parte 2: pontos de paragem e ocorrências (2026-11-04)

| Área | Verificação |
| --- | --- |
| Ponto de paragem por omissão a partir da lista, em rascunho editável; elemento parado na estrutura; só se liberta depois de aprovada e com a permissão; libertação no histórico encadeado; reinspeção herda; cancelada não para | Testes + browser (estrutura mostra PARADO e deixa de mostrar após libertar) |
| Ocorrências: criar com responsável da equipa e prazo (não no passado), começar/resolver só pelo responsável, reabrir e fechar por quem valida, anular com motivo, comentários e fotografias, historial | Testes + browser (NC aberta, editada, resolvida e fechada) |
| NC a partir de um ponto não conforme (texto, nota, local, especialidade; só pontos NC da mesma obra) e ligação mostrada no ponto | Testes + browser |
| Escalonamento: só por resolver e atrasadas, uma vez por prazo, um email por pessoa (responsável + diretores de obra; sem diretor, administradores); mudar prazo volta a permitir | Testes (Mail::fake) + email montado à mão |
| Permissões por perfil e equipa, edição só por quem registou ou valida, obra arquivada, isolamento, exportação | Testes |
| Ecrãs em 360 px sem deslizamento lateral | Browser |

Total: **162 testes, 1334 asserções**.

Decisões: sem atividades (fase 4), o ponto de paragem pertence à inspeção e para o local/elemento dela; o responsável de uma ocorrência é um utilizador da equipa da obra (não um subempreiteiro como entidade, que chega na fase 4); "escala ao diretor de obra" = diretores de obra da equipa da obra, com os administradores como recurso.

### Limitações

- O escalonamento é diário e único por prazo; não há lembretes antes do prazo nem segundo nível de escalonamento.
- O email só sai se a fila do servidor estiver a correr (cron do `schedule:run`, já configurado em produção).
- Ocorrências e inspeções não funcionam sem rede.
- Aprovações em vários passos, relatório PDF e restantes emails: parte seguinte da fase 3.

## v0.11.0 — Fase 3, parte 1: listas de verificação e inspeções (2026-11-04)

| Área | Verificação |
| --- | --- |
| Listas de verificação: pontos com fotografia/medição exigidas, nome único sem maiúsculas, versão só sobe quando os pontos mudam, arquivar em vez de apagar quando usada | Testes + browser (criada pela interface) |
| Inspeção a partir de lista (pontos copiados; mudar a lista depois não a altera) ou com pontos à mão; especialidade herdada da lista; local/elemento da estrutura | Testes + browser |
| 9 estados e passagens por permissão (pedir / fazer / decidir), justificação obrigatória, aprovar bloqueado com não conformidades, reinspeção, cancelamento, obra arquivada | Testes + browser (pedida → em curso → submetida → aprovada com reservas → reinspeção) |
| Evidências obrigatórias na submissão e índice explicado (recomendados baixam o índice) | Testes + browser (medição fora dos limites assinalada, índice 80 %) |
| Histórico encadeado: hash por passo, modelo recusa alterar/apagar, alteração direta na base de dados detetada no passo certo | Testes |
| Fotografias de evidência: só em curso, quota, servidas só a quem vê inspeções (dono de obra com diário não as abre), fora do histórico do elemento | Testes + browser (miniatura gerada) |
| Permissões por perfil e equipa, isolamento entre empresas, exportação (listas e inspeções com a cadeia) | Testes |
| Ecrãs em 360 px sem deslizamento lateral | Browser |

Total: **156 testes, 1176 asserções**.

Decisão: os 9 estados não estavam definidos nos documentos; ficaram rascunho, pedida, agendada, em curso, em apreciação, aprovada, aprovada com reservas, rejeitada e cancelada (passagens em `App\Inspections\InspectionWorkflow`). A cadeia imutável, prevista para "aprovações", começa já nesta parte para todos os passos da inspeção.

### Limitações

- Pontos de paragem, ocorrências/não conformidades com escalonamento, aprovações em vários passos, relatório PDF e emails: partes seguintes da fase 3.
- Inspeções não funcionam sem rede (só o diário).
- A funcionalidade `inspections` dos planos ainda não é verificada (como o diário e os documentos).
- As fotografias de evidência não têm legenda nem local próprio (são do ponto e da inspeção).

## v0.10.0 — Fase 2, parte 9: especialidades (2026-11-04)

| Área | Verificação |
| --- | --- |
| Lista habitual no registo e por botão (só as que faltam), código único sem maiúsculas, arquivar em vez de apagar com ligações, arquivada não se escolhe | Testes + browser (empresa anterior: "Usar a lista habitual" juntou 13) |
| Elemento com especialidade (estrutura devolve a lista; código junto ao elemento) | Testes + browser |
| Documento com especialidade (envio, janela do documento), filtro em todas as pastas, envio com o filtro ativo | Testes + browser |
| Permissões (subempreiteiro consulta a lista; só specialties.manage altera), isolamento, exportação | Testes |
| Erro na consola ao abrir Documentos (janela do documento, desde a 0.4.0) | Corrigido e confirmado no browser |

Total: **149 testes, 979 asserções**.

Com esta parte, a **Fase 2 fica concluída**: obras e equipas, diário com fotografias e modo sem rede, documentos com versões, estrutura ligada, clientes, calendários de trabalho, departamentos, centros de custo e especialidades.

### Limitações

- A especialidade ainda não se usa em inspeções nem em subempreiteiros (fases 3 e 4).
- Fotografias e linhas do diário não têm especialidade (herdam-na do elemento, se estiverem ligadas a um).

## v0.9.0 — Fase 2, parte 8: departamentos e centros de custo (2026-11-04)

| Área | Verificação |
| --- | --- |
| Departamentos: nome único, pessoas (uma por departamento; mudar tira do anterior), apagar deixa as pessoas sem departamento, pessoas de outra empresa recusadas | Testes + browser |
| Utilizadores: departamento e filtro (incluindo "sem departamento", e vindo de "Ver pessoas") | Testes + browser |
| Centros de custo: códigos automáticos (1, 1.01…), código manual único sem distinguir maiúsculas, sem ciclos, arquivar em vez de apagar com obras ou filhos, arquivado não se escolhe | Testes + browser |
| Obra com centro de custo; permissões e isolamento; exportação e auditoria | Testes + browser |

Total: **145 testes, 921 asserções**.

### Limitações

- Os centros de custo ainda não recebem custos (fase 4: orçamentos, compras, custos realizados).
- Um centro por obra é escolhido à mão; não é criado sozinho ao criar a obra.
- Especialidades ficam para depois.

## v0.8.0 — Fase 2, parte 7: clientes e calendários de trabalho (2026-11-03)

| Área | Verificação |
| --- | --- |
| Clientes: NIF português (dígito de controlo) único por empresa, estrangeiro sem validação PT, arquivar em vez de apagar com obras, opções para escolher (arquivado só o atual), permissões e isolamento | Testes + browser (erro de NIF no campo, criar, ficha) |
| Obra com cliente (escolhido ou criado no formulário da obra; Enter cria o cliente sem submeter a obra) e calendário | Testes + browser |
| Feriados nacionais 2016–2099 (Páscoa por Meeus/Jones/Butcher; nomes juntos quando coincidem, ex.: 25/4/2038) | Testes unitários |
| Calendário por omissão no registo da empresa e criado quando falta (empresas anteriores); dias e períodos sem trabalho; feriados nacionais por ano | Testes + browser (férias coletivas agrupadas numa linha) |
| Prazo da obra em dias úteis (decorridos e por fazer) e mês da obra com feriados | Testes (20/4–8/5/2026 = 14 dias úteis; 15 com sábados) + browser (1/10/2026–31/3/2027 = 124) |
| Diário: feriado junto ao dia, sem aviso de "hoje" em dias sem trabalho, dias úteis sem registo | Browser (outubro: 20 dias, sem o 5 de outubro nem fins de semana) |
| Exportação e auditoria | Testes |

Total: **141 testes, 843 asserções**.

### Limitações

- Os feriados nacionais de cada ano novo juntam-se à mão (botão próprio, com aviso); não há tarefa automática.
- O feriado municipal não é sugerido pelo concelho da obra: marca-se no calendário.
- Calendários por obra servem para contar dias; o planeamento (Gantt/CPM) chega na fase 4.
- Departamentos, centros de custo e especialidades ficam para depois.

## v0.7.0 — Fase 2, parte 6: modo sem rede (2026-11-03)

| Área | Verificação |
| --- | --- |
| `client_uuid` em registos e fotografias; reenvio devolve o existente (sem duplicar nem contar duas vezes na quota); mesmo dia de outro aparelho = 422 | Testes |
| Fila no IndexedDB (resources/js/offline): envio passo a passo e retomável, submissão depois do envio, conflitos guardados | Ensaio real no browser com o servidor desligado |
| Abrir sem rede: shell + todos os ficheiros do build no service worker; sessão, obra, estrutura e diário em cópia local apagada ao sair | Ensaio real: recarregar com o servidor desligado |
| Indicadores e lista do diário com registos só no telemóvel; conflito com "Tentar outra vez"/"Descartar" | Ensaio real |

Ensaio feito: com rede abriu-se a obra; desligou-se o servidor; abriu-se do zero o "Novo registo" de 30/10, escreveu-se texto e uma linha ligada ao P1, guardou-se e juntou-se uma fotografia; recarregou-se (tudo lá, "1 por enviar"); religou-se o servidor: ao abrir, o registo chegou com a linha ligada ao P1 e a fotografia processada, sem duplicados. Conflito simulado no dia 2/11: "Com problema", texto local visível, descartado sem tocar no servidor.

Total: **129 testes**.

### Limitações

- Só o diário (texto e fotografias) funciona sem rede; documentos, estrutura e equipa precisam de rede.
- Os dados ficam no browser do telemóvel: limpar os dados do site apaga o que estiver por enviar.
- A cópia de leitura é a última vista: um mês nunca aberto com rede aparece só com o que está no telemóvel.

## v0.6.0 — Fase 2, parte 5: ligar à estrutura (2026-11-03)

| Área | Verificação |
| --- | --- |
| `location_id`/`element_id` em linhas do diário, fotografias e documentos, com FKs compostas e validação por obra | Testes |
| Elemento define o local; mover o elemento leva as ligações | Testes |
| Histórico por elemento e por local (com tudo o que está dentro), filtrado por diary.view / documents.view | Testes + browser |
| Locais e elementos com ligações não se apagam (409) | Testes |
| Interface: "Onde?" nas linhas do diário e fotografias, "Refere-se a" nos documentos, histórico na Estrutura | Browser |

Total: **126 testes**.

## v0.5.0 — Fase 2, parte 4: estrutura da obra (2026-11-03)

| Área | Verificação |
| --- | --- |
| Hierarquia edifício → piso → zona com regras de encadeamento; localizações de outra obra recusadas; só se apagam vazias | Testes |
| Elementos com código automático por prefixo (PA1 não conta para P), únicos por obra; mover entre localizações | Testes + browser |
| `structure.manage` por obra (diretor de obra sim, encarregado não), fora da equipa 403, outra empresa 404, obra arquivada 409 | Testes |
| Interface em árvore, criar pisos "-1 a 2" de uma vez, formulário de elementos | Browser (540 px) |

Total: **122 testes, 606 asserções**.

### Não feito

- Atividades (WBS, quantidades, dependências) — chegam com o planeamento.
- Ligar o diário, fotografias e documentos a localizações/elementos; especialidades.

## v0.4.0 — Fase 2, parte 3: documentos da obra (2026-11-03)

| Área | Verificação |
| --- | --- |
| Pastas (aninhadas, nome único sem distinguir maiúsculas, só apagam vazias), navegação e pesquisa em toda a obra | Testes + browser |
| Versões imutáveis numeradas com bloqueio contra envios simultâneos; v1 intacta depois da v2 (SHA-256) | Testes + browser |
| Abrir (PDF/imagens, inline) e descarregar (resto como binário); registo de cada descarga | Testes + browser |
| Permissões por obra: encarregado envia e versiona os seus mas não os de outros; trabalhador só consulta; fora da equipa 403; outra empresa 404 | Testes |
| Extensões fora da lista (exe, php, html, svg) recusadas; quota; obra arquivada; apagar liberta espaço e ficheiros | Testes |

Total: **117 testes, 555 asserções**.

### Não feito

- Antivírus (ClamAV não existe no alojamento partilhado).
- Especialidades e etiquetas nos documentos; partilha com externos fora da equipa; pré-visualização de DWG/IFC.

## v0.3.0 — Fase 2, parte 2: fotografias no diário (2026-11-03)

| Área | Verificação |
| --- | --- |
| Envio de fotografias para registos em rascunho; tipo verificado pelo conteúdo (getimagesize), 20 MB, 30 por registo, limite de envios por hora | Testes |
| Original intacto (SHA-256 igual ao enviado); pré-visualização 1600 px e miniatura 400 px em fila (GD); orientação e data do EXIF | Testes (JPEG com EXIF construído no teste: orientação 6, DateTimeOriginal) + browser |
| Quota `storage_mb` (original + versões) com contagem atómica; apagar fotografia ou rascunho liberta ficheiros e espaço | Testes (bytes exatos) + browser (268 231 B = 2 originais + 4 versões) |
| Ficheiros só por API com sessão: diary.view na obra; outra empresa 404; `Cache-Control: private` | Testes |
| Interface: tirar fotografia / escolher várias, progresso, legenda, ver em grande, descarregar original; contagem na lista | Browser (375–540 px) |

Total: **110 testes, 470 asserções**.

### Não feito

- As fotografias não entram no ZIP da exportação (só a lista com SHA-256): podem somar gigabytes.
- HEIC (o iPhone converte para JPEG ao enviar pelo browser), vídeos, galeria da obra, fila offline.

## v0.2.0 — Fase 2, parte 1: obras e diário de obra (2026-11-03)

### Implementado e verificado

| Área | Verificação |
| --- | --- |
| Obras: CRUD, código automático por empresa e ano, estados, limite `max_active_projects` (só obras abertas contam) | Testes |
| Equipa da obra com perfil por obra; permissões por obra em `AccessResolver` (`projects.all` ou perfil na equipa) | Testes de visibilidade, perfil diferente do da empresa, escalada, isolamento e FK composta |
| Diário de obra: um registo por dia, sem dias futuros, linhas por tipo, rascunho → submetido imutável | Testes + fluxo no browser (criar, guardar, submeter, consulta, copiar do registo anterior) |
| Obras e diário na exportação de dados; eventos de auditoria `project.*` e `diary.*` | Testes (ZIP real) |
| Interface: lista de obras, obra com separadores Diário / Equipa / Dados, registo diário pensado para telemóvel | Browser em 375–540 px e 1280 px |
| Agendador sem `proc_open`; HTTPS em subpasta; auditoria com valor antigo correto | Testes + produção (coda.pt) |

Total: **101 testes, 404 asserções** (SQLite). O CI corre também em MySQL 8.4.

### Não feito nesta parte

- Fotografias no diário e documentos (precisam de armazenamento com quota, hash e miniaturas em fila).
- Estrutura da obra (edifício → piso → zona → elemento), clientes, departamentos, calendários.
- Modo obra offline (fila no telemóvel).
- Relatório PDF do diário.

### Próximo passo

Fotografias do diário (armazenamento, quota por plano, miniaturas em fila), depois documentos com versões.

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
| Instalação em subpasta (ex.: `www.coda.pt/obras/obraconnect`) com site existente na raiz | Apache 2.4 real, symlink e cópia: site principal intacto, registo, login com regresso ao endereço, convites, exportação/download, `/instalar`; cookies e service worker limitados à subpasta; 3 testes automáticos |
| SQL de instalação para phpMyAdmin gerado das migrações | Importado em MariaDB 10.11 (local) e MySQL 8.4 (CI); esquema igual ao das migrações |
| ZIP de release | Gerado localmente e no CI; instalado e testado localmente |
| PWA (manifesto, service worker do shell) | Service worker regista no build de produção |
| Interface em pt-PT, responsiva | Capturas em 1280 px e 390 px |
| CI GitHub Actions | Verde: Pint, tipos, build, testes SQLite + MySQL 8.4, SQL, ZIP |

Total: **77 testes, 229 asserções**, a passar em SQLite, MariaDB 10.11 e MySQL 8.4.

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
