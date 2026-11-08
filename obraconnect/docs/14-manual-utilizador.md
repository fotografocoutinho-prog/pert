# Manual do utilizador (versão 0.15.0)

## Entrar

1. Abra o endereço da plataforma e escolha **Entrar**.
2. Se tiver autenticação de dois fatores, introduza o código de 6 dígitos da aplicação autenticadora.
3. Esqueceu a password? **Esqueceu-se?** e siga o email.

## Aceitar um convite

Abra a ligação do email. Se ainda não tem conta, crie-a com o **mesmo email** do convite. Se já tem, entre e carregue em **Aceitar convite**.

## Várias empresas

Se pertence a mais de uma empresa, escolha a empresa ativa no seletor no topo do ecrã. Tudo o que vê e faz aplica-se só à empresa ativa.

## A minha conta

- **Dados pessoais**: nome e email (um email novo tem de ser confirmado).
- **Alterar password**: mínimo 10 caracteres, com letras e números.
- **Autenticação de dois fatores**: carregue em **Ativar 2FA**, confirme a password, leia o código QR com Google Authenticator, Microsoft Authenticator ou Authy, e introduza o código. Guarde os **códigos de recuperação** num local seguro: servem para entrar se perder o telemóvel.

## O que vê depende do seu perfil

O menu mostra apenas as áreas a que o seu perfil dá acesso. Se precisar de mais acesso, fale com o administrador da empresa.

## Obras

Em **Obras** vê as obras a que tem acesso. Quem tem acesso a todas as obras (por omissão: administrador, diretor técnico, medidor-orçamentista e administrativo) vê todas; os restantes veem só as obras de cuja equipa fazem parte.

- **Nova obra**: só o nome é obrigatório. O código é criado automaticamente (ex.: OB-2026-001) se o deixar vazio. Quem cria a obra fica na equipa.
- **Equipa**: quem trabalha na obra e com que **perfil nessa obra**. Por omissão é o perfil que a pessoa tem na empresa, mas pode ser outro (por exemplo, um trabalhador que é encarregado só numa obra). Pessoas de fora (subempreiteiros, fiscalização, dono de obra) são convidadas primeiro em **Utilizadores** e depois adicionadas à equipa.
- **Cliente e dono de obra**: escolha o cliente na lista (ou **+ Novo cliente**, que o cria ali mesmo). O campo **Dono de obra** só é preciso quando não é o cliente (por exemplo, quando a empresa trabalha como subempreiteira de outra).
- **Calendário de trabalho** e **Prazo**: nos dados da obra escolhe-se o calendário (por omissão, o da empresa). Com início e fim previsto preenchidos, o quadro **Prazo** mostra os dias úteis e de calendário, quantos já passaram e quantos faltam.
- **Estado**: em preparação → em curso → concluída → arquivada (também pode suspender e retomar). Concluir, arquivar e reabrir exigem uma permissão própria. Obras concluídas e arquivadas não contam para o limite do plano. Uma obra arquivada fica só para consulta.

## Diário de obra

Um registo por dia em cada obra, no separador **Diário** da obra.

1. **Novo registo** (ou **Registar agora**, se ainda não há registo de hoje).
2. Preencha o tempo (manhã, tarde, temperaturas), os trabalhos executados e as linhas de **mão de obra**, **equipamentos**, **materiais recebidos**, **ocorrências** e **visitas**. Num registo novo, **Copiar mão de obra e equipamentos do último registo** poupa trabalho quando as equipas se repetem.
3. **Guardar rascunho** para continuar mais tarde, ou **Guardar e submeter** para fechar o registo. Um registo submetido **não pode ser alterado nem apagado**.

**Fotografias.** Num rascunho já guardado, use **Tirar fotografia** (abre a câmara no telemóvel) ou **Escolher fotografias** (várias de uma vez). Pode escrever uma legenda em cada uma. Aceita JPEG, PNG e WebP até 20 MB, no máximo 30 por registo. As miniaturas aparecem ao fim de alguns segundos (até cerca de um minuto). Toque numa fotografia para a ver em grande ou **descarregar o original**, que é guardado exatamente como foi enviado. As fotografias ocupam o espaço do plano e ficam fechadas quando o registo é submetido.

**Dias em falta.** Numa obra em curso, o diário mostra os dias úteis do mês, desde o início da obra, que ainda não têm registo; toque num dia para o registar. Feriados e paragens do calendário da obra não contam e aparecem junto ao dia.

