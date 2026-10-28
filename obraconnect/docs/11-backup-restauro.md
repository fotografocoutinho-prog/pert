# Procedimentos de backup e restauro

## O que guardar

| Item | Onde | Frequência |
| --- | --- | --- |
| Base de dados | MySQL/MariaDB | Diária (mínimo); antes de cada atualização |
| Ficheiros das empresas | `storage/app/private/tenants-data/` (ou bucket S3) | Diária |
| Configuração | `.env` (contém `APP_KEY`) | Sempre que mudar; guardar fora do servidor e cifrado |

Sem a `APP_KEY` original, os segredos 2FA e as sessões existentes deixam de ser legíveis. Guarde-a num gestor de passwords.

## cPanel

### Backups do próprio cPanel

cPanel → **Cópia de segurança** (ou **JetBackup**, se o fornecedor o tiver): descarregar a base de dados e a pasta pessoal. Verifique quantos dias o fornecedor guarda e se os backups ficam noutro servidor.

### Backup automático por cron (recomendado, além do do fornecedor)

Crie `~/bin/backup-obraconnect.sh` (permissões `700`):

```bash
#!/usr/bin/env bash
set -euo pipefail
DEST="$HOME/backups/obraconnect"
STAMP="$(date +%Y%m%d-%H%M)"
mkdir -p "$DEST"

# Credenciais num ficheiro protegido (chmod 600), não na linha de comandos:
# ~/.my.cnf
# [client]
# user=conta_obra
# password=...
mysqldump --defaults-file="$HOME/.my.cnf" --single-transaction --quick --no-tablespaces \
  conta_obraconnect | gzip > "$DEST/db-$STAMP.sql.gz"

tar -czf "$DEST/files-$STAMP.tar.gz" -C "$HOME/obraconnect/storage/app" private

# Mantém 14 dias
find "$DEST" -type f -mtime +14 -delete
```

Cron diário (ex.: 03:30):
```
30 3 * * * $HOME/bin/backup-obraconnect.sh >> $HOME/backups/backup.log 2>&1
```

Copie regularmente `~/backups/` para fora do servidor (cPanel → Backup remoto, rclone para S3, ou download manual semanal). Um backup que só existe no mesmo servidor não protege contra perda do servidor.

## Restauro

1. Colocar a aplicação em manutenção (`php artisan down`) ou fora de horas.
2. Base de dados:
   ```bash
   gunzip -c db-AAAAMMDD-HHMM.sql.gz | mysql --defaults-file=$HOME/.my.cnf conta_obraconnect
   ```
   (sem SSH: phpMyAdmin → Importar o `.sql`).
3. Ficheiros:
   ```bash
   tar -xzf files-AAAAMMDD-HHMM.tar.gz -C $HOME/obraconnect/storage/app
   ```
4. Confirmar que o `.env` tem a mesma `APP_KEY`.
5. `php artisan optimize:clear && php artisan up`.
6. Verificar: login, uma empresa, um logótipo, `api/v1/health`.

## Teste de restauro (trimestral)

1. Criar uma base de dados temporária (`conta_obra_restore`).
2. Restaurar o backup mais recente para lá.
3. Contar registos principais e comparar com produção:
   ```sql
   SELECT (SELECT COUNT(*) FROM tenants) t, (SELECT COUNT(*) FROM users) u,
          (SELECT COUNT(*) FROM memberships) m, (SELECT MAX(created_at) FROM audit_logs) ultimo_evento;
   ```
4. Registar data, duração e resultado. Apagar a base temporária.

## Restauro de uma única empresa

Não há ainda ferramenta automática. Procedimento manual: restaurar o backup numa base temporária e copiar as linhas com `tenant_id = X` das tabelas da empresa (todas têm a coluna), respeitando a ordem das chaves estrangeiras (`tenants` → `roles` → `permission_role` → `memberships` → `invitations` → restantes). Documentar sempre na auditoria.
