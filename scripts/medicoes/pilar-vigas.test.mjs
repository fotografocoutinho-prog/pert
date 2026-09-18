import test from 'node:test';
import assert from 'node:assert/strict';

import { medir, parseArgs, normalizarFace, ErroMedicao, EXEMPLO } from './pilar-vigas.mjs';

const perto = (obtido, esperado, mensagem) =>
  assert.ok(
    Math.abs(obtido - esperado) < 1e-9,
    `${mensagem}: esperava ${esperado}, obtive ${obtido}`,
  );

const area = (resultado, face) => resultado.pilar.faces.find((x) => x.face === face).area;

test('exemplo do caderno: betão do pilar', () => {
  const r = medir(EXEMPLO);
  perto(r.pilar.betao, 0.48, 'betão do pilar');
});

test('exemplo do caderno: cofragem face a face', () => {
  const r = medir(EXEMPLO);
  perto(area(r, 'S'), 1.080, 'face sul (V4, h=0,40)');
  perto(area(r, 'E'), 1.400, 'face nascente (V1, h=0,50)');
  perto(area(r, 'N'), 1.125, 'face norte (V2, h=0,25)');
  perto(area(r, 'O'), 1.460, 'face poente (V3, h=0,35)');
  perto(r.pilar.cofragem, 5.065, 'cofragem total do pilar');
});

test('a verificação pelo perímetro fecha com a soma das faces', () => {
  const r = medir(EXEMPLO);
  perto(r.pilar.cofragemBruta, 5.60, 'perímetro × altura');
  perto(r.pilar.descontoVigas, 0.535, 'descontos das vigas');
  perto(
    r.pilar.cofragemBruta - r.pilar.descontoVigas - r.pilar.descontoFaces,
    r.pilar.cofragem,
    'bruta − descontos',
  );
});

test('a largura da face é a dimensão perpendicular ao eixo da viga', () => {
  const r = medir(EXEMPLO);
  perto(area(r, 'E') / (4.00 - 0.50), 0.40, 'face nascente usa b');
  perto(area(r, 'N') / (4.00 - 0.25), 0.30, 'face norte usa a');
});

test('face sem viga cofra-se em toda a altura', () => {
  const r = medir({
    pilar: { a: 0.30, b: 0.40, altura: 4.00 },
    vigas: [{ nome: 'V1', face: 'E', b: 0.40, h: 0.50 }],
  });
  perto(area(r, 'O'), 0.40 * 4.00, 'face poente, sem viga');
  perto(r.pilar.cofragem, 5.60 - 0.40 * 0.50, 'só V1 desconta');
});

test('face marcada como não cofrada não conta', () => {
  const r = medir({ ...EXEMPLO, facesSemCofragem: ['poente'] });
  perto(area(r, 'O'), 0, 'face poente não cofrada');
  perto(r.pilar.cofragem, 5.065 - 1.460, 'total sem a face poente');
  perto(
    r.pilar.cofragemBruta - r.pilar.descontoVigas - r.pilar.descontoFaces,
    r.pilar.cofragem,
    'a verificação continua a fechar',
  );
});

test('viga com vão: betão e cofragem', () => {
  const r = medir({
    pilar: { a: 0.30, b: 0.40, altura: 4.00 },
    vigas: [{ nome: 'V1', face: 'E', b: 0.40, h: 0.50, vao: 4.50 }],
  });
  const v = r.vigas[0];
  perto(v.betao, 0.40 * 0.50 * 4.50, 'betão da viga, sem laje');
  perto(v.cofragem, (0.40 + 2 * 0.50) * 4.50, 'fundo + 2 faces');
  perto(r.totais.betao, r.pilar.betao + v.betao, 'total de betão');
});

