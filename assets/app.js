(function () {
  'use strict';

  var API = 'api.php';
  var LIVE_MS = 30000;

  var S = {
    from: '', to: '', preset: 'today',
    q: '', dir: '', status: '', dept: '', agent: '', rec: false,
    page: 1, sort: 'date', order: 'desc',
    tech: false, tab: 'calls',
    open: {}, raw: {}, data: null, busy: false, first: true, seq: 0
  };

  var $ = function (id) { return document.getElementById(id); };
  var qsa = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function parseDate(s) { var p = s.split(/[- :]/); return new Date(+p[0], p[1] - 1, +p[2], +(p[3] || 0), +(p[4] || 0), +(p[5] || 0)); }

  function phone(n) {
    var d = String(n || '').replace(/\D/g, '');
    if (!d) return 'Número oculto';
    if (/^0?800/.test(d)) { d = d.replace(/^0(?=800)/, ''); return d.slice(0, 4) + ' ' + d.slice(4, 7) + ' ' + d.slice(7); }
    if (d.length >= 12 && d.slice(0, 2) === '55') d = d.slice(2);
    if (d.length === 13 && d[0] === '0') d = d.slice(3);          // 0 + operadora + DDD + número
    else if ((d.length === 12 || d.length === 11) && d[0] === '0') d = d.slice(1); // 0 + DDD + número
    if (d.length === 11) return '(' + d.slice(0, 2) + ') ' + d.slice(2, 7) + '-' + d.slice(7);
    if (d.length === 10) return '(' + d.slice(0, 2) + ') ' + d.slice(2, 6) + '-' + d.slice(6);
    if (d.length === 9) return d.slice(0, 5) + '-' + d.slice(5);
    if (d.length === 8) return d.slice(0, 4) + '-' + d.slice(4);
    return d;
  }

  function dur(s) {
    if (s == null) return '—';
    s = Math.max(0, Math.round(s));
    if (s < 60) return s + 's';
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), r = s % 60;
    if (h) return h + 'h ' + pad(m) + 'min';
    return m + 'min' + (r ? ' ' + pad(r) + 's' : '');
  }
  function clock(s) {
    if (s == null) return '—';
    s = Math.max(0, Math.round(s));
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), r = s % 60;
    return h ? h + ':' + pad(m) + ':' + pad(r) : m + ':' + pad(r);
  }
  function hm(dateStr) { var d = parseDate(dateStr); return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
  function dm(d) { return pad(d.getDate()) + '/' + pad(d.getMonth() + 1); }
  var WEEK = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
  var MONTHS = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

  function person(p) {
    if (!p || !p.ext) return '';
    if (p.external) return phone(p.ext);
    return p.name ? p.name + ' (' + p.ext + ')' : 'Ramal ' + p.ext;
  }
  function firstName(p) {
    if (!p || !p.ext) return '';
    if (p.external) return phone(p.ext);
    return p.name ? p.name.split(' ')[0] + ' (' + p.ext + ')' : 'ramal ' + p.ext;
  }
  function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

  function statusInfo(c) {
    var out = c.direction !== 'in';
    switch (c.status) {
      case 'answered': return { label: 'Atendida', cls: 'st-answered' };
      case 'missed': return c.direction === 'in' ? { label: 'Perdida', cls: 'st-missed' } : { label: 'Não atendida', cls: 'st-out-noanswer' };
      case 'ivr': return { label: 'Ficou na URA', cls: 'st-ivr' };
      case 'voicemail': return { label: 'Caixa postal', cls: 'st-voicemail' };
      case 'noanswer': return { label: 'Não atendeu', cls: out ? 'st-out-noanswer' : 'st-noanswer' };
      case 'busy': return { label: 'Ocupado', cls: out ? 'st-out-busy' : 'st-busy' };
      case 'failed': return { label: 'Não completou', cls: 'st-out-failed' };
    }
    return { label: c.status, cls: '' };
  }

  function reasonText(c) {
    if (c.status === 'ivr') return 'Desligou no menu';
    if (c.status === 'voicemail') return 'Deixou recado na caixa postal';
    if (c.reason === 'nobody') return 'Desligou antes de tocar em algum ramal';
    if (c.reason === 'busy') return 'Todos os ramais estavam ocupados';
    var n = 0;
    c.steps.forEach(function (s) { n += s.members.length; });
    return n ? 'Tocou em ' + plural(n, 'ramal', 'ramais') + ', ninguém atendeu' : 'Ninguém atendeu';
  }

  function callbackText(cb, short) {
    if (!cb) return '';
    if (cb.state === 'open') {
      if (short) return 'Sem retorno';
      return cb.tries ? 'Sem retorno: ' + plural(cb.tries, 'tentativa', 'tentativas') + ' sem sucesso' : 'Ainda sem retorno';
    }
    var at = hm(cb.at);
    if (cb.how === 'returned') {
      if (short) return 'Retornada ' + at;
      return 'Retornada às ' + at + (cb.by ? ' por ' + person(cb.by) : '') + ', ' + dur(cb.after) + ' depois';
    }
    if (short) return 'Ligou de novo ' + at;
    return 'Cliente ligou de novo às ' + at + ' e foi atendido' + (cb.by ? ' por ' + person(cb.by) : '');
  }

  var ICONS = {
    in: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M17 7L7 17M7 9v8h8"/></svg>',
    out: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17L17 7M9 7h8v8"/></svg>',
    int: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9h14l-4-4M20 15H6l4 4"/></svg>',
    rec: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/></svg>'
  };
  var DIR_NAME = { in: 'Recebida', out: 'Feita', int: 'Interna' };

  function presetRange(p) {
    var t = new Date(), f = new Date();
    if (p === 'yesterday') { t.setDate(t.getDate() - 1); f = new Date(t); }
    else if (p === '7d') { f.setDate(f.getDate() - 6); }
    else if (p === '30d') { f.setDate(f.getDate() - 29); }
    else if (p === 'month') { f = new Date(t.getFullYear(), t.getMonth(), 1); }
    return [ymd(f), ymd(t)];
  }

  function readUrl() {
    var u = new URLSearchParams(location.search);
    S.preset = u.get('p') || (u.get('from') ? '' : 'today');
    if (S.preset) { var r = presetRange(S.preset); S.from = r[0]; S.to = r[1]; }
    else { S.from = u.get('from') || ymd(new Date()); S.to = u.get('to') || S.from; }
    ['q', 'dir', 'status', 'dept', 'agent'].forEach(function (k) { S[k] = u.get(k) || ''; });
    S.rec = u.get('rec') === '1';
    S.tech = u.get('tech') === '1';
    S.tab = u.get('tab') || 'calls';
    S.sort = u.get('sort') || 'date';
    S.order = u.get('order') || 'desc';
    S.page = +(u.get('page') || 1);
  }

  function writeUrl() {
    var u = new URLSearchParams();
    if (S.preset) { if (S.preset !== 'today') u.set('p', S.preset); }
    else { u.set('from', S.from); u.set('to', S.to); }
    ['q', 'dir', 'status', 'dept', 'agent'].forEach(function (k) { if (S[k]) u.set(k, S[k]); });
    if (S.rec) u.set('rec', '1');
    if (S.tech) u.set('tech', '1');
    if (S.tab !== 'calls') u.set('tab', S.tab);
    if (S.sort !== 'date' || S.order !== 'desc') { u.set('sort', S.sort); u.set('order', S.order); }
    if (S.page > 1) u.set('page', S.page);
    var qs = u.toString();
    history.replaceState(null, '', location.pathname + (qs ? '?' + qs : ''));
  }

  function apiParams(extra) {
    var u = new URLSearchParams({ from: S.from, to: S.to, q: S.q, dir: S.dir, status: S.status, dept: S.dept, agent: S.agent });
    if (S.rec) u.set('rec', '1');
    if (S.tech) u.set('tech', '1');
    u.set('page', S.page); u.set('sort', S.sort); u.set('order', S.order);
    Object.keys(extra || {}).forEach(function (k) { u.set(k, extra[k]); });
    return u.toString();
  }

  function load(opts) {
    opts = opts || {};
    var my = ++S.seq;
    S.busy = true;
    writeUrl();
    if (!opts.quiet) $('calls-body').insertAdjacentHTML('afterbegin', '<div class="loading-bar"></div>');
    fetch(API + '?' + apiParams({ action: 'calls' }), { credentials: 'same-origin' })
      .then(function (r) {
        return r.json().then(function (j) {
          if (!r.ok) throw new Error(j && j.error ? j.error : 'Erro ' + r.status);
          return j;
        });
      })
      .then(function (data) {
        if (my !== S.seq) return;
        S.data = data;
        S.page = data.page;
        render();
      })
      .catch(function (e) {
        if (my !== S.seq) return;
        $('calls-body').innerHTML = '<div class="empty"><b>Não foi possível carregar as ligações</b>' + esc(e.message) + '</div>';
      })
      .then(function () {
        if (my !== S.seq) return;
        S.busy = false;
        if (S.first) {
          S.first = false;
          $('splash').classList.add('gone');
          setTimeout(function () { $('splash').hidden = true; }, 500);
        }
      });
  }

  function reset(page) { S.page = page || 1; S.open = {}; load(); }

  function render() {
    var d = S.data;
    renderControls();
    renderOverview(d.stats, d);
    renderChart(d.stats.series);
    renderCallbackAlert(d.stats.summary);
    renderFacets(d.facets);
    renderCalls(d.calls);
    renderPager(d.page, d.pages);
    renderDepts(d.stats);
    renderAgents(d.stats);
    $('count-calls').textContent = d.total;
    $('tech-toggle').hidden = !d.features.tech;
    var now = parseDate(d.generatedAt);
    $('foot').textContent = (d.demo ? 'Modo demonstração: dados fictícios. ' : '') + 'Atualizado às ' + pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds()) + '.';
    updateLive();
  }

  function rangeLabel() {
    var f = parseDate(S.from), t = parseDate(S.to), today = ymd(new Date());
    var y = new Date(); y.setDate(y.getDate() - 1);
    var one = function (d) { return WEEK[d.getDay()] + ', ' + d.getDate() + ' de ' + MONTHS[d.getMonth()]; };
    if (S.from === S.to) {
      if (S.from === today) return 'Hoje, ' + one(f);
      if (S.from === ymd(y)) return 'Ontem, ' + one(f);
      return one(f) + (f.getFullYear() !== new Date().getFullYear() ? ' de ' + f.getFullYear() : '');
    }
    return dm(f) + ' a ' + dm(t) + (t.getFullYear() !== new Date().getFullYear() ? '/' + t.getFullYear() : '');
  }

  function renderControls() {
    qsa('#presets button').forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.preset === S.preset)); });
    $('date-from').value = S.from;
    $('date-to').value = S.to;
    $('range-label').textContent = rangeLabel();
    $('f-q').value = S.q;
    $('f-q').classList.toggle('active', !!S.q);
    qsa('#f-dir button').forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.dir === S.dir)); });
    $('f-status').value = S.status;
    $('f-status').classList.toggle('active', !!S.status);
    $('f-rec').checked = S.rec;
    $('f-clear').hidden = !(S.q || S.dir || S.status || S.dept || S.agent || S.rec);
    $('tech-toggle').setAttribute('aria-pressed', String(S.tech));
    qsa('.tabs button').forEach(function (b) { b.setAttribute('aria-selected', String(b.dataset.tab === S.tab)); });
    ['calls', 'depts', 'agents'].forEach(function (t) { $('view-' + t).hidden = t !== S.tab; });
    qsa('.sort').forEach(function (b) {
      b.classList.toggle('asc', b.dataset.sort === S.sort && S.order === 'asc');
      b.classList.toggle('desc', b.dataset.sort === S.sort && S.order === 'desc');
    });
  }

  function metric(label, value, small, note, cls) {
    return '<div class="metric ' + (cls || '') + '"><dt>' + label + '</dt><dd>' + value +
      (small ? '<small>' + small + '</small>' : '') + '</dd>' + (note ? note : '') + '</div>';
  }

  function renderOverview(st, d) {
    var s = st.summary, html, bar = '', foot = '';
    if (S.dir === 'out') {
      var miss = s.made - s.madeAnswered;
      html = metric('Feitas', s.made) +
        metric('Atendidas', s.madeAnswered, s.made ? Math.round(100 * s.madeAnswered / s.made) + '%' : '', '', 'ok') +
        metric('Não atendidas', miss, '', '<span class="note">ocupado, não atendeu ou falhou</span>') +
        metric('Conversa média', dur(s.avgTalkOut)) +
        metric('Recebidas', s.received, '', '<span class="note">no mesmo período</span>');
      if (s.made) bar = '<span class="d-ok" style="width:' + (100 * s.madeAnswered / s.made) + '%"></span>';
      foot = '<b>' + s.madeAnswered + '</b> atendidas de <b>' + s.made + '</b> ligações feitas.';
    } else if (S.dir === 'int') {
      html = metric('Internas', s.internal) + metric('Recebidas', s.received) + metric('Feitas', s.made) + metric('', '') + metric('', '');
      foot = 'Ligações entre ramais.';
    } else {
      var lostNote = s.lost
        ? (s.open ? '<button type="button" class="note" data-go="open">' + s.open + ' sem retorno</button>' : '<span class="note">todas retornadas</span>')
        : '<span class="note">nenhuma</span>';
      html = metric('Recebidas', s.received, '', '<span class="note">' + s.made + ' feitas · ' + s.internal + ' internas</span>') +
        metric('Atendidas', s.answered, s.answerRate != null ? s.answerRate + '%' : '',
          s.serviceLevel != null ? '<span class="note" title="Atendidas em até ' + st.serviceLevelSeconds + ' segundos na fila">' + s.serviceLevel + '% em até ' + st.serviceLevelSeconds + 's</span>' : '', 'ok') +
        metric('Perdidas', s.lost, '', lostNote, 'lost' + (s.lost ? '' : ' zero')) +
        metric('Espera média', dur(s.avgWait), '', s.maxWait ? '<span class="note">maior: ' + dur(s.maxWait) + '</span>' : '') +
        metric('Conversa média', dur(s.avgTalk), '', '<span class="note">nas recebidas</span>');
      var tot = s.answered + s.lost + s.ivr;
      if (tot) {
        bar = '<span class="d-ok" style="width:' + (100 * s.answered / tot) + '%"></span>' +
          '<span class="d-lost" style="width:' + (100 * s.lost / tot) + '%"></span>' +
          '<span class="d-ivr" style="width:' + (100 * s.ivr / tot) + '%"></span>';
      }
      foot = tot
        ? '<span class="legend-dot" style="background:var(--ok)"></span><b>' + s.answered + '</b> atendidas &nbsp; ' +
          '<span class="legend-dot" style="background:var(--lost)"></span><b>' + s.lost + '</b> perdidas' +
          (s.voicemail ? ' (' + s.voicemail + ' na caixa postal)' : '') + ' &nbsp; ' +
          '<span class="legend-dot" style="background:#E8B864"></span><b>' + s.ivr + '</b> desligaram na URA' +
          (s.avgLostWait ? ' &nbsp;·&nbsp; quem desistiu esperou em média <b>' + dur(s.avgLostWait) + '</b>' : '')
        : 'Nenhuma ligação recebida no período.';
    }
    $('metrics').innerHTML = html;
    $('dist').innerHTML = bar;
    $('overview-foot').innerHTML = foot;
  }

  var lastSeries = null, resizeT = null;
  window.addEventListener('resize', function () {
    clearTimeout(resizeT);
    resizeT = setTimeout(function () { if (lastSeries) renderChart(lastSeries); }, 150);
  });

  function renderChart(series) {
    lastSeries = series;
    var pts = series.points.slice(), hourly = series.granularity === 'hour';
    var key = S.dir === 'out' ? 'out' : null;
    if (hourly) {
      var lo = 8, hi = 18;
      pts.forEach(function (p, i) { if (p.answered + p.lost + p.other + p.out) { lo = Math.min(lo, i); hi = Math.max(hi, i); } });
      pts = pts.slice(lo, hi + 1);
    }
    $('chart-title').innerHTML = '<span>' + (key ? 'Feitas' : 'Recebidas') + (hourly ? ' por hora' : ' por dia') + '</span>' +
      (key ? '' : '<span><span class="legend-dot" style="background:var(--ok)"></span>atendidas <span class="legend-dot" style="background:var(--lost);margin-left:8px"></span>perdidas</span>');
    var W = Math.max(280, Math.round($('chart-body').clientWidth || 600)), H = 150, top = 8, bottom = 20, left = 26;
    var max = 1;
    pts.forEach(function (p) { max = Math.max(max, key ? p.out : p.answered + p.lost + p.other); });
    var nice = max <= 5 ? 5 : max <= 10 ? 10 : Math.ceil(max / 10) * 10;
    var n = pts.length, slot = (W - left) / Math.max(1, n), bw = Math.max(3, Math.min(34, slot * 0.62));
    var y = function (v) { return top + (H - top - bottom) * (1 - v / nice); };
    var svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" width="' + W + '" height="' + H + '" role="img" aria-label="Gráfico de ligações">';
    [0, 0.5, 1].forEach(function (f) {
      var v = Math.round(nice * f), yy = y(v);
      svg += '<line class="grid" x1="' + left + '" x2="' + W + '" y1="' + yy + '" y2="' + yy + '"/>' +
        '<text class="axis" x="' + (left - 5) + '" y="' + (yy + 3) + '" text-anchor="end">' + v + '</text>';
    });
    var every = hourly ? (n > 14 ? 2 : 1) : Math.ceil(n / 10);
    pts.forEach(function (p, i) {
      var x = left + slot * i + (slot - bw) / 2, base = H - bottom, tip, label;
      if (hourly) { label = p.label + 'h'; }
      else { var dd = parseDate(p.label); label = dm(dd); }
      tip = label + ': ' + (key ? p.out + ' feitas' : p.answered + ' atendidas, ' + p.lost + ' perdidas' + (p.other ? ', ' + p.other + ' na URA' : ''));
      svg += '<g class="col"><title>' + esc(tip) + '</title><rect class="hit" x="' + (left + slot * i) + '" y="' + top + '" width="' + slot + '" height="' + (H - top - bottom) + '" fill="transparent"/>';
      var stack = key ? [['bar-ok', p.out]] : [['bar-ok', p.answered], ['bar-lost', p.lost], ['bar-ivr', p.other]];
      var acc = 0;
      stack.forEach(function (sg) {
        if (!sg[1]) return;
        var y0 = y(acc), y1 = y(acc + sg[1]);
        svg += '<rect class="' + sg[0] + '" x="' + x + '" y="' + y1 + '" width="' + bw + '" height="' + Math.max(1, y0 - y1) + '" rx="1.5"/>';
        acc += sg[1];
      });
      svg += '</g>';
      if (i % every === 0) svg += '<text class="axis" x="' + (x + bw / 2) + '" y="' + (H - 5) + '" text-anchor="middle">' + label + '</text>';
    });
    $('chart-body').innerHTML = svg + '</svg>';
  }

  function renderCallbackAlert(s) {
    var show = s.open > 0 && S.status !== 'open' && S.dir !== 'out' && S.dir !== 'int';
    $('callback-alert').hidden = !show;
    if (show) {
      $('callback-text').innerHTML = '<b>' + plural(s.open, 'ligação perdida', 'ligações perdidas') + '</b> ainda sem retorno. ' +
        'Ninguém ligou de volta e o cliente não voltou a ser atendido.';
    }
  }

  function fillSelect(sel, items, value, allLabel, fmt) {
    var html = '<option value="">' + allLabel + '</option>', found = false;
    items.forEach(function (it) {
      var v = typeof it === 'string' ? it : it.ext;
      if (v === value) found = true;
      html += '<option value="' + esc(v) + '">' + esc(fmt ? fmt(it) : it) + '</option>';
    });
    if (value && !found) html += '<option value="' + esc(value) + '">' + esc(value) + '</option>';
    sel.innerHTML = html;
    sel.value = value;
    sel.classList.toggle('active', !!value);
  }

  function renderFacets(f) {
    fillSelect($('f-dept'), f.departments, S.dept, 'Todos os departamentos');
    fillSelect($('f-agent'), f.agents, S.agent, 'Todos os atendentes', function (a) { return a.name ? a.name + ' (' + a.ext + ')' : 'Ramal ' + a.ext; });
  }

  function renderCalls(calls) {
    if (!calls.length) {
      var filtered = S.q || S.dir || S.status || S.dept || S.agent || S.rec;
      $('calls-body').innerHTML = '<div class="empty"><b>Nenhuma ligação encontrada</b>' +
        (filtered ? 'Nenhuma ligação combina com os filtros. <button type="button" class="link" data-clear>Limpar filtros</button>' : 'Não há ligações registradas neste período.') + '</div>';
      return;
    }
    var multi = S.from !== S.to;
    $('calls-body').innerHTML = calls.map(function (c) { return callHtml(c, multi); }).join('');
    calls.forEach(function (c) { if (S.open[c.id]) mountDetail(c); });
  }

  function callHtml(c, multi) {
    var d = parseDate(c.date), st = statusInfo(c);
    var when = '<b>' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + '</b><span>' + (multi ? WEEK[d.getDay()] + ' ' + dm(d) : pad(d.getSeconds()) + 's') + '</span>';
    if (!multi) when = '<b>' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + '</b><span>' + WEEK[d.getDay()] + ' ' + dm(d) + '</span>';

    var party, partySub = [];
    if (c.direction === 'in') {
      party = c.party.name || phone(c.party.number);
      if (c.party.name) partySub.push(phone(c.party.number));
      if (c.party.lineName) partySub.push(c.party.lineName);
      else if (c.party.line) partySub.push('Linha ' + phone(c.party.line));
    } else if (c.direction === 'out') {
      party = phone(c.party.number);
      partySub.push('Ligação feita');
    } else {
      party = c.party.name || 'Ramal ' + c.party.number;
      partySub.push('Ramal ' + c.party.number);
    }
    var repeat = c.repeat > 1 ? '<span class="repeat" title="Ligou ' + c.repeat + ' vezes no período">' + c.repeat + 'x</span>' : '';

    var dest, destSub = '';
    if (c.direction === 'in') {
      dest = c.department || (c.steps.length ? c.steps[c.steps.length - 1].label : '—');
      if (c.status === 'answered') {
        destSub = c.answeredBy.map(person).join(' → ');
      } else {
        destSub = '<span class="also">' + esc(reasonText(c)) + '</span>';
      }
      if (c.status === 'answered') destSub = esc(destSub);
    } else {
      dest = person(c.from) || c.from.ext || '—';
      destSub = esc(c.direction === 'int' ? 'Ligou para ramal ' + c.party.number : (c.department || 'Ramal ' + c.from.ext));
    }

    var sub = '';
    if (c.callback && c.direction === 'in') {
      sub = '<span class="sub ' + c.callback.state + '">' + esc(callbackText(c.callback, true)) + '</span>';
    } else if (c.transferred) {
      sub = '<span class="sub">Transferida</span>';
    }

    var waitCls = c.wait == null ? 'muted' : (c.wait > 60 ? 'warn' : '');
    var open = !!S.open[c.id];
    return '<div class="call' + (open ? ' open' : '') + '" data-id="' + esc(c.id) + '" role="rowgroup">' +
      '<button type="button" class="call-row" role="row" aria-expanded="' + open + '" aria-controls="d-' + esc(c.id) + '">' +
      '<span class="c-chev" role="cell"><span class="chev"></span></span>' +
      '<span class="c-when when" role="cell">' + when + '</span>' +
      '<span class="c-dir" role="cell"><span class="dir-ic dir-' + c.direction + '" title="' + DIR_NAME[c.direction] + '">' + ICONS[c.direction] + '</span></span>' +
      '<span class="c-party party" role="cell"><b>' + esc(party) + repeat + '</b><span>' + esc(partySub.join(' · ')) + '</span></span>' +
      '<span class="c-dest dest" role="cell"><b>' + esc(dest) + '</b><span>' + destSub + '</span></span>' +
      '<span class="c-wait num ' + waitCls + '" role="cell">' + (c.direction === 'in' ? clock(c.wait) : '—') + '</span>' +
      '<span class="c-talk num' + (c.talk ? '' : ' muted') + '" role="cell">' + (c.talk ? clock(c.talk) : '—') + '</span>' +
      '<span class="c-status status-cell" role="cell"><span class="pill ' + st.cls + '">' + st.label + '</span>' + sub + '</span>' +
      '<span class="c-rec rec-ic" role="cell">' + (c.recording ? '<span title="Tem gravação">' + ICONS.rec + '</span>' : '') + '</span>' +
      '</button></div>';
  }

  function findCall(id) {
    var list = S.data ? S.data.calls : [];
    for (var i = 0; i < list.length; i++) if (list[i].id === id) return list[i];
    return null;
  }

  function toggle(id) {
    var el = document.querySelector('.call[data-id="' + cssEsc(id) + '"]');
    var c = findCall(id);
    if (!el || !c) return;
    if (S.open[id]) {
      delete S.open[id];
      el.classList.remove('open');
      el.querySelector('.call-row').setAttribute('aria-expanded', 'false');
      var dd = el.querySelector('.detail');
      if (dd) dd.remove();
    } else {
      S.open[id] = true;
      mountDetail(c);
    }
  }

  function cssEsc(s) { return window.CSS && CSS.escape ? CSS.escape(s) : String(s).replace(/["\\]/g, '\\$&'); }

  function mountDetail(c) {
    var el = document.querySelector('.call[data-id="' + cssEsc(c.id) + '"]');
    if (!el) return;
    el.classList.add('open');
    el.querySelector('.call-row').setAttribute('aria-expanded', 'true');
    var old = el.querySelector('.detail');
    if (old) old.remove();
    el.insertAdjacentHTML('beforeend', detailHtml(c));
  }

  function journeyHtml(c) {
    var chips = [], arrow = '<span class="arrow" aria-hidden="true">→</span>';
    var chip = function (cls, text, em) {
      return '<span class="j ' + cls + '"><span class="j-dot"></span>' + esc(text) + (em ? ' <em>' + esc(em) + '</em>' : '') + '</span>';
    };
    if (c.direction === 'in') {
      chips.push(chip('', c.party.lineName || (c.party.line ? 'Linha ' + phone(c.party.line) : 'Entrou pela linha')));
    } else {
      chips.push(chip('', person(c.from) || 'Ramal ' + c.from.ext));
    }
    var answeredShown = 0;
    c.steps.forEach(function (s) {
      if (s.type === 'ivr') chips.push(chip('ivr', s.label, dur(s.dur)));
      else if (s.type === 'queue') chips.push(chip('', s.label, 'fila'));
      else if (s.type === 'group') chips.push(chip('', s.label, 'grupo'));
      else if (s.type === 'voicemail') chips.push(chip('vm', 'Caixa postal', dur(s.dur)));
      else if (s.type === 'external' && c.direction === 'in') chips.push(chip('', 'Desviada para ' + phone(s.target)));
      else if (s.type === 'ext' && c.direction === 'in' && answeredShown) chips.push(chip('', 'Transferida para ' + s.label));
      if (s.members.some(function (m) { return m.result === 'answered'; })) answeredShown++;
    });
    if (c.direction === 'out') chips.push(chip('', phone(c.party.number)));
    if (c.direction === 'int') chips.push(chip('', c.party.name || 'Ramal ' + c.party.number));

    var st = statusInfo(c);
    if (c.status === 'answered') {
      if (c.direction === 'in') chips.push(chip('ok', 'Atendida por ' + c.answeredBy.map(firstName).join(', depois ')));
      else chips.push(chip('ok', 'Atendeu'));
    } else if (c.direction === 'in' && c.status !== 'ivr') {
      chips.push(chip('bad', c.status === 'voicemail' ? 'Ninguém atendeu' : reasonText(c)));
    } else {
      chips.push(chip(c.status === 'ivr' ? 'ivr' : 'bad', st.label));
    }
    return '<div class="journey">' + chips.join(arrow) + '</div>';
  }

  function timelineHtml(c) {
    var total = Math.max(1, c.total), firstAns = null, lastRing = 0, hasMembers = false;
    c.steps.forEach(function (s) {
      if (s.type === 'ivr') lastRing = Math.max(lastRing, s.at + s.dur);
      s.members.forEach(function (m) {
        hasMembers = true;
        if (m.answeredAt != null && (firstAns == null || m.answeredAt < firstAns)) firstAns = m.answeredAt;
        m.tries.forEach(function (t) { lastRing = Math.max(lastRing, t.s + t.r); });
      });
      if (!s.members.length) lastRing = Math.max(lastRing, s.at + Math.min(s.dur, 600));
    });
    var view = total;
    if (firstAns != null) view = Math.min(total, Math.max(lastRing, firstAns) + Math.max(15, (firstAns) * 0.4));
    view = Math.max(view, 10);
    var clipped = view < total;
    var pct = function (v) { return Math.max(0, Math.min(100, 100 * v / view)); };
    var seg = function (cls, a, len, title) {
      if (len <= 0 && cls !== 'busy') return '';
      var more = a + len > view ? ' more' : '';
      return '<span class="tl-seg ' + cls + more + '" style="left:' + pct(a) + '%;width:' + (pct(a + len) - pct(a)) + '%" title="' + esc(title) + '"></span>';
    };

    var rows = '';
    c.steps.forEach(function (s) {
      if (s.type === 'ivr') {
        rows += '<div class="tl-label"><b>' + esc(s.label) + '</b><span>URA</span></div><div class="tl-track">' +
          seg('ivr', s.at, s.dur, 'No menu por ' + dur(s.dur)) + '</div><div class="tl-res">' + dur(s.dur) + '</div>';
        return;
      }
      if (s.type === 'voicemail') {
        rows += '<div class="tl-label"><b>Caixa postal</b></div><div class="tl-track">' + seg('vm', s.at, s.dur, 'Caixa postal') +
          '</div><div class="tl-res">' + dur(s.dur) + '</div>';
        return;
      }
      if (s.type === 'queue' || s.type === 'group') {
        rows += '<div class="tl-label group">' + esc(s.label) + (s.type === 'queue' ? ' · fila' : ' · grupo') + '</div>';
        if (!s.members.length) {
          rows += '<div class="tl-label"><b>Aguardando</b><span>nenhum ramal tocou</span></div><div class="tl-track">' +
            seg('wait', s.at, s.dur, 'Na fila por ' + dur(s.dur)) + '</div><div class="tl-res bad">' + dur(s.dur) + ' na fila</div>';
        }
      }
      s.members.forEach(function (m) {
        var name = m.external ? phone(m.ext) : (m.name || 'Ramal ' + m.ext);
        var tag = m.external ? (c.direction === 'out' ? 'destino' : 'externo') : m.ext;
        var track = '';
        m.tries.forEach(function (t) {
          if (t.d === 'BUSY' && !t.r) { track += seg('busy', t.s, 0.001, 'Ocupado'); return; }
          track += seg('ring', t.s, t.r, 'Chamando por ' + dur(t.r));
          if (t.t) track += seg('talk', t.s + t.r, t.t, 'Em conversa por ' + dur(t.t));
        });
        if (m.answeredAt != null) track += '<span class="tl-marker" style="left:' + pct(m.answeredAt) + '%"></span>';
        var res, cls = '';
        if (m.result === 'answered') { res = 'Atendeu após ' + dur(m.answeredAt - m.firstAt); cls = 'ok'; }
        else if (m.result === 'busy') { res = 'Ocupado'; cls = 'bad'; }
        else if (m.result === 'failed') { res = 'Indisponível'; cls = 'bad'; }
        else { res = 'Tocou ' + dur(m.ring) + (m.attempts > 1 ? ' · ' + m.attempts + 'x' : '') + ', não atendeu'; }
        rows += '<div class="tl-label"><b>' + esc(name) + '</b><span>' + esc(tag) + '</span></div><div class="tl-track">' + track +
          '</div><div class="tl-res ' + cls + '">' + esc(res) + '</div>';
      });
    });
    if (!rows) return '';

    var steps = [5, 10, 15, 20, 30, 60, 120, 300, 600, 900, 1800, 3600], step = steps[steps.length - 1];
    for (var i = 0; i < steps.length; i++) { if (view / steps[i] <= 6) { step = steps[i]; break; } }
    var axis = '';
    for (var t = 0; t <= view; t += step) axis += '<span style="left:' + pct(t) + '%">' + clock(t) + '</span>';

    var note = clipped ? '<p class="tl-note">A linha do tempo mostra os primeiros ' + dur(view) +
      ' para dar zoom na espera. A ligação durou ' + dur(total) + ' no total.</p>' : '';
    return '<div class="timeline"><div class="tl-head"><h3>Quem recebeu a ligação</h3><div class="tl-legend">' +
      '<span><i class="tl-seg ring" style="position:static;display:inline-block"></i>Chamando</span>' +
      '<span><i style="background:var(--ok)"></i>Em conversa</span>' +
      (c.steps.some(function (s) { return s.type === 'ivr'; }) ? '<span><i style="background:#E8B864"></i>No menu</span>' : '') +
      (hasMembers ? '<span><i style="background:var(--lost);width:4px"></i>Ocupado</span>' : '') +
      '</div></div><div class="tl-rows">' + rows + '</div>' +
      '<div class="tl-rows" aria-hidden="true"><span></span><div class="tl-axis">' + axis + '</div><span></span></div>' + note + '</div>';
  }

  function factsHtml(c) {
    var f = [];
    var fact = function (dt, dd, cls) { f.push('<div><dt>' + dt + '</dt><dd class="' + (cls || '') + '">' + dd + '</dd></div>'); };
    if (c.direction === 'in') {
      if (c.status !== 'ivr') fact(c.status === 'answered' ? 'Esperou para ser atendido' : 'Esperou até desistir', dur(c.wait));
      if (c.ivrTime) fact('No menu (URA)', dur(c.ivrTime));
    } else if (c.wait != null) {
      fact('Chamou por', dur(c.wait));
    }
    if (c.talk) fact('Conversa', dur(c.talk));
    fact('Duração total', dur(c.total));
    if (c.callback) fact('Retorno', esc(callbackText(c.callback, false)), c.callback.state);
    if (c.direction === 'in' && c.repeat > 1) fact('Este número ligou', c.repeat + ' vezes <small>no período</small>');
    if (c.trunk) fact('Tronco', esc(c.trunk));
    return '<dl class="facts">' + f.join('') + '</dl>';
  }

  function detailHtml(c) {
    var num = c.direction === 'int' ? '' : c.party.number;
    var actions = '<div class="actions">';
    if (c.recording) {
      actions += '<button type="button" class="btn small" data-play="' + esc(c.id) + '">Ouvir gravação</button>' +
        '<a class="btn ghost small" href="' + API + '?action=audio&download=1&id=' + encodeURIComponent(c.id) + '">Baixar gravação</a>';
    }
    if (num) {
      actions += '<button type="button" class="btn ghost small" data-number="' + esc(num) + '">Ver ligações deste número</button>' +
        '<button type="button" class="btn ghost small" data-copy="' + esc(num) + '">Copiar número</button>';
    }
    actions += '<span class="spacer"></span>';
    if (c.raw) actions += '<button type="button" class="btn ghost small" data-raw="' + esc(c.id) + '" aria-pressed="' + !!S.raw[c.id] + '">Registros do CDR (' + c.raw.length + ')</button>';
    actions += '</div>';
    return '<div class="detail" id="d-' + esc(c.id) + '">' + journeyHtml(c) + timelineHtml(c) + factsHtml(c) + actions +
      (c.raw && S.raw[c.id] ? rawHtml(c) : '') + '</div>';
  }

  function rawHtml(c) {
    var cols = ['calldate', 'src', 'dst', 'dcontext', 'channel', 'dstchannel', 'lastapp', 'disposition', 'duration', 'billsec', 'uniqueid', 'recordingfile', 'step'];
    var h = '<div class="raw-wrap"><table class="raw"><thead><tr>' + cols.map(function (k) { return '<th>' + (k === 'step' ? 'interpretado como' : k) + '</th>'; }).join('') + '</tr></thead><tbody>';
    c.raw.forEach(function (r) {
      h += '<tr>' + cols.map(function (k) {
        var v = r[k];
        if (k === 'calldate') v = String(v).slice(11);
        var cls = k === 'disposition' ? 'd-' + String(v).split(' ')[0] : (k === 'step' ? 'step' : '');
        return '<td class="' + cls + '">' + esc(v) + '</td>';
      }).join('') + '</tr>';
    });
    return h + '</tbody></table></div>';
  }

  function renderPager(page, pages) {
    if (pages <= 1) { $('pager').innerHTML = ''; return; }
    var h = '<button type="button" data-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + ' aria-label="Anterior">‹</button>';
    var set = {};
    [1, pages, page - 1, page, page + 1, page - 2, page + 2].forEach(function (p) { if (p >= 1 && p <= pages) set[p] = true; });
    var list = Object.keys(set).map(Number).sort(function (a, b) { return a - b; }), prev = 0;
    list.forEach(function (p) {
      if (p - prev > 1) h += '<span class="gap">…</span>';
      h += '<button type="button" data-page="' + p + '"' + (p === page ? ' aria-current="page"' : '') + '>' + p + '</button>';
      prev = p;
    });
    h += '<button type="button" data-page="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + ' aria-label="Próxima">›</button>';
    $('pager').innerHTML = h;
  }

  function rateCell(v) {
    if (v == null) return '<span class="zero">—</span>';
    return '<span class="rate"><span class="rate-bar"><i style="width:' + v + '%"></i></span>' + v + '%</span>';
  }
  function n0(v, bad) { return v ? (bad ? '<span class="bad">' + v + '</span>' : String(v)) : '<span class="zero">0</span>'; }

  function renderDepts(st) {
    var d = st.departments, sl = st.serviceLevelSeconds;
    var h = '<div class="panel"><h2>Recebidas por departamento</h2><p class="panel-sub">Departamento é a fila ou grupo onde a ligação foi atendida ou perdida. Clique numa linha para ver as ligações.</p>';
    if (!d.length) {
      h += '<div class="empty">Nenhuma ligação recebida no período.</div></div>';
    } else {
      h += '<div class="table-wrap"><table class="table"><thead><tr><th>Departamento</th><th>Recebidas</th><th>Atendidas</th><th>Perdidas</th>' +
        '<th>Sem retorno</th><th>Atendimento</th><th title="Atendidas em até ' + sl + 's">Em até ' + sl + 's</th><th>Espera média</th><th>Maior espera</th><th>Conversa média</th></tr></thead><tbody>';
      d.forEach(function (r) {
        var real = r.name !== 'Sem departamento';
        h += '<tr' + (real ? ' class="clickable" data-dept="' + esc(r.name) + '"' : '') + '><td><b>' + esc(real ? r.name : 'Não chegou a um departamento') + '</b>' +
          (real ? '' : '<span class="sub">desligou na URA</span>') + '</td><td>' + r.total + '</td><td>' + n0(r.answered) + '</td><td>' + n0(r.lost, true) +
          '</td><td>' + n0(r.open, true) + '</td><td>' + rateCell(r.answerRate) + '</td><td>' + (r.serviceLevel == null ? '—' : r.serviceLevel + '%') +
          '</td><td>' + dur(r.avgWait) + '</td><td>' + (r.maxWait ? dur(r.maxWait) : '—') + '</td><td>' + dur(r.avgTalk) + '</td></tr>';
      });
      h += '</tbody></table></div></div>';
    }
    var days = (parseDate(S.to) - parseDate(S.from)) / 864e5 + 1;
    if (days >= 5) h += heatHtml(st.heat);
    $('view-depts').innerHTML = h;
  }

  function heatHtml(heat) {
    var lo = 8, hi = 18, max = 1;
    Object.keys(heat).forEach(function (w) {
      Object.keys(heat[w]).forEach(function (h) { h = +h; lo = Math.min(lo, h); hi = Math.max(hi, h); max = Math.max(max, heat[w][h]); });
    });
    var cols = hi - lo + 1;
    var g = '<div class="heat-grid" style="--cols:' + cols + '"><span></span>';
    for (var h = lo; h <= hi; h++) g += '<span class="hl">' + h + 'h</span>';
    [1, 2, 3, 4, 5, 6, 0].forEach(function (w) {
      g += '<span class="dl">' + WEEK[w] + '</span>';
      for (var h = lo; h <= hi; h++) {
        var v = heat[w] && heat[w][h] ? heat[w][h] : 0;
        var a = v ? 0.12 + 0.88 * (v / max) : 0;
        g += '<span class="cell" title="' + WEEK[w] + ' ' + h + 'h: ' + plural(v, 'ligação', 'ligações') + '"' +
          (v ? ' style="background:rgba(31,147,255,' + a.toFixed(2) + ')"' : '') + '></span>';
      }
    });
    return '<div class="panel"><h2>Quando o telefone mais toca</h2><p class="panel-sub">Ligações recebidas por dia da semana e hora. Quanto mais forte o azul, mais ligações. Útil para montar a escala.</p>' +
      '<div class="heat">' + g + '</div></div></div>';
  }

  function renderAgents(st) {
    var a = st.agents;
    var h = '<div class="panel"><h2>Desempenho por atendente</h2><p class="panel-sub">"Tocou e ninguém atendeu" conta só as ligações que ficaram perdidas. ' +
      'Se um colega atendeu, não conta contra ninguém. Clique numa linha para ver as ligações do atendente.</p>';
    if (!a.length) {
      $('view-agents').innerHTML = h + '<div class="empty">Nenhum ramal recebeu ou fez ligações no período.</div></div>';
      return;
    }
    h += '<div class="table-wrap"><table class="table"><thead><tr><th>Atendente</th><th>Atendeu</th><th>Conversa total</th><th>Conversa média</th>' +
      '<th>Tempo para atender</th><th>Tocou e ninguém atendeu</th><th>Estava ocupado</th><th>Ligações feitas</th><th>Conversa nas feitas</th></tr></thead><tbody>';
    a.forEach(function (r) {
      h += '<tr class="clickable" data-agent="' + esc(r.ext) + '"><td><b>' + esc(r.name || 'Ramal ' + r.ext) + '</b><span class="sub">' + esc(r.ext) + '</span></td>' +
        '<td>' + n0(r.answered) + '</td><td>' + (r.talkIn ? dur(r.talkIn) : '—') + '</td><td>' + dur(r.avgTalk) + '</td><td>' + dur(r.avgRing) +
        '</td><td>' + n0(r.rangLost, true) + '</td><td>' + n0(r.busy) + '</td><td>' + (r.made ? r.made + '<span class="sub">' + r.madeAnswered + ' atendidas</span>' : '<span class="zero">0</span>') +
        '</td><td>' + (r.talkOut ? dur(r.talkOut) : '—') + '</td></tr>';
    });
    $('view-agents').innerHTML = h + '</tbody></table></div></div>';
  }

  var liveTimer = null;
  function includesToday() { var t = ymd(new Date()); return S.from <= t && S.to >= t; }
  function updateLive() {
    var active = $('live').checked && includesToday();
    document.querySelector('.live').classList.toggle('idle', !active);
    document.querySelector('.live').title = includesToday() ? 'Atualiza a cada 30 segundos' : 'Só atualiza quando o período inclui hoje';
  }
  function startLive() {
    clearInterval(liveTimer);
    liveTimer = setInterval(function () {
      if ($('live').checked && includesToday() && !S.busy && !document.hidden) load({ quiet: true });
    }, LIVE_MS);
  }

  function toast(msg) {
    var t = $('toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toast.t);
    toast.t = setTimeout(function () { t.classList.remove('show'); }, 2200);
  }

  function setFilter(k, v) { S[k] = v; reset(); }

  function bind() {
    qsa('#presets button').forEach(function (b) {
      b.addEventListener('click', function () {
        S.preset = b.dataset.preset;
        var r = presetRange(S.preset); S.from = r[0]; S.to = r[1];
        reset();
      });
    });
    ['date-from', 'date-to'].forEach(function (id) {
      $(id).addEventListener('change', function () {
        var f = $('date-from').value, t = $('date-to').value;
        if (!f || !t) return;
        if (t < f) { var x = f; f = t; t = x; }
        S.preset = ''; S.from = f; S.to = t;
        reset();
      });
    });

    var qTimer;
    $('f-q').addEventListener('input', function () {
      clearTimeout(qTimer);
      var v = this.value.trim();
      qTimer = setTimeout(function () { setFilter('q', v); }, 350);
    });
    qsa('#f-dir button').forEach(function (b) { b.addEventListener('click', function () { setFilter('dir', b.dataset.dir); }); });
    $('f-status').addEventListener('change', function () { setFilter('status', this.value); });
    $('f-dept').addEventListener('change', function () { setFilter('dept', this.value); });
    $('f-agent').addEventListener('change', function () { setFilter('agent', this.value); });
    $('f-rec').addEventListener('change', function () { setFilter('rec', this.checked); });
    $('f-clear').addEventListener('click', clearFilters);

    qsa('.sort').forEach(function (b) {
      b.addEventListener('click', function () {
        var k = b.dataset.sort;
        if (S.sort === k) S.order = S.order === 'desc' ? 'asc' : 'desc';
        else { S.sort = k; S.order = 'desc'; }
        reset();
      });
    });

    qsa('.tabs button').forEach(function (b) {
      b.addEventListener('click', function () { S.tab = b.dataset.tab; renderControls(); writeUrl(); });
    });

    $('tech-toggle').addEventListener('click', function () { S.tech = !S.tech; S.raw = {}; load(); });
    $('live').addEventListener('change', updateLive);

    $('export-btn').addEventListener('click', function (e) {
      e.stopPropagation();
      var open = $('export-menu').hidden;
      $('export-menu').hidden = !open;
      this.setAttribute('aria-expanded', String(open));
    });
    document.addEventListener('click', function () { $('export-menu').hidden = true; $('export-btn').setAttribute('aria-expanded', 'false'); });
    qsa('#export-menu button').forEach(function (b) {
      b.addEventListener('click', function () {
        location.href = API + '?' + apiParams({ action: 'export', mode: b.dataset.export });
      });
    });

    $('callback-show').addEventListener('click', function () { S.tab = 'calls'; S.dir = ''; setFilter('status', 'open'); });

    document.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target : e.target.parentNode;
      var row = t.closest('.call-row');
      if (row) { toggle(row.parentNode.dataset.id); return; }
      var el;
      if ((el = t.closest('[data-page]')) && !el.disabled) { reset(+el.dataset.page); window.scrollTo({ top: $('calls').offsetTop - 90, behavior: 'smooth' }); return; }
      if ((el = t.closest('[data-go="open"]'))) { S.tab = 'calls'; S.dir = ''; setFilter('status', 'open'); return; }
      if (t.closest('[data-clear]')) { clearFilters(); return; }
      if ((el = t.closest('[data-play]'))) {
        var audio = document.createElement('audio');
        audio.controls = true; audio.autoplay = true; audio.preload = 'auto';
        audio.src = API + '?action=audio&id=' + encodeURIComponent(el.dataset.play);
        audio.addEventListener('error', function () { toast('Não foi possível tocar a gravação. Tente baixar o arquivo.'); });
        el.replaceWith(audio);
        return;
      }
      if ((el = t.closest('[data-number]'))) {
        S.q = el.dataset.number; S.dir = ''; S.status = ''; S.dept = ''; S.agent = '';
        reset();
        return;
      }
      if ((el = t.closest('[data-copy]'))) {
        var v = el.dataset.copy;
        if (navigator.clipboard) navigator.clipboard.writeText(v).then(function () { toast('Número copiado'); });
        else toast(v);
        return;
      }
      if ((el = t.closest('[data-raw]'))) {
        var id = el.dataset.raw;
        S.raw[id] = !S.raw[id];
        var c = findCall(id);
        if (c) mountDetail(c);
        return;
      }
      if ((el = t.closest('tr[data-dept]'))) { S.tab = 'calls'; S.dept = el.dataset.dept; S.agent = ''; S.dir = 'in'; reset(); return; }
      if ((el = t.closest('tr[data-agent]'))) { S.tab = 'calls'; S.agent = el.dataset.agent; S.dept = ''; reset(); return; }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') $('export-menu').hidden = true;
    });
  }

  function clearFilters() { S.q = ''; S.dir = ''; S.status = ''; S.dept = ''; S.agent = ''; S.rec = false; reset(); }

  readUrl();
  bind();
  renderControls();
  load();
  startLive();
})();
