/**
 * Pedidos de orçamento: validação, normalização, valor estimado,
 * agregações para os gráficos e exportação para CSV.
 */
import {
  BUDGET_RANGES, AGE_RANGES, LEAD_STATUSES, MONTH_NAMES,
  validId, validIds, labelOf,
} from './catalog.js';
import { cleanText, cleanMultiline, csvCell, isEmail, leadReference, toInt, toNumber } from './util.js';

const BUDGET_MID = new Map(BUDGET_RANGES.map((b) => [b.id, b.mid]));

/** Remove acentos e maiúsculas — usado para agrupar destinos escritos de formas diferentes. */
export function foldKey(text) {
  return String(text)
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, ' ')
    .trim();
}

/** Capitaliza cada palavra do destino ("ilhas gregas" → "Ilhas Gregas"). */
function titleCase(text) {
  const small = new Set(['de', 'da', 'do', 'das', 'dos', 'e', 'no', 'na', 'em', 'a', 'o']);
  return text
    .split(/\s+/)
    .map((word, i) => {
      const lower = word.toLocaleLowerCase('pt-PT');
      if (i > 0 && small.has(lower)) return lower;
      return lower.charAt(0).toLocaleUpperCase('pt-PT') + lower.slice(1);
    })
    .join(' ');
}

/**
 * Valida e normaliza o que chega do formulário público.
 * @returns {{ok: true, lead: object} | {ok: false, errors: Record<string,string>}}
 */
export function normalizeLead(payload, { sequence, now = new Date() } = {}) {
  const errors = {};
  const p = payload && typeof payload === 'object' ? payload : {};
  const trip = p.trip || {};
  const party = p.party || {};
  const budget = p.budget || {};
  const prefs = p.prefs || {};
  const contact = p.contact || {};

  const tripType = validId('tripTypes', trip.type);
  if (!tripType) errors['trip.type'] = 'Escolha o tipo de viagem.';

  const undecided = Boolean(trip.undecided);
  const destinationRaw = cleanText(trip.destination, 120);
  if (!undecided && destinationRaw.length < 2) {
    errors['trip.destination'] = 'Indique o destino (ou escolha "ainda não sei").';
  }

  const name = cleanText(contact.name, 120);
  if (name.length < 2) errors['contact.name'] = 'Diga-nos como se chama.';

  const email = cleanText(contact.email, 160).toLowerCase();
  if (!isEmail(email)) errors['contact.email'] = 'Indique um e-mail válido.';

  const phone = cleanText(contact.phone, 40);
  const channel = validId('contactChannels', contact.channel, 'email');
  if ((channel === 'telefone' || channel === 'whatsapp') && phone.replace(/\D/g, '').length < 9) {
    errors['contact.phone'] = 'Para contacto telefónico precisamos do seu número.';
  }

  if (p.consent?.rgpd !== true) {
    errors['consent.rgpd'] = 'É necessário autorizar o tratamento dos dados.';
  }

  if (Object.keys(errors).length) return { ok: false, errors };

  const adults = toInt(party.adults, { min: 1, max: 40, fallback: 2 });
  const children = toInt(party.children, { min: 0, max: 20, fallback: 0 });
  const childrenAges = Array.isArray(party.childrenAges)
    ? party.childrenAges.slice(0, children).map((age) => toInt(age, { min: 0, max: 17, fallback: 0 }))
    : [];

  const budgetRange = validId('budgetRanges', budget.range, 'sem-limite');
  const perPersonExact = toNumber(budget.perPerson, { min: 0, max: 200_000, fallback: null });

  const iso = now.toISOString();
  const year = now.getUTCFullYear();

  const lead = {
    id: leadReference(year, sequence),
    createdAt: iso,
    updatedAt: iso,
    status: 'novo',
    trip: {
      type: tripType,
      destination: undecided ? '' : titleCase(destinationRaw),
      destinationKey: undecided ? 'indeciso' : foldKey(destinationRaw),
      undecided,
      startDate: cleanDate(trip.startDate),
      endDate: cleanDate(trip.endDate),
      month: cleanMonth(trip.month),
      flexible: Boolean(trip.flexible),
      nights: toInt(trip.nights, { min: 0, max: 120, fallback: 0 }),
    },
    party: {
      adults,
      children,
      childrenAges,
      travelers: adults + children,
      ageRange: validId('ageRanges', party.ageRange, ''),
    },
    budget: {
      range: budgetRange,
      perPerson: perPersonExact,
      includes: validIds('includes', budget.includes),
      currency: 'EUR',
    },
    prefs: {
      hotelCategory: validId('hotelCategories', prefs.hotelCategory, 'indiferente'),
      board: validId('boards', prefs.board, ''),
      pace: validId('paces', prefs.pace, ''),
      interests: validIds('interests', prefs.interests),
      notes: cleanMultiline(prefs.notes, 2000),
    },
    contact: {
      name,
      email,
      phone,
      channel,
      bestTime: validId('bestTimes', contact.bestTime, 'qualquer'),
      source: validId('sources', contact.source, ''),
    },
    consent: {
      rgpd: true,
      marketing: p.consent?.marketing === true,
      at: iso,
    },
    internal: {
      notes: '',
      quotedValue: null,
      owner: '',
      history: [{ at: iso, status: 'novo', note: 'Pedido recebido pelo site.' }],
    },
  };

  lead.estimatedValue = estimateValue(lead);
  return { ok: true, lead };
}

