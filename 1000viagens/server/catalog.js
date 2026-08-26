/**
 * Catálogo de opções do formulário e do backoffice.
 *
 * É a única fonte de verdade: o servidor valida contra estas listas e o site
 * público desenha o formulário a partir delas (GET /api/public/config).
 */

export const TRIP_TYPES = [
  { id: 'praia', label: 'Praia & Resort', icon: 'sun', hint: 'Sol, mar e descanso' },
  { id: 'citybreak', label: 'City break', icon: 'city', hint: 'Escapadinha urbana' },
  { id: 'cruzeiro', label: 'Cruzeiro', icon: 'ship', hint: 'Vários destinos, uma mala' },
  { id: 'natureza', label: 'Natureza & Aventura', icon: 'mountain', hint: 'Trilhos e paisagens' },
  { id: 'safari', label: 'Safari & Exótico', icon: 'safari', hint: 'Vida selvagem' },
  { id: 'luademel', label: 'Lua de mel', icon: 'heart', hint: 'A viagem de uma vida' },
  { id: 'familia', label: 'Família', icon: 'family', hint: 'A pensar nos miúdos' },
  { id: 'neve', label: 'Neve & Ski', icon: 'snow', hint: 'Montanha no inverno' },
  { id: 'circuito', label: 'Circuito organizado', icon: 'route', hint: 'Guia e roteiro incluídos' },
  { id: 'negocios', label: 'Negócios & Grupos', icon: 'briefcase', hint: 'Empresas e eventos' },
];

export const BUDGET_RANGES = [
  { id: 'ate-500', label: 'Até 500 €', min: 0, max: 500, mid: 400 },
  { id: '500-1000', label: '500 € – 1.000 €', min: 500, max: 1000, mid: 750 },
  { id: '1000-1500', label: '1.000 € – 1.500 €', min: 1000, max: 1500, mid: 1250 },
  { id: '1500-2500', label: '1.500 € – 2.500 €', min: 1500, max: 2500, mid: 2000 },
  { id: '2500-4000', label: '2.500 € – 4.000 €', min: 2500, max: 4000, mid: 3250 },
  { id: 'mais-4000', label: 'Mais de 4.000 €', min: 4000, max: 8000, mid: 5500 },
  { id: 'sem-limite', label: 'Sem limite definido', min: null, max: null, mid: null },
];

export const AGE_RANGES = [
  { id: '18-25', label: '18 – 25 anos', order: 1 },
  { id: '26-35', label: '26 – 35 anos', order: 2 },
  { id: '36-50', label: '36 – 50 anos', order: 3 },
  { id: '51-65', label: '51 – 65 anos', order: 4 },
  { id: '65-mais', label: 'Mais de 65 anos', order: 5 },
];

export const HOTEL_CATEGORIES = [
  { id: '3', label: '3 estrelas' },
  { id: '4', label: '4 estrelas' },
  { id: '5', label: '5 estrelas' },
  { id: 'boutique', label: 'Boutique / charme' },
  { id: 'indiferente', label: 'Indiferente — sugiram' },
];

export const BOARDS = [
  { id: 'so-alojamento', label: 'Só alojamento' },
  { id: 'pequeno-almoco', label: 'Pequeno-almoço' },
  { id: 'meia-pensao', label: 'Meia pensão' },
  { id: 'pensao-completa', label: 'Pensão completa' },
  { id: 'tudo-incluido', label: 'Tudo incluído' },
];

export const INCLUDES = [
  { id: 'voos', label: 'Voos' },
  { id: 'hotel', label: 'Alojamento' },
  { id: 'transferes', label: 'Transferes' },
  { id: 'seguro', label: 'Seguro de viagem' },
  { id: 'atividades', label: 'Atividades e visitas' },
  { id: 'carro', label: 'Aluguer de automóvel' },
  { id: 'bagagem', label: 'Bagagem de porão' },
];

export const INTERESTS = [
  { id: 'gastronomia', label: 'Gastronomia' },
  { id: 'cultura', label: 'Cultura & história' },
  { id: 'praia', label: 'Praia & relaxamento' },
  { id: 'aventura', label: 'Aventura' },
  { id: 'natureza', label: 'Natureza' },
  { id: 'compras', label: 'Compras' },
  { id: 'bemestar', label: 'Bem-estar & spa' },
  { id: 'vidanoturna', label: 'Vida noturna' },
  { id: 'fotografia', label: 'Fotografia' },
];

export const PACES = [
  { id: 'tranquilo', label: 'Tranquilo — descansar' },
  { id: 'equilibrado', label: 'Equilibrado' },
  { id: 'intenso', label: 'Intenso — ver tudo' },
];

export const CONTACT_CHANNELS = [
  { id: 'email', label: 'E-mail' },
  { id: 'telefone', label: 'Telefone' },
  { id: 'whatsapp', label: 'WhatsApp' },
];

