<?php
// public/index.php - local review page.
// Shows the report with verdicts, and queues or keeps titles (preview first).

// One-user tool with no login: only answer requests from this machine.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Local access only.');
}

require dirname(__DIR__) . '/src/delete.php';

// Every form carries a session token, so another website open in the browser
// can't submit changes to this page.
session_start();
$_SESSION['token'] ??= bin2hex(random_bytes(16));
$token = $_SESSION['token'];
$self  = strtok($_SERVER['REQUEST_URI'], '?');

$error   = null;
$preview = null;   // queue/keep/sync plan to show for confirmation
$deletePreview = null;  // deletion plan to show for confirmation
$trashPreview  = null;  // trash batches to show for confirmation
$flash   = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Config problems are shown on the page rather than breaking it.
$configError = null;
try {
    $config = config();
} catch (RuntimeException $e) {
    $config = null;
    $configError = $e->getMessage();
}

// Mode switch: changes can be turned on from the banner for live_mode_minutes, for this
// browser session only (the session cookie ends when the browser closes). config.php's
// dry_run is the default and is never edited by the page.
$liveUntil = (int) ($_SESSION['live_until'] ?? 0);
if ($liveUntil && $liveUntil <= time()) {
    unset($_SESSION['live_until']);   // expired: back to the default
    $liveUntil = 0;
}
if ($config) {
    if ($liveUntil) {
        dryRunOverride(false);
    } elseif (!empty($_SESSION['force_dry'])) {
        dryRunOverride(true);
    }
    $config = config();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($token, (string) ($_POST['token'] ?? ''))) {
        http_response_code(400);
        exit('This form has expired. Go back, reload the page and try again.');
    }
    set_time_limit(0);
    $action = $_POST['action'] ?? '';
    $op     = (string) ($_POST['op'] ?? '');
    $keys   = array_values(array_filter(explode(',', (string) ($_POST['keys'] ?? '')), 'ctype_digit'));

    if ($config && $action === 'mode_live') {
        $minutes = $config['live_mode_minutes'];
        $_SESSION['live_until'] = time() + $minutes * 60;
        unset($_SESSION['force_dry']);
        $_SESSION['flash'] = ['ok', "Changes are on for $minutes minutes. Every action still shows a preview first."];
        header("Location: $self");
        exit;
    }
    if ($config && $action === 'mode_dry') {
        unset($_SESSION['live_until']);
        $_SESSION['force_dry'] = true;
        $_SESSION['flash'] = ['ok', 'Back to dry run: actions only show previews.'];
        header("Location: $self");
        exit;
    }

    try {
        if ($action === 'generate') {
            // Generate, then redirect so a refresh doesn't regenerate.
            saveReportCache(buildReport());
            $_SESSION['flash'] = ['ok', 'Report generated.'];
            header("Location: $self");
            exit;
        }
        if (!$config) {
            throw new RuntimeException('Fix config.php before queueing or keeping titles.');
        }
        $typed = trim((string) ($_POST['confirm'] ?? ''));
        if ($action === 'delete_preview') {
            $deletePreview = buildDeletionPlan($keys) + ['keys' => $keys];
        } elseif ($action === 'delete_apply') {
            // Re-plan from Plex's current state; the typed number must match what will be moved.
            $plan = buildDeletionPlan($keys);
            $n = count($plan['items']);
            if ($n === 0 || $typed !== (string) $n) {
                $deletePreview = $plan + ['keys' => $keys];
                throw new RuntimeException("Type $n to confirm moving $n title" . ($n === 1 ? '' : 's') . ' to the trash.');
            }
            $result = applyDeletionPlan($plan);
            $msg = 'Moved ' . count($result['ok']) . ' title' . (count($result['ok']) === 1 ? '' : 's') . ' (' . gb($result['bytes'] / 1024 ** 3) . ') to the trash. Plex has been asked to rescan.';
            foreach ($result['failed'] as $f) {
                $msg .= "\nProblem: {$f['title']}: {$f['error']}";
            }
            $_SESSION['flash'] = [$result['failed'] ? 'error' : 'ok', $msg];
            header("Location: $self");
            exit;
        } elseif ($action === 'trash_preview') {
            $trashPreview = trashBatches();
        } elseif ($action === 'trash_apply') {
            $ready = array_filter(trashBatches(), fn($b) => $b['can_empty']);
            $n = count($ready);
            if ($n === 0 || $typed !== (string) $n) {
                $trashPreview = trashBatches();
                throw new RuntimeException("Type $n to confirm permanently deleting $n trash batch" . ($n === 1 ? '' : 'es') . '.');
            }
            $done = emptyTrash();
            $_SESSION['flash'] = ['ok', 'Permanently deleted ' . count($done['batches']) . ' trash batch' . (count($done['batches']) === 1 ? '' : 'es') . ', freeing ' . gb($done['bytes'] / 1024 ** 3) . '.'];
            header("Location: $self");
            exit;
        } elseif ($action === 'preview') {
            $preview = buildPlan($op, $keys) + ['keys' => $keys];
        } elseif ($action === 'apply') {
            // Rebuild the plan from Plex's current state rather than trusting the preview.
            $plan   = buildPlan($op, $keys);
            $result = applyPlan($plan);
            $done   = ['queue' => 'Queued', 'keep' => 'Kept', 'reconcile' => 'Synced labels for'][$op];
            $msg    = "$done " . count($result['ok']) . ' title' . (count($result['ok']) === 1 ? '' : 's') . '.';
            foreach ($result['failed'] as $f) {
                $msg .= "\nFailed: {$f['title']}: {$f['error']}";
            }
            $_SESSION['flash'] = [$result['failed'] ? 'error' : 'ok', $msg];
            header("Location: $self");
            exit;
        }
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

// Trash summary (files moved off the library, not yet permanently deleted).
$trash = null;
$trashTotals = ['bytes' => 0, 'ready' => 0];
if ($config) {
    try {
        $trash = trashBatches();
        foreach ($trash as $b) {
            $trashTotals['bytes'] += $b['bytes'];
            $trashTotals['ready'] += $b['can_empty'] ? $b['bytes'] : 0;
        }
    } catch (Throwable $e) {
        $trash = null; // share unreachable: leave the card out
    }
}

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
  .badge.v-due { background: #b3261e; color: #fff; font-weight: 600; }

  /* Mode banner and viewport outline */
  .mode-banner { padding: 10px 16px; text-align: center; font-size: 14px; position: sticky; top: 0; z-index: 20; }
  .mode-banner .hint { display: inline-block; margin-left: 8px; opacity: .85; }
  .mode-banner code { background: rgba(0, 0, 0, .25); }
  .mode-banner.dry { background: var(--accent); color: var(--accent-text); }
  .mode-banner.live { background: #b3261e; color: #fff; }
  .mode-form { display: inline; margin-left: 12px; }
  .mode-button { padding: 4px 12px; font-size: 13px; border-radius: 5px; background: rgba(0, 0, 0, .75); color: #fff; border: 1px solid rgba(255, 255, 255, .3); }
  .mode-button:hover { background: #000; filter: none; }
  body.mode-dry::after {
    content: ""; position: fixed; inset: 0; pointer-events: none; z-index: 30;
    border: 4px solid var(--accent);
  }

  /* Danger styling for deletion */
  button.danger, .button.danger { background: #b3261e; color: #fff; }
  button.mini.danger { background: #b3261e; color: #fff; }
  .card.danger { border-color: #b3261e; }
  .card.danger .big { color: #f2a3a6; }
  .preview.danger { border-color: #b3261e; }
  form.inline { display: inline; margin-left: 8px; }
  .confirm-form { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
  .confirm-form input[type="text"] { font: inherit; color: var(--text); background: var(--surface-2); border: 1px solid var(--border); border-radius: 6px; padding: 6px 10px; width: 70px; }
  .delete-list { display: grid; gap: 8px; margin: 12px 0; max-height: 55vh; overflow: auto; }
  .delete-item { background: var(--surface-2); border: 1px solid var(--border); border-radius: 6px; padding: 8px 12px; }
  .delete-item summary { cursor: pointer; display: flex; flex-wrap: wrap; gap: 6px 12px; align-items: center; }
  .delete-item .files { margin: 8px 0 0; padding-left: 18px; font-size: 13px; }
  .delete-item .files li { margin: 2px 0; word-break: break-all; }
  .delete-item .files li.missing code { text-decoration: line-through; opacity: .6; }
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
  /* Selection and actions */
  th.sel, td.sel { width: 32px; padding-right: 0; }
  td.row-actions { white-space: nowrap; }
  button.mini { padding: 3px 10px; font-size: 12px; border-radius: 5px; }
  button.mini.secondary { background: var(--surface-2); color: var(--text); border: 1px solid var(--border); font-weight: 500; }
  button.mini.secondary:hover { border-color: var(--muted); }
  .bulk {
    position: fixed; left: 50%; bottom: 20px; transform: translateX(-50%); z-index: 5;
    display: flex; gap: 12px; align-items: center; flex-wrap: wrap; justify-content: center;
    background: var(--surface-2); border: 1px solid var(--accent); border-radius: 10px;
    padding: 10px 16px; box-shadow: 0 8px 30px rgba(0, 0, 0, .5); max-width: calc(100% - 32px);
  }
  .bulk[hidden] { display: none; }
  .bulk [hidden] { display: none; }

  /* Pager */
  .pager { display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: center; justify-content: center; margin: 14px 0 80px; }
  .pager[hidden] { display: none; }
  .pager button:disabled { opacity: .4; cursor: default; }
  #pager-info { color: var(--muted); min-width: 260px; text-align: center; }
  #bulk-count { font-weight: 600; }

  /* Preview */
  .preview { padding: 18px 20px; }
  .preview-title { font-size: 18px !important; text-transform: none !important; letter-spacing: 0 !important; color: var(--text) !important; margin-bottom: 8px !important; }
  .preview-wrap { max-height: 55vh; margin: 12px 0; }
  .preview-actions { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-top: 16px; }
  .preview-actions .msg { margin: 0; }
  .skipped { margin-top: 12px; color: var(--muted); }
  .skipped summary { cursor: pointer; }
  .skipped ul { margin: 8px 0 0; padding-left: 20px; }
  .card.highlight { border-color: var(--accent); }
  .card.highlight .big { color: var(--accent); }

  .empty { background: var(--surface); border: 1px dashed var(--border); border-radius: 8px; padding: 40px; text-align: center; color: var(--muted); }

  @media (max-width: 600px) {
    input[type="search"] { width: 100%; }
    #f-count { margin-left: 0; }
  }
</style>
</head>
<body class="<?= $config && $config['dry_run'] ? 'mode-dry' : ($config ? 'mode-live' : '') ?>">

<?php if ($config && $config['dry_run']): ?>
  <div class="mode-banner dry" role="status">
    <strong>Dry run</strong> — every action only shows a preview. Nothing will change in Plex or on the drive.
    <form method="post" class="mode-form"
          onsubmit="return confirm('Turn on changes for <?= (int) $config['live_mode_minutes'] ?> minutes?\n\nConfirmed actions will change Plex, and Delete will move files to the trash. Every action still shows a preview first.');">
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <input type="hidden" name="action" value="mode_live">
      <button type="submit" class="mode-button">Turn on changes</button>
    </form>
  </div>
<?php elseif ($config): ?>
  <div class="mode-banner live" role="status">
    <strong>Changes enabled</strong> — confirmed actions change Plex and move files on the drive.
    <?php if ($liveUntil): ?>
      <span class="hint" id="live-left" data-until="<?= $liveUntil ?>">Switches back to dry run in <?= max(1, (int) ceil(($liveUntil - time()) / 60)) ?> min</span>
    <?php else: ?>
      <span class="hint">(<code>'dry_run' =&gt; false</code> is set in <code>config.php</code>)</span>
    <?php endif; ?>
    <form method="post" class="mode-form">
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <input type="hidden" name="action" value="mode_dry">
      <button type="submit" class="mode-button">Back to dry run</button>
    </form>
  </div>
<?php endif; ?>

<main>

<header>
  <h1>Plex <span>Cleanup</span></h1>
  <div class="actions">
    <?php if ($cache): ?>
      <span class="muted">Last generated <?= e(date('jS M Y, H:i', $cache['generated_at'])) ?></span>
      <a class="button secondary" href="?format=csv">Download CSV</a>
      <form method="post">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <input type="hidden" name="action" value="preview">
        <input type="hidden" name="op" value="reconcile">
        <button type="submit" class="button secondary" title="Match queued- labels to the Leaving Soon collections">Sync labels</button>
      </form>
    <?php endif; ?>
    <form method="post" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Generating… (about a minute)';">
      <input type="hidden" name="token" value="<?= e($token) ?>">
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
  <p class="msg error" style="white-space: pre-line"><?= e($error) ?></p>
<?php endif; ?>
<?php if ($flash): ?>
  <p class="msg <?= $flash[0] === 'ok' ? 'ok' : 'error' ?>" style="white-space: pre-line"><?= e($flash[1]) ?></p>
<?php endif; ?>

<?php if ($preview):
    $pCount = count($preview['items']);
    $pVerb  = QUEUE_ACTIONS[$preview['action']];
?>
  <section class="card preview">
    <h2 class="preview-title"><?= e($pVerb) ?>: preview</h2>
    <?php if ($preview['action'] === 'reconcile'): ?>
      <p class="muted">Matches labels to the Leaving Soon collections: titles added to a collection by hand get a <code><?= e($config['labels']['queue_prefix']) ?>DATE</code> label dated today, stale labels are removed, and kept titles leave the queue.</p>
    <?php endif; ?>

    <?php if ($pCount): ?>
      <p><strong><?= $pCount ?> title<?= $pCount === 1 ? '' : 's' ?></strong> (<?= gb(planSizeGb($preview, $cache['rows'] ?? [])) ?>) will change in Plex:</p>
      <div class="wrap preview-wrap">
        <table>
          <thead><tr><th>Title</th><th>Library</th><th>Changes</th></tr></thead>
          <tbody>
          <?php foreach ($preview['items'] as $item): ?>
            <tr>
              <td class="title"><?= e($item['title']) ?></td>
              <td><?= e($item['library']) ?></td>
              <td><?= e(ucfirst(implode(', ', array_map('describeOp', $item['ops'])))) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p><strong>Nothing to change.</strong></p>
    <?php endif; ?>

    <?php if ($preview['skipped']): ?>
      <details class="skipped">
        <summary><?= count($preview['skipped']) ?> title<?= count($preview['skipped']) === 1 ? '' : 's' ?> skipped</summary>
        <ul>
          <?php foreach ($preview['skipped'] as $s): ?>
            <li><?= e($s['title']) ?> <span class="muted">— <?= e($s['reason']) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </details>
    <?php endif; ?>

    <div class="preview-actions">
      <?php if ($pCount && !$config['dry_run']): ?>
        <form method="post" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Applying…';">
          <input type="hidden" name="token" value="<?= e($token) ?>">
          <input type="hidden" name="action" value="apply">
          <input type="hidden" name="op" value="<?= e($preview['action']) ?>">
          <input type="hidden" name="keys" value="<?= e(implode(',', $preview['keys'])) ?>">
          <button type="submit">Apply changes to <?= $pCount ?> title<?= $pCount === 1 ? '' : 's' ?></button>
        </form>
      <?php elseif ($pCount): ?>
        <p class="msg error">Dry run is on, so nothing will be changed. To apply changes, use <strong>Turn on changes</strong> in the banner, then run this again.</p>
      <?php endif; ?>
      <a class="button secondary" href="<?= e($self) ?>"><?= $pCount && !$config['dry_run'] ? 'Cancel' : 'Back to list' ?></a>
    </div>
  </section>
<?php elseif ($deletePreview):
    $dCount = count($deletePreview['items']);
    $dBytes = array_sum(array_column($deletePreview['items'], 'bytes'));
    $dFiles = array_sum(array_map(fn($i) => count(array_filter($i['files'], fn($f) => $f['exists'])), $deletePreview['items']));
?>
  <section class="card preview danger">
    <h2 class="preview-title">Move to trash: preview</h2>
    <p class="muted">Files are moved to <code><?= e(trashRoot() . '\\' . $deletePreview['date']) ?></code>, keeping their folder structure, so they can be moved back until the trash is emptied. Plex is asked to rescan afterwards, so the titles disappear from the library and the Leaving Soon collections. Space is only freed when the trash is emptied (after <?= $config['deletion']['trash_keep_days'] ?> days).</p>

    <?php if ($dCount): ?>
      <p><strong><?= $dCount ?> title<?= $dCount === 1 ? '' : 's' ?></strong>, <?= $dFiles ?> file<?= $dFiles === 1 ? '' : 's' ?>, <?= gb($dBytes / 1024 ** 3) ?>:</p>
      <div class="delete-list">
        <?php foreach ($deletePreview['items'] as $item): $present = array_filter($item['files'], fn($f) => $f['exists']); ?>
          <details class="delete-item">
            <summary>
              <strong><?= e($item['title']) ?></strong>
              <span class="muted"><?= e($item['library']) ?> · queued <?= e(niceDate($item['queued_date'])) ?> · <?= count($present) ?> file<?= count($present) === 1 ? '' : 's' ?> · <?= gb($item['bytes'] / 1024 ** 3) ?></span>
              <?php foreach ($item['warnings'] as $w): ?><span class="badge v-queued"><?= e($w) ?></span><?php endforeach; ?>
            </summary>
            <ul class="files">
              <?php foreach ($item['files'] as $f): ?>
                <li class="<?= $f['exists'] ? '' : 'missing' ?>">
                  <code><?= e($f['local']) ?></code>
                  <span class="muted"><?= $f['exists'] ? gb($f['size'] / 1024 ** 3) : 'missing' ?><?= $f['kind'] === 'sidecar' ? ' · sidecar' : '' ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p><strong>Nothing can be moved.</strong></p>
    <?php endif; ?>

    <?php if ($deletePreview['skipped']): ?>
      <details class="skipped" open>
        <summary><?= count($deletePreview['skipped']) ?> title<?= count($deletePreview['skipped']) === 1 ? '' : 's' ?> refused</summary>
        <ul>
          <?php foreach ($deletePreview['skipped'] as $s): ?>
            <li><?= e($s['title']) ?> <span class="muted">— <?= e($s['reason']) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </details>
    <?php endif; ?>

    <div class="preview-actions">
      <?php if ($dCount && !$config['dry_run']): ?>
        <form method="post" class="confirm-form" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Moving…';">
          <input type="hidden" name="token" value="<?= e($token) ?>">
          <input type="hidden" name="action" value="delete_apply">
          <input type="hidden" name="keys" value="<?= e(implode(',', $deletePreview['keys'])) ?>">
          <label>Type <strong><?= $dCount ?></strong> to confirm
            <input type="text" name="confirm" inputmode="numeric" autocomplete="off" required size="4">
          </label>
          <button type="submit" class="danger">Move <?= $dCount ?> title<?= $dCount === 1 ? '' : 's' ?> to trash</button>
        </form>
      <?php elseif ($dCount): ?>
        <p class="msg error">Dry run is on, so nothing will be moved. To move files, use <strong>Turn on changes</strong> in the banner, then run this again.</p>
      <?php endif; ?>
      <a class="button secondary" href="<?= e($self) ?>"><?= $dCount && !$config['dry_run'] ? 'Cancel' : 'Back to list' ?></a>
    </div>
  </section>
<?php elseif ($trashPreview !== null):
    $ready = array_filter($trashPreview, fn($b) => $b['can_empty']);
    $readyBytes = array_sum(array_column($ready, 'bytes'));
?>
  <section class="card preview danger">
    <h2 class="preview-title">Trash</h2>
    <p class="muted">Files moved to <code><?= e(trashRoot()) ?></code>, grouped by the day they were moved. A batch can be permanently deleted once it is <?= $config['deletion']['trash_keep_days'] ?> days old. Until then, files can be moved back to their original folders by hand (the folder structure is kept) and Plex will pick them up on its next scan.</p>
    <?php if ($trashPreview): ?>
      <div class="wrap preview-wrap">
        <table>
          <thead><tr><th>Moved on</th><th class="num">Size</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($trashPreview as $b): ?>
            <tr>
              <td><?= e(niceDate($b['date'])) ?> <span class="ago"><?= e(ago($b['date'])) ?></span></td>
              <td class="num"><?= gb($b['bytes'] / 1024 ** 3) ?></td>
              <td><?= $b['can_empty'] ? '<span class="badge v-due">Can be emptied</span>' : '<span class="badge">Kept for ' . ($config['deletion']['trash_keep_days'] - $b['age_days']) . ' more days</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p><strong>The trash is empty.</strong></p>
    <?php endif; ?>
    <div class="preview-actions">
      <?php if ($ready && !$config['dry_run']): ?>
        <form method="post" class="confirm-form" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Deleting…';">
          <input type="hidden" name="token" value="<?= e($token) ?>">
          <input type="hidden" name="action" value="trash_apply">
          <label>Type <strong><?= count($ready) ?></strong> to confirm
            <input type="text" name="confirm" inputmode="numeric" autocomplete="off" required size="4">
          </label>
          <button type="submit" class="danger">Permanently delete <?= count($ready) ?> batch<?= count($ready) === 1 ? '' : 'es' ?> (<?= gb($readyBytes / 1024 ** 3) ?>)</button>
        </form>
      <?php elseif ($ready): ?>
        <p class="msg error">Dry run is on, so nothing will be deleted.</p>
      <?php endif; ?>
      <a class="button secondary" href="<?= e($self) ?>">Back to list</a>
    </div>
  </section>
<?php elseif (!$rows): ?>
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
    <div class="card<?= $v['due']['count'] ? ' danger' : '' ?>">
      <h2>Due for deletion</h2>
      <div class="big"><?= gb($v['due']['gb']) ?></div>
      <div class="sub"><?= number_format($v['due']['count']) ?> title<?= $v['due']['count'] === 1 ? '' : 's' ?> whose countdown has ended<?= $v['due']['count'] ? ' · filter by <em>Due for deletion</em> to review' : '' ?></div>
    </div>
    <?php if ($trash !== null): ?>
      <div class="card">
        <h2>Trash</h2>
        <div class="big"><?= gb($trashTotals['bytes'] / 1024 ** 3) ?></div>
        <div class="sub">
          <?= count($trash) ?> batch<?= count($trash) === 1 ? '' : 'es' ?> · <?= gb($trashTotals['ready'] / 1024 ** 3) ?> can be emptied
          <?php if ($trash): ?>
            <form method="post" class="inline">
              <input type="hidden" name="token" value="<?= e($token) ?>">
              <input type="hidden" name="action" value="trash_preview">
              <button type="submit" class="mini secondary">Review trash</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
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
        Every action shows a preview first. Titles due for deletion are moved to a trash folder (after typing a confirmation), and the trash can be emptied after <?= $config['deletion']['trash_keep_days'] ?> days.
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
    <label>Rows per page
      <select id="f-size">
        <option value="25">25</option>
        <option value="50" selected>50</option>
        <option value="100">100</option>
        <option value="0">All</option>
      </select>
    </label>
    <span class="muted" id="f-count"></span>
  </div>

  <div class="wrap">
  <table id="report">
    <thead>
      <tr>
        <th class="sel"><input type="checkbox" id="sel-all" title="Select all shown" aria-label="Select all shown"></th>
        <th data-key="title">Title</th>
        <th data-key="verdict">Verdict</th>
        <th>Actions</th>
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
          data-rk="<?= e($r['rating_key']) ?>"
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
        <td class="sel"><input type="checkbox" class="row-sel" aria-label="Select <?= e($r['title']) ?>"></td>
        <td class="title"><?= $tmdbUrl ? '<a href="' . e($tmdbUrl) . '" target="_blank" rel="noopener">' . e($r['title']) . '</a>' : e($r['title']) ?></td>
        <td class="verdict">
          <?php if ($r['verdict'] !== 'none'): ?><span class="badge v-<?= e($r['verdict']) ?>"><?= e(VERDICTS[$r['verdict']]) ?></span><?php endif; ?>
          <span class="ago"><?= e($r['reason']) ?></span>
        </td>
        <td class="row-actions">
          <?php if ($r['verdict'] === 'due'): ?><button type="button" class="mini danger" data-op="delete">Delete</button><?php endif; ?>
          <?php if (!in_array($r['verdict'], ['queued', 'due', 'keep'], true)): ?><button type="button" class="mini" data-op="queue">Queue</button><?php endif; ?>
          <?php if ($r['verdict'] !== 'keep'): ?><button type="button" class="mini secondary" data-op="keep">Keep</button><?php endif; ?>
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

  <nav class="pager" id="pager" aria-label="Pages">
    <button type="button" class="button secondary" data-page="first" aria-label="First page">«</button>
    <button type="button" class="button secondary" data-page="prev">‹ Previous</button>
    <span id="pager-info"></span>
    <button type="button" class="button secondary" data-page="next">Next ›</button>
    <button type="button" class="button secondary" data-page="last" aria-label="Last page">»</button>
  </nav>

  <!-- Selected titles go to the preview first; nothing changes until Apply is pressed there. -->
  <form method="post" id="action-form" hidden>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <input type="hidden" name="action" value="preview">
    <input type="hidden" name="op">
    <input type="hidden" name="keys">
  </form>
  <div class="bulk" id="bulk" hidden>
    <span id="bulk-count"></span>
    <button type="button" id="bulk-all" class="button secondary" hidden></button>
    <button type="button" data-bulk="queue">Queue selected</button>
    <button type="button" data-bulk="keep" class="button secondary">Keep selected</button>
    <button type="button" data-bulk="delete" class="danger" title="Only titles due for deletion are moved; others are refused in the preview">Delete selected</button>
    <button type="button" id="bulk-clear" class="button secondary">Clear</button>
  </div>
<?php endif; ?>

</main>

<script>
// Live mode countdown: keep the minutes current, and reload when it runs out so the
// page never shows "changes enabled" after the server has switched back to dry run.
(() => {
  const el = document.getElementById('live-left');
  if (!el) return;
  const until = parseInt(el.dataset.until, 10) * 1000;
  const tick = () => {
    const left = until - Date.now();
    if (left <= 0) { location.href = location.pathname; return; }
    el.textContent = `Switches back to dry run in ${Math.ceil(left / 60000)} min`;
  };
  tick();
  setInterval(tick, 15000);
})();
</script>
<script>
(() => {
  const table = document.getElementById('report');
  if (!table) return;
  const tbody = table.tBodies[0];
  const rows = Array.from(tbody.rows);
  const numeric = new Set(['year', 'size', 'plays', 'users', 'eps', 'verdict']);
  const ascFirst = new Set(['verdict']); // candidates first on the first click
  const $ = id => document.getElementById(id);
  const fVerdict = $('f-verdict'), fType = $('f-type'), fUnwatched = $('f-unwatched');
  const fSearch = $('f-search'), fSize = $('f-size'), fCount = $('f-count');
  const pager = $('pager'), pagerInfo = $('pager-info');
  const selAll = $('sel-all'), bulk = $('bulk'), bulkCount = $('bulk-count'), bulkAll = $('bulk-all');
  const form = $('action-form');
  const isChecked = r => r.querySelector('.row-sel').checked;
  const fmtGb = gb => gb >= 1024 ? (gb / 1024).toFixed(2) + ' TB' : gb.toFixed(1) + ' GB';

  // The view (filters, sort, page) is remembered in this browser, so after Queue,
  // Keep or Delete you come back to the same place. Storage may be unavailable.
  const STORE = 'plex-cleanup-view';
  const view = Object.assign(
    { verdict: '', type: '', unwatched: false, q: '', sortKey: 'size', sortDir: 'desc', size: 50, page: 1 },
    (() => { try { return JSON.parse(localStorage.getItem(STORE)) || {}; } catch (e) { return {}; } })()
  );
  const save = () => { try { localStorage.setItem(STORE, JSON.stringify(view)); } catch (e) { /* ignore */ } };

  let matching = [];   // rows that pass the filters, in sorted order
  let pageRows = [];   // the matching rows on the current page

  function sortRows() {
    const key = view.sortKey, sign = view.sortDir === 'asc' ? 1 : -1;
    rows.sort((a, b) => {
      let x = a.dataset[key] ?? '', y = b.dataset[key] ?? '';
      if (numeric.has(key)) {
        x = parseFloat(x) || 0; y = parseFloat(y) || 0;
        // Ties (e.g. same verdict) fall back to size, largest first.
        return (x - y) * sign || (parseFloat(b.dataset.size) - parseFloat(a.dataset.size));
      }
      return x.localeCompare(y) * sign;
    });
    tbody.append(...rows);
    table.querySelectorAll('th').forEach(h => {
      if (h.dataset.key === key) h.dataset.dir = view.sortDir; else delete h.dataset.dir;
    });
  }

  // Apply filters and paging. Rows outside the filters or on other pages are hidden.
  function render() {
    const q = view.q.trim().toLowerCase();
    matching = rows.filter(r =>
      (!view.verdict || r.dataset.v === view.verdict)
      && (!view.type || r.dataset.type === view.type)
      && (!view.unwatched || r.dataset.viewed === '')
      && (!q || r.dataset.title.includes(q)));
    const size = view.size > 0 ? view.size : Math.max(matching.length, 1);
    const pages = Math.max(1, Math.ceil(matching.length / size));
    view.page = Math.min(Math.max(1, view.page), pages);
    const start = (view.page - 1) * size;
    pageRows = matching.slice(start, start + size);
    const onPage = new Set(pageRows);
    rows.forEach(r => { r.hidden = !onPage.has(r); });

    fCount.textContent = `${matching.length} of ${rows.length} titles match`;
    pagerInfo.textContent = matching.length
      ? `Page ${view.page} of ${pages} · showing ${start + 1}–${start + pageRows.length} of ${matching.length}`
      : 'No titles match';
    pager.querySelector('[data-page="first"]').disabled = pager.querySelector('[data-page="prev"]').disabled = view.page <= 1;
    pager.querySelector('[data-page="next"]').disabled = pager.querySelector('[data-page="last"]').disabled = view.page >= pages;
    pager.hidden = pages <= 1;
    save();
    updateBulk();
  }

  // Selection: ticked rows that match the filters, on any page. Rows filtered out
  // never take part in an action, even if they were ticked earlier.
  const selected = () => matching.filter(isChecked);
  function updateBulk() {
    const sel = selected();
    bulk.hidden = sel.length === 0;
    const size = sel.reduce((t, r) => t + (parseFloat(r.dataset.size) || 0), 0);
    const offPage = sel.filter(r => r.hidden).length;
    bulkCount.textContent = `${sel.length} selected · ${fmtGb(size)}` + (offPage ? ` (${offPage} on other pages)` : '');
    // Offer to extend a full-page selection to every matching title.
    const pageFull = pageRows.length > 0 && pageRows.every(isChecked);
    bulkAll.hidden = !(pageFull && sel.length < matching.length);
    bulkAll.textContent = `Select all ${matching.length} matching`;
    const checkedOnPage = pageRows.filter(isChecked).length;
    selAll.checked = pageFull;
    selAll.indeterminate = checkedOnPage > 0 && !pageFull;
  }

  function submitAction(op, keys) {
    // Delete has its own preview (with typed confirmation); the others share one.
    form.elements.action.value = op === 'delete' ? 'delete_preview' : 'preview';
    form.elements.op.value = op;
    form.elements.keys.value = keys.join(',');
    form.submit();
  }

  // Sorting: click a header; click again to reverse. Back to page 1.
  table.tHead.addEventListener('click', ev => {
    const th = ev.target.closest('th');
    if (!th || !th.dataset.key) return;
    const key = th.dataset.key;
    view.sortDir = view.sortKey === key
      ? (view.sortDir === 'desc' ? 'asc' : 'desc')
      : (numeric.has(key) && !ascFirst.has(key) ? 'desc' : 'asc');
    view.sortKey = key;
    view.page = 1;
    sortRows();
    render();
  });

  // Filters: any change goes back to page 1.
  const onFilter = () => {
    view.verdict = fVerdict.value; view.type = fType.value; view.unwatched = fUnwatched.checked;
    view.q = fSearch.value; view.size = parseInt(fSize.value, 10) || 0; view.page = 1;
    render();
  };
  [fVerdict, fType, fUnwatched, fSize].forEach(el => el.addEventListener('change', onFilter));
  fSearch.addEventListener('input', onFilter);

  pager.addEventListener('click', ev => {
    const btn = ev.target.closest('button[data-page]');
    if (!btn || btn.disabled) return;
    const step = { first: -Infinity, prev: -1, next: 1, last: Infinity }[btn.dataset.page];
    view.page = Number.isFinite(step) ? view.page + step : (step > 0 ? Infinity : 1);
    render();
    table.closest('.wrap').scrollTop = 0;
    table.closest('.wrap').scrollIntoView({ block: 'start', behavior: 'smooth' });
  });

  // "Select all" in the header ticks the current page only.
  selAll.addEventListener('change', () => {
    pageRows.forEach(r => { r.querySelector('.row-sel').checked = selAll.checked; });
    updateBulk();
  });
  bulkAll.addEventListener('click', () => {
    matching.forEach(r => { r.querySelector('.row-sel').checked = true; });
    updateBulk();
  });
  tbody.addEventListener('change', ev => { if (ev.target.classList.contains('row-sel')) updateBulk(); });
  tbody.addEventListener('click', ev => {
    const btn = ev.target.closest('button[data-op]');
    if (btn) submitAction(btn.dataset.op, [btn.closest('tr').dataset.rk]);
  });
  bulk.addEventListener('click', ev => {
    const btn = ev.target.closest('button[data-bulk]');
    if (btn) submitAction(btn.dataset.bulk, selected().map(r => r.dataset.rk));
  });
  $('bulk-clear').addEventListener('click', () => {
    rows.forEach(r => { r.querySelector('.row-sel').checked = false; });
    updateBulk();
  });

  // Restore the saved view into the controls, then draw.
  if (![...fVerdict.options].some(o => o.value === view.verdict)) view.verdict = '';
  if (!['', 'movie', 'show'].includes(view.type)) view.type = '';
  if (![...fSize.options].some(o => o.value === String(view.size))) view.size = 50;
  if (!table.querySelector(`th[data-key="${CSS.escape(view.sortKey)}"]`)) { view.sortKey = 'size'; view.sortDir = 'desc'; }
  fVerdict.value = view.verdict; fType.value = view.type; fUnwatched.checked = !!view.unwatched;
  fSearch.value = view.q; fSize.value = String(view.size);
  sortRows();
  render();
})();
</script>
</body>
</html>
