# Plano de migração para VPS / cloud

O código não muda. Só configuração e infraestrutura.

## Quando migrar

- Mais de ~30 empresas ativas ou ~300 utilizadores diários.
- Uso intensivo de fotografias/vídeos.
- Necessidade de workers permanentes (emails instantâneos, processamento de imagem, IA).
- Requisitos de clientes Enterprise (SLA, bases dedicadas, região dos dados).

## Arquitetura alvo

| Componente | cPanel (atual) | VPS / cloud |
| --- | --- | --- |
| Web | Apache + PHP-FPM | Nginx + PHP-FPM 8.3+ (ou Laravel Octane) |
| Base de dados | MySQL/MariaDB do alojamento | MySQL 8 gerido (ou instância própria com réplica) |
| Cache e sessões | `database` | `redis` |
| Filas | `database` via cron | `redis` + supervisor (`queue:work`) ou Laravel Horizon |
| Ficheiros | `storage/app/private` | S3 compatível (UE) |
| Agendador | cron `schedule:run` | igual (cron ou systemd timer) |
| Logs | ficheiro diário | ficheiro + agregador (ex.: Loki, CloudWatch) |
| Erros | logs | Sentry ou equivalente |

## Passos

1. Provisionar servidor (Ubuntu LTS), PHP 8.3, Nginx, Redis, supervisor, certificado TLS.
2. Copiar ficheiros de `storage/app/private` para o bucket S3 (`aws s3 sync` ou `rclone`), mantendo os mesmos caminhos (`tenants/{id}/...`).
3. Exportar a base de dados (`mysqldump --single-transaction --routines --no-tablespaces`) e importar no destino.
4. Ajustar `.env`:
   ```
   CACHE_STORE=redis
   SESSION_DRIVER=redis
   QUEUE_CONNECTION=redis
   QUEUE_RUN_VIA_SCHEDULER=false
   OBRA_PRIVATE_DISK=s3
   REDIS_HOST=127.0.0.1
   ```
5. Supervisor:
   ```ini
   [program:obraconnect-worker]
   command=php /var/www/obraconnect/artisan queue:work --sleep=1 --tries=3 --max-time=3600
   numprocs=2
   autorestart=true
   user=www-data
   ```
6. `php artisan migrate --force && php artisan optimize`.
7. Mudar DNS com TTL baixo; manter o cPanel em só leitura até confirmar.
8. Validar: login, convites (email), exportação, `api/v1/health`.

## Bases de dados dedicadas (Enterprise)

A coluna `tenants.database_connection` está preparada. A implementar na fase 6:

1. Ligação adicional em `config/database.php` por cliente.
2. `ResolveTenant` troca a ligação por omissão quando a coluna está preenchida.
3. Comando para mover uma empresa: exportar todas as linhas com `tenant_id = X` (as tabelas já o têm), importar na base dedicada, validar contagens, apagar na base partilhada.