Não é possível registar dias futuros. Quem só tem consulta (por exemplo, fiscal ou dono de obra) vê os registos mas não os altera.

## Estrutura da obra

No separador **Estrutura** da obra descreve-se a obra em **edifícios**, **pisos** e **zonas**, e os **elementos construtivos** de cada um.

- **+ Edifício** cria um edifício (numa obra de um só corpo, um edifício com o nome da obra).
- **+ Pisos** num edifício cria vários de uma vez: escreva, por exemplo, `-1 a 3`.
- **+ Zona** cria uma zona num piso (ou diretamente no edifício, para arranjos exteriores).
- **+ Elemento** junta um pilar, viga, laje, etc. Se deixar o código vazio, é numerado sozinho por tipo (P1, P2, V1, L1...). Toque num elemento para o corrigir, mudar de localização ou apagar.
- Só se apaga um edifício, piso ou zona vazio, e só se apaga um local ou elemento que não tenha registos ligados.
- Cada elemento pode ter uma **especialidade** (ex.: pilares em EST); o código aparece junto ao elemento.
- **Histórico** (em cada local) ou um toque num elemento mostra tudo o que lhe está ligado: linhas do diário (com o dia), fotografias e documentos. Num piso, inclui as zonas e os elementos desse piso.

**Ligar à estrutura.** Nas linhas do diário (campo **Onde?**), em cada fotografia e na janela de cada documento (**Refere-se a**) pode escolher o edifício, piso, zona ou elemento. Ao escolher um elemento (por exemplo, P1), o piso fica preenchido sozinho.

## Clientes

Em **Clientes** ficam os clientes da empresa: particulares, empresas e entidades públicas.

- **Novo cliente**: tipo e nome são obrigatórios. O NIF português é validado e não pode repetir-se na empresa; para clientes de outro país, mude o **País** (ex.: ES) e escreva o número fiscal desse país.
- Na ficha do cliente vê os contactos e as obras dele (só as obras a que tem acesso).
- Um cliente com obras não se apaga: **Arquivar** tira-o das escolhas, mas as obras que já o têm continuam a mostrá-lo. **Reativar** volta a pô-lo na lista.
- Por omissão, gerem clientes o administrador, o diretor técnico e o administrativo; o diretor de obra, o preparador e o medidor-orçamentista consultam.

## Organização (departamentos, centros de custo e especialidades)

Em **Organização** organiza-se a empresa.

- **Novo departamento** (ex.: Produção, Orçamentação, Compras). Em **Escolher pessoas** marque quem lá está: cada pessoa fica num só departamento, por isso quem escolher sai do departamento onde estava. **Ver pessoas** abre a lista de utilizadores já filtrada. Apagar um departamento não apaga ninguém: as pessoas ficam sem departamento.
- **Novo centro de custo** cria um centro na raiz; **+ Dentro** cria um dentro de outro. Se deixar o código vazio, é numerado sozinho (1, 2… na raiz; 1.01, 1.02… dentro do 1). Uma organização comum: **1 Obras**, com um centro por obra lá dentro, e **2 Estrutura** (Administração, Armazém, Viaturas).
- Nos **dados da obra** escolhe-se o centro de custo da obra.
- Um centro com obras, ou com centros dentro, não se apaga: em **Editar**, marque **Arquivado**. Deixa de aparecer para escolher; as obras que já o têm mantêm-no.
- **Especialidades** (Arquitetura, Estruturas, AVAC…): as empresas novas começam com a lista habitual; numa empresa anterior, use **Usar a lista habitual**. Pode criar outras (código curto, ex.: GEO, e nome), mudar nomes e arquivar as que não usa. Uma especialidade com elementos ou documentos não se apaga: arquiva-se.
- Por omissão, gerem departamentos o administrador e o administrativo; centros de custo e especialidades, o administrador, o diretor técnico e o administrativo. Quem vê a empresa consulta tudo.

## Calendários de trabalho

Em **Calendários de trabalho** definem-se os dias e horas de trabalho e os dias sem trabalho. Servem para contar os dias úteis do prazo das obras e os dias em falta no diário.

