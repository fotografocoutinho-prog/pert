# Histórico de versões

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
