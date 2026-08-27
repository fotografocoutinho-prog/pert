# 1000viagens

Site de travel booking com **página de captação de pedidos de orçamento**,
**páginas de destino otimizadas para o Google** e **backoffice** para gerir
marca, conteúdos, pedidos e estatísticas.

Feito de raiz, **sem dependências externas** (nem npm install, nem base de dados,
nem CDN). Corre em dois sítios, com o mesmo comportamento:

| Alojamento | Como |
|---|---|
| **cPanel / alojamento partilhado** (recomendado) | `npm run build:cpanel` → carregue `dist/1000viagens-cpanel.zip` para `public_html` e extraia. Só precisa de PHP 7.4+. |
| **Servidor próprio com Node** | `npm start` |

| | |
|---|---|
| Site público | `/` |
| Destinos | `/viagens/` e `/viagens/<destino>/` |
| Backoffice | `/admin` |
| Política de privacidade | `/privacidade` |

---

## Pôr no ar num alojamento cPanel

```bash
npm run build:cpanel
```

Gera `dist/1000viagens-cpanel.zip`. No cPanel:

1. **File Manager → public_html** → carregue o `.zip` → botão direito → **Extract**.
2. Ponha as pastas `dados/` e `uploads/` em **755** (Change Permissions).
3. Abra `https://oseudominio.pt/admin` — password inicial `1000viagens`, mude-a logo.
4. No backoffice, preencha **Marca → Endereço do site** com o seu domínio.
5. Ative o **SSL** no cPanel e retire o `#` das linhas de HTTPS no `.htaccess`.
6. Submeta `sitemap.xml` no Google Search Console.

As instruções completas, passo a passo, ficam em `dist/cpanel/LEIA-ME.txt`.
O ficheiro `docs/SEO.md` explica o que fazer a seguir para aparecer nas pesquisas.

---

## Arrancar em 30 segundos (Node, para desenvolvimento)

```bash
cd 1000viagens
ADMIN_PASSWORD="a-sua-password" npm start
```

Abra <http://localhost:3000/> e entre no backoffice em `/admin` com essa password.
Se não definir `ADMIN_PASSWORD`, a password inicial é `1000viagens` e o backoffice
avisa-o para a mudar (Segurança → Mudar password).

Para ver o backoffice com dados de exemplo antes de ter clientes:

```bash
npm run seed          # cria ~140 pedidos fictícios
npm run seed -- --clear   # apaga tudo quando já não precisar
```

---

## O que o cliente vê

Uma página principal pensada para transformar visitas em pedidos, mais uma
página por destino para captar quem procura no Google:

- **Herói** com ilustração de pôr do sol desenhada em SVG (ou a sua fotografia).
- **Destinos em destaque** — cada cartão abre o formulário com o destino preenchido.
- **Formulário de orçamento em 5 passos**, com barra de progresso, validação, rascunho
  guardado no dispositivo e ecrã final com número de referência:
  1. tipo de viagem e destino (ou «ainda não sei — surpreendam-me»);
  2. datas ou mês aproximado, duração, flexibilidade;
  3. adultos, crianças e idades, faixa etária de quem pede;
  4. orçamento por pessoa, o que quer incluído, hotel, regime, ritmo, interesses e notas;
  5. contactos, canal e horário preferidos, como nos conheceu, RGPD e marketing.
- **Como funciona**, **porquê nós**, **testemunhos**, **perguntas frequentes**.
- **Páginas de destino** (`/viagens/maldivas/`, `/viagens/laponia/`…) com texto
  próprio, melhor altura para viajar, o que está incluído, informação prática,
  perguntas frequentes e ligação ao formulário já com o destino preenchido.
- **Rodapé** com contactos, morada, NIF, RNAVT e ligação ao Livro de Reclamações.
- Botão flutuante de **WhatsApp** e aviso de privacidade.

Tudo em português de Portugal, responsivo, acessível (navegação por teclado,
etiquetas, contraste) e sem tipos de letra ou scripts de terceiros — o que evita
problemas de RGPD à partida.

## O que o dono da agência vê

**Painel** — filtros de período e tipo de viagem no topo; tudo por baixo obedece-lhes.