- Cada empresa começa com o **Calendário geral** (segunda a sexta, 8 horas, com os feriados nacionais deste ano e do seguinte). É o calendário **por omissão**: vale para as obras sem calendário escolhido.
- **Novo calendário** cria outro (por exemplo, para obras que trabalham ao sábado). **Tornar por omissão** troca o da empresa. Só se apaga um calendário que não seja o por omissão e não tenha obras.
- **Juntar feriados nacionais de AAAA** junta os 13 feriados obrigatórios desse ano (o Carnaval só se marcar a opção). Faça-o todos os anos para o ano seguinte: a página avisa quando falta.
- **Marcar dia ou período**: feriado municipal (ex.: São João), feriado facultativo ou paragem da empresa. Num período (por exemplo, férias coletivas de 3 a 14 de agosto) só ficam marcados os dias de trabalho; aparece numa só linha e retira-se de uma vez.

## Documentos da obra

**Especialidade.** Na janela de cada documento escolhe-se a especialidade (ex.: ARQ, EST, AVAC). O filtro **Todas as especialidades**, ao lado da pesquisa, mostra os documentos dessa especialidade em todas as pastas; enquanto o filtro estiver ativo, os documentos que enviar ficam com essa especialidade.

No separador **Documentos** da obra:

- **Enviar documentos** (vários de uma vez) para a pasta aberta. Aceita PDF, DWG, DXF, IFC, Word, Excel, PowerPoint, OpenDocument, TXT, CSV, imagens e ZIP, até 50 MB cada.
- **Nova pasta**, **Renomear** e **Apagar** (só pastas vazias) para quem gere os documentos.
- Toque num documento para ver as **versões**. **Enviar nova versão** guarda a anterior sem alterações; escreva o que mudou. **Abrir** mostra PDF e imagens no browser; **Descarregar** guarda o ficheiro.
- Quem enviou um documento pode mudar o título e juntar versões; mover, apagar e versões de documentos de outras pessoas ficam para quem gere os documentos.
- Cada abertura ou descarga fica registada (o número aparece em cada versão).

## Listas de verificação

Em **Listas de verificação** (para quem as gere) criam-se os modelos usados nas inspeções, por exemplo "Betonagem de lajes".

- Cada **ponto** diz o que se verifica e, se for preciso, as evidências: **Exige fotografia** (com o número mínimo) e **Exige medição** (unidade e, se quiser, mínimo e máximo — um valor fora deles fica assinalado). As **indicações** ajudam quem inspeciona.
- Cada inspeção guarda uma **cópia** dos pontos. Se mudar os pontos de uma lista, ela passa à versão seguinte e as inspeções já criadas não mudam.
- Uma lista usada em inspeções não se apaga: **Arquive-a** e deixa de aparecer ao criar inspeções.

## Inspeções

No separador **Inspeções** da obra:

1. **Nova inspeção**: escolha uma lista de verificação (ou escreva os pontos), o local ou elemento, a especialidade e a data pretendida. Marque **Inspeção remota** se for feita por fotografias. Fica em **Rascunho**: ainda pode mudar tudo.
2. **Pedir inspeção**: os pontos ficam fixos e a inspeção passa a **Pedida**.
3. Quem faz inspeções pode **Agendar** (data e hora) e **Começar a inspeção**.
4. **Em curso**, em cada ponto: **Conforme**, **Não conforme** ou **Não aplicável**, o valor medido, observações e fotografias (**Tirar fotografia** abre a câmara do telemóvel). Uma não conformidade tem de ser descrita.
5. O quadro **Evidências** mostra o índice (0–100 %) e o que falta, ponto a ponto. **Submeter para apreciação** só fica disponível quando não falta nada obrigatório; depois disso os pontos e as fotografias ficam fechados.
6. Quem decide: **Aprovar** (só sem não conformidades), **Aprovar com reservas**, **Rejeitar** ou **Devolver para completar** (volta a "em curso"). Reservas, rejeição e devolução pedem uma justificação.
7. Depois de rejeitada ou aprovada com reservas, **Pedir reinspeção** cria um rascunho novo com os mesmos pontos, ligado ao anterior.

Uma inspeção pedida, agendada ou em curso pode ser **cancelada** (com motivo); um rascunho apaga-se.

O **Histórico** mostra cada passo, quem o deu e quando. Cada passo fica selado com o código (hash) do anterior: a mensagem **Cadeia íntegra** confirma que nada foi alterado depois.

Por omissão: pedem inspeções o diretor técnico, o diretor de obra, o encarregado, o preparador, o técnico de qualidade e o subempreiteiro (nas obras da equipa); fazem-nas o fiscal, o coordenador de segurança e o técnico de qualidade; decidem o fiscal e o coordenador de segurança. O administrador pode tudo.

