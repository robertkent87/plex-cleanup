<?php
// src/queue.php - queue sync: the only code that changes Plex.
//
// It only ever adds or removes titles from the Leaving Soon collections and adds
// or removes the keep and queued- labels. It never deletes media.
//
// Every change is a two-step process: buildPlan() reads the current state from
// Plex and lists what would change (the preview / dry run); applyPlan() makes
// those changes, refuses to run while config dry_run is true, and re-reads each
// title afterwards to confirm the result.

require_once __DIR__ . '/report.php';
require_once __DIR__ . '/rules.php';

const QUEUE_ACTIONS = [
    'queue'     => 'Queue',
    'keep'      => 'Keep',
    'reconcile' => 'Sync labels',
];

/** Current Plex state of one title: labels, collections and library. Null if Plex doesn't know it. */
function itemState(string $ratingKey): ?array
{
    return itemStates([$ratingKey])[$ratingKey];
}

/** Current Plex state of several titles, as [ratingKey => state or null], fetched in batches. */
function itemStates(array $ratingKeys): array
{
    return array_map(
        fn($m) => $m === null ? null : stateFromMetadata($m),
        fetchMetadata($ratingKeys)
    );
}

/**
 * Full Plex metadata for several titles, as [ratingKey => metadata or null (not found)].
 * Titles are requested one each but several at a time. (Comma-separated keys in one request
 * were tried: Plex still prepares each title in turn, so a batch with a few slow titles
 * timed out, and unlike single requests it didn't leave them fast for next time.)
 */
function fetchMetadata(array $ratingKeys): array
{
    $out = [];
    foreach (array_unique(array_map('strval', $ratingKeys)) as $rk) {
        $out[$rk] = "/library/metadata/$rk";
    }
    $responses = plexMany(array_values($out));
    foreach ($out as $rk => $path) {
        $out[$rk] = $responses[$path]['MediaContainer']['Metadata'][0] ?? null;
    }
    return $out;
}

/** Labels, collection and library of a title, from its Plex metadata. */
function stateFromMetadata(array $m): array
{
    $ratingKey = (string) $m['ratingKey'];
    $keepLabel = config('labels.keep');
    $prefix    = config('labels.queue_prefix');
    $labels    = array_values(array_map(fn($t) => $t['tag'], $m['Label'] ?? []));
    $library   = (string) ($m['librarySectionTitle'] ?? '');
    $colName   = config('collections')[$library] ?? null;
    $inCol     = $colName !== null && in_array(mb_strtolower($colName), array_map(fn($t) => mb_strtolower($t['tag']), $m['Collection'] ?? []), true);

    return [
        'rating_key'  => $ratingKey,
        'title'       => (string) ($m['title'] ?? ''),
        'type'        => (string) ($m['type'] ?? ''),
        'section_key' => (string) ($m['librarySectionID'] ?? ''),
        'library'     => $library,
        'labels'      => $labels,
        'keep'        => (bool) array_filter($labels, fn($l) => strcasecmp($l, $keepLabel) === 0),
        'queued'      => array_values(array_filter($labels, fn($l) => stripos($l, $prefix) === 0)),
        'collection'  => $colName,
        'in_collection' => $inCol,
    ];
}

/** ratingKey of a library's Leaving Soon collection, or null if it doesn't exist. Cached per request. */
function collectionKey(string $sectionKey, string $collectionTitle): ?string
{
    static $cache = [];
    $id = "$sectionKey|$collectionTitle";
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = null;
        foreach (plex("/library/sections/$sectionKey/collections")['MediaContainer']['Metadata'] ?? [] as $col) {
            if (strcasecmp($col['title'] ?? '', $collectionTitle) === 0) {
                $cache[$id] = (string) $col['ratingKey'];
                break;
            }
        }
    }
    return $cache[$id];
}

/**
 * Work out what an action would change, from Plex's current state.
 * $ratingKeys is ignored for 'reconcile', which checks every Leaving Soon collection and queued- label.
 *
 * Returns ['action', 'date', 'items' => [rk => [...state, 'ops' => [...]]], 'skipped' => [rk => ['title', 'reason']]].
 * Each op is ['op' => collection_add|collection_remove|label_add|label_remove, 'value' => collection or label name].
 */
