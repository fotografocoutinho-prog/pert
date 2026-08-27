/**
 * Definições do site — tudo o que o backoffice consegue editar.
 *
 * DEFAULT_SETTINGS funciona como conteúdo de arranque: o site fica bonito e
 * completo mesmo antes de a agência preencher seja o que for.
 */

export const DEFAULT_SETTINGS = {
  brand: {
    name: '1000viagens',
    siteUrl: 'https://www.1000viagens.pt',   // usado no canonical, Open Graph e sitemap
    tagline: 'Travel Booking · mil destinos, uma viagem só sua',
    logoUrl: '',            // preenchido pelo upload no backoffice
    logoDarkUrl: '',
    faviconUrl: '',
    primaryColor: '#0E4F6B',
    accentColor: '#F07A3C',
    sandColor: '#F7F1E7',
  },

  company: {
    legalName: '1000viagens — Travel Booking, Lda.',
    nif: '',
    rnavt: '',
    address: '',
    postalCode: '',
    city: '',
    country: 'Portugal',
    phone: '',
    whatsapp: '',
    email: 'geral@1000viagens.pt',
    hours: 'Segunda a sexta, 9h30 – 19h00 · Sábado, 10h00 – 13h00',
    mapsUrl: '',
    socials: {
      instagram: '',
      facebook: '',
      tiktok: '',
      linkedin: '',
      youtube: '',
    },
  },

  content: {
    hero: {
      eyebrow: 'Travel Booking · Portugal',
      title: 'A sua próxima viagem começa com cinco perguntas',
      subtitle:
        'Diga-nos para onde sonha ir, quando e com que orçamento. Em menos de 24 horas úteis recebe uma proposta feita à mão por quem conhece o destino.',
      ctaPrimary: 'Pedir orçamento gratuito',
      ctaSecondary: 'Ver destinos',
      imageUrl: '',
      stats: [
        { value: '1.000+', label: 'viagens organizadas' },
        { value: '24 h', label: 'resposta ao pedido' },
        { value: '4,9/5', label: 'avaliação dos clientes' },
      ],
    },

    highlights: [
      {
        icon: 'compass',
        title: 'Proposta feita à medida',
        text: 'Nada de pacotes automáticos: cada itinerário é montado a partir das suas respostas, com alternativas para comparar.',
      },
      {
        icon: 'wallet',
        title: 'O melhor preço, transparente',
        text: 'Comparamos operadores, companhias e hotéis. Mostramos sempre o que está — e o que não está — incluído.',
      },
      {
        icon: 'shield',
        title: 'Apoio antes, durante e depois',
        text: 'Agência licenciada, com seguro e assistência. Se algo correr mal em viagem, fala com uma pessoa, não com um robô.',
      },
    ],

    destinationsTitle: 'Destinos que estão a encher malas',
    destinationsSubtitle:
      'Uma seleção da equipa para inspirar o seu pedido. Não encontra o que procura? Escreva-nos o destino — organizamos viagens para todo o mundo.',

    destinations: [
      { name: 'Maldivas', region: 'Oceano Índico', from: '1.890', nights: '7 noites · tudo incluído', art: 'tropical', tag: 'Lua de mel', imageUrl: '' },
      { name: 'Ilhas Gregas', region: 'Grécia', from: '740', nights: '7 noites · voo + hotel', art: 'mediterranean', tag: 'Verão', imageUrl: '' },
      { name: 'Lapónia', region: 'Finlândia', from: '1.250', nights: '4 noites · aurora boreal', art: 'nordic', tag: 'Inverno', imageUrl: '' },
      { name: 'Marraquexe', region: 'Marrocos', from: '390', nights: '4 noites · riad no centro', art: 'desert', tag: 'Escapadinha', imageUrl: '' },
      { name: 'Cruzeiro no Mediterrâneo', region: 'Itália · Espanha · França', from: '690', nights: '7 noites · pensão completa', art: 'cruise', tag: 'Família', imageUrl: '' },
      { name: 'Nova Iorque', region: 'Estados Unidos', from: '980', nights: '5 noites · voo + hotel', art: 'city', tag: 'City break', imageUrl: '' },
    ],

    stepsTitle: 'Como funciona',
    steps: [
      { title: 'Responde ao questionário', text: 'Três minutos. Perguntas simples sobre destino, datas, viajantes e orçamento.' },
      { title: 'Estudamos as melhores opções', text: 'Um consultor dedicado compara operadores e prepara duas ou três propostas.' },
      { title: 'Recebe o orçamento', text: 'Por e-mail, telefone ou WhatsApp — como preferir. Sem compromisso e sem custos.' },
      { title: 'Viaja descansado', text: 'Tratamos de reservas, documentos e assistência. Está sempre acompanhado.' },
    ],

    testimonialsTitle: 'O que dizem os nossos clientes',
    testimonials: [
      { name: 'Sofia & Miguel', trip: 'Lua de mel nas Maldivas', text: 'Explicámos o que queríamos e receberam-nos com três propostas no dia seguinte. A viagem correu sem um único problema.', rating: 5 },
      { name: 'Família Nogueira', trip: 'Cruzeiro no Mediterrâneo', text: 'Viajar com duas crianças pequenas assustava-nos. Trataram de tudo, incluindo os transferes e o seguro.', rating: 5 },
      { name: 'Carlos M.', trip: 'Circuito no Japão', text: 'Roteiro impecável, com sugestões que nunca encontraria sozinho. Já é a terceira viagem que faço com eles.', rating: 5 },
    ],

    faqTitle: 'Perguntas frequentes',
    faq: [
      { q: 'Pedir orçamento tem algum custo?', a: 'Não. O pedido e a proposta são totalmente gratuitos e sem compromisso. Só paga se decidir avançar com a reserva.' },
      { q: 'Quanto tempo demora a resposta?', a: 'Respondemos em menos de 24 horas úteis. Se a viagem for para muito breve, indique-o nas notas e damos prioridade.' },
      { q: 'Posso pagar a prestações?', a: 'Sim. Trabalhamos com pagamento faseado até à data da viagem e, em muitos operadores, com sinal reduzido na reserva.' },
      { q: 'E se ainda não souber o destino?', a: 'Ainda melhor. Escolha "Ainda não sei — surpreendam-me" e sugerimos destinos a partir do orçamento, das datas e do tipo de viagem.' },
      { q: 'Os meus dados ficam seguros?', a: 'Os dados são usados apenas para preparar o orçamento e são guardados de acordo com o RGPD. Pode pedir a remoção a qualquer momento.' },
    ],

    ctaTitle: 'Pronto para começar?',
    ctaText: 'Leva três minutos a preencher e não fica obrigado a nada.',

    formTitle: 'Conte-nos a viagem que tem em mente',
    formSubtitle: 'Quanto mais nos contar, mais afinada fica a proposta.',
    successTitle: 'Pedido recebido!',
    successText:
      'Obrigado — um consultor da 1000viagens vai analisar o seu pedido e responder em menos de 24 horas úteis.',

    privacyNote:
      'Ao enviar, autoriza a 1000viagens a contactá-lo para preparar o orçamento pedido. Os dados não são partilhados com terceiros para fins de marketing.',
    cookieNote:
      'Este site usa apenas armazenamento local para guardar o rascunho do seu pedido. Não usamos cookies de publicidade.',
  },

  integrations: {
    travelPartner: {
      enabled: false,
      authorizationCode: '',   // nunca é exposto no site público
      agencyId: '',
      endpoint: '',
      notes: '',
      updatedAt: '',
    },
    webhookUrl: '',            // cópia de cada pedido para Zapier / Make / n8n
    notificationEmail: '',     // para onde avisar quando entra um pedido
    analyticsSnippet: '',      // opcional: script de analítica no site público
  },

  meta: {
    createdAt: '',
    updatedAt: '',
    version: 1,
  },
};

