# Histórico de versões

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