export const BEST_TIMES = [
  { id: 'manha', label: 'Manhã (9h – 13h)' },
  { id: 'tarde', label: 'Tarde (13h – 19h)' },
  { id: 'noite', label: 'Fim do dia (19h – 21h)' },
  { id: 'qualquer', label: 'Qualquer hora' },
];

export const SOURCES = [
  { id: 'google', label: 'Pesquisa no Google' },
  { id: 'instagram', label: 'Instagram' },
  { id: 'facebook', label: 'Facebook' },
  { id: 'amigo', label: 'Recomendação de amigo' },
  { id: 'cliente', label: 'Já sou cliente' },
  { id: 'loja', label: 'Passei na loja' },
  { id: 'outro', label: 'Outro' },
];

/** Estados do funil comercial (ordenados — alimentam o gráfico do pipeline). */
export const LEAD_STATUSES = [
  { id: 'novo', label: 'Novo', order: 1, tone: 'info' },
  { id: 'contactado', label: 'Contactado', order: 2, tone: 'info' },
  { id: 'orcamento_enviado', label: 'Orçamento enviado', order: 3, tone: 'warning' },
  { id: 'ganho', label: 'Ganho', order: 4, tone: 'good' },
  { id: 'perdido', label: 'Perdido', order: 5, tone: 'critical' },
];

export const MONTH_NAMES = [
  'jan', 'fev', 'mar', 'abr', 'mai', 'jun',
  'jul', 'ago', 'set', 'out', 'nov', 'dez',
];

/** Destinos sugeridos no campo com preenchimento automático. */
export const POPULAR_DESTINATIONS = [
  'Maldivas', 'Bali', 'Tailândia', 'Japão', 'Nova Iorque', 'Cancún', 'Punta Cana',
  'Zanzibar', 'Cabo Verde', 'Madeira', 'Açores', 'Algarve', 'Ilhas Gregas',
  'Santorini', 'Roma', 'Paris', 'Londres', 'Amesterdão', 'Praga', 'Marraquexe',
  'Dubai', 'Egito', 'Islândia', 'Noruega', 'Lapónia', 'Brasil', 'Peru', 'Costa Rica',
  'África do Sul', 'Quénia', 'Seychelles', 'Maurícia', 'Turquia', 'Croácia', 'Malta',
  'Cuba', 'México', 'Canadá', 'Austrália', 'Vietname', 'Camboja', 'Índia', 'Cruzeiro no Mediterrâneo',
  'Cruzeiro nas Caraíbas', 'Cruzeiro no Douro', 'Disneyland Paris', 'Orlando',
];

const byId = (list) => new Map(list.map((item) => [item.id, item]));

export const CATALOG_INDEX = {
  tripTypes: byId(TRIP_TYPES),
  budgetRanges: byId(BUDGET_RANGES),
  ageRanges: byId(AGE_RANGES),
  hotelCategories: byId(HOTEL_CATEGORIES),
  boards: byId(BOARDS),
  includes: byId(INCLUDES),
  interests: byId(INTERESTS),
  paces: byId(PACES),
  contactChannels: byId(CONTACT_CHANNELS),
  bestTimes: byId(BEST_TIMES),
  sources: byId(SOURCES),
  statuses: byId(LEAD_STATUSES),
};

/** Devolve o id se existir na lista; caso contrário devolve o fallback. */
export function validId(kind, value, fallback = '') {
  const index = CATALOG_INDEX[kind];
  if (!index) return fallback;
  return index.has(value) ? value : fallback;
}

/** Filtra um array de ids, mantendo apenas os válidos e sem repetições. */
export function validIds(kind, values, max = 20) {
  if (!Array.isArray(values)) return [];
  const index = CATALOG_INDEX[kind];
  const seen = new Set();
  const out = [];
  for (const value of values) {
    if (index.has(value) && !seen.has(value)) {
      seen.add(value);
      out.push(value);
      if (out.length >= max) break;
    }
  }
  return out;
}

/** Etiqueta legível para um id do catálogo. */
export function labelOf(kind, id, fallback = '—') {
  return CATALOG_INDEX[kind]?.get(id)?.label ?? fallback;
}

/** O catálogo completo enviado ao browser. */
export const PUBLIC_CATALOG = {
  tripTypes: TRIP_TYPES,
  budgetRanges: BUDGET_RANGES,
  ageRanges: AGE_RANGES,
  hotelCategories: HOTEL_CATEGORIES,
  boards: BOARDS,
  includes: INCLUDES,
  interests: INTERESTS,
  paces: PACES,
  contactChannels: CONTACT_CHANNELS,
  bestTimes: BEST_TIMES,
  sources: SOURCES,
  statuses: LEAD_STATUSES,
  popularDestinations: POPULAR_DESTINATIONS,
};