test('com laje, a cofragem lateral desce à nervura', () => {
  const base = {
    pilar: { a: 0.30, b: 0.40, altura: 4.00 },
    laje: 0.20,
    vigas: [{ nome: 'V1', face: 'E', b: 0.40, h: 0.50, vao: 4.50 }],
  };
  const nervura = medir(base).vigas[0];
  perto(nervura.betao, 0.40 * 0.30 * 4.50, 'convenção nervura: h − laje');
  perto(nervura.cofragem, (0.40 + 2 * 0.30) * 4.50, 'laterais a 0,30');

  const total = medir({ ...base, convencao: 'total' }).vigas[0];
  perto(total.betao, 0.40 * 0.50 * 4.50, 'convenção total: altura toda');
  perto(total.cofragem, nervura.cofragem, 'a cofragem não depende da convenção');
});

test('viga de bordadura: um lado à vista em toda a altura', () => {
  const r = medir({
    pilar: { a: 0.30, b: 0.40, altura: 4.00 },
    laje: 0.20,
    vigas: [{ nome: 'V1', face: 'E', b: 0.40, h: 0.50, vao: 4.50, ladosComLaje: 1 }],
  });
  perto(r.vigas[0].cofragem, (0.40 + 0.30 + 0.50) * 4.50, 'um lado a 0,30 e outro a 0,50');
});

test('aceita faces por extenso e vírgula decimal', () => {
  assert.equal(normalizarFace('Nascente'), 'E');
  assert.equal(normalizarFace(' poente '), 'O');
  const r = medir({
    pilar: { a: '0,30', b: '0,40', altura: '4,00' },
    vigas: [{ nome: 'V1', face: 'este', b: '0,40', h: '0,50' }],
  });
  perto(r.pilar.betao, 0.48, 'betão com vírgula decimal');
});

test('recusa dados impossíveis', () => {
  const pilar = { a: 0.30, b: 0.40, altura: 4.00 };
  assert.throws(
    () => medir({ pilar, vigas: [{ nome: 'V1', face: 'E', b: 0.4, h: 0.5 }, { nome: 'V9', face: 'nascente', b: 0.4, h: 0.3 }] }),
    ErroMedicao,
    'duas vigas na mesma face',
  );
  assert.throws(() => medir({ pilar, vigas: [{ face: 'cima', b: 0.4, h: 0.5 }] }), ErroMedicao, 'face inválida');
  assert.throws(() => medir({ pilar, vigas: [{ face: 'E', b: 0.4, h: 4.5 }] }), ErroMedicao, 'viga mais alta que o pilar');
  assert.throws(() => medir({ pilar, laje: 0.6, vigas: [{ face: 'E', b: 0.4, h: 0.5 }] }), ErroMedicao, 'laje maior que a viga');
  assert.throws(() => medir({ pilar: { a: 0, b: 0.4, altura: 4 } }), ErroMedicao, 'secção nula');
  assert.throws(() => medir({}), ErroMedicao, 'sem pilar');
});

test('linha de comandos', () => {
  const opcoes = parseArgs([
    '--pilar', '0.30x0.40x4.00',
    '--viga', 'V1:nascente:0.40x0.50:4.50',
    '--viga', 'V2:N:0.30x0.25',
    '--laje', '0.20',
    '--sem-cofragem', 'N,O',
  ]);
  assert.deepEqual(opcoes.pilar, { a: '0.30', b: '0.40', altura: '4.00' });
  assert.equal(opcoes.vigas.length, 2);
  assert.deepEqual(opcoes.vigas[0], { nome: 'V1', face: 'nascente', b: '0.40', h: '0.50', vao: '4.50' });
  assert.equal(opcoes.vigas[1].vao, undefined);
  assert.deepEqual(opcoes.facesSemCofragem, ['N', 'O']);

  const r = medir({ pilar: opcoes.pilar, vigas: [opcoes.vigas[0]], laje: opcoes.laje });
  perto(r.vigas[0].betao, 0.40 * 0.30 * 4.50, 'medição a partir da linha de comandos');

  assert.throws(() => parseArgs(['--viga', 'V1:E:0.40']), ErroMedicao, 'secção incompleta');
  assert.throws(() => parseArgs(['--pilar']), ErroMedicao, 'valor em falta');
  assert.throws(() => parseArgs(['--xpto']), ErroMedicao, 'opção desconhecida');
});
