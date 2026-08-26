/* ============================================================================
   1000viagens — backoffice
   ========================================================================== */

(function () {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
  const THEME_KEY = 'mv_admin_tema';
  const { fmt } = window.MVCharts;

  const state = {
    settings: null,
    catalog: null,
    stats: null,
    leads: [],
    leadsTotal: 0,
    currentLead: null,
    dash: { range: '365', type: '' },
    leadFilter: { range: '365', status: '', type: '', q: '' },
    view: 'dashboard',
    dirty: false,
  };

  const VIEWS = {
    dashboard: ['Painel', 'Resumo dos pedidos de orçamento'],
    leads: ['Pedidos', 'Todos os pedidos recebidos pelo site'],
    brand: ['Marca e logótipo', 'Logótipo, nome e cores do site'],
    company: ['Dados da empresa', 'Contactos, morada e informação legal'],
    content: ['Conteúdos do site', 'Textos, destinos, testemunhos e perguntas'],
    integrations: ['Integrações', 'TravelPartner, webhooks e avisos'],
    security: ['Segurança', 'Password de acesso ao backoffice'],
  };

  /* ─────────────────────────────────  API  ──────────────────────────────── */

  async function api(path, { method = 'GET', body } = {}) {
    const response = await fetch(path, {
      method,
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': '1000viagens',
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    if (response.status === 401) {
      showLogin();
      throw new Error('Sessão expirada.');
    }
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.error || `Erro ${response.status}`);
    return payload;
  }

  function toast(message, kind = 'ok') {
    const node = $('#toast');
    node.textContent = message;
    node.className = `toast${kind === 'error' ? ' toast--error' : ''}`;
    node.hidden = false;
    clearTimeout(node.__timer);
    node.__timer = setTimeout(() => { node.hidden = true; }, 4200);
  }

  /* ───────────────────────────────  Arranque  ───────────────────────────── */

  document.addEventListener('DOMContentLoaded', boot);

  async function boot() {
    applyTheme(localStorage.getItem(THEME_KEY));
    $('#theme-toggle').addEventListener('click', () => {
      const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      applyTheme(next);
      localStorage.setItem(THEME_KEY, next);
    });
    $('#login-form').addEventListener('submit', doLogin);
    $('#logout').addEventListener('click', doLogout);
    $('#nav-toggle').addEventListener('click', () => {
      $('#sidebar').classList.add('is-open');
      $('#scrim').hidden = false;
    });
    $('#scrim').addEventListener('click', closeOverlays);
    $('#drawer-close').addEventListener('click', closeOverlays);
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeOverlays(); });

    $$('.sidebar__link[data-view]').forEach((link) =>
      link.addEventListener('click', () => switchView(link.dataset.view)));

    window.addEventListener('beforeunload', (event) => {
      if (!state.dirty) return;
      event.preventDefault();
      event.returnValue = '';
    });

    const session = await fetch('/api/admin/session').then((r) => r.json()).catch(() => ({ authenticated: false }));
    if (session.authenticated) {
      state.session = session;
      await startApp();
    } else {
      showLogin();
    }
  }

  function applyTheme(theme) {
    if (theme === 'dark' || theme === 'light') document.documentElement.setAttribute('data-theme', theme);
    else document.documentElement.removeAttribute('data-theme');
    document.dispatchEvent(new CustomEvent('mv:theme'));
  }

  function showLogin() {
    $('#app').hidden = true;
    $('#login').hidden = false;
    setTimeout(() => $('#password')?.focus(), 60);
  }

  async function doLogin(event) {
    event.preventDefault();
    const button = $('#login-submit');
    const error = $('#login-error');
    error.hidden = true;
    button.disabled = true;
    button.textContent = 'A entrar…';
    try {
      const result = await api('/api/admin/login', { method: 'POST', body: { password: $('#password').value } });
      $('#password').value = '';
      state.session = { authenticated: true, mustChangePassword: result.mustChangePassword };
      await startApp();
      if (result.mustChangePassword) {
        switchView('security');
        toast('Está a usar a password inicial — mude-a agora.', 'error');
      }
    } catch (err) {
      error.textContent = err.message;
      error.hidden = false;
    } finally {
      button.disabled = false;
      button.textContent = 'Entrar';
    }
  }

  async function doLogout() {
    await api('/api/admin/logout', { method: 'POST' }).catch(() => {});
    location.reload();
  }

  async function startApp() {
    $('#login').hidden = true;
    $('#app').hidden = false;
    const payload = await api('/api/admin/settings');
    state.settings = payload.settings;
    state.catalog = payload.catalog;
    paintBrand();
    fillFilterSelects();
    await Promise.all([loadStats(), loadLeads()]);
    switchView(location.hash.replace('#', '') in VIEWS ? location.hash.replace('#', '') : 'dashboard');
  }

  function paintBrand() {
    const { brand } = state.settings;
    const host = $('#sidebar-logo');
    if (brand.logoUrl) {
      host.innerHTML = '';
      const img = document.createElement('img');
      img.src = brand.logoUrl;
      img.alt = brand.name || '1000viagens';
      host.appendChild(img);
    }
    document.title = `Backoffice — ${brand.name || '1000viagens'}`;
  }

  function closeOverlays() {
    $('#sidebar').classList.remove('is-open');
    $('#drawer').classList.remove('is-open');
    $('#drawer').setAttribute('aria-hidden', 'true');
    $('#scrim').hidden = true;
  }

  function switchView(view) {
    if (!VIEWS[view]) view = 'dashboard';
    if (state.dirty && !confirm('Tem alterações por guardar. Sair mesmo assim?')) return;
    state.dirty = false;
    state.view = view;
    history.replaceState(null, '', `#${view}`);
    $$('.view').forEach((node) => node.classList.toggle('is-active', node.id === `view-${view}`));
    $$('.sidebar__link[data-view]').forEach((link) => link.classList.toggle('is-active', link.dataset.view === view));
    const [title, sub] = VIEWS[view];
    $('#view-title').firstChild.textContent = title;
    $('#view-sub').textContent = sub;
    closeOverlays();
    window.scrollTo({ top: 0 });

    if (view === 'brand') renderBrandView();
    if (view === 'company') renderCompanyView();
    if (view === 'content') renderContentView();
    if (view === 'integrations') renderIntegrationsView();
    if (view === 'security') renderSecurityView();
  }

  /* ──────────────────────────  Auxiliares de dados  ─────────────────────── */

  const label = (kind, id, fallback = '—') =>
    state.catalog?.[kind]?.find((item) => item.id === id)?.label || fallback;

  function get(object, path) {
    return path.split('.').reduce((acc, key) => (acc == null ? undefined : acc[key]), object);
  }

  function set(object, path, value) {
    const keys = path.split('.');
    const last = keys.pop();
    const target = keys.reduce((acc, key) => {
      if (acc[key] == null || typeof acc[key] !== 'object') acc[key] = {};
      return acc[key];
    }, object);
    target[last] = value;
  }

  const euro = (value) => (value == null ? '—' : fmt.euro(value));
  const dateShort = (iso) => new Date(iso).toLocaleDateString('pt-PT', { day: '2-digit', month: 'short', year: '2-digit' });
  const dateLong = (iso) => new Date(iso).toLocaleString('pt-PT', { dateStyle: 'long', timeStyle: 'short' });

  function tripWhen(lead) {
    if (lead.trip.startDate) {
      const start = new Date(`${lead.trip.startDate}T12:00:00`).toLocaleDateString('pt-PT', { day: '2-digit', month: 'short', year: '2-digit' });
      return lead.trip.endDate
        ? `${start} → ${new Date(`${lead.trip.endDate}T12:00:00`).toLocaleDateString('pt-PT', { day: '2-digit', month: 'short' })}`
        : start;
    }
    if (lead.trip.month) {
      return new Date(`${lead.trip.month}-01T12:00:00`).toLocaleDateString('pt-PT', { month: 'long', year: 'numeric' });
    }
    return 'Sem datas';
  }

  function fillFilterSelects() {
    const types = [{ id: '', label: 'Todos os tipos de viagem' }, ...state.catalog.tripTypes];
    [['#filter-type', types], ['#leads-type', types]].forEach(([selector, items]) => {
      const select = $(selector);
      select.innerHTML = '';
      items.forEach((item) => {
        const option = document.createElement('option');
        option.value = item.id;
        option.textContent = item.label;
        select.appendChild(option);
      });
    });

    const statusSelect = $('#leads-status');
    statusSelect.innerHTML = '';
    [{ id: '', label: 'Todos os estados' }, ...state.catalog.statuses].forEach((item) => {
      const option = document.createElement('option');
      option.value = item.id;
      option.textContent = item.label;
      statusSelect.appendChild(option);
    });
  }

  /* ───────────────────────  Componentes de formulário  ──────────────────── */

  function makeField({ path, label: text, type = 'text', hint, options, placeholder, rows, value }) {
    const wrap = document.createElement('div');
    wrap.className = 'field';
    const id = `f_${path.replace(/\W/g, '_')}`;

    if (type === 'checkbox') {
      const line = document.createElement('label');
      line.className = 'lbl';
      line.style.cssText = 'display:flex;gap:10px;align-items:flex-start;cursor:pointer;font-weight:550';
      const input = document.createElement('input');
      input.type = 'checkbox';
      input.id = id;
      input.checked = Boolean(value !== undefined ? value : get(state.settings, path));
      input.style.cssText = 'width:18px;height:18px;margin-top:2px;accent-color:var(--brand)';
      input.addEventListener('change', () => { set(state.settings, path, input.checked); markDirty(); });
      const span = document.createElement('span');
      span.textContent = text;
      line.append(input, span);
      wrap.appendChild(line);
      if (hint) wrap.appendChild(hintNode(hint));
      return wrap;
    }

    const lbl = document.createElement('label');
    lbl.className = 'lbl';
    lbl.setAttribute('for', id);
    lbl.textContent = text;
    wrap.appendChild(lbl);

    let input;
    if (type === 'textarea') {
      input = document.createElement('textarea');
      input.className = 'textarea';
      if (rows) input.rows = rows;
    } else if (type === 'select') {
      input = document.createElement('select');
      input.className = 'select';
      (options || []).forEach((option) => {
        const node = document.createElement('option');
        node.value = option.id;
        node.textContent = option.label;
        input.appendChild(node);
      });
    } else {
      input = document.createElement('input');
      input.className = 'input';
      input.type = type;
    }
    input.id = id;
    if (placeholder) input.placeholder = placeholder;
    const current = value !== undefined ? value : get(state.settings, path);
    input.value = current == null ? '' : current;
    input.addEventListener('input', () => { set(state.settings, path, input.value); markDirty(); });
    if (type === 'select' || type === 'color') {
      input.addEventListener('change', () => { set(state.settings, path, input.value); markDirty(); });
    }
    wrap.appendChild(input);
    if (hint) wrap.appendChild(hintNode(hint));
    return wrap;
  }

  function hintNode(text) {
    const node = document.createElement('p');
    node.className = 'hint';
    node.textContent = text;
    return node;
  }

  /** Campo de imagem: pré-visualização + envio + remoção. */
  function makeUpload({ path, label: text, hint, kind }) {
    const wrap = document.createElement('div');
    wrap.className = 'field';
    const lbl = document.createElement('label');
    lbl.className = 'lbl';
    lbl.textContent = text;
    wrap.appendChild(lbl);

    const row = document.createElement('div');
    row.className = 'upload';
    const preview = document.createElement('div');
    preview.className = 'upload__preview';
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/png,image/jpeg,image/webp,image/svg+xml,image/gif';
    input.hidden = true;
    const pick = document.createElement('button');
    pick.type = 'button';
    pick.className = 'btn btn--ghost btn--sm';
    pick.textContent = 'Escolher imagem';
    const clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'btn btn--ghost btn--sm';
    clear.textContent = 'Remover';

    const paint = () => {
      const url = get(state.settings, path);
      preview.innerHTML = '';
      if (url) {
        const img = document.createElement('img');
        img.src = url;
        img.alt = '';
        preview.appendChild(img);
        clear.hidden = false;
      } else {
        const span = document.createElement('span');
        span.textContent = 'sem imagem';
        preview.appendChild(span);
        clear.hidden = true;
      }
    };
    paint();

    pick.addEventListener('click', () => input.click());
    clear.addEventListener('click', () => { set(state.settings, path, ''); paint(); markDirty(); });
    input.addEventListener('change', async () => {
      const file = input.files?.[0];
      if (!file) return;
      if (file.size > 4 * 1024 * 1024) return toast('A imagem não pode exceder 4 MB.', 'error');
      pick.disabled = true;
      pick.textContent = 'A enviar…';
      try {
        const dataUrl = await new Promise((resolve, reject) => {
          const reader = new FileReader();
          reader.onload = () => resolve(reader.result);
          reader.onerror = reject;
          reader.readAsDataURL(file);
        });
        const result = await api('/api/admin/upload', { method: 'POST', body: { dataUrl, kind: kind || 'imagem' } });
        set(state.settings, path, result.url);
        paint();
        markDirty();
        toast('Imagem carregada. Não se esqueça de guardar.');
      } catch (err) {
        toast(err.message, 'error');
      } finally {
        pick.disabled = false;
        pick.textContent = 'Escolher imagem';
        input.value = '';
      }
    });

    row.append(preview, pick, clear, input);
    wrap.appendChild(row);
    if (hint) wrap.appendChild(hintNode(hint));
    return wrap;
  }

  /** Lista editável (destinos, testemunhos, perguntas…). */
  function makeRepeater({ path, itemName, fields, defaults, columns = 2 }) {
    const wrap = document.createElement('div');
    const list = document.createElement('div');
    wrap.appendChild(list);

    const draw = () => {
      list.innerHTML = '';
      const items = get(state.settings, path) || [];
      items.forEach((item, index) => {
        const card = document.createElement('div');
        card.className = 'repeater__item';

        const head = document.createElement('div');
        head.className = 'repeater__head';
        const title = document.createElement('b');
        title.textContent = `${itemName} ${index + 1}`;
        const up = iconButton('Subir', 'M12 5v14M6 11l6-6 6 6', () => moveItem(index, -1));
        const down = iconButton('Descer', 'M12 5v14M6 13l6 6 6-6', () => moveItem(index, 1));
        const remove = iconButton('Remover', 'M6 6l12 12M18 6 6 18', () => {
          const current = get(state.settings, path);
          current.splice(index, 1);
          markDirty();
          draw();
        });
        remove.classList.add('btn--danger');
        head.append(title, up, down, remove);
        card.appendChild(head);

        const grid = document.createElement('div');
        grid.className = 'grid grid--form';
        if (columns === 1) grid.style.gridTemplateColumns = '1fr';
        fields.forEach((field) => {
          const node = field.type === 'upload'
            ? makeUpload({ path: `${path}.${index}.${field.key}`, label: field.label, hint: field.hint, kind: field.kind })
            : makeField({
                path: `${path}.${index}.${field.key}`,
                label: field.label, type: field.type || 'text',
                options: field.options, placeholder: field.placeholder, rows: field.rows, hint: field.hint,
              });
          if (field.full) node.style.gridColumn = '1/-1';
          grid.appendChild(node);
        });
        card.appendChild(grid);
        list.appendChild(card);
      });
    };

    const moveItem = (index, delta) => {
      const items = get(state.settings, path);
      const target = index + delta;
      if (target < 0 || target >= items.length) return;
      [items[index], items[target]] = [items[target], items[index]];
      markDirty();
      draw();
    };

    const add = document.createElement('button');
    add.type = 'button';
    add.className = 'btn btn--ghost btn--sm';
    add.textContent = `Adicionar ${itemName.toLowerCase()}`;
    add.addEventListener('click', () => {
      const items = get(state.settings, path) || [];
      items.push(structuredClone(defaults));
      set(state.settings, path, items);
      markDirty();
      draw();
    });

    draw();
    wrap.appendChild(add);
    return wrap;
  }

  function iconButton(title, pathData, onClick) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn--ghost btn--icon btn--sm';
    button.title = title;
    button.setAttribute('aria-label', title);
    button.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="${pathData}"/></svg>`;
    button.addEventListener('click', onClick);
    return button;
  }

  function sectionCard(title, hint, ...children) {
    const card = document.createElement('div');
    card.className = 'card section-card';
    const heading = document.createElement('h2');
    heading.textContent = title;
    card.appendChild(heading);
    if (hint) card.appendChild(hintNode(hint));
    children.filter(Boolean).forEach((child) => card.appendChild(child));
    return card;
  }

  function grid(...children) {
    const node = document.createElement('div');
    node.className = 'grid grid--form';
    children.filter(Boolean).forEach((child) => node.appendChild(child));
    return node;
  }

  function markDirty() {
    state.dirty = true;
    $$('.saving-bar .btn').forEach((button) => { button.disabled = false; });
    $$('.saving-bar span').forEach((node) => { node.textContent = 'Alterações por guardar.'; });
  }

  function savingBar() {
    const bar = document.createElement('div');
    bar.className = 'saving-bar';
    const status = document.createElement('span');
    status.textContent = 'Tudo guardado.';
    const save = document.createElement('button');
    save.className = 'btn';
    save.textContent = 'Guardar alterações';
    save.disabled = true;
    save.addEventListener('click', async () => {
      save.disabled = true;
      save.innerHTML = '<span class="spinner"></span> A guardar…';
      try {
        const result = await api('/api/admin/settings', { method: 'PUT', body: state.settings });
        state.settings = result.settings;
        state.dirty = false;
        status.textContent = 'Tudo guardado.';
        toast('Definições guardadas. O site já está atualizado.');
        paintBrand();
      } catch (err) {
        toast(err.message, 'error');
        save.disabled = false;
      } finally {
        save.textContent = 'Guardar alterações';
      }
    });
    bar.append(status, save);
    return bar;
  }

  /* ═══════════════════════════════  PAINEL  ═══════════════════════════════ */

  $$('#range-presets button').forEach((button) =>
    button.addEventListener('click', () => {
      $$('#range-presets button').forEach((other) => other.classList.toggle('is-active', other === button));
      state.dash.range = button.dataset.range;
      loadStats();
    }));

  document.addEventListener('DOMContentLoaded', () => {
    $('#filter-type').addEventListener('change', (event) => {
      state.dash.type = event.target.value;
      loadStats();
    });
    $$('[data-table]').forEach((button) =>
      button.addEventListener('click', () => {
        const key = button.dataset.table;
        const table = $(`#table-${key}`);
        const chart = $(`#chart-${key}`);
        const showTable = table.hidden;
        table.hidden = !showTable;
        chart.hidden = showTable;
        button.textContent = showTable ? 'Ver gráfico' : 'Ver tabela';
      }));

    // Pedidos
    $('#leads-search').addEventListener('input', debounce(() => {
      state.leadFilter.q = $('#leads-search').value.trim();
      loadLeads();
    }, 320));
    ['status', 'type', 'range'].forEach((key) => {
      $(`#leads-${key}`).addEventListener('change', () => {
        state.leadFilter[key] = $(`#leads-${key}`).value;
        loadLeads();
      });
    });
  });

  function debounce(fn, wait) {
    let timer;
    return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), wait); };
  }

  async function loadStats() {
    const params = new URLSearchParams({ range: state.dash.range });
    if (state.dash.type) params.set('type', state.dash.type);
    // Enquanto recarrega, mantém o desenho anterior mais esbatido (sem saltos)
    $('#view-dashboard').style.opacity = '.55';
    try {
      const { stats } = await api(`/api/admin/stats?${params}`);
      state.stats = stats;
      renderDashboard();
    } catch (err) {
      toast(err.message, 'error');
    } finally {
      $('#view-dashboard').style.opacity = '';
    }
  }

  function renderDashboard() {
    const stats = state.stats;
    const { totals, kpis } = stats;
    const charts = window.MVCharts;

    $('#kpi-hero').textContent = kpis.avgPerLead ? fmt.euro(kpis.avgPerLead) : '—';
    $('#kpi-hero-meta').textContent = totals.leads
      ? `Média de ${totals.leads} pedido${totals.leads === 1 ? '' : 's'}, com ${fmt.dec(kpis.avgTravelers || 0)} viajantes e ${kpis.avgNights || '—'} noites em média.`
      : 'Ainda não há pedidos neste período.';

    $('#kpi-leads').textContent = fmt.int(totals.leads);
    $('#kpi-leads-sub').textContent = `${fmt.int(totals.travelers)} viajantes no total`;
    $('#kpi-person').textContent = kpis.avgPerPerson ? fmt.euro(kpis.avgPerPerson) : '—';
    $('#kpi-person-sub').textContent = 'orçamento indicado por pessoa';
    $('#kpi-conv').textContent = totals.conversionRate == null ? '—' : fmt.pct(totals.conversionRate);
    $('#kpi-conv-sub').textContent = totals.closed
      ? `${totals.won} ganhos em ${totals.closed} fechados`
      : 'ainda sem pedidos fechados';
    $('#kpi-pipeline').textContent = fmt.euroShort(totals.openValue || 0);
    $('#kpi-pipeline-sub').textContent = 'pedidos ainda em aberto';

    const granularityLabel = { day: 'por dia', week: 'por semana', month: 'por mês' }[stats.trend.granularity];
    $('#trend-sub').textContent = `Pedidos recebidos ${granularityLabel}`;

    charts.lineChart($('#chart-trend'), {
      points: stats.trend.points.map((point) => ({
        label: point.label,
        value: point.leads,
        secondary: point.value ? fmt.euro(point.value) : null,
      })),
      secondaryLabel: 'em orçamento estimado',
      unit: 'pedidos',
      ariaLabel: 'Evolução do número de pedidos ao longo do tempo',
      height: 220,
    });
    charts.tableView($('#table-trend'), ['Período', 'Pedidos', 'Valor estimado'],
      stats.trend.points.map((p) => [p.label, fmt.int(p.leads), p.value ? fmt.euro(p.value) : '—']));

    charts.horizontalBars($('#chart-destinations'), {
      data: stats.destinations.map((item) => ({
        label: item.label,
        value: item.value,
        extra: item.amount ? fmt.euro(item.amount) : null,
        residual: item.key === 'outros',
      })),
      ariaLabel: 'Destinos mais pedidos',
      maxBars: 9,
    });
    charts.tableView($('#table-destinations'), ['Destino', 'Pedidos'],
      stats.destinations.map((d) => [d.label, fmt.int(d.value)]));

    charts.columns($('#chart-ages'), {
      data: stats.ages.map((age) => ({ label: age.label.replace(' anos', ''), title: age.label, value: age.value })),
      ordinal: true,
      ariaLabel: 'Distribuição por faixa etária',
      height: 175,
    });
    charts.tableView($('#table-ages'), ['Faixa etária', 'Pedidos'],
      stats.ages.map((a) => [a.label, fmt.int(a.value)]));

    charts.horizontalBars($('#chart-types'), {
      data: stats.tripTypes.map((type) => ({ label: type.label, value: type.value })),
      ariaLabel: 'Pedidos por tipo de viagem',
      maxBars: 8,
    });
    charts.tableView($('#table-types'), ['Tipo de viagem', 'Pedidos'],
      stats.tripTypes.map((t) => [t.label, fmt.int(t.value)]));

    charts.horizontalBars($('#chart-budgets'), {
      data: stats.budgets.map((budget) => ({ label: budget.label, value: budget.value })),
      ordinal: true,
      ariaLabel: 'Pedidos por escalão de orçamento',
    });
    charts.tableView($('#table-budgets'), ['Orçamento por pessoa', 'Pedidos'],
      stats.budgets.map((b) => [b.label, fmt.int(b.value)]));

    charts.columns($('#chart-season'), {
      data: stats.season.map((month) => ({ label: month.label, value: month.value })),
      ariaLabel: 'Mês de partida pedido',
      height: 165,
    });
    charts.tableView($('#table-season'), ['Mês', 'Pedidos'],
      stats.season.map((m) => [m.label, fmt.int(m.value)]));

    charts.horizontalBars($('#chart-pipeline'), {
      data: stats.pipeline.map((stage) => ({ label: stage.label, value: stage.value })),
      ordinal: true,
      ariaLabel: 'Pedidos por estado comercial',
    });
    charts.tableView($('#table-pipeline'), ['Estado', 'Pedidos'],
      stats.pipeline.map((s) => [s.label, fmt.int(s.value)]));

    $('#filter-note').textContent = `${fmt.int(totals.leads)} pedido${totals.leads === 1 ? '' : 's'} no período`;
  }

  /* ═══════════════════════════════  PEDIDOS  ══════════════════════════════ */

  async function loadLeads() {
    const params = new URLSearchParams({ range: state.leadFilter.range, limit: '200' });
    if (state.leadFilter.status) params.set('status', state.leadFilter.status);
    if (state.leadFilter.type) params.set('type', state.leadFilter.type);
    if (state.leadFilter.q) params.set('q', state.leadFilter.q);
    try {
      const payload = await api(`/api/admin/leads?${params}`);
      state.leads = payload.items;
      state.leadsTotal = payload.total;
      renderLeads();
      $('#export-csv').href = `/api/admin/export.csv?${params}`;
    } catch (err) {
      toast(err.message, 'error');
    }
  }

  function renderLeads() {
    const body = $('#leads-body');
    body.innerHTML = '';
    $('#leads-empty').hidden = state.leads.length > 0;
    $('#leads-count').textContent = state.leads.length
      ? `${fmt.int(state.leadsTotal)} pedido${state.leadsTotal === 1 ? '' : 's'} · clique numa linha para ver o detalhe`
      : '';

    const novos = state.leads.filter((lead) => lead.status === 'novo').length;
    const badge = $('#leads-badge');
    badge.hidden = novos === 0;
    badge.textContent = novos ? `${novos} novo${novos === 1 ? '' : 's'}` : '';

    state.leads.forEach((lead) => {
      const row = document.createElement('tr');
      row.tabIndex = 0;

      row.appendChild(cell(lead.id, { className: 'mono' }));

      const who = document.createElement('td');
      const name = document.createElement('span');
      name.className = 'cell-strong';
      name.textContent = lead.contact.name;
      const contact = document.createElement('span');
      contact.className = 'cell-sub';
      contact.textContent = lead.contact.email;
      who.append(name, contact);
      row.appendChild(who);

      const trip = document.createElement('td');
      const dest = document.createElement('span');
      dest.className = 'cell-strong';
      dest.textContent = lead.trip.undecided ? 'Ainda sem destino' : lead.trip.destination;
      const type = document.createElement('span');
      type.className = 'cell-sub';
      type.textContent = label('tripTypes', lead.trip.type);
      trip.append(dest, type);
      row.appendChild(trip);

      const when = document.createElement('td');
      when.innerHTML = '';
      const whenMain = document.createElement('span');
      whenMain.textContent = tripWhen(lead);
      const whenSub = document.createElement('span');
      whenSub.className = 'cell-sub';
      whenSub.textContent = `recebido a ${dateShort(lead.createdAt)}`;
      when.append(whenMain, whenSub);
      row.appendChild(when);

      row.appendChild(cell(
        `${lead.party.adults}${lead.party.children ? ` + ${lead.party.children}` : ''}`,
        { className: 'num' },
      ));
      row.appendChild(cell(euro(lead.internal?.quotedValue || lead.estimatedValue), { className: 'num' }));

      const status = document.createElement('td');
      status.appendChild(statusPill(lead.status));
      row.appendChild(status);

      row.addEventListener('click', () => openLead(lead.id));
      row.addEventListener('keydown', (event) => { if (event.key === 'Enter') openLead(lead.id); });
      body.appendChild(row);
    });
  }

  function cell(value, { className } = {}) {
    const td = document.createElement('td');
    if (className) td.className = className;
    td.textContent = value;
    return td;
  }

  function statusPill(status) {
    const pill = document.createElement('span');
    pill.className = `pill pill--${status}`;
    const dot = document.createElement('span');
    dot.className = 'pill__dot';
    pill.append(dot, document.createTextNode(label('statuses', status)));
    return pill;
  }

  async function openLead(id) {
    try {
      const { lead } = await api(`/api/admin/leads/${id}`);
      state.currentLead = lead;
      renderDrawer(lead);
      $('#drawer').classList.add('is-open');
      $('#drawer').setAttribute('aria-hidden', 'false');
      $('#scrim').hidden = false;
    } catch (err) {
      toast(err.message, 'error');
    }
  }

  function renderDrawer(lead) {
    $('#drawer-title').textContent = lead.contact.name;
    $('#drawer-sub').textContent = `${lead.id} · recebido a ${dateLong(lead.createdAt)}`;

    const body = $('#drawer-body');
    body.innerHTML = '';

    // Estado + valor orçamentado
    const manage = block('Gestão do pedido');
    const manageGrid = document.createElement('div');
    manageGrid.className = 'grid grid--form';

    const statusField = document.createElement('div');
    statusField.className = 'field';
    statusField.innerHTML = '<label class="lbl" for="drawer-status">Estado</label>';
    const statusSelect = document.createElement('select');
    statusSelect.className = 'select';
    statusSelect.id = 'drawer-status';
    state.catalog.statuses.forEach((item) => {
      const option = document.createElement('option');
      option.value = item.id;
      option.textContent = item.label;
      option.selected = item.id === lead.status;
      statusSelect.appendChild(option);
    });
    statusSelect.addEventListener('change', () => patchLead(lead.id, { status: statusSelect.value }));
    statusField.appendChild(statusSelect);

    const valueField = document.createElement('div');
    valueField.className = 'field';
    valueField.innerHTML = '<label class="lbl" for="drawer-value">Valor orçamentado (€)</label>';
    const valueInput = document.createElement('input');
    valueInput.className = 'input';
    valueInput.type = 'number';
    valueInput.id = 'drawer-value';
    valueInput.min = '0';
    valueInput.placeholder = String(lead.estimatedValue ?? '');
    valueInput.value = lead.internal?.quotedValue ?? '';
    valueInput.addEventListener('change', () => patchLead(lead.id, { quotedValue: valueInput.value === '' ? null : Number(valueInput.value) }));
    valueField.appendChild(valueInput);
    valueField.appendChild(hintNode(`Estimado pelo cliente: ${euro(lead.estimatedValue)}`));

    manageGrid.append(statusField, valueField);
    manage.appendChild(manageGrid);

    const notesField = document.createElement('div');
    notesField.className = 'field';
    notesField.innerHTML = '<label class="lbl" for="drawer-notes">Notas internas</label>';
    const notes = document.createElement('textarea');
    notes.className = 'textarea';
    notes.id = 'drawer-notes';
    notes.value = lead.internal?.notes || '';
    notes.placeholder = 'Operador contactado, fornecedor, condições especiais…';
    notes.addEventListener('change', () => patchLead(lead.id, { notes: notes.value }));
    notesField.appendChild(notes);
    manage.appendChild(notesField);
    body.appendChild(manage);

    // Viagem
    const trip = block('A viagem');
    trip.appendChild(kv([
      ['Tipo', label('tripTypes', lead.trip.type)],
      ['Destino', lead.trip.undecided ? 'Ainda sem destino (querem sugestões)' : lead.trip.destination],
      ['Quando', tripWhen(lead) + (lead.trip.flexible ? ' · datas flexíveis' : '')],
      ['Duração', lead.trip.nights ? `${lead.trip.nights} noites` : '—'],
      ['Viajantes', `${lead.party.adults} adultos${lead.party.children ? `, ${lead.party.children} crianças (${lead.party.childrenAges.join(', ')} anos)` : ''}`],
      ['Faixa etária', label('ageRanges', lead.party.ageRange)],
    ]));
    body.appendChild(trip);

    // Orçamento e preferências
    const prefs = block('Orçamento e preferências');
    prefs.appendChild(kv([
      ['Orçamento', label('budgetRanges', lead.budget.range)],
      ['Valor estimado', euro(lead.estimatedValue)],
      ['Hotel', label('hotelCategories', lead.prefs.hotelCategory)],
      ['Regime', label('boards', lead.prefs.board)],
      ['Ritmo', label('paces', lead.prefs.pace)],
    ]));
    if (lead.budget.includes.length) {
      prefs.appendChild(tagList('Quer incluído', lead.budget.includes.map((id) => label('includes', id))));
    }
    if (lead.prefs.interests.length) {
      prefs.appendChild(tagList('Interesses', lead.prefs.interests.map((id) => label('interests', id))));
    }
    if (lead.prefs.notes) {
      const note = document.createElement('p');
      note.style.cssText = 'margin:12px 0 0;padding:12px 14px;background:var(--surface-2);border-radius:9px;font-size:.89rem;white-space:pre-wrap';
      note.textContent = lead.prefs.notes;
      prefs.appendChild(note);
    }
    body.appendChild(prefs);

    // Contactos
    const contact = block('Contactos');
    contact.appendChild(kv([
      ['E-mail', lead.contact.email],
      ['Telefone', lead.contact.phone || '—'],
      ['Prefere', label('contactChannels', lead.contact.channel)],
      ['Horário', label('bestTimes', lead.contact.bestTime)],
      ['Conheceu-nos', label('sources', lead.contact.source)],
      ['Marketing', lead.consent.marketing ? 'Autorizado' : 'Não autorizado'],
    ]));
    body.appendChild(contact);

    // Histórico
    if (lead.internal?.history?.length) {
      const history = block('Histórico');
      const timeline = document.createElement('div');
      timeline.className = 'timeline';
      [...lead.internal.history].reverse().forEach((entry) => {
        const item = document.createElement('div');
        item.className = 'timeline__item';
        const dot = document.createElement('span');
        dot.className = 'timeline__dot';
        const content = document.createElement('div');
        const title = document.createElement('div');
        title.textContent = `${label('statuses', entry.status)}${entry.note ? ` — ${entry.note}` : ''}`;
        const meta = document.createElement('div');
        meta.className = 'timeline__meta';
        meta.textContent = dateLong(entry.at);
        content.append(title, meta);
        item.append(dot, content);
        timeline.appendChild(item);
      });
      history.appendChild(timeline);
      body.appendChild(history);
    }

    // Ações
    const foot = $('#drawer-foot');
    foot.innerHTML = '';
    const mailBody = encodeURIComponent(
      `Olá ${lead.contact.name.split(' ')[0]},\n\nObrigado pelo seu pedido (${lead.id}) para ${lead.trip.undecided ? 'uma viagem a combinar' : lead.trip.destination}.\n\n`,
    );
    foot.appendChild(linkButton('Responder por e-mail',
      `mailto:${lead.contact.email}?subject=${encodeURIComponent(`Proposta ${lead.id} — 1000viagens`)}&body=${mailBody}`, 'btn'));
    if (lead.contact.phone) {
      const digits = lead.contact.phone.replace(/[^\d]/g, '');
      foot.appendChild(linkButton('WhatsApp', `https://wa.me/${digits.length > 9 ? digits : `351${digits}`}`, 'btn btn--ghost'));
      foot.appendChild(linkButton('Ligar', `tel:${lead.contact.phone}`, 'btn btn--ghost'));
    }
    const remove = document.createElement('button');
    remove.className = 'btn btn--danger btn--sm';
    remove.style.marginLeft = 'auto';
    remove.textContent = 'Apagar';
    remove.addEventListener('click', async () => {
      if (!confirm(`Apagar definitivamente o pedido ${lead.id}?`)) return;
      await api(`/api/admin/leads/${lead.id}`, { method: 'DELETE' });
      closeOverlays();
      toast('Pedido apagado.');
      await Promise.all([loadLeads(), loadStats()]);
    });
    foot.appendChild(remove);
  }

  function block(title) {
    const node = document.createElement('div');
    node.className = 'drawer__block';
    const heading = document.createElement('h4');
    heading.textContent = title;
    node.appendChild(heading);
    return node;
  }

  function kv(pairs) {
    const list = document.createElement('dl');
    list.className = 'kv';
    pairs.forEach(([term, value]) => {
      const dt = document.createElement('dt');
      dt.textContent = term;
      const dd = document.createElement('dd');
      dd.textContent = value || '—';
      list.append(dt, dd);
    });
    return list;
  }

  function tagList(title, items) {
    const wrap = document.createElement('div');
    wrap.style.marginTop = '12px';
    const heading = document.createElement('div');
    heading.className = 'timeline__meta';
    heading.style.marginBottom = '6px';
    heading.textContent = title;
    const tags = document.createElement('div');
    tags.className = 'tags';
    items.forEach((item) => {
      const tag = document.createElement('span');
      tag.className = 'tag';
      tag.textContent = item;
      tags.appendChild(tag);
    });
    wrap.append(heading, tags);
    return wrap;
  }

  function linkButton(text, href, className) {
    const link = document.createElement('a');
    link.className = className;
    link.href = href;
    link.textContent = text;
    if (href.startsWith('http')) { link.target = '_blank'; link.rel = 'noopener'; }
    return link;
  }

  async function patchLead(id, patch) {
    try {
      const { lead } = await api(`/api/admin/leads/${id}`, { method: 'PATCH', body: patch });
      state.currentLead = lead;
      toast('Pedido atualizado.');
      await Promise.all([loadLeads(), loadStats()]);
      renderDrawer(lead);
    } catch (err) {
      toast(err.message, 'error');
    }
  }

  /* ═════════════════════════════  DEFINIÇÕES  ═════════════════════════════ */

  function renderBrandView() {
    const view = $('#view-brand');
    view.innerHTML = '';

    view.appendChild(sectionCard(
      'Identidade',
      'O nome e o slogan aparecem no cabeçalho, no rodapé e no separador do navegador.',
      grid(
        makeField({ path: 'brand.name', label: 'Nome da agência', placeholder: '1000viagens' }),
        makeField({ path: 'brand.tagline', label: 'Slogan', placeholder: 'Mil destinos, uma viagem só sua' }),
      ),
    ));

    view.appendChild(sectionCard(
      'Logótipo e imagens',
      'PNG ou SVG com fundo transparente dão o melhor resultado. Máximo 4 MB por ficheiro.',
      makeUpload({ path: 'brand.logoUrl', label: 'Logótipo principal', kind: 'logotipo', hint: 'Substitui o nome escrito no cabeçalho e no rodapé.' }),
      makeUpload({ path: 'brand.faviconUrl', label: 'Ícone do separador (favicon)', kind: 'favicon', hint: 'Quadrado, idealmente 64×64 px ou SVG.' }),
      makeUpload({ path: 'content.hero.imageUrl', label: 'Fotografia de fundo da primeira secção', kind: 'capa', hint: 'Opcional. Sem fotografia, é usada a ilustração desenhada do pôr do sol.' }),
    ));

    view.appendChild(sectionCard(
      'Cores',
      'A cor principal pinta os botões e destaques; a cor de realce é usada nos acentos e no botão de orçamento.',
      grid(
        makeField({ path: 'brand.primaryColor', label: 'Cor principal', type: 'color' }),
        makeField({ path: 'brand.accentColor', label: 'Cor de realce', type: 'color' }),
        makeField({ path: 'brand.sandColor', label: 'Cor de fundo suave', type: 'color' }),
      ),
    ));

    view.appendChild(savingBar());
  }

  function renderCompanyView() {
    const view = $('#view-company');
    view.innerHTML = '';

    view.appendChild(sectionCard(
      'Identificação',
      'Aparece no rodapé do site e na política de privacidade.',
      grid(
        makeField({ path: 'company.legalName', label: 'Designação social' }),
        makeField({ path: 'company.nif', label: 'NIF' }),
        makeField({ path: 'company.rnavt', label: 'RNAVT', hint: 'Registo Nacional de Agentes de Viagens e Turismo.' }),
      ),
    ));

    view.appendChild(sectionCard(
      'Contactos',
      'O telefone e o WhatsApp ficam visíveis no cabeçalho e no botão flutuante.',
      grid(
        makeField({ path: 'company.phone', label: 'Telefone', placeholder: '+351 200 000 000' }),
        makeField({ path: 'company.whatsapp', label: 'WhatsApp', placeholder: '+351 900 000 000', hint: 'Deixe vazio para esconder o botão de WhatsApp.' }),
        makeField({ path: 'company.email', label: 'E-mail', type: 'email' }),
      ),
      grid(
        makeField({ path: 'company.address', label: 'Morada' }),
        makeField({ path: 'company.postalCode', label: 'Código postal' }),
        makeField({ path: 'company.city', label: 'Localidade' }),
      ),
      grid(
        makeField({ path: 'company.hours', label: 'Horário' }),
        makeField({ path: 'company.mapsUrl', label: 'Ligação para o mapa', placeholder: 'https://maps.google.com/…' }),
      ),
    ));

    view.appendChild(sectionCard(
      'Redes sociais',
      'Deixe em branco as que não usar — só aparecem as preenchidas.',
      grid(
        makeField({ path: 'company.socials.instagram', label: 'Instagram', placeholder: 'https://instagram.com/…' }),
        makeField({ path: 'company.socials.facebook', label: 'Facebook' }),
        makeField({ path: 'company.socials.tiktok', label: 'TikTok' }),
        makeField({ path: 'company.socials.linkedin', label: 'LinkedIn' }),
        makeField({ path: 'company.socials.youtube', label: 'YouTube' }),
      ),
    ));

    view.appendChild(savingBar());
  }

  function renderContentView() {
    const view = $('#view-content');
    view.innerHTML = '';

    view.appendChild(sectionCard(
      'Primeira secção',
      'É a primeira coisa que o visitante lê. Use * à volta das palavras que quer destacar a cor: por exemplo "começa com *cinco perguntas*".',
      grid(
        makeField({ path: 'content.hero.eyebrow', label: 'Etiqueta pequena' }),
        makeField({ path: 'content.hero.ctaPrimary', label: 'Botão principal' }),
        makeField({ path: 'content.hero.ctaSecondary', label: 'Botão secundário' }),
      ),
      makeField({ path: 'content.hero.title', label: 'Título' }),
      makeField({ path: 'content.hero.subtitle', label: 'Subtítulo', type: 'textarea', rows: 2 }),
      subHeading('Números em destaque'),
      makeRepeater({
        path: 'content.hero.stats', itemName: 'Número',
        fields: [
          { key: 'value', label: 'Valor', placeholder: '1.000+' },
          { key: 'label', label: 'Descrição', placeholder: 'viagens organizadas' },
        ],
        defaults: { value: '', label: '' },
      }),
    ));

    view.appendChild(sectionCard(
      'Destinos em destaque',
      'Cada cartão leva o visitante ao formulário com o destino já preenchido. Sem fotografia, é desenhada uma ilustração do estilo escolhido.',
      makeRepeater({
        path: 'content.destinations', itemName: 'Destino',
        fields: [
          { key: 'name', label: 'Destino', placeholder: 'Maldivas' },
          { key: 'region', label: 'Região', placeholder: 'Oceano Índico' },
          { key: 'from', label: 'Preço desde (€)', placeholder: '1.890' },
          { key: 'nights', label: 'Detalhe', placeholder: '7 noites · tudo incluído' },
          { key: 'tag', label: 'Etiqueta', placeholder: 'Lua de mel' },
          {
            key: 'art', label: 'Ilustração', type: 'select',
            options: [
              { id: 'tropical', label: 'Praia tropical' },
              { id: 'mediterranean', label: 'Mediterrâneo' },
              { id: 'nordic', label: 'Norte / aurora boreal' },
              { id: 'desert', label: 'Deserto' },
              { id: 'cruise', label: 'Cruzeiro' },
              { id: 'city', label: 'Cidade' },
              { id: 'mountain', label: 'Montanha' },
            ],
          },
          { key: 'imageUrl', label: 'Fotografia (opcional)', type: 'upload', kind: 'destino', full: true },
        ],
        defaults: { name: '', region: '', from: '', nights: '', tag: '', art: 'tropical', imageUrl: '' },
      }),
    ));

    view.appendChild(sectionCard(
      'Como funciona',
      'Os quatro passos explicados ao cliente.',
      makeRepeater({
        path: 'content.steps', itemName: 'Passo',
        fields: [
          { key: 'title', label: 'Título' },
          { key: 'text', label: 'Descrição', type: 'textarea', rows: 2, full: true },
        ],
        defaults: { title: '', text: '' },
      }),
    ));

    view.appendChild(sectionCard(
      'Porquê a nossa agência',
      'Três razões para confiarem em si.',
      makeRepeater({
        path: 'content.highlights', itemName: 'Vantagem',
        fields: [
          { key: 'title', label: 'Título' },
          {
            key: 'icon', label: 'Ícone', type: 'select',
            options: [
              { id: 'compass', label: 'Bússola' }, { id: 'wallet', label: 'Carteira' },
              { id: 'shield', label: 'Escudo' }, { id: 'heart', label: 'Coração' },
              { id: 'plane', label: 'Avião' }, { id: 'route', label: 'Percurso' },
            ],
          },
          { key: 'text', label: 'Descrição', type: 'textarea', rows: 2, full: true },
        ],
        defaults: { title: '', icon: 'compass', text: '' },
      }),
    ));

    view.appendChild(sectionCard(
      'Testemunhos',
      'Histórias reais de clientes — com autorização deles, claro.',
      makeRepeater({
        path: 'content.testimonials', itemName: 'Testemunho',
        fields: [
          { key: 'name', label: 'Nome' },
          { key: 'trip', label: 'Viagem' },
          { key: 'rating', label: 'Estrelas (1 a 5)', type: 'number' },
          { key: 'text', label: 'Texto', type: 'textarea', rows: 3, full: true },
        ],
        defaults: { name: '', trip: '', rating: 5, text: '' },
      }),
    ));

    view.appendChild(sectionCard(
      'Perguntas frequentes',
      'Responder aqui poupa telefonemas.',
      makeRepeater({
        path: 'content.faq', itemName: 'Pergunta',
        columns: 1,
        fields: [
          { key: 'q', label: 'Pergunta', full: true },
          { key: 'a', label: 'Resposta', type: 'textarea', rows: 3, full: true },
        ],
        defaults: { q: '', a: '' },
      }),
    ));

    view.appendChild(sectionCard(
      'Textos do formulário e avisos',
      'Mensagens que o cliente vê ao pedir orçamento.',
      grid(
        makeField({ path: 'content.formTitle', label: 'Título do formulário' }),
        makeField({ path: 'content.formSubtitle', label: 'Subtítulo do formulário' }),
        makeField({ path: 'content.successTitle', label: 'Título depois de enviar' }),
      ),
      makeField({ path: 'content.successText', label: 'Mensagem depois de enviar', type: 'textarea', rows: 2 }),
      makeField({ path: 'content.privacyNote', label: 'Texto do consentimento RGPD', type: 'textarea', rows: 2 }),
      makeField({ path: 'content.cookieNote', label: 'Aviso de privacidade no canto', type: 'textarea', rows: 2 }),
      grid(
        makeField({ path: 'content.ctaTitle', label: 'Título da faixa final' }),
        makeField({ path: 'content.ctaText', label: 'Texto da faixa final' }),
      ),
    ));

    view.appendChild(savingBar());
  }

  function subHeading(text) {
    const node = document.createElement('h3');
    node.style.cssText = 'font-size:.8rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin:22px 0 12px';
    node.textContent = text;
    return node;
  }

  function renderIntegrationsView() {
    const view = $('#view-integrations');
    view.innerHTML = '';

    // ── TravelPartner ──
    const tp = state.settings.integrations.travelPartner;
    const codeField = document.createElement('div');
    codeField.className = 'field';
    codeField.innerHTML = '<label class="lbl" for="tp-code">Código de autorização</label>';
    const codeRow = document.createElement('div');
    codeRow.style.cssText = 'display:flex;gap:8px;align-items:center';
    const codeInput = document.createElement('input');
    codeInput.className = 'input';
    codeInput.id = 'tp-code';
    codeInput.type = 'password';
    codeInput.autocomplete = 'off';
    codeInput.value = tp.authorizationCode || '';
    codeInput.placeholder = 'Cole aqui o código fornecido pela TravelPartner';
    codeInput.addEventListener('input', () => {
      set(state.settings, 'integrations.travelPartner.authorizationCode', codeInput.value);
      markDirty();
    });
    const reveal = document.createElement('button');
    reveal.type = 'button';
    reveal.className = 'btn btn--ghost btn--sm';
    reveal.textContent = 'Mostrar';
    reveal.addEventListener('click', async () => {
      if (codeInput.type === 'text') {
        codeInput.type = 'password';
        reveal.textContent = 'Mostrar';
        return;
      }
      try {
        const payload = await api('/api/admin/settings?reveal=1');
        const real = payload.settings.integrations.travelPartner.authorizationCode || '';
        if (!state.dirty) {
          codeInput.value = real;
          set(state.settings, 'integrations.travelPartner.authorizationCode', real);
        }
        codeInput.type = 'text';
        reveal.textContent = 'Esconder';
      } catch (err) {
        toast(err.message, 'error');
      }
    });
    codeRow.append(codeInput, reveal);
    codeField.appendChild(codeRow);
    codeField.appendChild(hintNode(
      tp.hasAuthorizationCode
        ? `Código guardado${tp.updatedAt ? ` (atualizado a ${dateLong(tp.updatedAt)})` : ''}. Fica só no servidor e nunca aparece no site público.`
        : 'Ainda sem código. Fica guardado apenas no servidor e nunca aparece no site público.',
    ));

    view.appendChild(sectionCard(
      'TravelPartner',
      'Credenciais de acesso ao portal de reservas TravelPartner. Guardadas no servidor, fora do alcance do site público.',
      makeField({ path: 'integrations.travelPartner.enabled', label: 'Integração ativa', type: 'checkbox' }),
      codeField,
      grid(
        makeField({ path: 'integrations.travelPartner.agencyId', label: 'Identificador da agência', placeholder: 'Ex.: AG-10234' }),
        makeField({ path: 'integrations.travelPartner.endpoint', label: 'Endereço do serviço', placeholder: 'https://…' }),
      ),
      makeField({ path: 'integrations.travelPartner.notes', label: 'Notas internas', type: 'textarea', rows: 2, hint: 'Contacto do gestor de conta, condições negociadas, o que precisar.' }),
    ));

    // ── Avisos ──
    const smtpNote = document.createElement('p');
    smtpNote.className = 'hint';
    smtpNote.style.marginTop = '10px';
    smtpNote.textContent = state.session?.smtpConfigured
      ? '✓ Servidor de e-mail configurado — os avisos são enviados automaticamente.'
      : 'ℹ Envio de e-mail ainda não configurado no servidor (variáveis SMTP_HOST, SMTP_USER, SMTP_PASS). Sem isso, use o webhook ou consulte os pedidos aqui no backoffice.';

    view.appendChild(sectionCard(
      'Avisos de novos pedidos',
      'Escolha como quer ser avisado quando entra um pedido pelo site.',
      makeField({ path: 'integrations.notificationEmail', label: 'E-mail para receber os avisos', type: 'email', placeholder: 'reservas@1000viagens.pt' }),
      smtpNote,
      makeField({
        path: 'integrations.webhookUrl', label: 'Webhook (Zapier, Make, n8n…)',
        placeholder: 'https://hooks.zapier.com/…',
        hint: 'Cada pedido é enviado para este endereço em JSON — útil para ligar ao seu CRM ou ao Excel.',
      }),
    ));

    view.appendChild(sectionCard(
      'Analítica',
      'Cole aqui o código de medição (Google Analytics, Plausible, Matomo…). Fica no site público.',
      makeField({ path: 'integrations.analyticsSnippet', label: 'Código a inserir', type: 'textarea', rows: 3, hint: 'Deixe vazio se não quiser qualquer medição.' }),
    ));

    view.appendChild(savingBar());
  }

  function renderSecurityView() {
    const view = $('#view-security');
    view.innerHTML = '';

    const card = document.createElement('div');
    card.className = 'card section-card';
    card.innerHTML = '<h2>Mudar a password</h2>';
    card.appendChild(hintNode('Ao mudar a password, todas as sessões abertas são terminadas — incluindo esta.'));

    const form = document.createElement('form');
    const current = passwordField('current', 'Password atual');
    const next = passwordField('next', 'Nova password', 'Pelo menos 8 caracteres.');
    const confirmField = passwordField('confirm', 'Repetir a nova password');
    const error = document.createElement('p');
    error.className = 'hint';
    error.style.color = 'var(--critical)';
    error.hidden = true;
    const submit = document.createElement('button');
    submit.className = 'btn';
    submit.type = 'submit';
    submit.textContent = 'Mudar password';

    form.append(current.wrap, next.wrap, confirmField.wrap, error, submit);
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      error.hidden = true;
      if (next.input.value !== confirmField.input.value) {
        error.textContent = 'As passwords novas não coincidem.';
        error.hidden = false;
        return;
      }
      submit.disabled = true;
      try {
        await api('/api/admin/password', {
          method: 'POST',
          body: { currentPassword: current.input.value, newPassword: next.input.value },
        });
        toast('Password alterada. Entre novamente.');
        setTimeout(() => location.reload(), 1200);
      } catch (err) {
        error.textContent = err.message;
        error.hidden = false;
        submit.disabled = false;
      }
    });
    card.appendChild(form);
    view.appendChild(card);

    const info = document.createElement('div');
    info.className = 'card section-card';
    info.innerHTML = '<h2>Sessão e dados</h2>';
    info.appendChild(kv([
      ['Última entrada', state.session?.lastLoginAt ? dateLong(state.session.lastLoginAt) : '—'],
      ['Sessão válida até', state.session?.expiresAt ? dateLong(state.session.expiresAt) : '—'],
      ['Envio de e-mail', state.session?.smtpConfigured ? 'Configurado' : 'Não configurado'],
      ['Pedidos guardados', `${fmt.int(state.leadsTotal)} no período filtrado`],
    ]));
    info.appendChild(hintNode(
      'Os pedidos e as definições ficam em ficheiros JSON na pasta de dados do servidor. '
      + 'Faça cópia dessa pasta com regularidade — é aí que está tudo.',
    ));
    view.appendChild(info);
  }

  function passwordField(id, label, hint) {
    const wrap = document.createElement('div');
    wrap.className = 'field';
    wrap.style.maxWidth = '380px';
    const lbl = document.createElement('label');
    lbl.className = 'lbl';
    lbl.setAttribute('for', `pw-${id}`);
    lbl.textContent = label;
    const input = document.createElement('input');
    input.className = 'input';
    input.type = 'password';
    input.id = `pw-${id}`;
    input.autocomplete = id === 'current' ? 'current-password' : 'new-password';
    input.required = true;
    wrap.append(lbl, input);
    if (hint) wrap.appendChild(hintNode(hint));
    return { wrap, input };
  }
})();
