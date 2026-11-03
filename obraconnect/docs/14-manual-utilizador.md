# Manual do utilizador (versão 0.6.0)

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
- **Estado**: em preparação → em curso → concluída → arquivada (também pode suspender e retomar). Concluir, arquivar e reabrir exigem uma permissão própria. Obras concluídas e arquivadas não contam para o limite do plano. Uma obra arquivada fica só para consulta.

## Diário de obra

Um registo por dia em cada obra, no separador **Diário** da obra.

1. **Novo registo** (ou **Registar agora**, se ainda não há registo de hoje).
2. Preencha o tempo (manhã, tarde, temperaturas), os trabalhos executados e as linhas de **mão de obra**, **equipamentos**, **materiais recebidos**, **ocorrências** e **visitas**. Num registo novo, **Copiar mão de obra e equipamentos do último registo** poupa trabalho quando as equipas se repetem.
3. **Guardar rascunho** para continuar mais tarde, ou **Guardar e submeter** para fechar o registo. Um registo submetido **não pode ser alterado nem apagado**.

**Fotografias.** Num rascunho já guardado, use **Tirar fotografia** (abre a câmara no telemóvel) ou **Escolher fotografias** (várias de uma vez). Pode escrever uma legenda em cada uma. Aceita JPEG, PNG e WebP até 20 MB, no máximo 30 por registo. As miniaturas aparecem ao fim de alguns segundos (até cerca de um minuto). Toque numa fotografia para a ver em grande ou **descarregar o original**, que é guardado exatamente como foi enviado. As fotografias ocupam o espaço do plano e ficam fechadas quando o registo é submetido.

Não é possível registar dias futuros. Quem só tem consulta (por exemplo, fiscal ou dono de obra) vê os registos mas não os altera.

## Estrutura da obra

No separador **Estrutura** da obra descreve-se a obra em **edifícios**, **pisos** e **zonas**, e os **elementos construtivos** de cada um.

- **+ Edifício** cria um edifício (numa obra de um só corpo, um edifício com o nome da obra).
- **+ Pisos** num edifício cria vários de uma vez: escreva, por exemplo, `-1 a 3`.
- **+ Zona** cria uma zona num piso (ou diretamente no edifício, para arranjos exteriores).
- **+ Elemento** junta um pilar, viga, laje, etc. Se deixar o código vazio, é numerado sozinho por tipo (P1, P2, V1, L1...). Toque num elemento para o corrigir, mudar de localização ou apagar.
- Só se apaga um edifício, piso ou zona vazio, e só se apaga um local ou elemento que não tenha registos ligados.
- **Histórico** (em cada local) ou um toque num elemento mostra tudo o que lhe está ligado: linhas do diário (com o dia), fotografias e documentos. Num piso, inclui as zonas e os elementos desse piso.

**Ligar à estrutura.** Nas linhas do diário (campo **Onde?**), em cada fotografia e na janela de cada documento (**Refere-se a**) pode escolher o edifício, piso, zona ou elemento. Ao escolher um elemento (por exemplo, P1), o piso fica preenchido sozinho.

## Documentos da obra

No separador **Documentos** da obra:

- **Enviar documentos** (vários de uma vez) para a pasta aberta. Aceita PDF, DWG, DXF, IFC, Word, Excel, PowerPoint, OpenDocument, TXT, CSV, imagens e ZIP, até 50 MB cada.
- **Nova pasta**, **Renomear** e **Apagar** (só pastas vazias) para quem gere os documentos.
- Toque num documento para ver as **versões**. **Enviar nova versão** guarda a anterior sem alterações; escreva o que mudou. **Abrir** mostra PDF e imagens no browser; **Descarregar** guarda o ficheiro.
- Quem enviou um documento pode mudar o título e juntar versões; mover, apagar e versões de documentos de outras pessoas ficam para quem gere os documentos.
- Cada abertura ou descarga fica registada (o número aparece em cada versão).

## No telemóvel

A aplicação funciona no browser do telemóvel e pode ser instalada no ecrã inicial (Android: menu do Chrome → **Instalar aplicação**; iPhone: Safari → Partilhar → **Adicionar ao ecrã principal**). Nesta versão é preciso ligação à internet para trabalhar; o registo offline chega com o módulo de obra.

## Mensagens comuns

| Mensagem | O que fazer |
| --- | --- |
| "A sua empresa exige autenticação de dois fatores" | Ative a 2FA em **A minha conta** |
| "A empresa está em modo só leitura" | A subscrição expirou; o administrador da empresa deve contactar a ObraConnect |
| "A empresa está suspensa" | Contacte o administrador da empresa |
| "Não tem permissão para aceder a esta área" | O seu perfil não inclui essa área |
