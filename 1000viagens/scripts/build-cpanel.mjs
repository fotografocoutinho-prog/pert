/**
 * Constrói o pacote pronto a carregar num alojamento com cPanel.
 *
 *   npm run build:cpanel
 *
 * Produz dist/cpanel/ (a pasta a enviar para public_html) e
 * dist/1000viagens-cpanel.zip (para carregar e extrair pelo File Manager).
 *
 * O que entra: o site em HTML, o backoffice, os ficheiros PHP que fazem o
 * papel do servidor, as páginas de destino já geradas, o mapa do site e as
 * regras do Apache (.htaccess).
 */
import fs from 'node:fs/promises';
import path from 'node:path';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';

import { DEFAULT_SETTINGS } from '../server/settings.js';
import { PUBLIC_CATALOG } from '../server/catalog.js';
import { build as buildSeo } from './build-seo.mjs';

const execFileAsync = promisify(execFile);
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const DIST = path.join(ROOT, 'dist');
const SAIDA = path.join(DIST, 'cpanel');

/** Copia uma pasta, ignorando o que não deve ir para o servidor. */
async function copiar(origem, destino, ignorar = []) {
  await fs.mkdir(destino, { recursive: true });
  for (const entrada of await fs.readdir(origem, { withFileTypes: true })) {
    if (ignorar.includes(entrada.name)) continue;
    const de = path.join(origem, entrada.name);
    const para = path.join(destino, entrada.name);
    if (entrada.isDirectory()) await copiar(de, para, ignorar);
    else await fs.copyFile(de, para);
  }
}

/** Ilustrações e ícones prontos a usar pelo PHP (com __UID__ substituível). */
async function exportarArte() {
  const source = await fs.readFile(path.join(ROOT, 'public/assets/js/scenes.js'), 'utf8');
  const janela = {};
  new Function('window', source)(janela);
  const { SCENES, ICONS } = janela.MVArt;

  const cenas = {};
  for (const nome of Object.keys(SCENES)) cenas[nome] = SCENES[nome]('__UID__');

  const icones = {};
  for (const nome of Object.keys(ICONS)) icones[nome] = janela.MVArt.icon(nome, 26);

  return { cenas, icones };
}

const LEIAME = `1000viagens — como pôr o site no ar (cPanel)
==================================================================

O QUE ESTÁ NESTA PASTA
  index.php / index.html   página principal
  admin.html               backoffice (endereço: /admin)
  viagens/                 páginas de destino, já otimizadas para o Google
  assets/                  imagens, estilos e código do site
  api/                     o "motor" em PHP (pedidos, backoffice, estatísticas)
  dados/                   onde ficam guardados os pedidos e as definições
  uploads/                 imagens que enviar pelo backoffice (logótipo, fotos)
  .htaccess                regras do servidor (endereços, segurança, velocidade)


PASSO 1 — CARREGAR OS FICHEIROS
  1. Entre no cPanel → File Manager → pasta public_html.
  2. Carregue o ficheiro 1000viagens-cpanel.zip.
  3. Clique com o botão direito → Extract.
  4. Apague o .zip depois de extrair.

  Se o site vai ficar num subdomínio ou numa subpasta, extraia para a pasta
  correspondente em vez de public_html.


PASSO 2 — PERMISSÕES DAS PASTAS
  No File Manager, clique com o botão direito em cada uma destas pastas →
  Change Permissions, e ponha 755 (ou 775 se 755 não deixar gravar):

      dados/      uploads/

  É nelas que o site grava os pedidos e as imagens.


PASSO 3 — PRIMEIRA ENTRADA NO BACKOFFICE
  Abra https://oseudominio.pt/admin
  Password inicial:  1000viagens
  Mude-a logo em Segurança → Mudar password.


PASSO 4 — PREENCHER OS SEUS DADOS
  No backoffice:
   · Marca e logótipo → carregue o logótipo e escreva o ENDEREÇO DO SITE
     (por exemplo https://www.oseudominio.pt). Este passo é importante:
     é o endereço que o Google indexa.
   · Dados da empresa → NIF, RNAVT, morada, telefone, WhatsApp, e-mail.
   · Integrações → e-mail para receber os pedidos e o código TravelPartner.


PASSO 5 — SSL E ENDEREÇO ÚNICO
  1. cPanel → Segurança → SSL/TLS Status → ative o certificado gratuito.
  2. Abra o ficheiro .htaccess (File Manager → Edit) e retire o # das linhas
     do HTTPS e da versão do domínio que escolher (com ou sem www).


PASSO 6 — DIZER AO GOOGLE QUE O SITE EXISTE
  1. Vá a search.google.com/search-console e adicione a propriedade do domínio.
  2. Em "Sitemaps", submeta:  sitemap.xml
  3. Em "Inspeção de URL", peça a indexação da página inicial.
  4. Crie também o perfil gratuito em business.google.com (Perfil de Empresa) —
     para pesquisas locais é o que mais peso tem.

  O sitemap já inclui a página inicial, o índice de destinos e cada página de
  destino. As páginas novas aparecem no Google em regra ao fim de alguns dias.


DÚVIDAS FREQUENTES
  · "O backoffice diz que não consegue gravar" → permissões da pasta dados/.
  · "As imagens não aparecem" → permissões da pasta uploads/.
  · "Não recebo os e-mails" → confirme o e-mail em Integrações e veja a pasta
    de spam; alguns alojamentos exigem que o remetente seja do mesmo domínio.
  · "Acrescentei destinos no backoffice e não têm página própria" → as páginas
    de destino são geradas quando o pacote é preparado; peça a regeneração.

CÓPIA DE SEGURANÇA
  Guarde de vez em quando a pasta dados/ (e uploads/). É aí que estão todos os
  pedidos dos clientes e as definições do site.
`;