### Aprovações de várias pessoas

Em trabalhos críticos, uma lista de verificação (ou uma inspeção, enquanto é rascunho) pode pedir **2 ou 3 aprovações**. Cada pessoa com permissão de decidir aprova uma vez; a inspeção fica **Em apreciação** — com a indicação "Aprovações: 1 de 2" — até juntar todas. Uma rejeição decide logo. Se for devolvida para completar, as aprovações recomeçam do zero na nova submissão.

### Relatório em PDF

Fora do rascunho, **Relatório PDF** abre o relatório da inspeção para imprimir, enviar ou arquivar: pontos, resultados, fotografias (miniaturas), índice de evidências, decisão, aprovações e o histórico com o **código de verificação**. Enquanto a inspeção não está decidida, o relatório diz que é provisório. Os originais das fotografias ficam na aplicação.

### Avisos por email

A aplicação avisa por email as pessoas da **equipa da obra** quando há algo para elas: uma inspeção para fazer ou para decidir, uma decisão sobre uma inspeção que pediram ou fizeram, um ponto de paragem libertado, uma ocorrência de que são responsáveis, resolvida (para validar) ou reaberta. Quem fez a ação não recebe o aviso. Quem vê todas as obras pelo perfil da empresa só recebe avisos das obras em cuja equipa está.

### Pontos de paragem

Marque **Ponto de paragem** numa inspeção (ou numa lista de verificação, para valer em todas as inspeções criadas a partir dela) quando os trabalhos não podem avançar sem a inspeção — por exemplo, verificar as armaduras antes de betonar.

- Enquanto não for libertado, a inspeção mostra **Ponto de paragem: trabalhos parados** e, no separador **Estrutura**, o elemento aparece a vermelho com **PARADO**. O filtro **Pontos de paragem** no separador Inspeções lista todos.
- Depois de a inspeção ser aprovada (com ou sem reservas), quem tem a permissão — por omissão, o fiscal — usa **Libertar ponto de paragem**, com uma observação opcional. Fica no histórico da inspeção.
- Se a inspeção for rejeitada, a reinspeção herda o ponto de paragem. Uma inspeção cancelada não para nada.

### Não conformidade a partir de um ponto

Num ponto **Não conforme**, o botão **Abrir não conformidade** cria-a com o texto do ponto, a observação, o local e a especialidade da inspeção. Depois escolha o responsável e o prazo. A NC fica indicada no ponto.

## Ocorrências e não conformidades

No separador **Ocorrências e NC** da obra registam-se problemas que alguém tem de resolver até uma data.

1. **Nova ocorrência**: tipo (não conformidade ou ocorrência), gravidade, título, descrição, local, especialidade, **responsável** (uma pessoa da equipa da obra) e **prazo**. Depois de registar, junte fotografias.
2. O responsável usa **Começar a resolver** e, quando estiver feito, **Marcar como resolvida**, escrevendo o que foi feito.
3. Quem valida (por omissão: diretor técnico, diretor de obra, fiscal, coordenador de segurança e técnico de qualidade) usa **Validar e fechar** ou **Reabrir** (com o motivo). Uma ocorrência registada por engano **anula-se**.
4. Toda a gente que participa pode **comentar**; tudo fica no **Historial** (mudanças de responsável e de prazo incluídas).

**Prazo ultrapassado.** Todas as manhãs (7h30), as ocorrências por resolver com o prazo ultrapassado recebem a marca **Atrasada** e o responsável e o diretor de obra da equipa recebem um email (sem diretor de obra na equipa, vai para os administradores da empresa). Cada ocorrência só é escalonada uma vez; se combinar um novo prazo, mude-o em **Editar**.

Filtros: **Por resolver**, **Por validar**, **Atrasadas**, **Fechadas**, **Todas** e **Só as minhas**.

## Biblioteca de preços

Em **Biblioteca de preços** ficam os **recursos** (mão de obra, materiais, equipamentos, subempreitadas) com o custo unitário e os **artigos**. Um artigo composto soma os recursos × rendimento (ex.: 1,05 m3 de betão + 0,5 h de pedreiro + 0,8 h de servente). Os orçamentos guardam uma cópia: mudar um preço na biblioteca não altera orçamentos feitos. Recursos e artigos usados arquivam-se em vez de se apagarem.

## Orçamento da obra

No separador **Orçamento** da obra:

