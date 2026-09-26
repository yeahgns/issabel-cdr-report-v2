<?php
require dirname(__FILE__) . '/lib/bootstrap.php';
$cfg = app_config();
app_require_auth();
header('Content-Type: text/html; charset=UTF-8');
$company = htmlspecialchars($cfg['company'], ENT_QUOTES, 'UTF-8');
$v = '20260925';
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Relatório de ligações<?php echo $company !== '' ? ' - ' . $company : ''; ?></title>
  <link rel="icon" href="assets/logo.svg" type="image/svg+xml">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/app.css?v=<?php echo $v; ?>">
</head>
<body>
  <div id="splash" aria-hidden="true"><img src="assets/logo.svg" alt=""></div>

  <header class="topbar">
    <div class="brand">
      <div>
        <h1>Relatório de ligações</h1>
        <p class="brand-sub"><?php echo $company !== '' ? $company . ' · ' : ''; ?><span id="range-label">Hoje</span></p>
      </div>
    </div>

    <div class="period" role="group" aria-label="Período">
      <div class="seg" id="presets">
        <button type="button" data-preset="today">Hoje</button>
        <button type="button" data-preset="yesterday">Ontem</button>
        <button type="button" data-preset="7d">7 dias</button>
        <button type="button" data-preset="30d">30 dias</button>
        <button type="button" data-preset="month">Este mês</button>
      </div>
      <div class="dates">
        <label class="sr-only" for="date-from">De</label>
        <input type="date" id="date-from">
        <span aria-hidden="true">até</span>
        <label class="sr-only" for="date-to">Até</label>
        <input type="date" id="date-to">
      </div>
    </div>

    <div class="top-actions">
      <label class="live" title="Atualiza a cada 30 segundos quando o período inclui hoje">
        <input type="checkbox" id="live" checked>
        <span class="live-dot" aria-hidden="true"></span>
        <span>Ao vivo</span>
      </label>
      <button type="button" class="btn ghost" id="tech-toggle" aria-pressed="false" hidden>Visão técnica</button>
      <div class="menu">
        <button type="button" class="btn" id="export-btn" aria-haspopup="true" aria-expanded="false">Exportar</button>
        <div class="menu-pop" id="export-menu" hidden>
          <button type="button" data-export="calls"><strong>Uma linha por ligação</strong><span>O resumo que o cliente vê na tela</span></button>
          <button type="button" data-export="legs"><strong>Detalhado por ramal</strong><span>Cada ramal que tocou, com resultado e tempos</span></button>
        </div>
      </div>
    </div>
  </header>

  <main>
    <section class="overview" aria-label="Resumo do período">
      <div class="overview-main">
        <dl class="metrics" id="metrics"></dl>
        <div class="dist" id="dist" aria-hidden="true"></div>
        <p class="overview-foot" id="overview-foot"></p>
      </div>
      <figure class="chart" id="chart">
        <figcaption id="chart-title">Recebidas por hora</figcaption>
        <div id="chart-body"></div>
      </figure>
    </section>

    <div class="callback-alert" id="callback-alert" hidden>
      <span class="callback-icon" aria-hidden="true"></span>
      <p id="callback-text"></p>
      <button type="button" class="btn small" id="callback-show">Ver quem ligou</button>
    </div>

    <nav class="tabs" role="tablist">
      <button type="button" role="tab" data-tab="calls" aria-selected="true">Ligações <span class="count" id="count-calls"></span></button>
      <button type="button" role="tab" data-tab="depts" aria-selected="false">Departamentos</button>
      <button type="button" role="tab" data-tab="agents" aria-selected="false">Atendentes</button>
    </nav>

    <section class="view" id="view-calls" role="tabpanel">
      <div class="filters">
        <div class="search">
          <label class="sr-only" for="f-q">Buscar</label>
          <input type="search" id="f-q" placeholder="Buscar número, nome ou ramal" autocomplete="off">
        </div>
        <div class="seg small" id="f-dir" role="group" aria-label="Tipo de ligação">
          <button type="button" data-dir="" aria-pressed="true">Todas</button>
          <button type="button" data-dir="in" aria-pressed="false">Recebidas</button>
          <button type="button" data-dir="out" aria-pressed="false">Feitas</button>
          <button type="button" data-dir="int" aria-pressed="false">Internas</button>
        </div>
        <label class="sr-only" for="f-status">Situação</label>
        <select id="f-status">
          <option value="">Todas as situações</option>
          <option value="answered">Atendidas</option>
          <option value="lost">Perdidas</option>
          <option value="open">Perdidas sem retorno</option>
          <option value="ivr">Encerradas na URA</option>
          <option value="voicemail">Caixa postal</option>
          <option value="noanswer">Feitas e não atendidas</option>
          <option value="busy">Ocupado</option>
        </select>
        <label class="sr-only" for="f-dept">Departamento</label>
        <select id="f-dept"><option value="">Todos os departamentos</option></select>
        <label class="sr-only" for="f-agent">Atendente</label>
        <select id="f-agent"><option value="">Todos os atendentes</option></select>
        <label class="check"><input type="checkbox" id="f-rec"> Com gravação</label>
        <button type="button" class="link" id="f-clear" hidden>Limpar filtros</button>
      </div>

      <div class="calls" id="calls" role="table" aria-label="Ligações">
        <div class="calls-head" role="row">
          <span role="columnheader" class="c-chev"></span>
          <span role="columnheader" class="c-when"><button type="button" class="sort" data-sort="date">Quando</button></span>
          <span role="columnheader" class="c-dir"><span class="sr-only">Tipo</span></span>
          <span role="columnheader" class="c-party">Contato</span>
          <span role="columnheader" class="c-dest">Destino</span>
          <span role="columnheader" class="c-wait"><button type="button" class="sort" data-sort="wait">Espera</button></span>
          <span role="columnheader" class="c-talk"><button type="button" class="sort" data-sort="talk">Conversa</button></span>
          <span role="columnheader" class="c-status">Situação</span>
          <span role="columnheader" class="c-rec"><span class="sr-only">Gravação</span></span>
        </div>
        <div id="calls-body"></div>
      </div>
      <nav class="pager" id="pager" aria-label="Páginas"></nav>
    </section>

    <section class="view" id="view-depts" role="tabpanel" hidden></section>
    <section class="view" id="view-agents" role="tabpanel" hidden></section>

    <p class="foot" id="foot"></p>
  </main>

  <div class="toast" id="toast" role="status" aria-live="polite"></div>
  <script src="assets/app.js?v=<?php echo $v; ?>"></script>
</body>
</html>
