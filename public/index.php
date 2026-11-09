<?php
// public/index.php - local review page.
// Shows the last generated report and can regenerate it from Plex.

// One-user tool with no login: only answer requests from this machine.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Local access only.');
}

require dirname(__DIR__) . '/src/report.php';
require dirname(__DIR__) . '/src/rules.php';

$error = null;

// Config problems are shown on the page rather than breaking it.
$configError = null;
try {
    $config = config();
} catch (RuntimeException $e) {
    $config = null;
    $configError = $e->getMessage();
}

// Generate: POST, then redirect so a refresh doesn't regenerate.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    set_time_limit(0);
    try {
        saveReportCache(buildReport());
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?generated=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$cache = loadReportCache();
$rows  = $cache['rows'] ?? [];

// Verdicts are worked out on every page load, so config changes apply on reload.
if ($config) {
    $rows = applyRules($rows, $config);
} else {
    foreach ($rows as &$row) {
        $row += ['verdict' => 'none', 'rule' => null, 'reason' => 'Config has errors'];
    }
    unset($row);
}
$summary = summariseVerdicts($rows);

// CSV download of the saved report, with verdicts.
if (($_GET['format'] ?? '') === 'csv' && $cache) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="plex-report-' . date('Y-m-d', $cache['generated_at']) . '.csv"');
    writeReportCsv(fopen('php://output', 'w'), $rows);
    exit;
}

// Default order: largest first, matching the candidate ranking.
usort($rows, fn($a, $b) => $b['size_gb'] <=> $a['size_gb']);

$totals = [];
foreach ($rows as $r) {
    $t = &$totals[$r['type']];
    $t['count']    = ($t['count'] ?? 0) + 1;
    $t['gb']       = ($t['gb'] ?? 0) + $r['size_gb'];
    $t['unwatched'] = ($t['unwatched'] ?? 0) + ($r['last_viewed'] === '' ? 1 : 0);
    unset($t);
}

