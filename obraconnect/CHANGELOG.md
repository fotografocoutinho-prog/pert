# Histórico de versões

## 0.8.0 — 2026-11-09

Fase 2, sétima parte: clientes e calendários de trabalho.

- **Clientes** (particulares, empresas, entidades públicas): NIF português validado (dígito de controlo) e único na empresa, ou n.º fiscal estrangeiro; contactos, morada, notas. Com obras não se apagam: arquivam-se (saem das escolhas, as obras continuam a mostrá-los). Permissões `clients.view` e `clients.manage`.
- Cada obra pode ter **cliente** (escolhido na lista ou criado ali mesmo, no formulário da obra) e continua a ter **dono de obra** em texto, para quando a empresa trabalha como subempreiteira. A ficha do cliente mostra as obras dele a que quem consulta tem acesso.
- **Calendários de trabalho**: horas por dia da semana e dias sem trabalho (feriados nacionais, municipais, facultativos e paragens da empresa, um dia ou um período). Cada empresa tem um calendário por omissão (segunda a sexta, 8 h, com os feriados nacionais deste ano e do seguinte); cada obra pode escolher outro. Permissão `calendars.manage`.
- Feriados nacionais calculados para qualquer ano desde 2016 (art. 234.º do Código do Trabalho, com Páscoa, Sexta-feira Santa e Corpo de Deus móveis); Carnaval opcional. Aviso quando falta o ano seguinte.
- **Prazo da obra em dias úteis** (no separador Dados): dias úteis e de calendário entre o início e o fim previsto, decorridos e por fazer, com aviso se o calendário não tiver os feriados de algum ano do prazo.
- **Diário**: os feriados aparecem junto ao dia; "Ainda não há registo de hoje" não aparece em dias sem trabalho; numa obra em curso, lista dos dias úteis do mês já passados sem registo (cada um abre o registo desse dia).
- Clientes e calendários entram na exportação de dados e na auditoria.

## 0.7.0 — 2026-11-08

Fase 2, sexta parte: modo sem rede no diário de obra.

- Sem rede, o registo do diário (texto, linhas, "onde") e as fotografias ficam guardados no telemóvel (IndexedDB) e são enviados sozinhos quando houver rede: ao abrir a aplicação, quando a rede volta e de minuto a minuto. "Guardar e submeter" sem rede submete depois de enviar.
- Envio passo a passo e retomável (registo, cada fotografia, submissão), idempotente: o servidor aceita `client_uuid` no registo e em cada fotografia e devolve o que já existe num reenvio — nada fica duplicado nem conta duas vezes na quota.
- Conflitos (o mesmo dia criado noutro aparelho, registo entretanto submetido) não perdem nada: ficam no telemóvel com o motivo, "Tentar outra vez" e "Descartar".
- A aplicação abre sem rede: o service worker guarda todos os ecrãs compilados (lidos de `build/manifest.json`) e atualiza o shell a cada versão; a última sessão, obra, estrutura e diário vistos ficam numa cópia local, apagada ao sair da conta. O service worker continua a não guardar respostas da API.
- Indicadores "Sem rede", "N por enviar" e "N com problema"; registos só no telemóvel aparecem na lista do diário.

## 0.6.0 — 2026-11-07

Fase 2, quinta parte: ligar o diário, as fotografias e os documentos à estrutura.

- Cada linha do diário, fotografia e documento pode indicar "onde": um edifício, piso, zona ou elemento (P1, L1...). Com elemento, o local vem dele e acompanha-o se o elemento mudar de sítio.
- Na Estrutura, o histórico de um elemento ou local mostra as linhas do diário (com ligação ao dia), as fotografias e os documentos ligados; num local inclui o que está dentro dele.
- O histórico respeita as permissões: diário e fotografias só para quem consulta o diário; documentos só para quem os consulta.
- Não se apagam locais nem elementos com ligações (para não perder histórico).
- Ligações sempre dentro da mesma obra (validação) e da mesma empresa (chaves estrangeiras compostas).

## 0.5.0 — 2026-11-06

Fase 2, quarta parte: estrutura da obra.

- Edifícios, pisos e zonas numa hierarquia (piso dentro de edifício; zona dentro de piso ou diretamente no edifício).
- Criar vários pisos de uma vez ("-1 a 3").
- Elementos construtivos (sapata, pilar, viga, laje, parede, escada, muro de suporte, cobertura, outro) em cada localização, com código automático por tipo (S1, P1, V1, L1, PA1...) e único na obra.
- Permissão nova `structure.manage` (diretor técnico, diretor de obra, preparador, medidor-orçamentista); quem vê a obra vê a estrutura.
- Só se apaga uma localização vazia; elementos podem mudar de localização. Estrutura na exportação de dados.

## 0.4.0 — 2026-11-05

Fase 2, terceira parte: documentos da obra.

- Pastas e subpastas por obra; pesquisa em todas as pastas (título e nome do ficheiro).
- Documentos com versões imutáveis (v1, v2, …) e nota do que mudou; SHA-256 de cada versão; a versão atual é a mais recente.
- PDF e imagens abrem no browser; os restantes tipos são sempre descarregados como binário. Cada abertura e descarga fica registada.
- Tipos aceites: PDF, DWG, DXF, IFC, Office, OpenDocument, TXT, CSV, imagens e ZIP, até 50 MB.
- Permissões novas `documents.view`, `documents.upload` (inclui versões dos documentos que a própria pessoa enviou) e `documents.manage` (pastas, mover, apagar, versões de qualquer documento), obra a obra.
- Quota de armazenamento; apagar um documento apaga todas as versões e liberta o espaço. Lista na exportação de dados.

## 0.3.0 — 2026-11-04

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