- Valor médio por pedido (número principal), pedidos recebidos, valor médio por
  pessoa, taxa de conversão e valor em carteira.
- **Evolução dos pedidos** (linha, com cruzeta e leitura por período).
- **Destinos mais pedidos** · **Faixas etárias** · **Tipo de viagem** ·
  **Orçamento por pessoa** · **Quando querem viajar** · **Funil comercial**.
- Cada gráfico tem uma **vista em tabela** equivalente e tooltips no rato e no teclado.
- Tema claro e escuro.

**Pedidos** — procura, filtros por estado/tipo/período, tabela e ficha completa de
cada pedido: estado (novo → contactado → orçamento enviado → ganho/perdido), valor
orçamentado, notas internas, histórico, e botões para responder por e-mail, WhatsApp
ou telefone. Exportação **CSV** pronta para o Excel.

**Definições**

| Secção | O que se edita |
|---|---|
| Marca e logótipo | nome, slogan, logótipo, favicon, fotografia de capa, cores |
| Dados da empresa | NIF, RNAVT, morada, telefone, WhatsApp, e-mail, horário, redes sociais |
| Conteúdos do site | textos do herói, destinos, passos, vantagens, testemunhos, FAQ, avisos |
| Integrações | **código de autorização TravelPartner**, identificador da agência e endereço do serviço; webhook; e-mail de avisos; código de analítica |
| Segurança | mudar a password, ver a sessão e o estado do envio de e-mail |

Tudo o que grava aparece no site imediatamente — não é preciso publicar nada.

---

## O código de autorização TravelPartner

Fica guardado **apenas no servidor**, no ficheiro `data/settings.json`:

- nunca é incluído na resposta que o site público recebe (há um teste automático a garanti-lo);
- no backoffice aparece mascarado (`••••••••4242`) e só é revelado quando carrega em **Mostrar**;
- gravar o formulário com o valor mascarado **não** apaga o código guardado.

Junto com ele pode guardar o identificador da agência, o endereço do serviço e notas
internas (contacto do gestor de conta, condições negociadas).

---

## Onde estão os dados

Tudo vive na pasta `data/` (configurável com `DATA_DIR`):

```
data/
├── leads.json      pedidos de orçamento
├── settings.json   definições, conteúdos e integrações
├── auth.json       hash da password e sessões abertas
└── uploads/        logótipo, favicon e fotografias
```

**A cópia de segurança do site é copiar esta pasta.** As escritas são atómicas
(ficheiro temporário + renomear), por isso uma cópia feita a qualquer momento é
consistente. Um exemplo de cópia diária:

```bash
0 3 * * * tar czf /backups/1000viagens-$(date +\%F).tar.gz -C /caminho/para/1000viagens data
```

---

## Configuração

Copie `.env.example` para `.env` (ou defina no painel do alojamento):

| Variável | Para que serve |
|---|---|
| `PORT` | porta HTTP (3000 por omissão) |
| `ADMIN_PASSWORD` | password inicial do backoffice (só usada no primeiro arranque) |
| `DATA_DIR` | onde guardar os dados (`./data` por omissão) |
| `LEADS_WEBHOOK_URL` | webhook alternativo ao configurado no backoffice |
| `SMTP_HOST` `SMTP_PORT` `SMTP_USER` `SMTP_PASS` `SMTP_FROM` | envio de avisos de novos pedidos por e-mail (opcional) |

Sem SMTP configurado o site funciona na mesma: os pedidos ficam no backoffice e,
se definir um webhook, seguem também para o Zapier/Make/n8n ou para o seu CRM.

---

## Pôr online

### Servidor próprio (VPS) com Nginx

```bash
# 1. copiar o projeto e criar o serviço
sudo useradd -r -s /bin/false 1000viagens
sudo cp -r 1000viagens /opt/ && sudo chown -R 1000viagens /opt/1000viagens

# 2. /etc/systemd/system/1000viagens.service
[Unit]
Description=1000viagens
After=network.target

[Service]
User=1000viagens
WorkingDirectory=/opt/1000viagens
Environment=PORT=3000
Environment=ADMIN_PASSWORD=a-sua-password
ExecStart=/usr/bin/node server/index.js
Restart=always

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now 1000viagens
```

