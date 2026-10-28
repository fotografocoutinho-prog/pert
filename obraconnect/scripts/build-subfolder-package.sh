#!/usr/bin/env bash
#
# Gera um pacote pronto a extrair na pasta pessoal do cPanel para instalar o
# ObraConnect numa subpasta de um site existente.
#
# Uso:  scripts/build-subfolder-package.sh https://www.coda.pt/obras/obraconnect [pasta-de-saída]
#
# O pacote contém:
#   obraconnect/                      aplicação (fica FORA de public_html), com .env pré-preenchido
#   public_html/<caminho>/            ficheiros públicos + index.php que encontra a aplicação
#   LEIA-ME.txt                       passos de instalação para este endereço
#
# O .env não leva segredos: base de dados, email e SETUP_TOKEN ficam por preencher,
# e a APP_KEY é gerada na página /instalar.
#
set -euo pipefail

URL="${1:?Indique o endereço final, ex.: https://www.coda.pt/obras/obraconnect}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$(mkdir -p "${2:-$ROOT/dist}" && cd "${2:-$ROOT/dist}" && pwd)"
VERSION="$(tr -d '[:space:]' < "$ROOT/VERSION")"

URL="${URL%/}"
SCHEME="$(php -r 'echo parse_url($argv[1], PHP_URL_SCHEME) ?: "";' "$URL")"
HOST="$(php -r 'echo parse_url($argv[1], PHP_URL_HOST) ?: "";' "$URL")"
BASEPATH="$(php -r 'echo rtrim(parse_url($argv[1], PHP_URL_PATH) ?: "", "/");' "$URL")"

[ "$SCHEME" = "https" ] || { echo "ERRO: use um endereço https://"; exit 1; }
[ -n "$HOST" ] || { echo "ERRO: endereço inválido"; exit 1; }
[ -n "$BASEPATH" ] || { echo "ERRO: sem subpasta no endereço. Para a raiz de um domínio use scripts/build-release.sh"; exit 1; }
[[ "$BASEPATH" =~ ^(/[a-z0-9_-]+)+$ ]] || { echo "ERRO: a subpasta só pode ter letras minúsculas, números, - e _"; exit 1; }

# Com e sem "www." para a sessão funcionar nas duas formas do domínio.
BARE="${HOST#www.}"
STATEFUL="www.$BARE,$BARE"
SLUG="$(echo "$BARE$BASEPATH" | tr '/' '-' )"

RELEASE="$OUT/obraconnect-$VERSION.zip"
if [ ! -f "$RELEASE" ]; then
    "$ROOT/scripts/build-release.sh" "$OUT"
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
cd "$WORK"
unzip -q "$RELEASE"

echo "→ Pasta pública: public_html$BASEPATH"
mkdir -p "public_html$BASEPATH"
cp -a obraconnect/public/. "public_html$BASEPATH/"
cp obraconnect/deploy/cpanel/public_html/index.php "public_html$BASEPATH/index.php"

echo "→ .env para $URL"
sed -e "s|^APP_URL=.*|APP_URL=$URL|" \
    -e "s|^SESSION_PATH=.*|SESSION_PATH=$BASEPATH|" \
    -e "s|^SANCTUM_STATEFUL_DOMAINS=.*|SANCTUM_STATEFUL_DOMAINS=$STATEFUL|" \
    -e "s|^DB_DATABASE=.*|DB_DATABASE=|" \
    -e "s|^DB_USERNAME=.*|DB_USERNAME=|" \
    -e "s|^MAIL_HOST=.*|MAIL_HOST=mail.$BARE|" \
    -e "s|^MAIL_USERNAME=.*|MAIL_USERNAME=nao-responder@$BARE|" \
    -e "s|^MAIL_FROM_ADDRESS=.*|MAIL_FROM_ADDRESS=\"nao-responder@$BARE\"|" \
    obraconnect/.env.example > obraconnect/.env

PHPBIN="/opt/cpanel/ea-php84/root/usr/bin/php"
cat > LEIA-ME.txt <<EOF
ObraConnect $VERSION — instalação em $URL
=====================================================================

Este pacote extrai-se na PASTA PESSOAL do cPanel (a que contém public_html).
Cria duas pastas:
  obraconnect/                 a aplicação (fica fora da parte pública)
  public_html$BASEPATH/   o que o browser vê em $URL

ANTES DE COMEÇAR
  Abra http://$HOST$(dirname "$BASEPATH" | sed 's|^/$||')/ no browser. Se aparecer uma página do site atual
  nesse endereço, a nova pasta pode substituí-la. Nesse caso NÃO extraia:
  escolha outro caminho (ou peça um pacote novo).

1. FICHEIROS
   cPanel > Gestor de ficheiros > pasta pessoal (/home/UTILIZADOR).
   Carregar > escolher este ZIP > voltar > botão direito no ZIP > Extrair.
   Depois apague o ZIP.

2. BASE DE DADOS
   cPanel > Bases de dados MySQL:
   - criar a base de dados (ex.: obraconnect; fica UTILIZADOR_obraconnect)
   - criar um utilizador com password forte
   - adicionar o utilizador à base de dados com TODOS OS PRIVILÉGIOS

3. TABELAS
   cPanel > phpMyAdmin > escolher a base de dados nova > Importar >
   ficheiro obraconnect/database/sql/obraconnect-install.sql
   (descarregue-o primeiro pelo Gestor de ficheiros).

4. CONFIGURAÇÃO
   Gestor de ficheiros > Definições > "Mostrar ficheiros ocultos".
   Editar obraconnect/.env e preencher:
     DB_DATABASE=   nome completo da base de dados (com o prefixo)
     DB_USERNAME=   utilizador completo (com o prefixo)
     DB_PASSWORD=   password do utilizador da base de dados
     MAIL_PASSWORD= password da conta de email nao-responder@$BARE
                    (crie-a em cPanel > Contas de email; confirme MAIL_HOST)
     SETUP_TOKEN=   texto aleatório com 32 ou mais caracteres (só para o passo 5)
   Já está preenchido: APP_URL, SESSION_PATH e SANCTUM_STATEFUL_DOMAINS.

5. INSTALAR
   Abrir $URL/instalar
   - "Gerar APP_KEY" (pede o SETUP_TOKEN)
   - confirmar que todos os requisitos estão OK
   - criar o super administrador (a password escolhe-a você)
   Depois APAGUE a linha SETUP_TOKEN do .env.

6. TAREFA CRON (obrigatória: emails, exportações, subscrições)
   cPanel > Tarefas Cron > "Uma vez por minuto" > comando:
   $PHPBIN /home/UTILIZADOR/obraconnect/artisan schedule:run >> /dev/null 2>&1
   (troque UTILIZADOR pelo seu utilizador do cPanel; se o caminho do PHP não
   existir, veja obraconnect/deploy/cpanel/cron.txt)

7. VERIFICAR
   $URL/api/v1/health   deve mostrar "status":"ok"
   $URL/.env            deve dar erro 403 (nunca o conteúdo)
   Entrar em $URL/entrar com o super administrador: é pedido para ativar
   a autenticação de dois fatores.

Guia completo: obraconnect/docs/07-guia-cpanel.md (opção D).
EOF

ZIP="$OUT/obraconnect-$VERSION-$SLUG.zip"
rm -f "$ZIP"
zip -qr -9 "$ZIP" obraconnect public_html LEIA-ME.txt
(cd "$OUT" && sha256sum "$(basename "$ZIP")" > "$(basename "$ZIP").sha256")

echo "✓ $ZIP ($(du -h "$ZIP" | cut -f1))"