async function main() {
  console.log('\nA preparar o pacote para cPanel…\n');

  await fs.rm(SAIDA, { recursive: true, force: true });
  await fs.mkdir(SAIDA, { recursive: true });

  // 1. páginas de destino, sitemap e robots sempre frescos
  console.log('· páginas para pesquisa');
  await buildSeo({ quiet: true });

  // 2. site e backoffice
  console.log('· site e backoffice');
  await copiar(path.join(ROOT, 'public'), SAIDA);

  // 3. motor em PHP
  console.log('· motor em PHP');
  await copiar(path.join(ROOT, 'cpanel'), SAIDA);

  // 4. dados de base que o PHP lê (catálogo, definições de origem, ilustrações)
  console.log('· catálogo e ilustrações');
  const base = path.join(SAIDA, 'api/base');
  await fs.mkdir(base, { recursive: true });
  const { cenas, icones } = await exportarArte();
  await fs.writeFile(path.join(base, 'catalogo.json'), JSON.stringify(PUBLIC_CATALOG, null, 2));
  await fs.writeFile(path.join(base, 'definicoes.json'), JSON.stringify(DEFAULT_SETTINGS, null, 2));
  await fs.writeFile(path.join(base, 'cenas.json'), JSON.stringify(cenas));
  await fs.writeFile(path.join(base, 'icones.json'), JSON.stringify(icones));

  // 5. instruções
  await fs.writeFile(path.join(SAIDA, 'LEIA-ME.txt'), LEIAME);

  // 6. arquivo pronto a carregar
  const zip = path.join(DIST, '1000viagens-cpanel.zip');
  await fs.rm(zip, { force: true });
  try {
    await execFileAsync('zip', ['-r', '-q', zip, '.', '-x', '*.DS_Store'], { cwd: SAIDA });
  } catch (err) {
    console.warn('  (não foi possível criar o .zip — a pasta dist/cpanel está pronta na mesma)');
  }

  const contar = async (pasta) => {
    let total = 0;
    for (const entrada of await fs.readdir(pasta, { withFileTypes: true })) {
      total += entrada.isDirectory() ? await contar(path.join(pasta, entrada.name)) : 1;
    }
    return total;
  };

  const tamanho = await fs.stat(zip).then((s) => `${(s.size / 1024).toFixed(0)} KB`).catch(() => '—');
  console.log(`\n✓ ${await contar(SAIDA)} ficheiros em dist/cpanel/`);
  console.log(`✓ dist/1000viagens-cpanel.zip (${tamanho}) — carregue este ficheiro para public_html e extraia.`);
  console.log('\n  Instruções passo a passo: dist/cpanel/LEIA-ME.txt\n');
}

main().catch((err) => {
  console.error('Falhou a preparação do pacote:', err);
  process.exit(1);
});
