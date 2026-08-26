/* ============================================================================
   1000viagens — gráficos em SVG, escritos de raiz (sem bibliotecas externas).

   Regras seguidas:
   · uma série = uma cor (sem rampas em categorias sem ordem natural);
   · categorias ordenadas (idades, funil) usam a rampa ordinal de um só tom;
   · marcas finas, extremidade arredondada a 4 px, folga de 2 px entre barras;
   · grelha e eixos em traço fino e discreto; texto nunca usa a cor da série;
   · rótulos directos selectivos + tooltip + vista em tabela para tudo.
   ========================================================================== */

(function (global) {
  'use strict';

  const NS = 'http://www.w3.org/2000/svg';
  // useGrouping:'always' — em pt-PT o Intl não agrupa 4 dígitos por omissão (7173),
  // e para valores em euros o separador ajuda a ler.
  const nfOptions = { useGrouping: 'always' };
  const nf = new Intl.NumberFormat('pt-PT', nfOptions);
  const nf1 = new Intl.NumberFormat('pt-PT', { ...nfOptions, maximumFractionDigits: 1 });

  const fmt = {
    int: (v) => nf.format(Math.round(v)),
    dec: (v) => nf1.format(v),
    euro: (v) => `${nf.format(Math.round(v))} €`,
    euroShort: (v) => (Math.abs(v) >= 10000 ? `${nf1.format(v / 1000)} mil €` : `${nf.format(Math.round(v))} €`),
    pct: (v) => `${nf1.format(v * 100)} %`,
  };

  const el = (name, attrs = {}, parent) => {
    const node = document.createElementNS(NS, name);
    for (const [key, value] of Object.entries(attrs)) {
      if (value != null) node.setAttribute(key, String(value));
    }
    if (parent) parent.appendChild(node);
    return node;
  };

  const text = (parent, content, attrs = {}) => {
    const node = el('text', attrs, parent);
    node.textContent = content;
    return node;
  };

  const cssVar = (name, fallback) =>
    getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;

  /**
   * Cor da rampa ordinal para a posição `index` de `count` categorias ordenadas.
   * Com mais de 5 categorias os passos são distribuídos (nunca repetidos no fim),
   * para a leitura clara→escura continuar a acompanhar a ordem.
   */
  function ordinalColor(index, count, reverse = false) {
    const slots = ['--ord-1', '--ord-2', '--ord-3', '--ord-4', '--ord-5'];
    const position = count <= 1 ? 0 : Math.round((index / (count - 1)) * (slots.length - 1));
    const slot = slots[reverse ? slots.length - 1 - position : position];
    return cssVar(slot, '#2A78D6');
  }

  /** Escala com números redondos: 0 / 500 / 1.000 / 1.500 … */
  function niceScale(max, targetTicks = 4) {
    if (!Number.isFinite(max) || max <= 0) return { max: 1, ticks: [0, 1] };
    const rough = max / targetTicks;
    const power = 10 ** Math.floor(Math.log10(rough));
    const step = [1, 1.5, 2, 2.5, 3, 4, 5, 7.5, 10].map((m) => m * power).find((s) => s >= rough) || 10 * power;
    const top = Math.ceil(max / step) * step;
    const ticks = [];
    for (let value = 0; value <= top + 1e-9; value += step) ticks.push(Number(value.toFixed(6)));
    return { max: top, ticks };
  }

  /** Caminho de barra horizontal com a ponta arredondada e a base esquadrada. */
  function barPathH(x, y, width, height, radius = 4) {
    const r = Math.min(radius, Math.max(0, width), height / 2);
    if (width <= r) return `M${x},${y}h${Math.max(width, 0.5)}v${height}h${-Math.max(width, 0.5)}Z`;
    return `M${x},${y}h${width - r}a${r},${r} 0 0 1 ${r},${r}v${height - 2 * r}a${r},${r} 0 0 1 ${-r},${r}h${-(width - r)}Z`;
  }

  /** Caminho de coluna com o topo arredondado. */
  function barPathV(x, yTop, width, height, radius = 4) {
    const r = Math.min(radius, width / 2, Math.max(0, height));
    if (height <= r) return `M${x},${yTop + height}v${-Math.max(height, 0.5)}h${width}v${Math.max(height, 0.5)}Z`;
    return `M${x},${yTop + height}v${-(height - r)}a${r},${r} 0 0 1 ${r},${-r}h${width - 2 * r}a${r},${r} 0 0 1 ${r},${r}v${height - r}Z`;
  }

  /* ─────────────────────────────  Infraestrutura  ────────────────────────── */

  function prepare(host) {
    host.innerHTML = '';
    host.classList.add('chart');
    const tooltip = document.createElement('div');
    tooltip.className = 'tooltip';
    tooltip.hidden = true;
    host.appendChild(tooltip);
    return tooltip;
  }

  function showTooltip(host, tooltip, x, y, title, rows) {
    tooltip.innerHTML = '';
    const head = document.createElement('div');
    head.className = 'tooltip__title';
    head.textContent = title;
    tooltip.appendChild(head);
    rows.forEach((row) => {
      const line = document.createElement('div');
      line.className = 'tooltip__row';
      if (row.color) {
        const key = document.createElement('span');
        key.className = 'tooltip__key';
        key.style.background = row.color;
        line.appendChild(key);
      }
      const value = document.createElement('span');
      value.className = 'tooltip__value';
      value.textContent = row.value;
      const name = document.createElement('span');
      name.className = 'tooltip__name';
      name.textContent = row.name || '';
      line.append(value, name);
      tooltip.appendChild(line);
    });
    tooltip.hidden = false;
    const width = tooltip.offsetWidth;
    const left = Math.max(width / 2 + 4, Math.min(host.clientWidth - width / 2 - 4, x));
    tooltip.style.left = `${left}px`;
    tooltip.style.top = `${Math.max(34, y)}px`;
  }

  const hideTooltip = (host, tooltip) => {
    tooltip.hidden = true;
    host.classList.remove('is-hovering');
    host.querySelectorAll('.is-hot').forEach((node) => node.classList.remove('is-hot'));
  };

  function emptyState(host, message) {
    host.innerHTML = `<div class="chart__empty">${message}</div>`;
  }

  /** Redesenha quando a largura muda (mantém o texto sempre no mesmo tamanho). */
  function responsive(host, draw) {
    let last = 0;
    const render = () => {
      const width = Math.round(host.clientWidth);
      if (!width || width === last) return;
      last = width;
      draw(width);
    };
    render();
    if (host.__mvResize) host.__mvResize.disconnect();
    if ('ResizeObserver' in global) {
      const observer = new ResizeObserver(() => render());
      observer.observe(host);
      host.__mvResize = observer;
    }
    // Redesenhar ao mudar de tema (as cores vêm de variáveis CSS)
    if (!host.__mvTheme) {
      host.__mvTheme = () => { last = 0; render(); };
      document.addEventListener('mv:theme', host.__mvTheme);
    }
  }

  /* ───────────────────────  Barras horizontais (categorias)  ─────────────── */

  /**
   * @param {HTMLElement} host
   * @param {{data: {label:string, value:number, extra?:string}[], format?:Function,
   *          unit?:string, ordinal?:boolean, maxBars?:number}} options
   */
  function horizontalBars(host, options) {
    const data = (options.data || []).filter((d) => d.value > 0);
    if (!data.length) return emptyState(host, options.emptyText || 'Ainda não há dados para este período.');
    const tooltip = prepare(host);
    const format = options.format || fmt.int;
    const unit = options.unit || 'pedidos';

    responsive(host, (width) => {
      host.querySelectorAll('svg').forEach((node) => node.remove());
      const rows = data.slice(0, options.maxBars || 10);
      const labelWidth = Math.min(168, Math.max(90, width * 0.28));
      const padRight = 62;
      const barBand = 34;
      const barHeight = Math.min(24, barBand - 12);
      const height = rows.length * barBand + 12;
      const plotWidth = width - labelWidth - padRight;
      const max = Math.max(...rows.map((row) => row.value));
      const scale = (value) => (max > 0 ? (value / max) * plotWidth : 0);

      const svg = el('svg', {
        viewBox: `0 0 ${width} ${height}`, width, height, role: 'img',
        'aria-label': options.ariaLabel || 'Gráfico de barras',
      }, host);

      rows.forEach((row, index) => {
        const y = index * barBand + 6;
        const barY = y + (barBand - barHeight) / 2 - 6;
        const barWidth = Math.max(2, scale(row.value));
        const color = row.residual
          ? cssVar('--axis', '#C3C2B7')          // o resto não é uma categoria: fica em cinzento
          : options.ordinal
            ? ordinalColor(index, rows.length, true)
            : cssVar('--series-1', '#2A78D6');

        // Etiqueta da categoria (cor de texto, nunca a cor da série)
        const label = text(svg, row.label, {
          x: labelWidth - 12, y: barY + barHeight / 2 + 4, 'text-anchor': 'end', class: 'cat-label',
        });
        label.appendChild(el('title', {}, null)).textContent = row.label;

        const path = el('path', {
          d: barPathH(labelWidth, barY, barWidth, barHeight),
          fill: color, class: 'mark',
        }, svg);

        // Valor directamente na ponta da barra
        text(svg, format(row.value), {
          x: labelWidth + barWidth + 9, y: barY + barHeight / 2 + 4, class: 'value-label',
        });

        // Área de toque maior do que a marca
        const hit = el('rect', {
          x: 0, y, width, height: barBand, class: 'hit',
        }, svg);
        const enter = (event) => {
          host.classList.add('is-hovering');
          path.classList.add('is-hot');
          const box = host.getBoundingClientRect();
          const x = event.clientX ? event.clientX - box.left : labelWidth + barWidth / 2;
          showTooltip(host, tooltip, x, barY, row.label, [
            { value: format(row.value), name: unit, color },
            ...(row.extra ? [{ value: row.extra, name: '' }] : []),
          ]);
        };
        hit.addEventListener('pointermove', enter);
        hit.addEventListener('pointerleave', () => { path.classList.remove('is-hot'); hideTooltip(host, tooltip); });
        hit.setAttribute('tabindex', '0');
        hit.addEventListener('focus', enter);
        hit.addEventListener('blur', () => hideTooltip(host, tooltip));
      });
    });
  }

  /* ─────────────────────────────  Colunas (séries)  ──────────────────────── */

  function columns(host, options) {
    const data = options.data || [];
    if (!data.some((d) => d.value > 0)) return emptyState(host, options.emptyText || 'Ainda não há dados para este período.');
    const tooltip = prepare(host);
    const format = options.format || fmt.int;
    const unit = options.unit || 'pedidos';

    responsive(host, (width) => {
      host.querySelectorAll('svg').forEach((node) => node.remove());
      const padLeft = 40;
      const padRight = 10;
      const padTop = 18;
      const axisBand = 30;
      const plotHeight = options.height || 190;
      const height = plotHeight + padTop + axisBand;
      const plotWidth = width - padLeft - padRight;
      const { max, ticks } = niceScale(Math.max(...data.map((d) => d.value)));
      const band = plotWidth / data.length;
      const barWidth = Math.min(24, Math.max(6, band - (data.length > 12 ? 4 : 14)));

      const svg = el('svg', {
        viewBox: `0 0 ${width} ${height}`, width, height, role: 'img',
        'aria-label': options.ariaLabel || 'Gráfico de colunas',
      }, host);

      ticks.forEach((tick) => {
        const y = padTop + plotHeight - (tick / max) * plotHeight;
        el('line', { x1: padLeft, x2: width - padRight, y1: y, y2: y, class: tick === 0 ? 'axis-line' : 'gridline' }, svg);
        text(svg, format(tick), { x: padLeft - 8, y: y + 4, 'text-anchor': 'end', class: 'tick' });
      });

      const highest = Math.max(...data.map((d) => d.value));

      data.forEach((point, index) => {
        const color = options.ordinal
          ? ordinalColor(index, data.length)
          : cssVar('--series-1', '#2A78D6');
        const barHeight = max > 0 ? (point.value / max) * plotHeight : 0;
        const x = padLeft + index * band + (band - barWidth) / 2;
        const yTop = padTop + plotHeight - barHeight;

        const path = el('path', {
          d: barPathV(x, yTop, barWidth, barHeight), fill: color, class: 'mark',
        }, svg);

        // Rótulo directo apenas onde ajuda: poucas colunas, ou o máximo
        const labelIt = data.length <= 7 || point.value === highest;
        if (labelIt && point.value > 0) {
          text(svg, format(point.value), {
            x: x + barWidth / 2, y: yTop - 7, 'text-anchor': 'middle', class: 'value-label',
          });
        }

        text(svg, point.label, {
          x: x + barWidth / 2, y: height - 10, 'text-anchor': 'middle', class: 'tick',
        });

        const hit = el('rect', { x: padLeft + index * band, y: padTop, width: band, height: plotHeight, class: 'hit' }, svg);
        const enter = () => {
          host.classList.add('is-hovering');
          path.classList.add('is-hot');
          showTooltip(host, tooltip, padLeft + index * band + band / 2, yTop, point.title || point.label, [
            { value: format(point.value), name: unit, color },
          ]);
        };
        hit.addEventListener('pointermove', enter);
        hit.addEventListener('pointerleave', () => { path.classList.remove('is-hot'); hideTooltip(host, tooltip); });
        hit.setAttribute('tabindex', '0');
        hit.addEventListener('focus', enter);
        hit.addEventListener('blur', () => hideTooltip(host, tooltip));
      });
    });
  }

  /* ──────────────────────────  Linha (evolução no tempo)  ────────────────── */

  function lineChart(host, options) {
    const points = options.points || [];
    if (points.length < 2) return emptyState(host, options.emptyText || 'Ainda não há histórico suficiente.');
    const tooltip = prepare(host);
    const format = options.format || fmt.int;
    const unit = options.unit || 'pedidos';

    responsive(host, (width) => {
      host.querySelectorAll('svg').forEach((node) => node.remove());
      const padLeft = 44;
      const padRight = 18;
      const padTop = 20;
      const axisBand = 28;
      const plotHeight = options.height || 210;
      const height = plotHeight + padTop + axisBand;
      const plotWidth = width - padLeft - padRight;
      const { max, ticks } = niceScale(Math.max(...points.map((p) => p.value)));
      const stepX = plotWidth / Math.max(1, points.length - 1);
      const color = cssVar('--series-1', '#2A78D6');
      const surface = cssVar('--surface', '#fff');

      const x = (index) => padLeft + index * stepX;
      const y = (value) => padTop + plotHeight - (max > 0 ? (value / max) * plotHeight : 0);

      const svg = el('svg', {
        viewBox: `0 0 ${width} ${height}`, width, height, role: 'img',
        'aria-label': options.ariaLabel || 'Evolução ao longo do tempo',
      }, host);

      ticks.forEach((tick) => {
        const ty = y(tick);
        el('line', { x1: padLeft, x2: width - padRight, y1: ty, y2: ty, class: tick === 0 ? 'axis-line' : 'gridline' }, svg);
        text(svg, format(tick), { x: padLeft - 8, y: ty + 4, 'text-anchor': 'end', class: 'tick' });
      });

      // Área ao lavado (10 %) + linha de 2 px
      const line = points.map((point, index) => `${index ? 'L' : 'M'}${x(index)},${y(point.value)}`).join('');
      el('path', {
        d: `${line}L${x(points.length - 1)},${y(0)}L${x(0)},${y(0)}Z`,
        fill: color, opacity: .1,
      }, svg);
      el('path', {
        d: line, fill: 'none', stroke: color, 'stroke-width': 2,
        'stroke-linejoin': 'round', 'stroke-linecap': 'round', class: 'mark',
      }, svg);

      // Marcador no fim, com anel da cor da superfície
      const lastIndex = points.length - 1;
      el('circle', { cx: x(lastIndex), cy: y(points[lastIndex].value), r: 5, fill: color, stroke: surface, 'stroke-width': 2 }, svg);
      text(svg, format(points[lastIndex].value), {
        x: x(lastIndex) - 6, y: y(points[lastIndex].value) - 12, 'text-anchor': 'end', class: 'value-label',
      });

      // Datas: mostrar no máximo 7 marcas para não amontoar
      const stride = Math.max(1, Math.ceil(points.length / 7));
      points.forEach((point, index) => {
        if (index % stride && index !== lastIndex) return;
        text(svg, point.label, { x: x(index), y: height - 9, 'text-anchor': 'middle', class: 'tick' });
      });

      // Cruzeta: o ponteiro aponta a uma data, não a uma linha de 2 px
      const crosshair = el('line', {
        y1: padTop, y2: padTop + plotHeight, class: 'gridline', stroke: cssVar('--axis', '#c3c2b7'),
        'stroke-width': 1, opacity: 0,
      }, svg);
      const marker = el('circle', { r: 5, fill: color, stroke: surface, 'stroke-width': 2, opacity: 0 }, svg);

      const surfaceRect = el('rect', {
        x: padLeft - stepX / 2, y: padTop, width: plotWidth + stepX, height: plotHeight, class: 'hit',
      }, svg);

      const move = (event) => {
        const box = host.getBoundingClientRect();
        const localX = (event.clientX ?? box.left + padLeft) - box.left;
        const index = Math.max(0, Math.min(points.length - 1, Math.round((localX - padLeft) / stepX)));
        const point = points[index];
        crosshair.setAttribute('x1', x(index));
        crosshair.setAttribute('x2', x(index));
        crosshair.setAttribute('opacity', 1);
        marker.setAttribute('cx', x(index));
        marker.setAttribute('cy', y(point.value));
        marker.setAttribute('opacity', 1);
        const rows = [{ value: format(point.value), name: unit, color }];
        if (point.secondary != null) rows.push({ value: point.secondary, name: options.secondaryLabel || '' });
        showTooltip(host, tooltip, x(index), y(point.value), point.title || point.label, rows);
      };
      surfaceRect.addEventListener('pointermove', move);
      surfaceRect.addEventListener('pointerleave', () => {
        crosshair.setAttribute('opacity', 0);
        marker.setAttribute('opacity', 0);
        hideTooltip(host, tooltip);
      });
    });
  }

  /* ────────────────────────────  Vista em tabela  ────────────────────────── */

  function tableView(host, columnsDef, rows) {
    host.innerHTML = '';
    const table = document.createElement('table');
    const thead = document.createElement('thead');
    const headRow = document.createElement('tr');
    columnsDef.forEach((column) => {
      const th = document.createElement('th');
      th.textContent = column;
      headRow.appendChild(th);
    });
    thead.appendChild(headRow);
    const tbody = document.createElement('tbody');
    rows.forEach((row) => {
      const tr = document.createElement('tr');
      row.forEach((cell) => {
        const td = document.createElement('td');
        td.textContent = cell;
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });
    table.append(thead, tbody);
    host.appendChild(table);
  }

  global.MVCharts = { horizontalBars, columns, lineChart, tableView, fmt, niceScale, ordinalColor };
})(window);
