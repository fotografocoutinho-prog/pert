#!/usr/bin/env node
//
// Medição de betão e cofragem de um pilar e das vigas que nele descarregam.
//
// Convenções usadas (as correntes em medições PT):
//   - o pilar leva a altura toda (pé-direito estrutural);
//   - as vigas medem-se em vão livre, entre faces dos pilares;
//   - a cofragem do pilar mede-se face a face, descontando em cada face a
//     altura da viga que aí chega — nessa zona quem cofra é a viga;
//   - a cofragem da viga é o fundo mais as faces laterais visíveis
//     (h - espessura da laje, do lado onde a laje assenta).
//
// Orientação das faces, em planta:
//   a  = dimensão do pilar na direção nascente-poente (onde chegam as vigas E e O)
//   b  = dimensão do pilar na direção norte-sul       (onde chegam as vigas N e S)
//   logo: faces nascente/poente têm largura b, faces norte/sul têm largura a.
//
// Uso rápido:
//   node pilar-vigas.mjs                # corre o exemplo do caderno
//   node pilar-vigas.mjs --ajuda
//

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';

export class ErroMedicao extends Error {}

const FACES = ['S', 'E', 'N', 'O'];
const NOME_FACE = { N: 'norte', S: 'sul', E: 'nascente', O: 'poente' };
const ALIAS_FACE = new Map(
  Object.entries({
    n: 'N', norte: 'N',
    s: 'S', sul: 'S',
    e: 'E', este: 'E', nascente: 'E',
    o: 'O', w: 'O', oeste: 'O', poente: 'O',
  }),
);

const EXEMPLO = {
  nome: 'P1',
  pilar: { a: 0.30, b: 0.40, altura: 4.00 },
  vigas: [
    { nome: 'V1', face: 'E', b: 0.40, h: 0.50 },
    { nome: 'V2', face: 'N', b: 0.30, h: 0.25 },
    { nome: 'V3', face: 'O', b: 0.40, h: 0.35 },
    { nome: 'V4', face: 'S', b: 0.30, h: 0.40 },
  ],
};

// ---------------------------------------------------------------- utilitários

function semAcentos(texto) {
  return texto.normalize('NFD').replace(/[̀-ͯ]/g, '');
}

export function normalizarFace(valor) {
  const chave = semAcentos(String(valor).trim().toLowerCase());
  const face = ALIAS_FACE.get(chave);
  if (!face) {
    throw new ErroMedicao(`face desconhecida: "${valor}" (use N, S, E/nascente ou O/poente)`);
  }
  return face;
}

function num(valor, campo) {
  const n = typeof valor === 'string' ? Number(valor.replace(',', '.')) : Number(valor);
  if (!Number.isFinite(n)) throw new ErroMedicao(`${campo}: valor inválido ("${valor}")`);
  return n;
}

function positivo(valor, campo) {
  const n = num(valor, campo);
  if (n <= 0) throw new ErroMedicao(`${campo}: tem de ser maior que zero (${n})`);
  return n;
}

function f(n, casas = 3) {
  return n.toFixed(casas).replace('.', ',');
}

function larguraDaFace(pilar, face) {
  return face === 'E' || face === 'O' ? pilar.b : pilar.a;
}

// -------------------------------------------------------------------- medição

function lerPilar(bruto) {
  if (!bruto) throw new ErroMedicao('falta a secção do pilar (--pilar axbxaltura)');
  const a = positivo(bruto.a, 'pilar.a');
  const b = positivo(bruto.b, 'pilar.b');
  const altura = positivo(bruto.altura, 'pilar.altura');
  return { a, b, altura };
}

