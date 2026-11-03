# Registo de progresso

## v0.7.0 — Fase 2, parte 6: modo sem rede (2026-11-08)

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

## v0.6.0 — Fase 2, parte 5: ligar à estrutura (2026-11-07)

| Área | Verificação |
| --- | --- |
| `location_id`/`element_id` em linhas do diário, fotografias e documentos, com FKs compostas e validação por obra | Testes |
| Elemento define o local; mover o elemento leva as ligações | Testes |
| Histórico por elemento e por local (com tudo o que está dentro), filtrado por diary.view / documents.view | Testes + browser |
| Locais e elementos com ligações não se apagam (409) | Testes |
| Interface: "Onde?" nas linhas do diário e fotografias, "Refere-se a" nos documentos, histórico na Estrutura | Browser |

Total: **126 testes**.

## v0.5.0 — Fase 2, parte 4: estrutura da obra (2026-11-06)

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

## v0.4.0 — Fase 2, parte 3: documentos da obra (2026-11-05)

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

## v0.3.0 — Fase 2, parte 2: fotografias no diário (2026-11-04)

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
