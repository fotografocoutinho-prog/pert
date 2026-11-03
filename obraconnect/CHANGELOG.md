# Histórico de versões

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