function cleanDate(value) {
  const text = cleanText(value, 10);
  return /^\d{4}-\d{2}-\d{2}$/.test(text) ? text : '';
}

function cleanMonth(value) {
  const text = cleanText(value, 7);
  return /^\d{4}-\d{2}$/.test(text) ? text : '';
}

/** Valor estimado do pedido: orçamento por pessoa × nº de viajantes. */
export function estimateValue(lead) {
  const perPerson = lead.budget.perPerson ?? BUDGET_MID.get(lead.budget.range) ?? null;
  if (perPerson == null) return null;
  return Math.round(perPerson * Math.max(1, lead.party.travelers));
}

/** Valor a usar nas contas: o orçamento fechado, se existir; senão o estimado. */
export function leadValue(lead) {
  const quoted = lead.internal?.quotedValue;
  if (typeof quoted === 'number' && quoted > 0) return quoted;
  return lead.estimatedValue ?? null;
}

/** Mês/data de partida preferida — para o gráfico de sazonalidade. */
function departureMonth(lead) {
  if (lead.trip.startDate) return lead.trip.startDate.slice(0, 7);
  if (lead.trip.month) return lead.trip.month;
  return '';
}

/* ─────────────────────────  Agregações para os gráficos  ───────────────────────── */

function bucketKey(date, granularity) {
  const d = new Date(date);
  if (granularity === 'day') return d.toISOString().slice(0, 10);
  if (granularity === 'week') {
    const copy = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate()));
    const day = (copy.getUTCDay() + 6) % 7;             // segunda-feira = 0
    copy.setUTCDate(copy.getUTCDate() - day);
    return copy.toISOString().slice(0, 10);
  }
  return d.toISOString().slice(0, 7);
}

function bucketLabel(key, granularity) {
  if (granularity === 'month') {
    const [year, month] = key.split('-');
    return `${MONTH_NAMES[Number(month) - 1]} ${year.slice(2)}`;
  }
  const [year, month, day] = key.split('-');
  const base = `${Number(day)} ${MONTH_NAMES[Number(month) - 1]}`;
  return granularity === 'week' ? `${base}` : base;
}

function advance(date, granularity) {
  const d = new Date(date);
  if (granularity === 'day') d.setUTCDate(d.getUTCDate() + 1);
  else if (granularity === 'week') d.setUTCDate(d.getUTCDate() + 7);
  else d.setUTCMonth(d.getUTCMonth() + 1);
  return d;
}

/** Série temporal contínua (sem buracos) entre duas datas. */
function buildTrend(leads, from, to) {
  const spanDays = Math.max(1, Math.round((to - from) / 86_400_000));
  const granularity = spanDays <= 31 ? 'day' : spanDays <= 130 ? 'week' : 'month';

  const buckets = new Map();
  let cursor = new Date(bucketKey(from, granularity) + (granularity === 'month' ? '-01' : '') + 'T00:00:00Z');
  const limit = new Date(to);
  let guard = 0;
  while (cursor <= limit && guard++ < 800) {
    const key = bucketKey(cursor, granularity);
    buckets.set(key, { key, label: bucketLabel(key, granularity), leads: 0, value: 0 });
    cursor = advance(cursor, granularity);
  }

  for (const lead of leads) {
    const key = bucketKey(lead.createdAt, granularity);
    const bucket = buckets.get(key);
    if (!bucket) continue;
    bucket.leads += 1;
    const value = leadValue(lead);
    if (value) bucket.value += value;
  }

  return { granularity, points: [...buckets.values()] };
}

