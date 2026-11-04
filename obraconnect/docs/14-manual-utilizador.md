# Manual do utilizador (versão 0.10.0)

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