function lerViga(bruta, indice, lajeGlobal, pilar, convencao) {
  const nome = bruta.nome ?? `V${indice + 1}`;
  const face = normalizarFace(bruta.face);
  const b = positivo(bruta.b, `${nome}.b`);
  const h = positivo(bruta.h, `${nome}.h`);
  const temVao = bruta.vao !== undefined && bruta.vao !== null && bruta.vao !== '';
  const vao = temVao ? positivo(bruta.vao, `${nome}.vao`) : null;
  const laje = bruta.laje === undefined ? lajeGlobal : num(bruta.laje, `${nome}.laje`);
  const ladosComLaje = bruta.ladosComLaje === undefined ? 2 : Number(bruta.ladosComLaje);

  if (![0, 1, 2].includes(ladosComLaje)) {
    throw new ErroMedicao(`${nome}.ladosComLaje: use 0, 1 ou 2`);
  }
  if (laje < 0 || laje >= h) {
    throw new ErroMedicao(
      `${nome}: espessura da laje (${f(laje, 2)}) tem de ser menor que a altura da viga (${f(h, 2)})`,
    );
  }
  if (h >= pilar.altura) {
    throw new ErroMedicao(
      `${nome}: altura da viga (${f(h, 2)}) não pode igualar ou exceder o pé-direito (${f(pilar.altura, 2)})`,
    );
  }

  const alturaNervura = h - laje;
  const alturaBetao = convencao === 'nervura' ? alturaNervura : h;
  const lateral = ladosComLaje * alturaNervura + (2 - ladosComLaje) * h;

  const avisos = [];
  const larguraFace = larguraDaFace(pilar, face);
  if (b > larguraFace + 1e-9) {
    avisos.push(
      `${nome} tem ${f(b, 2)} m de largura e a face ${NOME_FACE[face]} do pilar só tem ${f(larguraFace, 2)} m`,
    );
  }

  return {
    nome, face, b, h, vao, laje, ladosComLaje,
    alturaNervura, alturaBetao, lateral,
    betao: vao === null ? null : b * alturaBetao * vao,
    cofragem: vao === null ? null : (b + lateral) * vao,
    avisos,
  };
}

export function medir(dados) {
  const pilar = lerPilar(dados.pilar);
  const lajeGlobal = dados.laje === undefined ? 0 : num(dados.laje, 'laje');
  if (lajeGlobal < 0) throw new ErroMedicao('laje: não pode ser negativa');

  const convencao = dados.convencao ?? 'nervura';
  if (convencao !== 'nervura' && convencao !== 'total') {
    throw new ErroMedicao(`convenção desconhecida: "${convencao}" (use "nervura" ou "total")`);
  }

  const semCofragem = new Set((dados.facesSemCofragem ?? []).map(normalizarFace));

  const vigas = [];
  const porFace = new Map();
  for (const [indice, bruta] of (dados.vigas ?? []).entries()) {
    const viga = lerViga(bruta, indice, lajeGlobal, pilar, convencao);
    const jaExiste = porFace.get(viga.face);
    if (jaExiste) {
      throw new ErroMedicao(
        `face ${NOME_FACE[viga.face]}: ${jaExiste.nome} e ${viga.nome} chegam à mesma face do pilar`,
      );
    }
    porFace.set(viga.face, viga);
    vigas.push(viga);
  }

  const faces = FACES.map((face) => {
    const viga = porFace.get(face) ?? null;
    const largura = larguraDaFace(pilar, face);
    const desconto = viga ? viga.h : 0;
    const alturaCofrada = pilar.altura - desconto;
    const cofrada = !semCofragem.has(face);
    return { face, viga, largura, desconto, alturaCofrada, cofrada, area: cofrada ? largura * alturaCofrada : 0 };
  });

  const betaoPilar = pilar.a * pilar.b * pilar.altura;
  const cofragemPilar = faces.reduce((s, x) => s + x.area, 0);
  const perimetro = 2 * (pilar.a + pilar.b);
  const cofragemBruta = perimetro * pilar.altura;
  const descontoVigas = faces.filter((x) => x.cofrada).reduce((s, x) => s + x.largura * x.desconto, 0);
  const descontoFaces = faces.filter((x) => !x.cofrada).reduce((s, x) => s + x.largura * pilar.altura, 0);

  const comVao = vigas.filter((v) => v.vao !== null);
  const betaoVigas = comVao.reduce((s, v) => s + v.betao, 0);
  const cofragemVigas = comVao.reduce((s, v) => s + v.cofragem, 0);

  return {
    nome: dados.nome ?? 'P1',
    convencao,
    laje: lajeGlobal,
    pilar: {
      ...pilar, faces, betao: betaoPilar, cofragem: cofragemPilar,
      perimetro, cofragemBruta, descontoVigas, descontoFaces,
    },
    vigas,
    totais: {
      betao: betaoPilar + betaoVigas,
      betaoPilar,
      betaoVigas,
      cofragem: cofragemPilar + cofragemVigas,
      cofragemPilar,
      cofragemVigas,
      vigasSemVao: vigas.filter((v) => v.vao === null).map((v) => v.nome),
    },
    avisos: vigas.flatMap((v) => v.avisos),
  };
}

