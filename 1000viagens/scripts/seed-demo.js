/**
 * Dados de demonstração.
 *
 *   node scripts/seed-demo.js            → cria ~140 pedidos fictícios (se não houver nenhum)
 *   node scripts/seed-demo.js --reset    → apaga tudo e volta a gerar
 *   node scripts/seed-demo.js --count=60 → escolhe quantos
 *   node scripts/seed-demo.js --clear    → apaga todos os pedidos
 *
 * Serve para ver o backoffice com vida antes de o site ter clientes reais.
 * Não usar em produção depois de começarem a entrar pedidos verdadeiros.
 */
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { JsonStore } from '../server/store.js';
import { normalizeLead } from '../server/leads.js';
import { TRIP_TYPES, BUDGET_RANGES, AGE_RANGES, INTERESTS, INCLUDES, SOURCES, BOARDS, HOTEL_CATEGORIES } from '../server/catalog.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');
const DATA_DIR = path.resolve(ROOT, process.env.DATA_DIR || 'data');

const args = process.argv.slice(2);
const has = (flag) => args.includes(flag);
const count = Number((args.find((a) => a.startsWith('--count=')) || '--count=140').split('=')[1]);

const pick = (list) => list[Math.floor(Math.random() * list.length)];
const pickWeighted = (pairs) => {
  const total = pairs.reduce((sum, [, weight]) => sum + weight, 0);
  let roll = Math.random() * total;
  for (const [value, weight] of pairs) {
    roll -= weight;
    if (roll <= 0) return value;
  }
  return pairs[0][0];
};
const between = (min, max) => Math.floor(Math.random() * (max - min + 1)) + min;
const some = (list, max) => {
  const copy = [...list].sort(() => Math.random() - 0.5);
  return copy.slice(0, between(0, max)).map((item) => item.id);
};

/**
 * Destinos por tipo de viagem — assim os dados de demonstração fazem sentido
 * (ninguém pede "Neve & Ski" nas Maldivas).
 */
const DESTINATIONS_BY_TYPE = {
  praia: [['Maldivas', 6], ['Cancún', 6], ['Punta Cana', 6], ['Cabo Verde', 5], ['Algarve', 5], ['Zanzibar', 4], ['Seychelles', 3], ['Madeira', 3]],
  citybreak: [['Nova Iorque', 5], ['Roma', 5], ['Paris', 5], ['Londres', 4], ['Amesterdão', 3], ['Praga', 3], ['Dubai', 3]],
  cruzeiro: [['Cruzeiro no Mediterrâneo', 6], ['Cruzeiro nas Caraíbas', 4], ['Cruzeiro no Douro', 2]],
  familia: [['Disneyland Paris', 5], ['Algarve', 4], ['Madeira', 3], ['Cancún', 3], ['Orlando', 3], ['Cabo Verde', 2]],
  luademel: [['Maldivas', 6], ['Bali', 5], ['Seychelles', 4], ['Maurícia', 3], ['Santorini', 3], ['Tailândia', 2]],
  natureza: [['Islândia', 4], ['Açores', 4], ['Costa Rica', 3], ['Noruega', 3], ['Madeira', 3], ['Peru', 2]],
  safari: [['Quénia', 4], ['África do Sul', 4], ['Tanzânia', 2], ['Zanzibar', 2]],
  neve: [['Andorra', 4], ['Alpes Franceses', 3], ['Lapónia', 4], ['Serra Nevada', 2]],
  circuito: [['Japão', 4], ['Tailândia', 3], ['Egito', 4], ['Vietname', 2], ['Turquia', 3], ['Croácia', 2]],
  negocios: [['Madrid', 3], ['Londres', 3], ['Frankfurt', 2], ['Dubai', 2]],
};

const NAMES = [
  'Ana Ferreira', 'João Marques', 'Sofia Nogueira', 'Miguel Antunes', 'Rita Carvalho',
  'Pedro Sousa', 'Inês Barbosa', 'Tiago Lopes', 'Carla Ribeiro', 'Nuno Faria',
  'Beatriz Cunha', 'Ricardo Matos', 'Mariana Pinto', 'André Teixeira', 'Helena Braga',
  'Bruno Correia', 'Catarina Melo', 'Rui Guerreiro', 'Patrícia Amaral', 'Vasco Rocha',
  'Sara Domingues', 'Filipe Neves', 'Luísa Gonçalves', 'Hugo Pereira', 'Marta Simões',
  'Diogo Vieira', 'Cláudia Reis', 'Paulo Fonseca', 'Susana Moreira', 'Gonçalo Dias',
];

const NOTES = [
  'Aniversário de casamento — gostávamos de algo especial.',
  'Viajamos com uma criança pequena, precisamos de berço.',
  'Preferimos voo direto, mesmo que custe mais.',
  'Já fomos convosco a Cabo Verde e correu muito bem.',
  'Temos alguma flexibilidade nas datas se houver melhor preço.',
  'Precisamos de quarto acessível (mobilidade reduzida).',
  'Queremos incluir uma excursão de um dia inteiro.',
  '',
  '',
  '',
];

const STATUS_FLOW = [
  ['novo', 30], ['contactado', 22], ['orcamento_enviado', 22], ['ganho', 16], ['perdido', 10],
];

