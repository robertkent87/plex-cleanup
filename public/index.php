<?php
// public/index.php - local review page.
// Shows the last generated report and can regenerate it from Plex.

// One-user tool with no login: only answer requests from this machine.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Local access only.');
}

require dirname(__DIR__) . '/src/report.php';

$error = null;

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

// CSV download of the saved report.
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

function gb(float $v): string
{
    return $v >= 1024 ? number_format($v / 1024, 2) . ' TB' : number_format($v, 1) . ' GB';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Plex Cleanup</title>
<style>
  body { font: 14px/1.4 system-ui, sans-serif; margin: 16px; color: #222; background: #fff; }
  h1 { font-size: 20px; margin: 0 0 8px; }
  .bar { display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: center; margin: 12px 0; }
  .muted { color: #666; }
  .error { background: #fde8e8; border: 1px solid #e0a0a0; padding: 8px 12px; }
  .ok { background: #e8f5e8; border: 1px solid #a0d0a0; padding: 8px 12px; }
  .stats span { margin-right: 16px; }
  table { border-collapse: collapse; width: 100%; }
  th, td { padding: 4px 8px; border-bottom: 1px solid #ddd; text-align: left; white-space: nowrap; }
  th { position: sticky; top: 0; background: #f4f4f4; cursor: pointer; user-select: none; }
  th[data-dir="asc"]::after { content: " ▲"; }
  th[data-dir="desc"]::after { content: " ▼"; }
  td.num, th.num { text-align: right; }
  td.title { white-space: normal; min-width: 200px; }
  tr:hover td { background: #fafafa; }
  .wrap { overflow-x: auto; }
</style>
</head>
<body>

<h1>Plex Cleanup</h1>

<?php if ($error): ?>
  <p class="error">Report generation failed: <?= e($error) ?></p>
<?php elseif (isset($_GET['generated'])): ?>
  <p class="ok">Report generated.</p>
<?php endif; ?>

<div class="bar">
  <form method="post" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Generating… (this can take a few minutes)';">
    <input type="hidden" name="action" value="generate">
    <button type="submit"><?= $cache ? 'Regenerate report' : 'Generate report' ?></button>
  </form>
  <?php if ($cache): ?>
    <span class="muted">Last generated <?= e(date('Y-m-d H:i', $cache['generated_at'])) ?></span>
    <a href="?format=csv">Download CSV</a>
  <?php else: ?>
    <span class="muted">No report yet.</span>
  <?php endif; ?>
</div>

<?php if ($rows): ?>
  <p class="stats">
    <?php foreach ($totals as $type => $t): ?>
      <span><strong><?= e(ucfirst($type)) ?>s:</strong> <?= $t['count'] ?> · <?= gb($t['gb']) ?> · <?= $t['unwatched'] ?> never watched</span>
    <?php endforeach; ?>
  </p>

  <div class="bar">
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
        <td><?= e($r['type']) ?></td>
        <td class="num"><?= e($r['year']) ?></td>
        <td class="num"><?= gb((float) $r['size_gb']) ?></td>
        <td><?= e($r['added']) ?></td>
        <td><?= $r['last_viewed'] !== '' ? e($r['last_viewed']) : '<span class="muted">never</span>' ?></td>
        <td class="num"><?= e($r['view_count']) ?></td>
        <td class="num"><?= e($r['users_watched']) ?></td>
        <td class="num"><?= $isShow ? e($r['episodes_watched'] . ' / ' . $r['episodes_total']) : '' ?></td>
        <td><?= e($r['tmdb_status']) ?><?= $r['tmdb_in_production'] === 'yes' ? ' <span class="muted">(in production)</span>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<script>
(() => {
  const table = document.getElementById('report');
  if (!table) return;
  const tbody = table.tBodies[0];
  const rows = Array.from(tbody.rows);
  const numeric = new Set(['year', 'size', 'plays', 'users', 'eps']);

  // Sorting: click a header; click again to reverse.
  table.tHead.addEventListener('click', ev => {
    const th = ev.target.closest('th');
    if (!th) return;
    const key = th.dataset.key;
    const dir = th.dataset.dir === 'desc' ? 'asc' : (th.dataset.dir === 'asc' ? 'desc' : (numeric.has(key) ? 'desc' : 'asc'));
    table.querySelectorAll('th').forEach(h => delete h.dataset.dir);
    th.dataset.dir = dir;
    const sign = dir === 'asc' ? 1 : -1;
    rows.sort((a, b) => {
      let x = a.dataset[key], y = b.dataset[key];
      if (numeric.has(key)) { x = parseFloat(x) || 0; y = parseFloat(y) || 0; return (x - y) * sign; }
      return x.localeCompare(y) * sign;
    });
    tbody.append(...rows);
  });

  // Filtering.
  const fType = document.getElementById('f-type');
  const fUnwatched = document.getElementById('f-unwatched');
  const fSearch = document.getElementById('f-search');
  const fCount = document.getElementById('f-count');
  function filter() {
    const type = fType.value, unwatched = fUnwatched.checked, q = fSearch.value.trim().toLowerCase();
    let shown = 0;
    for (const r of rows) {
      const ok = (!type || r.dataset.type === type)
        && (!unwatched || r.dataset.viewed === '')
        && (!q || r.dataset.title.includes(q));
      r.hidden = !ok;
      if (ok) shown++;
    }
    fCount.textContent = `${shown} of ${rows.length} shown`;
  }
  [fType, fUnwatched].forEach(el => el.addEventListener('change', filter));
  fSearch.addEventListener('input', filter);
  filter();
})();
</script>
</body>
</html>
