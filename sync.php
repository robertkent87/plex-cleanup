<?php
// sync.php - bring queued- labels in line with the Leaving Soon collections.
// Usage: php sync.php            (applies changes, unless dry_run is true in config.php)
//        php sync.php --dry-run  (only lists what would change)
//
// It never queues new titles and never deletes anything:
//   - in a Leaving Soon collection with no queued- label -> label added, dated today
//   - queued- label but no longer in the collection      -> label removed
//   - labelled keep but still queued                     -> removed from collection and label removed

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

require __DIR__ . '/src/queue.php';

$dryRun = in_array('--dry-run', $argv, true) || config('dry_run');

try {
    $plan = buildPlan('reconcile', []);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

if (!$plan['items']) {
    echo "Everything is in sync. Nothing to change.\n";
    exit(0);
}

echo ($dryRun ? "Would change" : "Changing") . " " . count($plan['items']) . " title(s):\n";
foreach ($plan['items'] as $item) {
    echo "  {$item['title']} ({$item['library']}): " . implode(', ', array_map('describeOp', $item['ops'])) . "\n";
}

if ($dryRun) {
    echo "\nDry run: nothing was changed." . (config('dry_run') ? " (dry_run is true in config.php)" : '') . "\n";
    exit(0);
}

$result = applyPlan($plan);
echo "\nDone: " . count($result['ok']) . " ok, " . count($result['failed']) . " failed.\n";
foreach ($result['failed'] as $f) {
    fwrite(STDERR, "  FAILED {$f['title']}: {$f['error']}\n");
}
exit($result['failed'] ? 1 : 0);
