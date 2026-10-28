# Guia de instalação em cPanel

Testado localmente a partir do ZIP de release com Apache 2.4 + mod_php 8.3 + MariaDB 10.11, nos três cenários da secção 4 (raiz em `public/`, aplicação inteira dentro da pasta pública, e `index.php` adaptador em `public_html`): importação do SQL, página `/instalar`, registo de empresa pela interface, ficheiros secretos bloqueados. Não foi testado num servidor cPanel real; os nomes dos menus variam entre fornecedores e versões.

## 0. Requisitos do alojamento

| Requisito | Mínimo |
| --- | --- |
| PHP | 8.3 ou 8.4 (cPanel → **MultiPHP Manager**) |
| Extensões PHP | pdo_mysql, mbstring, openssl, tokenizer, xml, dom, ctype, curl, fileinfo, zip, session (opcional: intl, gd) |
| Base de dados | MySQL 8.0+ ou MariaDB 10.6+ |
| Tarefas cron | Sim, a cada minuto |
| SSL | Certificado ativo no domínio (AutoSSL / Let's Encrypt) |
| Espaço | ~80 MB para a aplicação + ficheiros das empresas (ou S3) |
| Recomendado | Acesso **Terminal** ou SSH (não é obrigatório) |

Sugestão: use um subdomínio dedicado, por exemplo `app.suaempresa.pt`. Fica com raiz própria e não mistura com o site institucional.

## 1. Obter o ZIP

- Pelo GitHub: separador **Actions** → workflow **ObraConnect** → execução mais recente → artefacto `obraconnect-release`.
- Ou localmente: `cd obraconnect && scripts/build-release.sh` (gera `dist/obraconnect-<versão>.zip` e o `.sha256`).

O ZIP já inclui `vendor/` e o frontend compilado. **Não precisa de Node.js nem de Composer no servidor.**

## 2. Criar a base de dados

cPanel → **Bases de dados MySQL**:

1. Criar base de dados, ex.: `conta_obraconnect`.
2. Criar utilizador, ex.: `conta_obra`, com password forte.
3. Adicionar o utilizador à base de dados com **Todos os privilégios**.

## 3. Enviar e extrair os ficheiros

cPanel → **Gestor de ficheiros**:

1. Na pasta pessoal (`/home/UTILIZADOR/`, **fora** de `public_html`) envie o ZIP.
2. Extraia. Fica `/home/UTILIZADOR/obraconnect/`.
3. Apague o ZIP.

## 4. Apontar o domínio para `obraconnect/public`

Escolha **uma** opção:

**Opção A — subdomínio ou domínio adicional (recomendado).**
cPanel → **Domínios** → criar `app.suaempresa.pt` com raiz `obraconnect/public`. (Num domínio já existente: **Gerir** → alterar a raiz.)

**Opção B — domínio principal com raiz fixa em `public_html`, com Terminal/SSH.**
```bash
mv ~/public_html ~/public_html.antigo
ln -s ~/obraconnect/public ~/public_html
```

**Opção C — domínio principal, sem symlink possível.**
1. Copie o **conteúdo** de `obraconnect/public/` para `public_html/`.
2. Substitua `public_html/index.php` por `obraconnect/deploy/cpanel/public_html/index.php`.
3. Em cada atualização volte a copiar `obraconnect/public/build/` para `public_html/build/`.

**Opção D — numa subpasta de um site existente (ex.: `www.coda.pt/obras/obraconnect`).**
A aplicação fica em `/home/UTILIZADOR/obraconnect/` (fora de `public_html`) e o site existente não é alterado.

- Com Terminal/SSH (recomendado, facilita as atualizações):
  ```bash
  mkdir -p ~/public_html/obras
  ln -s ~/obraconnect/public ~/public_html/obras/obraconnect
  ```
- Sem Terminal: crie `public_html/obras/obraconnect/`, copie para lá o **conteúdo** de `obraconnect/public/` e substitua o `index.php` por `obraconnect/deploy/cpanel/public_html/index.php` (encontra a pasta da aplicação sozinho). Em cada atualização volte a copiar `public/build/`.

No `.env` (passo 6), além do resto:
```
APP_URL=https://www.coda.pt/obras/obraconnect
SESSION_PATH=/obras/obraconnect
SANCTUM_STATEFUL_DOMAINS=www.coda.pt
```
Com `SESSION_PATH` os cookies ficam limitados à subpasta e não interferem com o site principal. O service worker da PWA também fica limitado a `/obras/obraconnect/`.

**Versão do PHP numa subpasta.** A subpasta usa a versão de PHP do domínio (MultiPHP Manager é por domínio). O OBRACONNECT precisa de PHP 8.3+. Se o site principal estiver numa versão mais antiga, **não mude a versão do domínio sem confirmar que o site principal funciona em 8.3**: peça ao alojamento para definir PHP 8.3 só na subpasta (muitos cPanel aceitam uma linha `AddHandler` no `.htaccess` da subpasta), ou use um subdomínio (opção A).

Testado com Apache 2.4 + mod_php 8.3, com um site existente na raiz com regras de reescrita do tipo WordPress, nas duas variantes (symlink e cópia com `index.php` adaptador): o site principal continua a responder, a aplicação funciona em `/obras/obraconnect/` (registo, login com regresso ao endereço pedido, convites, exportação e download, `/instalar`), e `.env` dá 403.

Nunca coloque a pasta `obraconnect` inteira dentro de `public_html`. Se isso acontecer, o `.htaccess` incluído bloqueia `.env`, `vendor/`, `storage/` e encaminha para `public/`, mas é uma rede de segurança e não a configuração correta.

## 5. Criar as tabelas

**Com Terminal/SSH** (preferível):
```bash
cd ~/obraconnect
cp .env.example .env        # editar no passo 6 antes de continuar
php artisan key:generate
php artisan migrate --force
php artisan obraconnect:sync-permissions
php artisan db:seed --class=PlanSeeder --force
```

**Sem SSH**: cPanel → **phpMyAdmin** → escolha a base de dados vazia → **Importar** → ficheiro `obraconnect/database/sql/obraconnect-install.sql` (descarregue-o primeiro pelo Gestor de ficheiros). Cria as tabelas, as permissões e os quatro planos. Não contém dados pessoais.

## 6. Configurar o `.env`

Gestor de ficheiros → `obraconnect/` → copie `.env.example` para `.env` (ative "mostrar ficheiros ocultos") e edite:

| Variável | Valor |
| --- | --- |
| `APP_KEY` | Com SSH: `php artisan key:generate`. Sem SSH: gere no seu computador com `php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"` e cole |
| `APP_URL` | `https://app.suaempresa.pt` |
| `DB_CONNECTION` | `mysql` (ou `mariadb` se o servidor for MariaDB; ambos funcionam) |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Os do passo 2 (com o prefixo da conta) |
| `SANCTUM_STATEFUL_DOMAINS` | `app.suaempresa.pt` (só o domínio, sem caminho) |
| `SESSION_PATH` | `/` na raiz do domínio; o caminho da subpasta na opção D (ex.: `/obras/obraconnect`) |
| `MAIL_*` | Conta de email criada no cPanel (ex.: `nao-responder@suaempresa.pt`, porta 465, `MAIL_SCHEME=smtps`) |
| `SETUP_TOKEN` | Só sem SSH: texto aleatório com 32+ caracteres |

Mantenha `APP_DEBUG=false` e `FORCE_HTTPS=true` em produção.

## 7. Permissões de pastas

`storage/` e `bootstrap/cache/` têm de ter escrita pelo PHP. Em cPanel com PHP-FPM/suPHP o dono é a própria conta e `755` nas pastas chega. Se a página `/instalar` indicar falta de escrita: Gestor de ficheiros → selecionar as pastas → **Permissões** → `755` (recursivo).

## 8. Criar o super administrador da plataforma

**Com SSH:**
```bash
php artisan obraconnect:create-platform-admin admin@suaempresa.pt --name="Nome Apelido"
```

**Sem SSH:** abra `https://app.suaempresa.pt/instalar`, verifique os requisitos, introduza o `SETUP_TOKEN` e crie o administrador. A página desativa-se sozinha depois disso. **Apague a linha `SETUP_TOKEN` do `.env`.**

No primeiro login é pedido que ative a autenticação de dois fatores (obrigatória para a equipa da plataforma).

## 9. Tarefa cron (obrigatória)

cPanel → **Tarefas Cron** → "Uma vez por minuto":
```
/opt/cpanel/ea-php83/root/usr/bin/php /home/UTILIZADOR/obraconnect/artisan schedule:run >> /dev/null 2>&1
```
O caminho do PHP depende do servidor (ver `deploy/cpanel/cron.txt`). Sem esta tarefa não há envio de emails (convites, recuperação de password), exportações nem ciclo de subscrições.

## 10. Otimização (com SSH)

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```
Depois de qualquer alteração ao `.env`, volte a correr `php artisan config:cache`. Sem SSH estas caches são opcionais; a aplicação funciona sem elas.

Valores de PHP recomendados (MultiPHP INI Editor): ver `deploy/cpanel/user.ini`.

## 11. Verificação final

- [ ] `https://app.suaempresa.pt/api/v1/health` devolve `"status":"ok"`.
- [ ] `https://app.suaempresa.pt/.env` dá **403** ou mostra a aplicação (nunca o conteúdo do ficheiro).
- [ ] Registo de uma empresa de teste chega ao email (cron a correr).
- [ ] Login do super administrador pede 2FA.
- [ ] `/instalar` responde 404.

## 12. Atualizar para uma nova versão

1. **Backup** da base de dados e de `storage/app/` (ver `docs/11-backup-restauro.md`).
2. Com SSH: `php artisan down`. Sem SSH: faça a atualização fora do horário de trabalho.
3. Extraia o ZIP novo para `obraconnect_novo/` e copie para lá o `.env` e o conteúdo de `storage/app/` da instalação atual.
4. Troque as pastas: `obraconnect` → `obraconnect_antigo`, `obraconnect_novo` → `obraconnect`.
5. Aplique as migrações:
   - com SSH: `php artisan migrate --force && php artisan obraconnect:sync-permissions`;
   - sem SSH: a página `/instalar` já não está disponível (existe um administrador). Crie uma **Tarefa Cron** temporária com `php /home/UTILIZADOR/obraconnect/artisan migrate --force && php /home/UTILIZADOR/obraconnect/artisan obraconnect:sync-permissions`, espere que corra uma vez e apague-a.
6. Com SSH: `php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan up`.
7. Verifique. Se correr mal, volte a trocar as pastas e reponha o backup da base de dados.

## Problemas frequentes

| Sintoma | Causa provável |
| --- | --- |
| Erro 500 em todas as páginas | `APP_KEY` vazia, versão de PHP errada, ou falta de escrita em `storage/` |
| "419 Page Expired" / sessão perde-se | `SESSION_SECURE_COOKIE=true` sem HTTPS, ou `SANCTUM_STATEFUL_DOMAINS` diferente do domínio |
| Emails não chegam | Cron não configurado, ou dados SMTP errados (ver `storage/logs/`) |
| Página em branco após atualizar | Cache antiga: `php artisan optimize:clear` |
| Imagens/CSS em falta (opção C) | Não copiou `public/build/` para `public_html/build/` |
| Ícones da app em falta | Não use a pasta `/icons/`: o Apache do cPanel tem um alias global com esse nome. A aplicação usa `/pwa/` |