1. **Novo orçamento** cria um rascunho. Em **Condições** escolha os encargos e a margem (%) e o IVA (à taxa, autoliquidação ou isento).
2. Junte **capítulos** (e subcapítulos) e, em cada um, **artigos**: da biblioteca ou escritos à mão, com a composição do preço. O preço de venda é calculado (custo × encargos × margem); pode escrevê-lo à mão num artigo.
3. Ou **Importar Excel / CSV** com as colunas Código, Descrição, Unidade, Quantidade e Custo unitário (as linhas sem unidade são capítulos).
4. **Aprovar orçamento**: passa a ser a base dos autos e do custo previsto, e deixa de se poder alterar. Para mudar, **Fazer revisão** (cria a versão seguinte em rascunho; ao aprová-la, a anterior fica substituída).

**Exportar CSV** tira o orçamento para folha de cálculo.

## Medições e autos

No separador **Medições e autos**:

- **Registar medição**: escolha o artigo do orçamento aprovado e escreva as dimensões (n.º, comprimento, largura, altura: a quantidade é o produto) ou a quantidade. O quadro **Avanço por artigo** mostra orçado, medido e autado.
- **Novo auto**: indique o período. O auto traz as medições ainda não autadas até ao fim do período, aos preços de venda do orçamento; em rascunho pode acertar a quantidade de cada linha. **Submeter** → quem aprova autos (por omissão, fiscal e diretor de obra) **Aprova** ou **Reabre**. Uma linha acima do contratado fica a vermelho.

## Compras e subempreitadas

- **Nova compra**: fornecedor, natureza (materiais, equipamentos…), centro de custo e linhas (pode ligar cada linha a um artigo do orçamento). **Aprovar** → o valor passa a custo **comprometido**; **Recebida**; **Registar fatura** (número, data, valor) → passa a custo **realizado**. Também se cancela.
- **Nova subempreitada**: subempreiteiro, objeto, retenção de garantia (%) e os trabalhos com os preços acordados. **Adjudicar** → comprometido. Em curso, **Novo auto** cria o auto da subempreitada: escreva a quantidade do período em cada trabalho; a retenção calcula-se sozinha. Autos aprovados contam como realizado.

Os fornecedores e subempreiteiros criam-se em **Fornecedores e subempreiteiros**, com especialidades e documentos com validade (alvará, seguros, certidões): aparece o aviso quando estão a expirar ou expirados.

## Horas e equipamentos

No separador **Horas e equipamentos**:

- **Horas da semana**: grelha por trabalhador e dia (amarelo por aprovar, verde aprovadas), com o custo se o seu perfil o mostrar.
- **Folha do dia**: escolha o dia, escreva as horas normais e extra de cada pessoa e **Guardar folha**. Voltar a gravar o mesmo dia substitui o registo (se ainda não estiver aprovado). Quem só regista as próprias horas vê apenas a sua linha (o trabalhador tem de estar ligado à sua conta em **Trabalhadores**).
- **Aprovar**: escolha os registos (ou todos) e **Aprovar** ou **Rejeitar**.
- **Equipamentos na obra**: registe as horas de cada equipamento por dia; entram no custo com o custo/hora do equipamento.

Em **Trabalhadores** gerem-se as pessoas e o custo/hora (de subempreiteiros também) e **Exportar horas (CSV)** tira as horas aprovadas de um mês para os salários. Em **Equipamentos** fica a ficha de cada um (onde está, situação, manutenções com a próxima data) e a **etiqueta QR**: imprima-a e cole-a no equipamento; lida no telemóvel, abre a ficha (com sessão iniciada).

## Custos

O separador **Custos** compara o **previsto** (orçamento aprovado) com o **comprometido** (encomendas aprovadas e subempreitadas por faturar), o **realizado** (faturas, autos de subempreitada aprovados, horas, equipamentos e lançamentos) e a **previsão final** (realizado + comprometido), por natureza, por centro de custo e por mês.

- **Teto**: por omissão é o custo do orçamento; **Alterar teto** escreve outro. Aparece o aviso **Perto do teto** a partir de 90% e **Acima do teto** quando a previsão o passa.
- **Lançamentos**: custos que não vêm de compras, autos, horas ou equipamentos (taxas, licenças, seguros, ajustes).
- **Exportar mapa de custos (CSV)**.

O **Painel financeiro** (menu) junta todas as obras: contrato, autado, realizado, comprometido, previsão, margem e situação face ao teto.