// ------------------------------------------------------------------ relatório

function tabela(colunas, linhas) {
  const larguras = colunas.map((coluna, i) =>
    Math.max(coluna.titulo.length, ...linhas.map((linha) => String(linha[i]).length)),
  );
  const formatar = (celulas) =>
    celulas
      .map((celula, i) =>
        colunas[i].dir ? String(celula).padStart(larguras[i]) : String(celula).padEnd(larguras[i]),
      )
      .join('  ')
      .trimEnd();
  return [
    formatar(colunas.map((c) => c.titulo)),
    larguras.map((w) => '─'.repeat(w)).join('  '),
    ...linhas.map(formatar),
  ].join('\n');
}

export function relatorio(r) {
  const { pilar } = r;
  const partes = [];

  partes.push(`MEDIÇÃO — ${r.nome}   ${f(pilar.a, 2)} × ${f(pilar.b, 2)} m   pé-direito ${f(pilar.altura, 2)} m`);

  partes.push('\nBETÃO DO PILAR');
  partes.push(
    `  ${f(pilar.a, 2)} × ${f(pilar.b, 2)} × ${f(pilar.altura, 2)} = ${f(pilar.betao)} m³`,
  );

  partes.push('\nCOFRAGEM DO PILAR (face a face)');
  partes.push(
    tabela(
      [
        { titulo: 'Face' },
        { titulo: 'Viga' },
        { titulo: 'h viga', dir: true },
        { titulo: 'Largura', dir: true },
        { titulo: 'Altura a cofrar', dir: true },
        { titulo: 'Área (m²)', dir: true },
      ],
      pilar.faces.map((x) => [
        NOME_FACE[x.face],
        x.viga ? x.viga.nome : '—',
        x.viga ? f(x.desconto, 2) : '—',
        f(x.largura, 2),
        x.cofrada ? `${f(pilar.altura, 2)} − ${f(x.desconto, 2)} = ${f(x.alturaCofrada, 2)}` : 'não cofrada',
        f(x.area),
      ]),
    )
      .split('\n')
      .map((linha) => `  ${linha}`)
      .join('\n'),
  );
  partes.push(`  → total da cofragem do pilar: ${f(pilar.cofragem)} m²`);
  partes.push(
    `  verificação: perímetro ${f(pilar.perimetro, 2)} × ${f(pilar.altura, 2)} = ${f(pilar.cofragemBruta)}` +
      ` − descontos ${f(pilar.descontoVigas + pilar.descontoFaces)} = ${f(pilar.cofragemBruta - pilar.descontoVigas - pilar.descontoFaces)} m²`,
  );

  if (r.vigas.length > 0) {
    partes.push('\nVIGAS (vão livre, entre faces dos pilares)');
    partes.push(
      tabela(
        [
          { titulo: 'Viga' },
          { titulo: 'Face' },
          { titulo: 'Secção (m)' },
          { titulo: 'Vão', dir: true },
          { titulo: 'Betão (m³)', dir: true },
          { titulo: 'Cofragem (m²)', dir: true },
        ],
        r.vigas.map((v) => [
          v.nome,
          NOME_FACE[v.face],
          `${f(v.b, 2)} × ${f(v.h, 2)}`,
          v.vao === null ? '—' : f(v.vao, 2),
          v.betao === null ? '—' : f(v.betao),
          v.cofragem === null ? '—' : f(v.cofragem),
        ]),
      )
        .split('\n')
        .map((linha) => `  ${linha}`)
        .join('\n'),
    );
  }

  partes.push('\nTOTAIS');
  partes.push(
    tabela(
      [{ titulo: '' }, { titulo: 'Pilar', dir: true }, { titulo: 'Vigas', dir: true }, { titulo: 'Total', dir: true }],
      [
        ['Betão (m³)', f(r.totais.betaoPilar), f(r.totais.betaoVigas), f(r.totais.betao)],
        ['Cofragem (m²)', f(r.totais.cofragemPilar), f(r.totais.cofragemVigas), f(r.totais.cofragem)],
      ],
    )
      .split('\n')
      .map((linha) => `  ${linha}`)
      .join('\n'),
  );

  const notas = [];
  if (r.totais.vigasSemVao.length > 0) {
    notas.push(
      `sem vão livre indicado (${r.totais.vigasSemVao.join(', ')}): entram só como desconto na cofragem do pilar`,
    );
  }
  if (r.laje > 0) {
    notas.push(
      r.convencao === 'nervura'
        ? `viga medida abaixo da laje (h − ${f(r.laje, 2)}); a laje mede-se em toda a área`
        : `viga medida com a altura total; a laje mede-se entre faces das vigas`,
    );
  }
  for (const aviso of r.avisos) notas.push(`atenção: ${aviso}`);
  if (notas.length > 0) {
    partes.push('\nNOTAS');
    for (const nota of notas) partes.push(`  · ${nota}`);
  }

  return partes.join('\n');
}

