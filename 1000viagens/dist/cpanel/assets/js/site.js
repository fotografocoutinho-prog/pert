/* ============================================================================
   1000viagens — comportamento do site público
   ========================================================================== */

(function () {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
  const DRAFT_KEY = 'mv_rascunho_pedido';
  const COOKIE_KEY = 'mv_aviso_privacidade';

  const state = {
    settings: null,
    catalog: null,
    step: 1,
    totalSteps: 5,
    startedAt: Date.now(),
    sending: false,
    form: {
      tripType: '', destination: '', undecided: false,
      startDate: '', endDate: '', month: '', nights: '', flexible: true,
      adults: 2, children: 0, childrenAges: [], ageRange: '',
      budgetRange: '', includes: [], hotelCategory: 'indiferente', board: '', pace: '',
      interests: [], notes: '',
      name: '', email: '', phone: '', channel: 'email', bestTime: 'qualquer', source: '',
      rgpd: false, marketing: false,
    },
  };

  const STEP_LABELS = ['A viagem', 'Datas', 'Viajantes', 'Orçamento', 'Contactos'];

  /* ───────────────────────────────  Arranque  ──────────────────────────── */

  document.addEventListener('DOMContentLoaded', init);

  async function init() {
    wireChrome();
    restoreDraft();
    try {
      const response = await fetch('/api/public/config');
      const payload = await response.json();
      state.settings = payload.settings;
      state.catalog = payload.catalog;
    } catch (err) {
      console.warn('Não foi possível carregar as definições do site:', err);
      state.settings = null;
      state.catalog = null;
    }
    if (state.settings) applySettings(state.settings);
    if (state.catalog) buildForm(state.catalog);
    wireForm();
    applyDraftToDom();
    applyUrlIntent();
    goToStep(state.step, { silent: true });
    revealOnScroll();
  }

  /* ─────────────────────────  Cabeçalho e miudezas  ────────────────────── */

  function wireChrome() {
    const header = $('#header');
    const onScroll = () => header.classList.toggle('is-stuck', window.scrollY > 40);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });

    const toggle = $('#nav-toggle');
    const nav = $('#nav');
    toggle?.addEventListener('click', () => {
      const open = nav.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', String(open));
    });
    $$('#nav a').forEach((link) =>
      link.addEventListener('click', () => {
        nav.classList.remove('is-open');
        toggle?.setAttribute('aria-expanded', 'false');
      }),
    );

    $('#year').textContent = String(new Date().getFullYear());

    const cookie = $('#cookie');
    if (!localStorage.getItem(COOKIE_KEY)) {
      setTimeout(() => { cookie.hidden = false; }, 1200);
    }
    $('#cookie-ok')?.addEventListener('click', () => {
      localStorage.setItem(COOKIE_KEY, '1');
      cookie.hidden = true;
    });
  }

  function revealOnScroll() {
    const items = $$('.reveal:not(.is-visible)');
    const showAll = () => items.forEach((item) => item.classList.add('is-visible'));
    // Rede de segurança: nada fica invisível por causa de um observador que não dispara.
    setTimeout(showAll, 2500);
    if (!('IntersectionObserver' in window)) {
      showAll();
      return;
    }
    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
          }
        });
      },
      { rootMargin: '0px 0px -60px 0px', threshold: 0.08 },
    );
    items.forEach((item) => observer.observe(item));
  }

  /* ──────────────────────  Aplicar definições da marca  ─────────────────── */

  function applySettings(settings) {
    const { brand, company, content } = settings;

    // Cores da marca
    const root = document.documentElement;
    if (brand.primaryColor) root.style.setProperty('--ocean-700', brand.primaryColor);
    if (brand.accentColor) root.style.setProperty('--sunset', brand.accentColor);
    if (brand.sandColor) root.style.setProperty('--sand', brand.sandColor);
    if (brand.faviconUrl) $('#favicon').setAttribute('href', brand.faviconUrl);

    // Logótipo enviado no backoffice substitui o desenho por omissão
    if (brand.logoUrl) {
      $$('.logo').forEach((logo) => {
        logo.innerHTML = '';
        const img = document.createElement('img');
        img.className = 'logo__img';
        img.src = brand.logoUrl;
        img.alt = brand.name || '1000viagens';
        logo.appendChild(img);
      });
    } else if (brand.name) {
      renderWordmark($('#logo-name'), brand.name);
      renderWordmark($('#footer-logo-name'), brand.name);
      $('#footer-brand').textContent = brand.name;
    }
    if (brand.tagline) {
      $('#footer-tagline').textContent = brand.tagline;
      document.title = `${brand.name || '1000viagens'} — ${brand.tagline}`;
    }

    // Herói
    const hero = content.hero || {};
    if (hero.eyebrow) $('#hero-eyebrow').textContent = hero.eyebrow;
    if (hero.title) $('#hero-title').innerHTML = emphasise(hero.title);
    if (hero.subtitle) $('#hero-subtitle').textContent = hero.subtitle;
    if (hero.ctaPrimary) setButtonLabel($('#hero-cta1'), hero.ctaPrimary);
    if (hero.ctaSecondary) setButtonLabel($('#hero-cta2'), hero.ctaSecondary);
    if (hero.imageUrl) {
      const photo = $('#hero-photo');
      photo.style.backgroundImage = `url("${cssUrl(hero.imageUrl)}")`;
      photo.hidden = false;
      $('.hero__scene').style.display = 'none';
    }
    renderHeroStats(hero.stats || []);

    // Secções
    setText('#destinations-title', content.destinationsTitle);
    setText('#destinations-subtitle', content.destinationsSubtitle);
    setText('#steps-title', content.stepsTitle);
    setText('#testimonials-title', content.testimonialsTitle);
    setText('#faq-title', content.faqTitle);
    setText('#cta-title', content.ctaTitle);
    setText('#cta-text', content.ctaText);
    setText('#form-title', content.formTitle);
    setText('#form-subtitle', content.formSubtitle);
    setText('#success-title', content.successTitle);
    setText('#success-text', content.successText);
    setText('#cookie-text', content.cookieNote);
    if (content.privacyNote) {
      const label = $('#rgpd-label');
      label.textContent = `${content.privacyNote} `;
      const link = document.createElement('a');
      link.href = '/privacidade';
      link.target = '_blank';
      link.rel = 'noopener';
      link.textContent = 'Política de privacidade';
      label.append(link, '.');
    }

    injectAnalytics(settings.integrations?.analyticsSnippet || '');
    renderDestinations(content.destinations || []);
    renderSteps(content.steps || []);
    renderHighlights(content.highlights || []);
    renderTestimonials(content.testimonials || []);
    renderFaq(content.faq || []);
    renderCompany(company, brand);
    renderSchema(settings);
  }

  /**
   * Insere o código de analítica configurado no backoffice.
   * O conteúdo vem do servidor autenticado (mesmo nível de confiança do site),
   * mas os <script> criados por innerHTML não correm — daí recriá-los à mão.
   */
  function injectAnalytics(snippet) {
    if (!snippet.trim() || document.getElementById('mv-analytics')) return;
    const holder = document.createElement('div');
    holder.id = 'mv-analytics';
    holder.hidden = true;
    holder.innerHTML = snippet;
    document.body.appendChild(holder);
    [...holder.querySelectorAll('script')].forEach((old) => {
      const script = document.createElement('script');
      [...old.attributes].forEach((attr) => script.setAttribute(attr.name, attr.value));
      script.textContent = old.textContent;
      document.head.appendChild(script);
      old.remove();
    });
  }

  function renderWordmark(node, name) {
    if (!node) return;
    node.textContent = '';
    const match = /^(\d+)(.*)$/.exec(name);
    if (match) {
      node.append(match[1]);
      const em = document.createElement('em');
      em.textContent = match[2];
      node.append(em);
    } else {
      node.textContent = name;
    }
  }

  /** Realça a última expressão do título do herói (entre * ou as 2 últimas palavras). */
  function emphasise(title) {
    const safe = escapeHtml(title);
    if (safe.includes('*')) return safe.replace(/\*(.+?)\*/g, '<em>$1</em>');
    const words = safe.split(' ');
    if (words.length < 4) return safe;
    const tail = words.splice(-2).join(' ');
    return `${words.join(' ')} <em>${tail}</em>`;
  }

  function renderHeroStats(stats) {
    const host = $('#hero-stats');
    host.innerHTML = '';
    stats.filter((s) => s && s.value).forEach((stat) => {
      const box = document.createElement('div');
      box.className = 'hero__stat';
      const value = document.createElement('b');
      value.textContent = stat.value;
      const label = document.createElement('span');
      label.textContent = stat.label || '';
      box.append(value, label);
      host.appendChild(box);
    });
  }

  function renderDestinations(list) {
    const host = $('#destinations');
    host.innerHTML = '';
    list.filter((d) => d && d.name).forEach((dest, index) => {
      const card = document.createElement('a');
      card.className = 'destination reveal';
      card.href = `/viagens/${destinationSlug(dest.name)}/`;
      card.setAttribute('aria-label', `Viagens para ${dest.name} — ver detalhes e pedir orçamento`);

      const art = document.createElement('div');
      art.className = 'destination__art';
      if (dest.imageUrl) {
        const img = document.createElement('img');
        img.src = dest.imageUrl;
        img.alt = dest.name;
        img.loading = 'lazy';
        art.appendChild(img);
      } else {
        art.innerHTML = window.MVArt.scene(dest.art || 'tropical', `d${index}`);
      }
      if (dest.tag) {
        const tag = document.createElement('span');
        tag.className = 'destination__tag';
        tag.textContent = dest.tag;
        art.appendChild(tag);
      }

      const body = document.createElement('div');
      body.className = 'destination__body';
      body.innerHTML = `
        <span class="destination__region"></span>
        <h3 class="destination__name"></h3>
        <p class="destination__nights"></p>
        <div class="destination__foot">
          <span class="destination__price"><small>desde</small><b></b></span>
          <span class="destination__cta">Ver e pedir orçamento
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
          </span>
        </div>`;
      $('.destination__region', body).textContent = dest.region || '';
      $('.destination__name', body).textContent = dest.name;
      $('.destination__nights', body).textContent = dest.nights || '';
      $('.destination__price b', body).textContent = dest.from ? `${dest.from} €` : 'sob consulta';
      if (!dest.from) $('.destination__price small', body).textContent = '';

      card.append(art, body);
      host.appendChild(card);
    });
    revealOnScroll();
  }

  /** Preenche o destino a partir do endereço (/?destino=Maldivas#pedido). */
  function applyUrlIntent() {
    const params = new URLSearchParams(location.search);
    const destination = cleanParam(params.get('destino'));
    const type = cleanParam(params.get('tipo'));
    if (destination) {
      state.form.destination = destination;
      state.form.undecided = false;
      const input = $('#destination');
      if (input) { input.value = destination; input.disabled = false; }
      $('#undecided').checked = false;
    }
    if (type && state.catalog?.tripTypes.some((item) => item.id === type)) {
      state.form.tripType = type;
      const input = $(`input[name="tripType"][value="${type}"]`);
      if (input) input.checked = true;
    }
    if (destination || type) saveDraft();
  }

  const cleanParam = (value) => (value ? String(value).replace(/[<>"']/g, '').trim().slice(0, 80) : '');

  function pickDestination(name) {
    state.form.destination = name;
    state.form.undecided = false;
    const input = $('#destination');
    if (input) {
      input.value = name;
      $('#undecided').checked = false;
    }
    saveDraft();
    goToStep(1, { silent: true });
    document.getElementById('pedido').scrollIntoView({ behavior: 'smooth', block: 'start' });
    setTimeout(() => $('#trip-types input')?.focus({ preventScroll: true }), 500);
  }

  function renderSteps(steps) {
    const host = $('#steps');
    host.innerHTML = '';
    steps.forEach((step) => {
      const item = document.createElement('div');
      item.className = 'step';
      const title = document.createElement('h3');
      title.textContent = step.title || '';
      const text = document.createElement('p');
      text.textContent = step.text || '';
      item.append(title, text);
      host.appendChild(item);
    });
  }

  function renderHighlights(items) {
    const host = $('#highlights');
    host.innerHTML = '';
    items.forEach((item) => {
      const card = document.createElement('article');
      card.className = 'highlight';
      const icon = document.createElement('div');
      icon.className = 'highlight__icon';
      icon.innerHTML = window.MVArt.icon(item.icon || 'compass', 26);
      const title = document.createElement('h3');
      title.textContent = item.title || '';
      const text = document.createElement('p');
      text.textContent = item.text || '';
      card.append(icon, title, text);
      host.appendChild(card);
    });
  }

  function renderTestimonials(items) {
    const host = $('#testimonials');
    host.innerHTML = '';
    items.forEach((item) => {
      const card = document.createElement('article');
      card.className = 'testimonial';
      const stars = document.createElement('div');
      stars.className = 'testimonial__stars';
      stars.setAttribute('aria-label', `${item.rating || 5} em 5 estrelas`);
      stars.textContent = '★'.repeat(Math.max(1, Math.min(5, item.rating || 5)));
      const text = document.createElement('p');
      text.className = 'testimonial__text';
      text.textContent = `“${item.text || ''}”`;
      const who = document.createElement('div');
      who.className = 'testimonial__who';
      const avatar = document.createElement('span');
      avatar.className = 'testimonial__avatar';
      avatar.setAttribute('aria-hidden', 'true');
      avatar.textContent = initials(item.name || '');
      const meta = document.createElement('div');
      const name = document.createElement('b');
      name.textContent = item.name || '';
      const trip = document.createElement('span');
      trip.textContent = item.trip || '';
      meta.append(name, trip);
      who.append(avatar, meta);
      card.append(stars, text, who);
      host.appendChild(card);
    });
  }

  function renderFaq(items) {
    const host = $('#faq-list');
    host.innerHTML = '';
    items.forEach((item) => {
      const details = document.createElement('details');
      details.className = 'faq__item';
      const summary = document.createElement('summary');
      summary.textContent = item.q || '';
      const answer = document.createElement('p');
      answer.textContent = item.a || '';
      details.append(summary, answer);
      host.appendChild(details);
    });
  }

  function renderCompany(company, brand) {
    const digits = (value) => String(value || '').replace(/[^\d+]/g, '');

    if (company.phone) {
      const phoneLink = $('#header-phone');
      phoneLink.href = `tel:${digits(company.phone)}`;
      $('span', phoneLink).textContent = company.phone;
      phoneLink.hidden = false;
      const help = $('#quote-help');
      help.hidden = false;
      const helpPhone = $('#help-phone');
      helpPhone.href = `tel:${digits(company.phone)}`;
      helpPhone.textContent = company.phone;
      $('#help-hours').textContent = company.hours || '';
    }

    if (company.whatsapp) {
      const number = digits(company.whatsapp).replace(/^\+/, '');
      const link = `https://wa.me/${number}?text=${encodeURIComponent('Olá! Gostava de pedir um orçamento de viagem.')}`;
      const float = $('#whatsapp');
      float.href = link;
      float.hidden = false;
      const success = $('#success-whatsapp');
      success.href = link;
      success.hidden = false;
    }

    const contacts = $('#footer-contacts');
    contacts.innerHTML = '';
    const add = (text, href) => {
      if (!text) return;
      const li = document.createElement('li');
      if (href) {
        const link = document.createElement('a');
        link.href = href;
        link.textContent = text;
        li.appendChild(link);
      } else {
        li.textContent = text;
      }
      contacts.appendChild(li);
    };
    add(company.phone, `tel:${digits(company.phone)}`);
    add(company.email, `mailto:${company.email}`);
    const morada = [company.address, [company.postalCode, company.city].filter(Boolean).join(' ')].filter(Boolean).join(', ');
    add(morada, company.mapsUrl || null);
    add(company.hours);

    setText('#footer-legal-name', company.legalName || brand.name);
    if (company.nif) {
      const nif = $('#footer-nif');
      nif.textContent = `NIF ${company.nif}`;
      nif.hidden = false;
    }
    if (company.rnavt) {
      const rnavt = $('#footer-rnavt');
      rnavt.textContent = `RNAVT ${company.rnavt}`;
      rnavt.hidden = false;
      $('#trust-rnavt').textContent = `RNAVT ${company.rnavt}`;
    }

    const socials = $('#footer-socials');
    socials.innerHTML = '';
    const marks = {
      instagram: 'M12 7.6A4.4 4.4 0 1 0 16.4 12 4.4 4.4 0 0 0 12 7.6Zm0 7.3A2.9 2.9 0 1 1 14.9 12 2.9 2.9 0 0 1 12 14.9Zm5.6-7.5a1 1 0 1 1-1-1 1 1 0 0 1 1 1ZM21 8.9a6.3 6.3 0 0 0-1.7-4.2A6.3 6.3 0 0 0 15.1 3C13.5 2.9 10.5 2.9 8.9 3a6.3 6.3 0 0 0-4.2 1.7A6.3 6.3 0 0 0 3 8.9c-.1 1.6-.1 4.6 0 6.2a6.3 6.3 0 0 0 1.7 4.2A6.3 6.3 0 0 0 8.9 21c1.6.1 4.6.1 6.2 0a6.3 6.3 0 0 0 4.2-1.7 6.3 6.3 0 0 0 1.7-4.2c.1-1.6.1-4.6 0-6.2Zm-1.8 7.9a2.9 2.9 0 0 1-1.6 1.6c-1.1.5-3.8.4-5 .4s-3.9.1-5-.4a2.9 2.9 0 0 1-1.6-1.6c-.5-1.1-.4-3.8-.4-5s-.1-3.9.4-5A2.9 2.9 0 0 1 7 5.2c1.1-.5 3.8-.4 5-.4s3.9-.1 5 .4a2.9 2.9 0 0 1 1.6 1.6c.5 1.1.4 3.8.4 5s.1 3.9-.4 5Z',
      facebook: 'M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5 3.66 9.15 8.44 9.94v-7H7.9v-2.9h2.54V9.85c0-2.52 1.5-3.9 3.77-3.9 1.1 0 2.24.19 2.24.19v2.46h-1.26c-1.24 0-1.63.78-1.63 1.57v1.89h2.78l-.45 2.9h-2.33v7A9.99 9.99 0 0 0 22 12.06Z',
      tiktok: 'M16.6 5.8a5.2 5.2 0 0 1-1.3-3.4h-3v13.1a2.9 2.9 0 1 1-2.1-2.8V9.6a6 6 0 1 0 5.1 5.9V9.9a8.2 8.2 0 0 0 4.7 1.5V8.3a5.2 5.2 0 0 1-3.4-2.5Z',
      linkedin: 'M4.98 3.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5ZM3 9h4v12H3V9Zm7 0h3.8v1.7h.05a4.16 4.16 0 0 1 3.75-2.06c4 0 4.75 2.64 4.75 6.07V21h-4v-5.4c0-1.29-.02-2.95-1.8-2.95-1.8 0-2.07 1.4-2.07 2.85V21h-4V9Z',
      youtube: 'M23 12s0-3.2-.4-4.7a2.5 2.5 0 0 0-1.8-1.8C19.3 5 12 5 12 5s-7.3 0-8.8.5a2.5 2.5 0 0 0-1.8 1.8C1 8.8 1 12 1 12s0 3.2.4 4.7a2.5 2.5 0 0 0 1.8 1.8c1.5.5 8.8.5 8.8.5s7.3 0 8.8-.5a2.5 2.5 0 0 0 1.8-1.8C23 15.2 23 12 23 12ZM9.8 15.2V8.8l6 3.2-6 3.2Z',
    };
    Object.entries(company.socials || {}).forEach(([network, href]) => {
      if (!href || !marks[network]) return;
      const link = document.createElement('a');
      link.href = href;
      link.target = '_blank';
      link.rel = 'noopener';
      link.setAttribute('aria-label', network);
      link.innerHTML = `<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="${marks[network]}"/></svg>`;
      socials.appendChild(link);
    });
  }

  function renderSchema(settings) {
    const { brand, company, content } = settings;
    const base = (brand.siteUrl || location.origin).replace(/\/$/, '');
    const graph = [];

    if (Array.isArray(content.faq) && content.faq.length) {
      graph.push({
        '@type': 'FAQPage',
        mainEntity: content.faq.filter((item) => item.q && item.a).map((item) => ({
          '@type': 'Question',
          name: item.q,
          acceptedAnswer: { '@type': 'Answer', text: item.a },
        })),
      });
    }

    if (Array.isArray(content.destinations) && content.destinations.length) {
      graph.push({
        '@type': 'ItemList',
        name: 'Destinos em destaque',
        itemListElement: content.destinations.filter((d) => d.name).map((dest, index) => ({
          '@type': 'ListItem',
          position: index + 1,
          name: `Viagens para ${dest.name}`,
          url: `${base}/viagens/${destinationSlug(dest.name)}/`,
        })),
      });
    }

    const schema = {
      '@type': 'TravelAgency',
      '@id': `${base}/#organizacao`,
      name: brand.name || '1000viagens',
      description: brand.tagline || '',
      url: base || location.origin,
      email: company.email || undefined,
      telephone: company.phone || undefined,
      address: company.address
        ? {
            '@type': 'PostalAddress',
            streetAddress: company.address,
            postalCode: company.postalCode || undefined,
            addressLocality: company.city || undefined,
            addressCountry: company.country || 'PT',
          }
        : undefined,
      openingHours: company.hours || undefined,
      areaServed: company.country || 'Portugal',
      priceRange: '€€',
      image: brand.logoUrl ? `${base}${brand.logoUrl}` : `${base}/assets/img/og.png`,
      sameAs: Object.values(company.socials || {}).filter(Boolean),
    };

    graph.unshift(schema);
    $('#schema-org').textContent = JSON.stringify({ '@context': 'https://schema.org', '@graph': graph });
  }

  /* ─────────────────────────  Construir o formulário  ───────────────────── */

  function buildForm(catalog) {
    // Tipos de viagem
    const tripHost = $('#trip-types');
    tripHost.innerHTML = '';
    catalog.tripTypes.forEach((type) => {
      tripHost.appendChild(
        choice({
          name: 'tripType', value: type.id, type: 'radio',
          title: type.label, hint: type.hint, icon: type.icon,
          onChange: () => { state.form.tripType = type.id; clearError('field-trip-type'); saveDraft(); },
        }),
      );
    });

    // Destinos sugeridos
    const datalist = $('#destinos-populares');
    datalist.innerHTML = '';
    catalog.popularDestinations.forEach((name) => {
      const option = document.createElement('option');
      option.value = name;
      datalist.appendChild(option);
    });

    // Faixas etárias (do próprio cliente)
    chipGroup($('#age-ranges'), catalog.ageRanges, 'ageRange', 'radio', (value) => {
      state.form.ageRange = value;
    });

    // Orçamento
    const budgetHost = $('#budget-ranges');
    budgetHost.innerHTML = '';
    catalog.budgetRanges.forEach((range) => {
      budgetHost.appendChild(
        choice({
          name: 'budgetRange', value: range.id, type: 'radio',
          title: range.label,
          hint: range.mid ? 'por pessoa' : 'diga-nos o que faz sentido',
          icon: 'wallet',
          onChange: () => { state.form.budgetRange = range.id; saveDraft(); },
        }),
      );
    });

    chipGroup($('#includes'), catalog.includes, 'includes', 'checkbox', (values) => {
      state.form.includes = values;
    });
    chipGroup($('#interests'), catalog.interests, 'interests', 'checkbox', (values) => {
      state.form.interests = values;
    });
    chipGroup($('#contact-channels'), catalog.contactChannels, 'channel', 'radio', (value) => {
      state.form.channel = value;
      clearError('field-phone');
    });

    fillSelect($('#hotel-category'), catalog.hotelCategories, 'indiferente');
    fillSelect($('#board'), catalog.boards, '', 'Indiferente');
    fillSelect($('#pace'), catalog.paces, '', 'Indiferente');
    fillSelect($('#best-time'), catalog.bestTimes, 'qualquer');
    fillSelect($('#source'), catalog.sources, '', 'Prefiro não dizer');
  }

  /** Cartão de escolha com ícone (rádio ou caixa). */
  function choice({ name, value, type, title, hint, icon, onChange }) {
    const label = document.createElement('label');
    label.className = 'choice';
    const input = document.createElement('input');
    input.type = type;
    input.name = name;
    input.value = value;
    const box = document.createElement('span');
    box.className = 'choice__box';
    const iconSpan = document.createElement('span');
    iconSpan.className = 'choice__icon';
    iconSpan.innerHTML = window.MVArt.icon(icon || 'plane', 19);
    const text = document.createElement('span');
    text.className = 'choice__text';
    const strong = document.createElement('b');
    strong.textContent = title;
    text.appendChild(strong);
    if (hint) {
      const small = document.createElement('span');
      small.textContent = hint;
      text.appendChild(small);
    }
    box.append(iconSpan, text);
    input.addEventListener('change', onChange);
    label.append(input, box);
    return label;
  }

  /** Grupo de etiquetas redondas. */
  function chipGroup(host, items, name, type, onChange) {
    if (!host) return;
    host.innerHTML = '';
    items.forEach((item) => {
      const label = document.createElement('label');
      label.className = 'chip';
      const input = document.createElement('input');
      input.type = type;
      input.name = name;
      input.value = item.id;
      const box = document.createElement('span');
      box.className = 'chip__box';
      box.textContent = item.label;
      input.addEventListener('change', () => {
        if (type === 'checkbox') {
          const values = $$(`input[name="${name}"]:checked`, host).map((i) => i.value);
          onChange(values);
        } else {
          onChange(input.value);
        }
        saveDraft();
      });
      label.append(input, box);
      host.appendChild(label);
    });
  }

  function fillSelect(select, items, selected, emptyLabel) {
    if (!select) return;
    select.innerHTML = '';
    if (emptyLabel !== undefined) {
      const option = document.createElement('option');
      option.value = '';
      option.textContent = emptyLabel;
      select.appendChild(option);
    }
    items.forEach((item) => {
      const option = document.createElement('option');
      option.value = item.id;
      option.textContent = item.label;
      if (item.id === selected) option.selected = true;
      select.appendChild(option);
    });
  }

  /* ────────────────────────────  Lógica do form  ────────────────────────── */

  function wireForm() {
    // Progresso
    const dots = $('#progress-dots');
    dots.innerHTML = '';
    STEP_LABELS.forEach((label, index) => {
      const dot = document.createElement('button');
      dot.type = 'button';
      dot.className = 'progress__dot';
      dot.textContent = label;
      dot.setAttribute('role', 'listitem');
      dot.addEventListener('click', () => {
        if (index + 1 < state.step) goToStep(index + 1);
      });
      dots.appendChild(dot);
    });

    // Campos simples
    bindInput('#destination', 'destination');
    bindInput('#start-date', 'startDate');
    bindInput('#end-date', 'endDate');
    bindInput('#month', 'month');
    bindInput('#nights', 'nights');
    bindInput('#notes', 'notes');
    bindInput('#name', 'name');
    bindInput('#email', 'email');
    bindInput('#phone', 'phone');
    bindInput('#hotel-category', 'hotelCategory');
    bindInput('#board', 'board');
    bindInput('#pace', 'pace');
    bindInput('#best-time', 'bestTime');
    bindInput('#source', 'source');

    bindCheckbox('#undecided', 'undecided', () => {
      const input = $('#destination');
      input.disabled = state.form.undecided;
      if (state.form.undecided) { clearError('field-destination'); input.value = ''; state.form.destination = ''; }
    });
    bindCheckbox('#flexible', 'flexible');
    bindCheckbox('#rgpd', 'rgpd', () => clearError('field-rgpd'));
    bindCheckbox('#marketing', 'marketing');

    // Contadores
    $$('.counter__btn').forEach((button) => {
      button.addEventListener('click', () => {
        const key = button.dataset.counter;
        const delta = Number(button.dataset.delta);
        const limits = key === 'adults' ? [1, 20] : [0, 12];
        const next = Math.min(limits[1], Math.max(limits[0], state.form[key] + delta));
        state.form[key] = next;
        if (key === 'children') state.form.childrenAges = state.form.childrenAges.slice(0, next);
        renderCounters();
        saveDraft();
      });
    });

    $('#btn-next').addEventListener('click', () => {
      if (!validateStep(state.step)) return;
      goToStep(Math.min(state.totalSteps, state.step + 1));
    });
    $('#btn-back').addEventListener('click', () => goToStep(Math.max(1, state.step - 1)));
    $('#quote-form').addEventListener('submit', submitForm);
    $('#btn-new-request').addEventListener('click', resetForm);

    // Enter avança em vez de submeter no meio do formulário
    $('#quote-form').addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && event.target.tagName !== 'TEXTAREA' && state.step < state.totalSteps) {
        event.preventDefault();
        $('#btn-next').click();
      }
    });

    renderCounters();
  }

  function bindInput(selector, key) {
    const node = $(selector);
    if (!node) return;
    node.addEventListener('input', () => {
      state.form[key] = node.value;
      clearError(node.closest('.field')?.id);
      saveDraft();
    });
    node.addEventListener('change', () => {
      state.form[key] = node.value;
      saveDraft();
    });
  }

  function bindCheckbox(selector, key, after) {
    const node = $(selector);
    if (!node) return;
    node.addEventListener('change', () => {
      state.form[key] = node.checked;
      if (after) after();
      saveDraft();
    });
  }

  function renderCounters() {
    $('#adults-value').textContent = String(state.form.adults);
    $('#children-value').textContent = String(state.form.children);
    $$('.counter__btn').forEach((button) => {
      const key = button.dataset.counter;
      const delta = Number(button.dataset.delta);
      const limits = key === 'adults' ? [1, 20] : [0, 12];
      button.disabled = delta < 0 ? state.form[key] <= limits[0] : state.form[key] >= limits[1];
    });

    const host = $('#child-ages');
    host.innerHTML = '';
    for (let index = 0; index < state.form.children; index += 1) {
      const label = document.createElement('label');
      label.textContent = `Idade da ${index + 1}ª criança`;
      const input = document.createElement('input');
      input.type = 'number';
      input.min = '0';
      input.max = '17';
      input.placeholder = '—';
      input.value = state.form.childrenAges[index] ?? '';
      input.addEventListener('input', () => {
        state.form.childrenAges[index] = input.value === '' ? '' : Number(input.value);
        saveDraft();
      });
      label.appendChild(input);
      host.appendChild(label);
    }
  }

  function goToStep(step, { silent = false } = {}) {
    state.step = step;
    $$('.step-panel').forEach((panel) => {
      panel.classList.toggle('is-active', Number(panel.dataset.step) === step);
    });

    $('#progress-current').textContent = String(step);
    $('#progress-label').textContent = STEP_LABELS[step - 1];
    $('#progress-bar').style.width = `${(step / state.totalSteps) * 100}%`;
    $$('#progress-dots .progress__dot').forEach((dot, index) => {
      dot.classList.toggle('is-current', index + 1 === step);
      dot.classList.toggle('is-done', index + 1 < step);
    });

    $('#btn-back').hidden = step === 1;
    $('#btn-next').hidden = step === state.totalSteps;
    $('#btn-submit').hidden = step !== state.totalSteps;
    $('#nav-note').textContent = step === state.totalSteps
      ? 'Sem custos e sem compromisso.'
      : 'Guardamos o rascunho neste dispositivo.';

    if (step === state.totalSteps) renderSummary();

    if (!silent) {
      const card = $('.quote__card');
      const top = card.getBoundingClientRect().top + window.scrollY - 100;
      window.scrollTo({ top, behavior: 'smooth' });
      setTimeout(() => $(`.step-panel[data-step="${step}"] h3`)?.focus?.(), 300);
    }
    saveDraft();
  }

  function validateStep(step) {
    let valid = true;
    hideAlert();

    if (step === 1) {
      if (!state.form.tripType) { showError('field-trip-type'); valid = false; }
      if (!state.form.undecided && state.form.destination.trim().length < 2) {
        showError('field-destination'); valid = false;
      }
    }

    if (step === 5) {
      if (state.form.name.trim().length < 2) { showError('field-name'); valid = false; }
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(state.form.email.trim())) { showError('field-email'); valid = false; }
      const needsPhone = state.form.channel === 'telefone' || state.form.channel === 'whatsapp';
      if (needsPhone && state.form.phone.replace(/\D/g, '').length < 9) { showError('field-phone'); valid = false; }
      if (!state.form.rgpd) { showError('field-rgpd'); valid = false; }
    }

    if (!valid) {
      showAlert('Faltam alguns dados neste passo — veja os campos assinalados.');
      const first = $('.field--error');
      first?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      $('input, select, textarea', first)?.focus({ preventScroll: true });
    }
    return valid;
  }

  function showError(fieldId) {
    const field = document.getElementById(fieldId);
    field?.classList.add('field--error');
    if (fieldId === 'field-rgpd') $('#error-rgpd').style.display = 'block';
  }

  function clearError(fieldId) {
    if (!fieldId) return;
    document.getElementById(fieldId)?.classList.remove('field--error');
    if (fieldId === 'field-rgpd') $('#error-rgpd').style.display = 'none';
  }

  function showAlert(message) {
    $('#form-alert-text').textContent = message;
    $('#form-alert').classList.add('is-visible');
  }

  function hideAlert() {
    $('#form-alert').classList.remove('is-visible');
  }

  function renderSummary() {
    const list = $('#summary-list');
    list.innerHTML = '';
    const label = (kind, id) => state.catalog?.[kind]?.find((item) => item.id === id)?.label || '';

    const rows = [
      ['Viagem', label('tripTypes', state.form.tripType)],
      ['Destino', state.form.undecided ? 'Ainda sem destino — querem sugestões' : state.form.destination],
      ['Quando', formatWhen()],
      ['Viajantes', formatParty()],
      ['Orçamento', label('budgetRanges', state.form.budgetRange)],
    ].filter(([, value]) => value);

    rows.forEach(([term, value]) => {
      const dt = document.createElement('dt');
      dt.textContent = term;
      const dd = document.createElement('dd');
      dd.textContent = value;
      list.append(dt, dd);
    });
  }

  function formatWhen() {
    const { startDate, endDate, month, nights, flexible } = state.form;
    const parts = [];
    if (startDate) parts.push(endDate ? `${prettyDate(startDate)} a ${prettyDate(endDate)}` : `a partir de ${prettyDate(startDate)}`);
    else if (month) parts.push(prettyMonth(month));
    if (nights) parts.push(`${nights} noites`);
    if (flexible && parts.length) parts.push('datas flexíveis');
    return parts.join(' · ');
  }

  function formatParty() {
    const { adults, children } = state.form;
    const parts = [`${adults} adulto${adults === 1 ? '' : 's'}`];
    if (children) parts.push(`${children} criança${children === 1 ? '' : 's'}`);
    return parts.join(' + ');
  }

  function prettyDate(value) {
    const date = new Date(`${value}T12:00:00`);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString('pt-PT', { day: 'numeric', month: 'long', year: 'numeric' });
  }

  function prettyMonth(value) {
    const date = new Date(`${value}-01T12:00:00`);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString('pt-PT', { month: 'long', year: 'numeric' });
  }

  /* ──────────────────────────────  Submissão  ───────────────────────────── */

  async function submitForm(event) {
    event.preventDefault();
    if (state.sending) return;
    if (!validateStep(5)) return;

    const button = $('#btn-submit');
    const original = button.innerHTML;
    state.sending = true;
    button.disabled = true;
    button.innerHTML = '<span class="spinner"></span> A enviar…';

    const f = state.form;
    const payload = {
      trip: {
        type: f.tripType,
        destination: f.destination,
        undecided: f.undecided,
        startDate: f.startDate,
        endDate: f.endDate,
        month: f.month,
        flexible: f.flexible,
        nights: f.nights ? Number(f.nights) : 0,
      },
      party: {
        adults: f.adults,
        children: f.children,
        childrenAges: f.childrenAges.map((age) => (age === '' ? 0 : Number(age))),
        ageRange: f.ageRange,
      },
      budget: { range: f.budgetRange, includes: f.includes },
      prefs: {
        hotelCategory: f.hotelCategory,
        board: f.board,
        pace: f.pace,
        interests: f.interests,
        notes: f.notes,
      },
      contact: {
        name: f.name, email: f.email, phone: f.phone,
        channel: f.channel, bestTime: f.bestTime, source: f.source,
      },
      consent: { rgpd: f.rgpd, marketing: f.marketing },
      website: $('#website').value,
      elapsedMs: Date.now() - state.startedAt,
    };

    try {
      const response = await fetch('/api/leads', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const result = await response.json();

      if (!response.ok) {
        if (result.errors) {
          Object.keys(result.errors).forEach((key) => {
            const map = {
              'trip.type': 'field-trip-type', 'trip.destination': 'field-destination',
              'contact.name': 'field-name', 'contact.email': 'field-email',
              'contact.phone': 'field-phone', 'consent.rgpd': 'field-rgpd',
            };
            if (map[key]) showError(map[key]);
          });
        }
        showAlert(result.error || 'Não foi possível enviar o pedido. Tente novamente.');
        return;
      }

      $('#success-ref').textContent = result.id;
      $('#quote-form').style.display = 'none';
      $('#quote-progress').style.display = 'none';
      $('#success').classList.add('is-visible');
      $('#success').scrollIntoView({ behavior: 'smooth', block: 'center' });
      localStorage.removeItem(DRAFT_KEY);
    } catch (err) {
      console.error(err);
      showAlert('Falha de ligação. Verifique a internet e tente novamente — ou ligue-nos.');
    } finally {
      state.sending = false;
      button.disabled = false;
      button.innerHTML = original;
    }
  }

  function resetForm() {
    localStorage.removeItem(DRAFT_KEY);
    location.hash = '#pedido';
    location.reload();
  }

  /* ────────────────────────────────  Rascunho  ──────────────────────────── */

  function saveDraft() {
    try {
      localStorage.setItem(DRAFT_KEY, JSON.stringify({ form: state.form, step: state.step, at: Date.now() }));
    } catch { /* armazenamento cheio ou bloqueado — seguimos sem rascunho */ }
  }

  function restoreDraft() {
    try {
      const raw = localStorage.getItem(DRAFT_KEY);
      if (!raw) return;
      const draft = JSON.parse(raw);
      // rascunhos com mais de 14 dias são ignorados
      if (!draft.at || Date.now() - draft.at > 14 * 86400000) return;
      Object.assign(state.form, draft.form || {});
      state.step = Math.min(state.totalSteps, Math.max(1, draft.step || 1));
    } catch { /* rascunho ilegível */ }
  }

  function applyDraftToDom() {
    const f = state.form;
    const set = (selector, value) => { const node = $(selector); if (node && value != null) node.value = value; };
    set('#destination', f.destination);
    set('#start-date', f.startDate);
    set('#end-date', f.endDate);
    set('#month', f.month);
    set('#nights', f.nights);
    set('#notes', f.notes);
    set('#name', f.name);
    set('#email', f.email);
    set('#phone', f.phone);
    set('#hotel-category', f.hotelCategory);
    set('#board', f.board);
    set('#pace', f.pace);
    set('#best-time', f.bestTime);
    set('#source', f.source);
    $('#undecided').checked = Boolean(f.undecided);
    $('#destination').disabled = Boolean(f.undecided);
    $('#flexible').checked = Boolean(f.flexible);
    $('#marketing').checked = Boolean(f.marketing);

    const check = (name, values) => {
      $$(`input[name="${name}"]`).forEach((input) => {
        input.checked = Array.isArray(values) ? values.includes(input.value) : input.value === values;
      });
    };
    check('tripType', f.tripType);
    check('budgetRange', f.budgetRange);
    check('ageRange', f.ageRange);
    check('includes', f.includes);
    check('interests', f.interests);
    check('channel', f.channel || 'email');
    renderCounters();
  }

  /* ───────────────────────────────  Auxiliares  ─────────────────────────── */

  function setText(selector, value) {
    if (value == null || value === '') return;
    const node = $(selector);
    if (node) node.textContent = value;
  }

  function setButtonLabel(button, label) {
    if (!button) return;
    const svg = $('svg', button);
    button.textContent = label;
    if (svg) button.appendChild(svg);
  }

  function initials(name) {
    return name.split(/\s+/).slice(0, 2).map((word) => word.charAt(0).toUpperCase()).join('');
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  function cssUrl(url) {
    return String(url).replace(/["\\)]/g, '');
  }
})();
