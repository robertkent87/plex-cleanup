<?php
// src/rules.php - the rule engine.
// Gives every report row a verdict using the rules in config.php. Read-only:
// it never changes Plex or the file share, and never queues anything.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/format.php';

/** Verdicts, in the order they are checked. */
const VERDICTS = [
    'keep'      => 'Keep',          // labelled keep: never considered
    'queued'    => 'Queued',        // in Leaving Soon or has a queued- label
    'protected' => 'Protected',     // matched a rule, but the show is still airing (or unknown)
    'candidate' => 'Candidate',     // matched a rule: can be queued from the review page
    'none'      => 'Not a candidate',
];

const RULE_LABELS = [
    'unwatched'     => 'Never watched',
    'movie_watched' => 'Movie not rewatched',
    'show_watched'  => 'Show not watched recently',
];

/** True if a YYYY-MM-DD date is more than $months months before $today. Blank dates are never "older". */
function olderThanMonths(string $ymd, int $months, DateTimeImmutable $today): bool
{
    return $ymd !== '' && $ymd < $today->modify("-$months months")->format('Y-m-d');
}

/**
 * Verdict for one report row: ['verdict' => key of VERDICTS, 'rule' => key of RULE_LABELS or null, 'reason' => text].
 */
function evaluateTitle(array $row, array $config, DateTimeImmutable $today): array
{
    $rules   = $config['rules'];
    $isShow  = $row['type'] === 'show';
    $added   = (string) $row['added'];
    $last    = (string) $row['last_viewed'];
    $plays   = (int) $row['view_count'];
    $verdict = fn(string $v, ?string $rule, string $reason) => ['verdict' => $v, 'rule' => $rule, 'reason' => $reason];

    // 1. Keep always wins.
    if (($row['keep'] ?? '') === 'yes') {
        return $verdict('keep', null, 'Labelled ' . $config['labels']['keep']);
    }

    // 2. Already in the queue: shown with its countdown, not re-evaluated.
    $queuedDate = (string) ($row['queued_date'] ?? '');
    if ($queuedDate !== '') {
        $due = (new DateTimeImmutable($queuedDate))->modify('+' . $config['countdown_days'] . ' days');
        $daysLeft = (int) $today->diff($due)->format('%r%a');
        return $verdict('queued', null, $daysLeft > 0
            ? "Queued " . niceDate($queuedDate) . ", $daysLeft days left"
            : "Queued " . niceDate($queuedDate) . ", due for deletion (needs confirmation)");
    }
    if (($row['in_leaving_soon'] ?? '') === 'yes') {
        return $verdict('queued', null, 'In Leaving Soon, no queue date yet');
    }

    // 3. Has anyone watched it? A title marked watched with no recorded plays still counts.
    $watched = $isShow
        ? ($last !== '' || (int) $row['episodes_watched'] > 0 || $plays > 0)
        : ($last !== '' || $plays > 0);
    if (!$isShow && $watched) {
        $plays = max($plays, 1);
    }

    // 4. Apply the rules.
    $match = null;
    $why   = '';
    if (!$watched) {
        $r = $rules['unwatched'];
        if (!$r['enabled']) {
            $why = 'Never watched (rule off)';
        } elseif (olderThanMonths($added, $r['min_added_months'], $today)) {
            $match = ['unwatched', 'Never watched, added ' . ago($added, $today)];
        } else {
            $why = 'Never watched, but added ' . ($added !== '' ? ago($added, $today) : 'on an unknown date');
        }
    } elseif ($isShow) {
        $r = $rules['show_watched'];
        $eps = "{$row['episodes_watched']}/{$row['episodes_total']} episodes watched";
        if (!$r['enabled']) {
            $why = 'Rule off';
        } elseif ($last === '') {
            $why = "Watched, but no date recorded ($eps)";
        } elseif (!olderThanMonths($last, $r['min_last_viewed_months'], $today)) {
            $why = 'Watched ' . ago($last, $today);
        } elseif (!olderThanMonths($added, $r['min_added_months'], $today)) {
            $why = 'Added ' . ago($added, $today);
        } else {
            $match = ['show_watched', 'Last watched ' . ago($last, $today) . " ($eps)"];
        }
    } else {
        $r = $rules['movie_watched'];
        $times = $plays === 1 ? 'once' : "$plays times";
        if (!$r['enabled']) {
            $why = 'Rule off';
        } elseif ($plays > $r['max_plays']) {
            $why = "Watched $times";
        } elseif ($last === '') {
            $why = "Watched $times, no date recorded";
        } elseif (!olderThanMonths($last, $r['min_last_viewed_months'], $today)) {
            $why = 'Watched ' . ago($last, $today);
        } elseif (!olderThanMonths($added, $r['min_added_months'], $today)) {
            $why = 'Added ' . ago($added, $today);
        } else {
            $match = ['movie_watched', "Watched $times, last " . ago($last, $today)];
        }
    }

    if ($match === null) {
        return $verdict('none', null, $why);
    }

    // 5. Shows still airing (or with no TMDB status) are never candidates.
    if ($isShow) {
        $t = $config['tmdb'];
        $status = (string) $row['tmdb_status'];
        $protectedBy = match (true) {
            $t['protect_in_production'] && $row['tmdb_in_production'] === 'yes' => 'still in production',
            $status !== '' && in_array($status, $t['protected_statuses'], true)  => "TMDB status $status",
            $t['protect_unknown'] && $status === ''                             => 'no TMDB status',
            default => null,
        };
        if ($protectedBy !== null) {
            return $verdict('protected', $match[0], "{$match[1]}, but protected: $protectedBy");
        }
    }

    return $verdict('candidate', $match[0], $match[1]);
}

/** Add 'verdict', 'rule' and 'reason' to every row. */
function applyRules(array $rows, ?array $config = null, ?DateTimeImmutable $today = null): array
{
    $config ??= config();
    $today  ??= new DateTimeImmutable('today');
    foreach ($rows as &$row) {
        $row += evaluateTitle($row, $config, $today);
    }
    return $rows;
}

/**
 * Counts and sizes: ['verdicts' => [verdict => [count, gb]], 'rules' => [rule => [count, gb]]].
 * Rule totals count candidates only.
 */
function summariseVerdicts(array $rows): array
{
    $s = ['verdicts' => [], 'rules' => []];
    foreach (array_keys(VERDICTS) as $v) {
        $s['verdicts'][$v] = ['count' => 0, 'gb' => 0.0];
    }
    foreach (array_keys(RULE_LABELS) as $r) {
        $s['rules'][$r] = ['count' => 0, 'gb' => 0.0];
    }
    foreach ($rows as $row) {
        $s['verdicts'][$row['verdict']]['count']++;
        $s['verdicts'][$row['verdict']]['gb'] += (float) $row['size_gb'];
        if ($row['verdict'] === 'candidate') {
            $s['rules'][$row['rule']]['count']++;
            $s['rules'][$row['rule']]['gb'] += (float) $row['size_gb'];
        }
    }
    return $s;
}