function buildPlan(string $action, array $ratingKeys, ?DateTimeImmutable $today = null): array
{
    if (!isset(QUEUE_ACTIONS[$action])) {
        throw new InvalidArgumentException("Unknown action: $action");
    }
    $date       = ($today ?? new DateTimeImmutable('today'))->format('Y-m-d');
    $queueLabel = config('labels.queue_prefix') . $date;
    $keepLabel  = config('labels.keep');
    $plan       = ['action' => $action, 'date' => $date, 'items' => [], 'skipped' => []];

    if ($action === 'reconcile') {
        $ratingKeys = reconcileCandidates();
    }

    foreach (itemStates($ratingKeys) as $rk => $s) {
        $rk = (string) $rk;
        if ($s === null) {
            $plan['skipped'][$rk] = ['title' => "#$rk", 'reason' => 'Not found in Plex (regenerate the report)'];
            continue;
        }
        if (!in_array($s['type'], ['movie', 'show'], true)) {
            $plan['skipped'][$rk] = ['title' => $s['title'], 'reason' => "Not a movie or show ({$s['type']})"];
            continue;
        }
        $ops  = [];
        $skip = null;
        $colExists = $s['collection'] !== null && collectionKey($s['section_key'], $s['collection']) !== null;

        switch ($action) {
            case 'queue':
                if ($s['keep']) {
                    $skip = "Labelled $keepLabel";
                } elseif ($s['queued'] || $s['in_collection']) {
                    $skip = 'Already queued';
                } elseif ($s['collection'] === null) {
                    $skip = "No Leaving Soon collection set for library \"{$s['library']}\" in config.php";
                } elseif (!$colExists) {
                    $skip = "Collection \"{$s['collection']}\" not found in library \"{$s['library']}\"";
                } else {
                    $ops[] = ['op' => 'collection_add', 'value' => $s['collection']];
                    $ops[] = ['op' => 'label_add', 'value' => $queueLabel];
                }
                break;

            case 'keep':
                if (!$s['keep']) {
                    $ops[] = ['op' => 'label_add', 'value' => $keepLabel];
                }
                foreach ($s['queued'] as $l) {
                    $ops[] = ['op' => 'label_remove', 'value' => $l];
                }
                if ($s['in_collection'] && $colExists) {
                    $ops[] = ['op' => 'collection_remove', 'value' => $s['collection']];
                }
                if (!$ops) {
                    $skip = 'Already kept';
                }
                break;

            case 'reconcile':
                if ($s['keep']) {
                    // Kept titles leave the queue.
                    foreach ($s['queued'] as $l) {
                        $ops[] = ['op' => 'label_remove', 'value' => $l];
                    }
                    if ($s['in_collection'] && $colExists) {
                        $ops[] = ['op' => 'collection_remove', 'value' => $s['collection']];
                    }
                } elseif ($s['in_collection'] && !$s['queued']) {
                    // Added to the collection by hand: start its countdown today.
                    $ops[] = ['op' => 'label_add', 'value' => $queueLabel];
                } elseif (!$s['in_collection'] && $s['queued']) {
                    // Removed from the collection by hand: drop the stale queued- label.
                    foreach ($s['queued'] as $l) {
                        $ops[] = ['op' => 'label_remove', 'value' => $l];
                    }
                }
                if (!$ops) {
                    continue 2; // in sync: not worth listing
                }
                break;
        }

        if ($skip !== null) {
            $plan['skipped'][$rk] = ['title' => $s['title'], 'reason' => $skip];
        } else {
            $plan['items'][$rk] = $s + ['ops' => $ops];
        }
    }
    return $plan;
}

/** ratingKeys worth checking in a reconcile: Leaving Soon members plus anything with keep or queued- labels. */
function reconcileCandidates(): array
{
    $keys = [];
    foreach (plex('/library/sections')['MediaContainer']['Directory'] ?? [] as $section) {
        if (!in_array($section['type'], ['movie', 'show'], true)) {
            continue;
        }
        $plexType = $section['type'] === 'movie' ? 1 : 2;
        foreach (array_keys(trackedLabels((string) $section['key'], $plexType)) as $rk) {
            $keys[$rk] = true;
        }
        $colName = config('collections')[$section['title']] ?? null;
        if ($colName !== null) {
            foreach (array_keys(leavingSoonMembers((string) $section['key'], $colName)) as $rk) {
                $keys[$rk] = true;
            }
        }
    }
    return array_map('strval', array_keys($keys));
}

/** Total size of the titles in a plan, from the saved report. */
function planSizeGb(array $plan, array $reportRows): float
{
    $sizes = array_column($reportRows, 'size_gb', 'rating_key');
    return array_sum(array_map(fn($rk) => (float) ($sizes[$rk] ?? 0), array_keys($plan['items'])));
}

/**
 * Make the changes in a plan. Refuses while config dry_run is true.
 * Returns ['ok' => [rk => title], 'failed' => [rk => ['title', 'error']]] after re-reading each title from Plex.
 */