function countBy(leads, keyFn) {
  const map = new Map();
  for (const lead of leads) {
    const key = keyFn(lead);
    if (!key) continue;
    map.set(key, (map.get(key) || 0) + 1);
  }
  return map;
}

/** Top N destinos; o resto colapsa em "Outros" (nunca gerar cores novas). */
function topDestinations(leads, limit = 8) {
  const groups = new Map();
  for (const lead of leads) {
    const key = lead.trip.undecided ? 'indeciso' : lead.trip.destinationKey;
    if (!key) continue;
    const label = lead.trip.undecided ? 'Ainda sem destino' : lead.trip.destination;
    const entry = groups.get(key) || { key, label, value: 0, amount: 0 };
    entry.value += 1;
    const value = leadValue(lead);
    if (value) entry.amount += value;
    groups.set(key, entry);
  }
  const sorted = [...groups.values()].sort((a, b) => b.value - a.value || a.label.localeCompare(b.label, 'pt'));
  const top = sorted.slice(0, limit);
  const rest = sorted.slice(limit);
  if (rest.length) {
    top.push({
      key: 'outros',
      label: `Outros (${rest.length})`,
      value: rest.reduce((sum, item) => sum + item.value, 0),
      amount: rest.reduce((sum, item) => sum + item.amount, 0),
    });
  }
  return top;
}

/**
 * Todas as métricas do dashboard, já filtradas.
 * @param {object[]} leads  pedidos (já filtrados por período/tipo)
 * @param {{from: Date, to: Date}} range
 */
export function computeStats(leads, range) {
  const values = leads.map(leadValue).filter((v) => typeof v === 'number' && v > 0);
  const totalValue = values.reduce((sum, v) => sum + v, 0);
  const travelers = leads.reduce((sum, l) => sum + l.party.travelers, 0);
  const nights = leads.map((l) => l.trip.nights).filter((n) => n > 0);

  const statusCounts = countBy(leads, (l) => l.status);
  const won = leads.filter((l) => l.status === 'ganho');
  const wonValue = won.map(leadValue).filter(Boolean).reduce((sum, v) => sum + v, 0);
  const closed = leads.filter((l) => l.status === 'ganho' || l.status === 'perdido').length;
  const openValue = leads
    .filter((l) => l.status !== 'ganho' && l.status !== 'perdido')
    .map(leadValue)
    .filter(Boolean)
    .reduce((sum, v) => sum + v, 0);

  const perPersonValues = leads
    .map((l) => {
      const value = leadValue(l);
      return value ? value / Math.max(1, l.party.travelers) : null;
    })
    .filter(Boolean);

  const ages = AGE_RANGES.map((range_) => ({
    id: range_.id,
    label: range_.label,
    value: leads.filter((l) => l.party.ageRange === range_.id).length,
  }));

  const tripTypeCounts = countBy(leads, (l) => l.trip.type);
  const tripTypes = [...tripTypeCounts.entries()]
    .map(([id, value]) => ({ id, label: labelOf('tripTypes', id), value }))
    .sort((a, b) => b.value - a.value);

  const budgets = BUDGET_RANGES.map((b) => ({
    id: b.id,
    label: b.label,
    value: leads.filter((l) => l.budget.range === b.id).length,
  })).filter((b) => b.value > 0 || b.id !== 'sem-limite');

  const seasonCounts = new Map(MONTH_NAMES.map((_, index) => [index, 0]));
  for (const lead of leads) {
    const month = departureMonth(lead);
    if (!month) continue;
    const index = Number(month.slice(5, 7)) - 1;
    if (seasonCounts.has(index)) seasonCounts.set(index, seasonCounts.get(index) + 1);
  }

  const sourceCounts = countBy(leads, (l) => l.contact.source);

  return {
    range: { from: range.from.toISOString(), to: range.to.toISOString() },
    totals: {
      leads: leads.length,
      travelers,
      totalValue,
      wonValue,
      openValue,
      won: won.length,
      closed,
      conversionRate: closed ? won.length / closed : null,
      byStatus: Object.fromEntries(LEAD_STATUSES.map((s) => [s.id, statusCounts.get(s.id) || 0])),
    },
    kpis: {
      avgPerLead: values.length ? Math.round(totalValue / values.length) : null,
      avgPerPerson: perPersonValues.length
        ? Math.round(perPersonValues.reduce((sum, v) => sum + v, 0) / perPersonValues.length)
        : null,
      avgTravelers: leads.length ? Number((travelers / leads.length).toFixed(1)) : null,
      avgNights: nights.length ? Math.round(nights.reduce((sum, n) => sum + n, 0) / nights.length) : null,
    },
    destinations: topDestinations(leads),
    trend: buildTrend(leads, range.from, range.to),
    ages,
    tripTypes,
    budgets,
    season: MONTH_NAMES.map((label, index) => ({ id: label, label, value: seasonCounts.get(index) || 0 })),
    pipeline: LEAD_STATUSES.map((s) => ({ id: s.id, label: s.label, value: statusCounts.get(s.id) || 0 })),
    sources: [...sourceCounts.entries()]
      .map(([id, value]) => ({ id, label: labelOf('sources', id), value }))
      .sort((a, b) => b.value - a.value),
  };
}