export function csv(r) {
  const linhas = [['elemento', 'descricao', 'betao_m3', 'cofragem_m2'].join(';')];
  linhas.push(
    [
      r.nome,
      `Pilar ${f(r.pilar.a, 2)}x${f(r.pilar.b, 2)} h=${f(r.pilar.altura, 2)}`,
      f(r.pilar.betao),
      f(r.pilar.cofragem),
    ].join(';'),
  );
  for (const v of r.vigas) {
    if (v.vao === null) continue;
    linhas.push(
      [v.nome, `Viga ${f(v.b, 2)}x${f(v.h, 2)} vao=${f(v.vao, 2)}`, f(v.betao), f(v.cofragem)].join(';'),
    );
  }
  linhas.push(['TOTAL', '', f(r.totais.betao), f(r.totais.cofragem)].join(';'));
  return linhas.join('\n');
}

// ------------------------------------------------------------------------ CLI

const AJUDA = `
Medição de betão e cofragem de um pilar e das vigas que nele descarregam.

  node pilar-vigas.mjs [opções]

Opções
  --pilar AxBxH         secção e pé-direito, em metros. Ex: 0.30x0.40x4.00
                        A = direção nascente-poente, B = direção norte-sul
  --viga ESPEC          uma por viga (até 4). Formato:
                          nome:face:LARGxALT[:vão[:laje]]
                        Ex: V1:nascente:0.40x0.50:4.50
                        Sem vão, a viga só desconta a cofragem do pilar.
  --laje E              espessura da laje, para a cofragem lateral das vigas
  --convencao MODO      nervura (omissão) = viga medida abaixo da laje
                        total              = viga medida com a altura toda
  --sem-cofragem FACES  faces que não se cofram (pilar embebido). Ex: N,O
  --json FICHEIRO       lê os dados de um ficheiro JSON em vez das opções
  --csv                 imprime em CSV (;) em vez do relatório
  --exemplo             corre o exemplo do caderno
  --ajuda               esta ajuda

Faces: N/norte, S/sul, E/este/nascente, O/oeste/poente.

JSON equivalente ao exemplo:
{
  "nome": "P1",
  "pilar": { "a": 0.30, "b": 0.40, "altura": 4.00 },
  "laje": 0.20,
  "convencao": "nervura",
  "facesSemCofragem": [],
  "vigas": [
    { "nome": "V1", "face": "E", "b": 0.40, "h": 0.50, "vao": 4.50 },
    { "nome": "V2", "face": "N", "b": 0.30, "h": 0.25, "vao": 3.00, "ladosComLaje": 1 }
  ]
}
"ladosComLaje" (0, 1 ou 2, omissão 2) serve para vigas de bordadura, em que um
dos lados fica à vista em toda a altura.
`.trim();