function applyPlan(array $plan): array
{
    if (config('dry_run')) {
        throw new RuntimeException('Dry run is on, so no changes were made. Turn on changes in the banner and try again.');
    }
    $machine = plex('/')['MediaContainer']['machineIdentifier'] ?? null;
    if (!$machine) {
        throw new RuntimeException('Could not read the Plex server identifier.');
    }
    $result = ['ok' => [], 'failed' => []];

    // 1. Collection additions, batched per collection.
    $adds = [];
    foreach ($plan['items'] as $rk => $item) {
        foreach ($item['ops'] as $op) {
            if ($op['op'] === 'collection_add') {
                $adds[collectionKey($item['section_key'], $op['value'])][] = $rk;
            }
        }
    }
    foreach ($adds as $colRk => $rks) {
        foreach (array_chunk($rks, 100) as $chunk) {
            try {
                plexWrite('PUT', "/library/collections/$colRk/items", [
                    'uri' => "server://$machine/com.plexapp.plugins.library/library/metadata/" . implode(',', $chunk),
                ]);
            } catch (RuntimeException $e) {
                foreach ($chunk as $rk) {
                    $result['failed'][$rk] = ['title' => $plan['items'][$rk]['title'], 'error' => 'Adding to collection: ' . $e->getMessage()];
                }
            }
        }
    }

    // 2. Per-title changes: collection removals and labels.
    foreach ($plan['items'] as $rk => $item) {
        if (isset($result['failed'][$rk])) {
            continue;
        }
        $type   = $item['type'] === 'movie' ? 1 : 2;
        $add    = [];
        $remove = [];
        try {
            foreach ($item['ops'] as $op) {
                if ($op['op'] === 'collection_remove') {
                    $colRk = collectionKey($item['section_key'], $op['value']);
                    plexWrite('DELETE', "/library/collections/$colRk/items/$rk");
                } elseif ($op['op'] === 'label_add') {
                    $add[] = $op['value'];
                } elseif ($op['op'] === 'label_remove') {
                    $remove[] = $op['value'];
                }
            }
            if ($add) {
                // Setting labels replaces the whole list, so send the existing labels too.
                $query = ['type' => $type, 'id' => $rk, 'label.locked' => 1];
                foreach (array_values(array_unique(array_merge($item['labels'], $add))) as $i => $label) {
                    $query["label[$i].tag.tag"] = $label;
                }
                plexWrite('PUT', "/library/sections/{$item['section_key']}/all", $query);
            }
            if ($remove) {
                plexWrite('PUT', "/library/sections/{$item['section_key']}/all", [
                    'type' => $type, 'id' => $rk, 'label.locked' => 1,
                    'label[].tag.tag-' => implode(',', $remove),
                ]);
            }
        } catch (RuntimeException $e) {
            $result['failed'][$rk] = ['title' => $item['title'], 'error' => $e->getMessage()];
        }
    }

    // 3. Re-read every title and check the result matches the plan.
    $states = [];
    $after  = itemStates(array_keys($plan['items']));
    foreach ($plan['items'] as $rk => $item) {
        $s = $after[(string) $rk] ?? null;
        if ($s !== null) {
            $states[$rk] = $s;
        }
        if (isset($result['failed'][$rk])) {
            continue;
        }
        $problems = $s === null ? ['title no longer found'] : verifyOps($item, $s);
        if ($problems) {
            $result['failed'][$rk] = ['title' => $item['title'], 'error' => 'Not applied as expected: ' . implode('; ', $problems)];
        } else {
            $result['ok'][$rk] = $item['title'];
        }
    }

    patchReportCache($states);
    return $result;
}

/** Problems with a title's state after applying its ops; empty if everything took effect. */
function verifyOps(array $item, array $after): array
{
    $problems = [];
    $has = fn($label) => in_array(mb_strtolower($label), array_map('mb_strtolower', $after['labels']), true);
    foreach ($item['ops'] as $op) {
        $ok = match ($op['op']) {
            'collection_add'    => $after['in_collection'],
            'collection_remove' => !$after['in_collection'],
            'label_add'         => $has($op['value']),
            'label_remove'      => !$has($op['value']),
        };
        if (!$ok) {
            $problems[] = describeOp($op);
        }
    }
    // Labels that were there before and weren't meant to be removed must still be there.
    $removed = array_map(fn($op) => mb_strtolower($op['value']), array_filter($item['ops'], fn($op) => $op['op'] === 'label_remove'));
    foreach ($item['labels'] as $label) {
        if (!in_array(mb_strtolower($label), $removed, true) && !$has($label)) {
            $problems[] = "existing label \"$label\" was lost";
        }
    }
    return $problems;
}

/** Plain-English description of one op. */
function describeOp(array $op): string
{
    return match ($op['op']) {
        'collection_add'    => "add to \"{$op['value']}\"",
        'collection_remove' => "remove from \"{$op['value']}\"",
        'label_add'         => "add label \"{$op['value']}\"",
        'label_remove'      => "remove label \"{$op['value']}\"",
    };
}

/** Update the saved report with fresh label and collection state, so the page is right without regenerating. */
function patchReportCache(array $states): void
{
    $cache = loadReportCache();
    if (!$cache || !$states) {
        return;
    }
    foreach ($cache['rows'] as &$row) {
        $s = $states[$row['rating_key']] ?? null;
        if ($s === null) {
            continue;
        }
        $dates = array_filter(array_map(fn($l) => preg_match('/(\d{4}-\d{2}-\d{2})$/', $l, $m) ? $m[1] : null, $s['queued']));
        $row['keep']            = $s['keep'] ? 'yes' : '';
        $row['queued_date']     = $dates ? min($dates) : '';
        $row['in_leaving_soon'] = $s['in_collection'] ? 'yes' : '';
    }
    unset($row);
    // Keep the original generated_at: only these fields were refreshed.
    $tmp = REPORT_CACHE . '.tmp';
    file_put_contents($tmp, json_encode($cache, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    rename($tmp, REPORT_CACHE);
}