## Faturação (pré-faturas)

O ObraConnect **não emite faturas**: prepara **pré-faturas**, documentos internos sem valor fiscal, que se exportam para o programa de faturação certificado onde a fatura oficial é emitida.

1. Num **auto de medição** ao dono de obra já aprovado, **Criar pré-fatura**: vem com o cliente, a obra, a referência do auto e as linhas deste período (preços de venda e IVA do orçamento). Também há **Nova pré-fatura** em **Faturação**, para adiantamentos e outros trabalhos.
2. Em rascunho pode alterar o cliente (ou copiá-lo de um cliente da lista), as datas, as linhas, a taxa de IVA e o motivo de isenção. Com linhas a 0% é preciso o motivo (num orçamento em autoliquidação já vem "IVA - autoliquidação").
3. **Pronta a faturar**: recebe o número interno (PF 2026/0001…).
4. Em **Faturação**, no separador **Prontas a faturar**, exporte em **Excel**, **CSV** ou **JSON** e importe no seu programa de faturação (no importador, faça corresponder as colunas: documento, data, cliente, NIF, descrição, quantidade, preço, taxa de IVA…).
5. Depois de emitir a fatura no programa certificado, abra a pré-fatura e use **Registar fatura oficial** (número e data). Passa a **Faturada** e conta no **Painel financeiro**.

O **PDF** da pré-fatura serve para conferência interna e diz sempre que não serve de fatura. Antes de o enviar a um cliente, confirme com o contabilista.

## Planeamento

No separador **Planeamento**:

1. **Nova atividade**: nome, duração em **dias úteis** (o calendário da obra conta os fins de semana e feriados), "dentro de" (para agrupar em resumos) e, se quiser, a data "não antes de" e a estimativa PERT (otimista, mais provável, pessimista).
2. Abra a atividade e junte as **precedências** (fim → início, início → início, fim → fim, início → fim, com desfasamento em dias).
3. O **Gantt** mostra as datas calculadas, o **caminho crítico** a vermelho, o avanço (parte cheia da barra), hoje (azul) e o fim previsto da obra (roxo). Se o plano acabar depois do fim previsto, aparece o aviso.
4. **Avanço (%)**: a 100% a atividade fica concluída — exceto se tiver um **ponto de paragem** por libertar (marca **PP**). Ao criar uma inspeção pode escolher a atividade a que pertence.

## Empresa de demonstração

Se tiver acesso à **Atlântico Construções (demonstração)**, mude de empresa no menu (no topo). É uma empresa com dados fictícios em todas as áreas, para mostrar o produto; as pessoas da equipa têm emails `@atlantico-demo.test`, que nunca recebem emails. Pode experimentar à vontade: a demonstração recria-se com as datas do dia.

## No telemóvel

A aplicação funciona no browser do telemóvel e pode ser instalada no ecrã inicial (Android: menu do Chrome → **Instalar aplicação**; iPhone: Safari → Partilhar → **Adicionar ao ecrã principal**).

### Sem rede (diário de obra)

- Abra a aplicação e a obra **uma vez com rede** (por exemplo, no escritório ou no estaleiro com wi-fi). A partir daí ela abre sem rede, com a última cópia da obra, da estrutura e do diário.
- Sem rede, **Guardar rascunho** (ou **Guardar e submeter**) guarda o registo **neste telemóvel**, e as fotografias também. No topo aparece **Sem rede** e **N por enviar**.
- Quando houver rede, tudo é enviado sozinho (também pode tocar em **N por enviar**). Nada é enviado duas vezes, mesmo que a rede falhe a meio.
- Se o mesmo dia tiver sido registado entretanto noutro aparelho, o que escreveu fica guardado com o aviso **Com problema**: abra o registo, junte a informação à mão e use **Tentar outra vez** ou **Descartar**.
- Ao **sair da conta**, as cópias de leitura são apagadas; o que está por enviar fica guardado e segue quando voltar a entrar.

## Mensagens comuns

| Mensagem | O que fazer |
| --- | --- |
| "A sua empresa exige autenticação de dois fatores" | Ative a 2FA em **A minha conta** |
| "A empresa está em modo só leitura" | A subscrição expirou; o administrador da empresa deve contactar a ObraConnect |
| "A empresa está suspensa" | Contacte o administrador da empresa |
| "Não tem permissão para aceder a esta área" | O seu perfil não inclui essa área |