function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** "on"/"off" badge for a rule's enabled flag. */
function onOff(bool $on): string
{
    return $on ? '<span class="badge airing">on</span>' : '<span class="badge">off</span>';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Plex Cleanup</title>
<style>
  :root {
    color-scheme: dark;
    --bg: #111317;
    --surface: #1a1d23;
    --surface-2: #22262e;
    --border: #2e333d;
    --text: #e4e6eb;
    --muted: #8b929e;
    --accent: #e5a00d;
    --accent-text: #1a1206;
    --link: #f0b84a;
    --ok-bg: #15301f;   --ok-border: #2a6a40;   --ok-text: #8fdba8;
    --err-bg: #3a1618;  --err-border: #7a2c30;  --err-text: #f2a3a6;
    --badge-green: #1f3a2a; --badge-green-text: #8fdba8;
    --badge-blue: #1c2d45;  --badge-blue-text: #9cc3f5;
    --badge-grey: #2a2e36;  --badge-grey-text: #aab0bb;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    font: 14px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif;
    color: var(--text);
    background: var(--bg);
  }
  main { max-width: 1400px; margin: 0 auto; padding: 24px 16px 48px; }
  a { color: var(--link); text-decoration: none; }
  a:hover { text-decoration: underline; }
  .muted { color: var(--muted); }

  /* Header */
  header { display: flex; flex-wrap: wrap; gap: 12px 24px; align-items: center; justify-content: space-between; margin-bottom: 20px; }
  h1 { font-size: 22px; font-weight: 600; margin: 0; letter-spacing: .2px; }
  h1 span { color: var(--accent); }
  .actions { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }

  /* Controls */
  button, .button {
    font: inherit; font-weight: 600; cursor: pointer;
    padding: 8px 16px; border-radius: 6px; border: 1px solid transparent;
    background: var(--accent); color: var(--accent-text);
  }
  button:hover { filter: brightness(1.08); }
  button:disabled { cursor: progress; opacity: .7; }
  .button.secondary { background: var(--surface-2); color: var(--text); border-color: var(--border); font-weight: 500; }
  .button.secondary:hover { text-decoration: none; border-color: var(--muted); }
  input[type="search"], select {
    font: inherit; color: var(--text); background: var(--surface-2);
    border: 1px solid var(--border); border-radius: 6px; padding: 6px 10px;
  }
  input[type="search"] { width: 220px; }
  input:focus, select:focus, button:focus-visible, a:focus-visible { outline: 2px solid var(--accent); outline-offset: 1px; }
  input[type="checkbox"] { accent-color: var(--accent); }
  label { display: inline-flex; gap: 6px; align-items: center; color: var(--muted); }

  /* Messages */
  .msg { padding: 10px 14px; border-radius: 6px; border: 1px solid; margin: 0 0 16px; }
  .ok { background: var(--ok-bg); border-color: var(--ok-border); color: var(--ok-text); }
  .error { background: var(--err-bg); border-color: var(--err-border); color: var(--err-text); }

  /* Summary cards */
  .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-bottom: 20px; }
  .card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 14px 16px; }
  .card h2 { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .6px; color: var(--muted); margin: 0 0 6px; }
  .card .big { font-size: 24px; font-weight: 600; }
  .card .sub { color: var(--muted); font-size: 13px; }

  /* Filters */
  .filters { display: flex; flex-wrap: wrap; gap: 10px 20px; align-items: center; margin-bottom: 12px; }
  #f-count { margin-left: auto; }

  /* Table */
  .wrap { overflow: auto; max-height: calc(100vh - 120px); background: var(--surface); border: 1px solid var(--border); border-radius: 8px; }
  table { border-collapse: collapse; width: 100%; }
  th, td { padding: 8px 12px; text-align: left; white-space: nowrap; }
  th {
    position: sticky; top: 0; z-index: 1;
    background: var(--surface-2); color: var(--muted);
    font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px;
    border-bottom: 1px solid var(--border); cursor: pointer; user-select: none;
  }
  th:hover { color: var(--text); }
  th[data-dir] { color: var(--accent); }
  th[data-dir="asc"]::after { content: " ▲"; }
  th[data-dir="desc"]::after { content: " ▼"; }
  td { border-bottom: 1px solid var(--border); }
  tbody tr:nth-child(even) td { background: rgba(255, 255, 255, .015); }
  tbody tr:hover td { background: var(--surface-2); }
  td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
  td.size { font-weight: 600; }
  .ago { display: block; font-size: 12px; color: var(--muted); }
  td.title { white-space: normal; min-width: 220px; font-weight: 500; }
  td.title a { color: var(--text); }
  td.title a:hover { color: var(--link); }

  /* Badges */
  .badge { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 12px; font-weight: 500; background: var(--badge-grey); color: var(--badge-grey-text); }
  .badge.movie { background: var(--badge-blue); color: var(--badge-blue-text); }
  .badge.show { background: var(--badge-green); color: var(--badge-green-text); }
  .badge.airing { background: var(--badge-green); color: var(--badge-green-text); }
  .badge.v-candidate { background: var(--accent); color: var(--accent-text); font-weight: 600; }
  .badge.v-protected { background: var(--badge-blue); color: var(--badge-blue-text); }
  .badge.v-keep { background: var(--badge-green); color: var(--badge-green-text); }
  .badge.v-queued { background: var(--err-bg); color: var(--err-text); }
  td.verdict { white-space: normal; min-width: 200px; }
  td.verdict .ago { margin-top: 2px; }

  /* Rules panel */
  .rules { margin-bottom: 20px; padding: 0; }
  .rules summary { cursor: pointer; padding: 12px 16px; font-weight: 600; list-style: none; display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
  .rules summary::-webkit-details-marker { display: none; }
  .rules summary > span:first-child::before { content: "▸"; color: var(--muted); margin-right: 8px; }
  .rules[open] summary > span:first-child::before { content: "▾"; }
  .rules summary .muted { font-weight: 400; }
  .rules .body { padding: 0 16px 14px; overflow-x: auto; }
  .rules table td, .rules table th { position: static; background: none; cursor: default; padding: 6px 12px 6px 0; border-bottom: 1px solid var(--border); }
  .rules table th:hover { color: var(--muted); }
  .rules .note { margin: 12px 0 0; color: var(--muted); font-size: 13px; }
  code { font-family: ui-monospace, Consolas, monospace; font-size: 12px; background: var(--surface-2); padding: 1px 5px; border-radius: 4px; }
  .card.highlight { border-color: var(--accent); }
  .card.highlight .big { color: var(--accent); }

  .empty { background: var(--surface); border: 1px dashed var(--border); border-radius: 8px; padding: 40px; text-align: center; color: var(--muted); }

  @media (max-width: 600px) {
    input[type="search"] { width: 100%; }
    #f-count { margin-left: 0; }
  }
</style>
</head>
<body>

<main>

<header>
  <h1>Plex <span>Cleanup</span></h1>
  <div class="actions">
    <?php if ($cache): ?>
      <span class="muted">Last generated <?= e(date('jS M Y, H:i', $cache['generated_at'])) ?></span>
      <a class="button secondary" href="?format=csv">Download CSV</a>
    <?php endif; ?>
    <form method="post" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Generating… (about a minute)';">
      <input type="hidden" name="action" value="generate">
      <button type="submit"><?= $cache ? 'Regenerate report' : 'Generate report' ?></button>
    </form>
  </div>
</header>

<?php if ($configError): ?>
  <p class="msg error" style="white-space: pre-line">config.php has problems, so no rules were applied:
<?= e($configError) ?></p>
<?php endif; ?>
<?php if ($error): ?>
  <p class="msg error">Report generation failed: <?= e($error) ?></p>
<?php elseif (isset($_GET['generated'])): ?>
  <p class="msg ok">Report generated.</p>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="empty">No report yet. Use <strong>Generate report</strong> to read the Plex library.</div>
<?php else: ?>
  <section class="stats">
    <?php foreach ($totals as $type => $t): ?>
      <div class="card">
        <h2><?= e(ucfirst($type)) ?>s</h2>
        <div class="big"><?= gb($t['gb']) ?></div>
        <div class="sub"><?= number_format($t['count']) ?> titles · <?= number_format($t['unwatched']) ?> never watched</div>
      </div>
    <?php endforeach; ?>
    <?php $v = $summary['verdicts']; ?>
    <div class="card highlight">
      <h2>Candidates</h2>
      <div class="big"><?= gb($v['candidate']['gb']) ?></div>
      <div class="sub"><?= number_format($v['candidate']['count']) ?> titles · <?= $v['protected']['count'] ?> protected · <?= $v['queued']['count'] ?> queued · <?= $v['keep']['count'] ?> kept</div>
    </div>
  </section>

  <?php if ($config): $c = $config['rules']; ?>
  <details class="card rules" open>
    <summary>
      <span>Rules</span>
      <span class="muted">Edit <code>config.php</code> and reload to apply changes</span>
    </summary>
    <div class="body">
      <table>
        <thead><tr><th>Rule</th><th>Status</th><th>Flags a title when</th><th class="num">Candidates</th><th class="num">Size</th></tr></thead>
        <tbody>
          <tr>
            <td><?= e(RULE_LABELS['unwatched']) ?></td>
            <td><?= onOff($c['unwatched']['enabled']) ?></td>
            <td>Movie or show nobody has watched, added more than <?= $c['unwatched']['min_added_months'] ?> months ago</td>
            <td class="num"><?= $summary['rules']['unwatched']['count'] ?></td>
            <td class="num"><?= gb($summary['rules']['unwatched']['gb']) ?></td>
          </tr>
          <tr>
            <td><?= e(RULE_LABELS['movie_watched']) ?></td>
            <td><?= onOff($c['movie_watched']['enabled']) ?></td>
            <td>Movie watched at most <?= $c['movie_watched']['max_plays'] === 1 ? 'once' : $c['movie_watched']['max_plays'] . ' times' ?>, last watched more than <?= $c['movie_watched']['min_last_viewed_months'] ?> months ago, added more than <?= $c['movie_watched']['min_added_months'] ?> months ago</td>
            <td class="num"><?= $summary['rules']['movie_watched']['count'] ?></td>
            <td class="num"><?= gb($summary['rules']['movie_watched']['gb']) ?></td>
          </tr>
          <tr>
            <td><?= e(RULE_LABELS['show_watched']) ?></td>
            <td><?= onOff($c['show_watched']['enabled']) ?></td>
            <td>Show with no episode watched by anyone for more than <?= $c['show_watched']['min_last_viewed_months'] ?> months, added more than <?= $c['show_watched']['min_added_months'] ?> months ago</td>
            <td class="num"><?= $summary['rules']['show_watched']['count'] ?></td>
            <td class="num"><?= gb($summary['rules']['show_watched']['gb']) ?></td>
          </tr>
        </tbody>
      </table>
      <p class="note">
        <strong>Shows are protected</strong> (never candidates) when
        <?= implode('; ', array_filter([
            $config['tmdb']['protect_in_production'] ? 'TMDB says still in production' : null,
            $config['tmdb']['protected_statuses'] ? 'TMDB status is ' . e(implode(', ', $config['tmdb']['protected_statuses'])) : null,
            $config['tmdb']['protect_unknown'] ? 'there is no TMDB status' : null,
        ])) ?: 'never' ?>.
        Titles labelled <code><?= e($config['labels']['keep']) ?></code> are never considered.
        Queued titles have a <?= $config['countdown_days'] ?>-day countdown.
        Nothing is queued or deleted from this page yet: candidates are for review only.
      </p>
    </div>
  </details>
  <?php endif; ?>

  <div class="filters">
    <label>Verdict
      <select id="f-verdict">
        <option value="">All</option>
        <?php foreach (VERDICTS as $key => $label): ?>
          <option value="<?= e($key) ?>"><?= e($label) ?> (<?= $summary['verdicts'][$key]['count'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Type
      <select id="f-type">
        <option value="">All</option>
        <option value="movie">Movies</option>
        <option value="show">Shows</option>
      </select>
    </label>
    <label><input type="checkbox" id="f-unwatched"> Never watched only</label>
    <label>Search <input type="search" id="f-search" placeholder="Title"></label>
    <span class="muted" id="f-count"></span>
  </div>

  <div class="wrap">
  <table id="report">
    <thead>
      <tr>
        <th data-key="title">Title</th>
        <th data-key="verdict">Verdict</th>
        <th data-key="type">Type</th>
        <th data-key="year" class="num">Year</th>
        <th data-key="size" class="num" data-dir="desc">Size</th>
        <th data-key="added">Added</th>
        <th data-key="viewed">Last watched</th>
        <th data-key="plays" class="num">Plays</th>
        <th data-key="users" class="num">Users</th>
        <th data-key="eps" class="num">Episodes watched</th>
        <th data-key="status">TMDB status</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
        $isShow = $r['type'] === 'show';
        $tmdbUrl = $r['tmdb_id'] !== '' ? 'https://www.themoviedb.org/' . ($isShow ? 'tv' : 'movie') . '/' . $r['tmdb_id'] : null;
        $epsPct = $isShow && (int) $r['episodes_total'] > 0 ? $r['episodes_watched'] / $r['episodes_total'] : -1;
    ?>
      <tr data-type="<?= e($r['type']) ?>"
          data-v="<?= e($r['verdict']) ?>"
          data-verdict="<?= array_search($r['verdict'], array_keys(VERDICTS), true) ?>"
          data-title="<?= e(mb_strtolower($r['title'])) ?>"
          data-year="<?= e($r['year']) ?>"
          data-size="<?= e($r['size_gb']) ?>"
          data-added="<?= e($r['added']) ?>"
          data-viewed="<?= e($r['last_viewed']) ?>"
          data-plays="<?= e($r['view_count']) ?>"
          data-users="<?= e($r['users_watched']) ?>"
          data-eps="<?= e($epsPct) ?>"
          data-status="<?= e($r['tmdb_status']) ?>">
        <td class="title"><?= $tmdbUrl ? '<a href="' . e($tmdbUrl) . '" target="_blank" rel="noopener">' . e($r['title']) . '</a>' : e($r['title']) ?></td>
        <td class="verdict">
          <?php if ($r['verdict'] !== 'none'): ?><span class="badge v-<?= e($r['verdict']) ?>"><?= e(VERDICTS[$r['verdict']]) ?></span><?php endif; ?>
          <span class="ago"><?= e($r['reason']) ?></span>
        </td>
        <td><span class="badge <?= e($r['type']) ?>"><?= e($r['type']) ?></span></td>
        <td class="num"><?= e($r['year']) ?></td>
        <td class="num size"><?= gb((float) $r['size_gb']) ?></td>
        <td><?= e(niceDate($r['added'])) ?></td>
        <td><?php if ($r['last_viewed'] !== ''): ?><?= e(niceDate($r['last_viewed'])) ?> <span class="ago"><?= e(ago($r['last_viewed'])) ?></span><?php else: ?><span class="muted">never</span><?php endif; ?></td>
        <td class="num"><?= e($r['view_count']) ?></td>
        <td class="num"><?= e($r['users_watched']) ?></td>
        <td class="num"><?= $isShow ? e($r['episodes_watched'] . ' / ' . $r['episodes_total']) : '' ?></td>
        <td><?php if ($r['tmdb_status'] !== ''): ?><span class="badge<?= $r['tmdb_in_production'] === 'yes' ? ' airing' : '' ?>"><?= e($r['tmdb_status']) ?></span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

</main>

<script>
(() => {
  const table = document.getElementById('report');
  if (!table) return;
  const tbody = table.tBodies[0];
  const rows = Array.from(tbody.rows);
  const numeric = new Set(['year', 'size', 'plays', 'users', 'eps', 'verdict']);
  const ascFirst = new Set(['verdict']); // candidates first on the first click

  // Sorting: click a header; click again to reverse.
  table.tHead.addEventListener('click', ev => {
    const th = ev.target.closest('th');
    if (!th) return;
    const key = th.dataset.key;
    const dir = th.dataset.dir === 'desc' ? 'asc' : (th.dataset.dir === 'asc' ? 'desc' : (numeric.has(key) && !ascFirst.has(key) ? 'desc' : 'asc'));
    table.querySelectorAll('th').forEach(h => delete h.dataset.dir);
    th.dataset.dir = dir;
    const sign = dir === 'asc' ? 1 : -1;
    rows.sort((a, b) => {
      let x = a.dataset[key], y = b.dataset[key];
      if (numeric.has(key)) {
        x = parseFloat(x) || 0; y = parseFloat(y) || 0;
        // Ties (e.g. same verdict) fall back to size, largest first.
        return (x - y) * sign || (parseFloat(b.dataset.size) - parseFloat(a.dataset.size));
      }
      return x.localeCompare(y) * sign;
    });
    tbody.append(...rows);
  });

  // Filtering.
  const fVerdict = document.getElementById('f-verdict');
  const fType = document.getElementById('f-type');
  const fUnwatched = document.getElementById('f-unwatched');
  const fSearch = document.getElementById('f-search');
  const fCount = document.getElementById('f-count');
  function filter() {
    const verdict = fVerdict.value, type = fType.value, unwatched = fUnwatched.checked, q = fSearch.value.trim().toLowerCase();
    let shown = 0;
    for (const r of rows) {
      const ok = (!verdict || r.dataset.v === verdict)
        && (!type || r.dataset.type === type)
        && (!unwatched || r.dataset.viewed === '')
        && (!q || r.dataset.title.includes(q));
      r.hidden = !ok;
      if (ok) shown++;
    }
    fCount.textContent = `${shown} of ${rows.length} shown`;
  }
  [fVerdict, fType, fUnwatched].forEach(el => el.addEventListener('change', filter));
  fSearch.addEventListener('input', filter);
  filter();
})();
</script>
</body>
</html>