function parsePilar(texto) {
  const partes = String(texto).split(/x/i);
  if (partes.length !== 3) throw new ErroMedicao(`--pilar: use AxBxH (recebi "${texto}")`);
  return { a: partes[0], b: partes[1], altura: partes[2] };
}

function parseViga(texto) {
  const campos = String(texto).split(':');
  if (campos.length < 3 || campos.length > 5) {
    throw new ErroMedicao(`--viga: use nome:face:LARGxALT[:vão[:laje]] (recebi "${texto}")`);
  }
  const [nome, face, seccao, vao, laje] = campos;
  const dim = seccao.split(/x/i);
  if (dim.length !== 2) throw new ErroMedicao(`--viga ${nome}: secção tem de ser LARGxALT (recebi "${seccao}")`);
  const viga = { nome, face, b: dim[0], h: dim[1] };
  if (vao !== undefined && vao !== '') viga.vao = vao;
  if (laje !== undefined && laje !== '') viga.laje = laje;
  return viga;
}

export function parseArgs(argv) {
  const opcoes = { vigas: [] };
  for (let i = 0; i < argv.length; i += 1) {
    const arg = argv[i];
    const valor = () => {
      const v = argv[i + 1];
      if (v === undefined) throw new ErroMedicao(`${arg}: falta o valor`);
      i += 1;
      return v;
    };
    switch (arg) {
      case '--pilar': opcoes.pilar = parsePilar(valor()); break;
      case '--viga': opcoes.vigas.push(parseViga(valor())); break;
      case '--laje': opcoes.laje = valor(); break;
      case '--convencao': opcoes.convencao = valor(); break;
      case '--sem-cofragem': opcoes.facesSemCofragem = valor().split(/[,\s]+/).filter(Boolean); break;
      case '--nome': opcoes.nome = valor(); break;
      case '--json': opcoes.json = valor(); break;
      case '--csv': opcoes.csv = true; break;
      case '--exemplo': opcoes.exemplo = true; break;
      case '-h': case '--ajuda': case '--help': opcoes.ajuda = true; break;
      default: throw new ErroMedicao(`opção desconhecida: ${arg}`);
    }
  }
  return opcoes;
}

export function main(argv) {
  let opcoes;
  try {
    opcoes = parseArgs(argv);
  } catch (erro) {
    if (!(erro instanceof ErroMedicao)) throw erro;
    process.stderr.write(`erro: ${erro.message}\n\nCorra com --ajuda.\n`);
    return 2;
  }

  if (opcoes.ajuda) {
    process.stdout.write(`${AJUDA}\n`);
    return 0;
  }

  let dados;
  if (opcoes.json) {
    try {
      dados = JSON.parse(readFileSync(opcoes.json, 'utf8'));
    } catch (erro) {
      process.stderr.write(`erro ao ler ${opcoes.json}: ${erro.message}\n`);
      return 2;
    }
  } else if (opcoes.exemplo || (!opcoes.pilar && opcoes.vigas.length === 0)) {
    dados = EXEMPLO;
    process.stdout.write('(sem dados — a correr o exemplo do caderno; veja --ajuda)\n\n');
  } else {
    dados = {
      nome: opcoes.nome,
      pilar: opcoes.pilar,
      vigas: opcoes.vigas,
      laje: opcoes.laje,
      convencao: opcoes.convencao,
      facesSemCofragem: opcoes.facesSemCofragem,
    };
  }

  try {
    const resultado = medir(dados);
    process.stdout.write(`${opcoes.csv ? csv(resultado) : relatorio(resultado)}\n`);
    return 0;
  } catch (erro) {
    if (!(erro instanceof ErroMedicao)) throw erro;
    process.stderr.write(`erro: ${erro.message}\n`);
    return 1;
  }
}

export { EXEMPLO, NOME_FACE };

const executadoDirectamente =
  process.argv[1] !== undefined && fileURLToPath(import.meta.url) === resolve(process.argv[1]);
if (executadoDirectamente) process.exit(main(process.argv.slice(2)));