async function main() {
  const store = new JsonStore(path.join(DATA_DIR, 'leads.json'), { sequence: 0, items: [] });
  await store.load();

  if (has('--clear')) {
    await store.update((data) => { data.items = []; data.sequence = 0; });
    console.log('✓ Todos os pedidos foram apagados.');
    return;
  }

  const existing = (await store.read()).items;
  if (existing.length && !has('--reset')) {
    console.log(`Já existem ${existing.length} pedidos. Use --reset para substituir ou --clear para apagar.`);
    return;
  }

  const leads = [];
  const now = Date.now();

  for (let i = 0; i < count; i += 1) {
    // Mais pedidos nos meses recentes e nas épocas de reserva (jan–mar, set–out)
    const daysAgo = Math.floor(Math.random() ** 1.5 * 420);
    const createdAt = new Date(now - daysAgo * 86_400_000 - between(0, 20) * 3_600_000);

    const type = pickWeighted([
      ['praia', 10], ['citybreak', 7], ['familia', 6], ['cruzeiro', 5], ['luademel', 4],
      ['natureza', 4], ['circuito', 3], ['neve', 2], ['safari', 2], ['negocios', 1],
    ].filter(([id]) => TRIP_TYPES.some((t) => t.id === id)));

    const destination = pickWeighted(DESTINATIONS_BY_TYPE[type] || DESTINATIONS_BY_TYPE.praia);
    const undecided = Math.random() < 0.07;
    const adults = pickWeighted([[2, 10], [1, 3], [3, 2], [4, 3], [6, 1]]);
    const children = Math.random() < 0.32 ? between(1, 3) : 0;

    const departure = new Date(createdAt.getTime() + between(25, 300) * 86_400_000);
    const nights = pickWeighted([[7, 8], [5, 4], [10, 4], [3, 3], [15, 2]]);
    const name = pick(NAMES);
    const first = name.split(' ')[0].toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');

    const payload = {
      trip: {
        type,
        destination: undecided ? '' : destination,
        undecided,
        startDate: Math.random() < 0.65 ? departure.toISOString().slice(0, 10) : '',
        endDate: '',
        month: departure.toISOString().slice(0, 7),
        flexible: Math.random() < 0.7,
        nights,
      },
      party: {
        adults, children,
        childrenAges: Array.from({ length: children }, () => between(1, 15)),
        ageRange: pickWeighted([['36-50', 10], ['26-35', 8], ['51-65', 6], ['18-25', 3], ['65-mais', 2]]
          .filter(([id]) => AGE_RANGES.some((a) => a.id === id))),
      },
      budget: {
        range: pickWeighted([['1500-2500', 9], ['1000-1500', 8], ['2500-4000', 6], ['500-1000', 5], ['mais-4000', 3], ['ate-500', 2], ['sem-limite', 2]]
          .filter(([id]) => BUDGET_RANGES.some((b) => b.id === id))),
        includes: some(INCLUDES, 4),
      },
      prefs: {
        hotelCategory: pick(HOTEL_CATEGORIES).id,
        board: pick(BOARDS).id,
        pace: pick([{ id: 'tranquilo' }, { id: 'equilibrado' }, { id: 'intenso' }]).id,
        interests: some(INTERESTS, 4),
        notes: pick(NOTES),
      },
      contact: {
        name,
        email: `${first}${between(1, 99)}@exemplo.pt`,
        phone: `9${pick(['1', '2', '3', '6'])}${between(1000000, 9999999)}`,
        channel: pickWeighted([['email', 6], ['whatsapp', 3], ['telefone', 2]]),
        bestTime: pick([{ id: 'manha' }, { id: 'tarde' }, { id: 'noite' }, { id: 'qualquer' }]).id,
        source: pick(SOURCES).id,
      },
      consent: { rgpd: true, marketing: Math.random() < 0.55 },
    };

    const result = normalizeLead(payload, { sequence: i + 1, now: createdAt });
    if (!result.ok) continue;
    const lead = result.lead;

    // Estado: pedidos antigos já foram trabalhados; os recentes ainda estão novos
    lead.status = daysAgo < 6 ? 'novo' : pickWeighted(STATUS_FLOW);
    if (lead.status === 'ganho') {
      lead.internal.quotedValue = Math.round((lead.estimatedValue || 2000) * (0.85 + Math.random() * 0.4));
      lead.internal.notes = 'Reserva confirmada com o operador.';
    }
    if (lead.status !== 'novo') {
      lead.internal.history.push({
        at: new Date(createdAt.getTime() + 86_400_000).toISOString(),
        status: lead.status,
        note: 'Atualizado na demonstração.',
      });
    }
    leads.push(lead);
  }

  leads.sort((a, b) => new Date(a.createdAt) - new Date(b.createdAt));
  leads.forEach((lead, index) => {
    const year = new Date(lead.createdAt).getUTCFullYear();
    lead.id = `1V-${year}-${String(index + 1).padStart(4, '0')}`;
  });

  await store.update((data) => {
    data.items = leads;
    data.sequence = leads.length;
  });

  const won = leads.filter((l) => l.status === 'ganho').length;
  console.log(`✓ ${leads.length} pedidos de demonstração criados (${won} ganhos).`);
  console.log('  Abra o backoffice para ver os gráficos com dados.');
  console.log('  Para voltar a começar do zero: node scripts/seed-demo.js --clear');
}

main().catch((err) => {
  console.error('Falhou a criação dos dados de demonstração:', err);
  process.exit(1);
});