E no Nginx, com HTTPS via Certbot:

```nginx
server {
  server_name 1000viagens.pt www.1000viagens.pt;
  client_max_body_size 8m;              # uploads de imagens
  location / {
    proxy_pass http://127.0.0.1:3000;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
  }
}
```

### Docker

```bash
ADMIN_PASSWORD="a-sua-password" docker compose up -d --build
```

### Railway, Render, Fly.io e afins

Apontam para o repositório, definem `ADMIN_PASSWORD` e correm `npm start`.
**Ligue um disco persistente em `/app/data`** — sem isso os pedidos perdem-se a
cada nova versão.

---

## Segurança

- Password guardada com **scrypt** (nunca em claro); sessões são tokens aleatórios
  de 32 bytes com apenas o *hash* guardado, em cookie `HttpOnly`, `SameSite=Lax`,
  válidas 12 horas.
- Escritas no backoffice exigem o cabeçalho `X-Requested-With` (proteção CSRF).
- Limites de ritmo no envio de pedidos (6 por 10 minutos por IP) e no login (10 por 10 minutos).
- Campo-armadilha e tempo mínimo de preenchimento contra robôs.
- Uploads servidos com `Content-Security-Policy: default-src 'none'` e `nosniff`,
  o que neutraliza *scripts* escondidos dentro de um SVG.
- `/admin` marcado como `noindex` e bloqueado no `robots.txt`.
- Consentimento RGPD obrigatório, registado com data e hora, e política de
  privacidade preenchida com os dados da empresa.

---

## Estrutura

```
1000viagens/
├── server/          servidor HTTP, API, autenticação, estatísticas (Node puro)
│   ├── index.js     rotas e ficheiros estáticos
│   ├── leads.js     validação dos pedidos, valor estimado, agregações, CSV
│   ├── settings.js  definições por omissão e o que é público
│   ├── catalog.js   opções do formulário (fonte única para site e servidor)
│   ├── auth.js      password scrypt e sessões
│   ├── store.js     ficheiros JSON com escrita atómica
│   └── mailer.js    envio SMTP opcional
├── public/
│   ├── index.html   página principal
│   ├── admin.html   backoffice
│   ├── privacidade.html
│   └── assets/      css e js (scenes.js desenha as ilustrações, charts.js os gráficos)
├── scripts/
│   ├── seed-demo.js dados de demonstração
│   └── test-api.mjs testes de fumo (`npm test`)
└── data/            dados em produção (fora do repositório)
```

## Comandos

```bash
npm start                  # arrancar (Node)
npm run dev                # arrancar com recarregamento automático
npm test                   # 32 testes de ponta a ponta da API em Node
npm run build:seo          # gerar as páginas de destino, sitemap e robots
npm run build:cpanel       # preparar dist/1000viagens-cpanel.zip
npm run test:cpanel        # 39 testes do pacote PHP (precisa de php na máquina)
npm run test:all           # tudo acima, pela ordem certa
npm run seed               # dados de demonstração
npm run seed -- --reset    # regerar os dados de demonstração
npm run seed -- --clear    # apagar todos os pedidos
```

## Onde estão as coisas

| Precisa de… | Mexa em |
|---|---|
| texto das páginas de destino | `content/destinos.json` |
| opções do formulário | `server/catalog.js` |
| textos e conteúdos do site | backoffice → Conteúdos do site |
| o motor PHP do cPanel | `cpanel/api/` |
| regras do Apache | `cpanel/.htaccess` |
| estratégia de pesquisa | `docs/SEO.md` |

## Notas de desenho

Os gráficos foram construídos à mão em SVG com cores validadas para contraste e
daltonismo: uma cor por série (nunca uma rampa em categorias sem ordem natural),
rampa de um só tom nas categorias ordenadas (faixas etárias, escalões de orçamento,
funil), barras finas com extremidade arredondada, grelha discreta, rótulos directos
selectivos e uma vista em tabela para cada gráfico. As ilustrações dos destinos e do
herói são SVG desenhado, para o site não depender de bancos de imagens nem de CDNs.