/* ─────────────────────────────────  Exportação  ───────────────────────────────── */

const CSV_COLUMNS = [
  ['Referência', (l) => l.id],
  ['Data do pedido', (l) => new Date(l.createdAt).toLocaleString('pt-PT')],
  ['Estado', (l) => labelOf('statuses', l.status)],
  ['Nome', (l) => l.contact.name],
  ['E-mail', (l) => l.contact.email],
  ['Telefone', (l) => l.contact.phone],
  ['Canal preferido', (l) => labelOf('contactChannels', l.contact.channel)],
  ['Melhor horário', (l) => labelOf('bestTimes', l.contact.bestTime)],
  ['Como nos conheceu', (l) => labelOf('sources', l.contact.source)],
  ['Tipo de viagem', (l) => labelOf('tripTypes', l.trip.type)],
  ['Destino', (l) => (l.trip.undecided ? 'Ainda sem destino' : l.trip.destination)],
  ['Data de ida', (l) => l.trip.startDate],
  ['Data de volta', (l) => l.trip.endDate],
  ['Mês aproximado', (l) => l.trip.month],
  ['Datas flexíveis', (l) => (l.trip.flexible ? 'Sim' : 'Não')],
  ['Noites', (l) => l.trip.nights || ''],
  ['Adultos', (l) => l.party.adults],
  ['Crianças', (l) => l.party.children],
  ['Idades das crianças', (l) => l.party.childrenAges.join(' / ')],
  ['Faixa etária', (l) => labelOf('ageRanges', l.party.ageRange, '')],
  ['Orçamento por pessoa', (l) => labelOf('budgetRanges', l.budget.range, '')],
  ['Orçamento indicado (€)', (l) => l.budget.perPerson ?? ''],
  ['Valor estimado (€)', (l) => l.estimatedValue ?? ''],
  ['Valor orçamentado (€)', (l) => l.internal?.quotedValue ?? ''],
  ['Inclui', (l) => l.budget.includes.map((id) => labelOf('includes', id)).join(' / ')],
  ['Categoria de hotel', (l) => labelOf('hotelCategories', l.prefs.hotelCategory, '')],
  ['Regime', (l) => labelOf('boards', l.prefs.board, '')],
  ['Ritmo', (l) => labelOf('paces', l.prefs.pace, '')],
  ['Interesses', (l) => l.prefs.interests.map((id) => labelOf('interests', id)).join(' / ')],
  ['Notas do cliente', (l) => l.prefs.notes],
  ['Notas internas', (l) => l.internal?.notes || ''],
  ['Marketing autorizado', (l) => (l.consent.marketing ? 'Sim' : 'Não')],
];

/** CSV com ponto e vírgula e BOM — abre direito no Excel português. */
export function leadsToCsv(leads) {
  const header = CSV_COLUMNS.map(([title]) => csvCell(title)).join(';');
  const rows = leads.map((lead) => CSV_COLUMNS.map(([, get]) => csvCell(get(lead))).join(';'));
  return `﻿${[header, ...rows].join('\r\n')}\r\n`;
}