/** Fusão profunda: as definições guardadas por cima dos valores por omissão. */
export function mergeSettings(base, incoming) {
  if (Array.isArray(base)) return Array.isArray(incoming) ? incoming : base;
  if (base && typeof base === 'object') {
    if (!incoming || typeof incoming !== 'object' || Array.isArray(incoming)) return base;
    const out = {};
    for (const key of new Set([...Object.keys(base), ...Object.keys(incoming)])) {
      out[key] = key in base ? mergeSettings(base[key], incoming[key]) : incoming[key];
    }
    return out;
  }
  return incoming === undefined ? base : incoming;
}

/**
 * O que o site público pode ver. Tudo o que seja segredo (código de
 * autorização TravelPartner, webhooks, e-mail de notificação) fica de fora.
 */
export function publicSettings(settings) {
  const { brand, company, content } = settings;
  return {
    brand,
    company: {
      legalName: company.legalName,
      nif: company.nif,
      rnavt: company.rnavt,
      address: company.address,
      postalCode: company.postalCode,
      city: company.city,
      country: company.country,
      phone: company.phone,
      whatsapp: company.whatsapp,
      email: company.email,
      hours: company.hours,
      mapsUrl: company.mapsUrl,
      socials: company.socials,
    },
    content,
    integrations: {
      analyticsSnippet: settings.integrations.analyticsSnippet || '',
    },
  };
}

/**
 * Projeção para o backoffice: o código de autorização vai mascarado, para não
 * andar a passear em ecrãs partilhados. O valor completo só sai com ?reveal=1.
 */
export function adminSettings(settings, { reveal = false } = {}) {
  const clone = structuredClone(settings);
  const tp = clone.integrations.travelPartner;
  tp.hasAuthorizationCode = Boolean(tp.authorizationCode);
  if (!reveal) {
    tp.authorizationCode = tp.authorizationCode ? maskSecret(tp.authorizationCode) : '';
  }
  return clone;
}

export function maskSecret(value) {
  const text = String(value);
  if (text.length <= 4) return '••••';
  return `${'•'.repeat(Math.min(12, text.length - 4))}${text.slice(-4)}`;
}
