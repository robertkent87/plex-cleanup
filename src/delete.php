<?php
// src/delete.php - deletion: move due titles to a trash folder on the share, and empty the trash.
//
// Safety rules (see PROJECT_NOTES.md):
//   - Only titles in their library's Leaving Soon collection, with a queued- label whose
//     countdown has ended, and not labelled keep, can be moved. This is checked against
//     Plex's current state every time, whatever the caller asked for.
//   - Only individual files are moved: the title's video files (every version) and
//     sidecar files with the same name. Folders are only removed once empty, never a
//     library's root folder, and nothing outside the library folders is touched.
//   - Files are moved to share_path\trash_folder\YYYY-MM-DD\<original path>, so they can be
//     moved back. Emptying the trash permanently deletes batches older than trash_keep_days.
//   - Nothing happens while dry_run is true. Every move and purge is logged.

require_once __DIR__ . '/queue.php';

const DELETION_LOG = __DIR__ . '/../data/deletions.log';

/** The share as this PC sees it, with no trailing slash. */
function shareRoot(): string
{
    return rtrim(config('deletion.share_path'), '\\/');
}

function trashRoot(): string
{
    return shareRoot() . '\\' . config('deletion.trash_folder');
}

/** Map a Plex path (/storage/.../Movies/x.mkv) to this PC's path, or null if it is outside the mapped drive. */
function localPath(string $plexPath): ?string
{
    $prefix = rtrim(config('deletion.plex_path_prefix'), '/');
    if (!str_starts_with($plexPath, $prefix . '/')) {
        return null;
    }
    $rel = substr($plexPath, strlen($prefix) + 1);
    $parts = explode('/', $rel);
    if (in_array('..', $parts, true) || in_array('.', $parts, true) || in_array('', $parts, true)) {
        return null;
    }
    return shareRoot() . '\\' . implode('\\', $parts);
}

/** True if $path is strictly inside $dir (not equal to it). Case-insensitive, as Windows paths are. */
function isInside(string $path, string $dir): bool
{
    return stripos($path, rtrim($dir, '\\') . '\\') === 0;
}

/** Local paths of every movie and show library root folder. Cached per request. */
function libraryRoots(): array
{
    static $roots = null;
    if ($roots === null) {
        $roots = [];
        foreach (plex('/library/sections')['MediaContainer']['Directory'] ?? [] as $s) {
            if (!in_array($s['type'], ['movie', 'show'], true)) {
                continue;
            }
            foreach ($s['Location'] ?? [] as $loc) {
                $local = localPath($loc['path']);
                if ($local !== null) {
                    $roots[(string) $s['key']][] = $local;
                }
            }
        }
    }
    return $roots;
}

/** Sidecar files next to a video: same base name, then a dot, then anything, ending in a configured extension. */
function sidecarFiles(string $videoPath): array
{
    $dir  = dirname($videoPath);
    $base = pathinfo($videoPath, PATHINFO_FILENAME);
    $exts = array_map('strtolower', config('deletion.sidecar_extensions'));
    $out  = [];
    foreach (@scandir($dir) ?: [] as $name) {
        $full = "$dir\\$name";
        if ($full === $videoPath || !is_file($full)) {
            continue;
        }
        if (stripos($name, $base . '.') === 0 && in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $exts, true)) {
            $out[] = $full;
        }
    }
    return $out;
}

/** Video file paths (Plex's paths) for a movie (all versions) or show (all episodes). */
function plexFilesFor(string $ratingKey, string $type): array
{
    $items = $type === 'show'
        ? plex("/library/metadata/$ratingKey/allLeaves")['MediaContainer']['Metadata'] ?? []
        : plex("/library/metadata/$ratingKey")['MediaContainer']['Metadata'] ?? [];
    $files = [];
    foreach ($items as $item) {
        foreach ($item['Media'] ?? [] as $media) {
            foreach ($media['Part'] ?? [] as $part) {
                if (!empty($part['file'])) {
                    $files[] = ['plex' => $part['file'], 'size' => (int) ($part['size'] ?? 0)];
                }
            }
        }
    }
    return $files;
}

/**
 * Plan moving titles to the trash, from Plex's current state.
 * Returns ['date', 'items' => [rk => [title, library, section_key, queued_date, warnings, files => [[local, size, kind, exists]], bytes]],
 *          'skipped' => [rk => [title, reason]]].
 */
function buildDeletionPlan(array $ratingKeys, ?DateTimeImmutable $today = null): array
{
    $today ??= new DateTimeImmutable('today');
    $plan = ['date' => $today->format('Y-m-d'), 'items' => [], 'skipped' => []];
    $countdown = config('countdown_days');

    foreach (array_unique(array_map('strval', $ratingKeys)) as $rk) {
        $s = itemState($rk);
        $skip = function (string $why) use (&$plan, $rk, $s): void {
            $plan['skipped'][$rk] = ['title' => $s['title'] ?? "#$rk", 'reason' => $why];
        };

        // Hard checks: these are the only titles that may ever be moved.
        if ($s === null) { $skip('Not found in Plex'); continue; }
        if (!in_array($s['type'], ['movie', 'show'], true)) { $skip('Not a movie or show'); continue; }
        if ($s['keep']) { $skip('Labelled ' . config('labels.keep')); continue; }
        if ($s['collection'] === null) { $skip("No Leaving Soon collection set for library \"{$s['library']}\""); continue; }
        if (!$s['in_collection']) { $skip("Not in \"{$s['collection']}\""); continue; }
        $dates = array_filter(array_map(fn($l) => preg_match('/(\d{4}-\d{2}-\d{2})$/', $l, $m) ? $m[1] : null, $s['queued']));
        if (!$dates) { $skip('No queued- label (use Sync labels)'); continue; }
        $queued = min($dates);
        $left = queueDaysLeft($queued, $countdown, $today);
        if ($left > 0) { $skip("Countdown not finished: $left day" . ($left === 1 ? '' : 's') . ' left'); continue; }

        $roots = libraryRoots()[$s['section_key']] ?? [];
        if (!$roots) { $skip('Library folder is not on the mapped share (check deletion settings)'); continue; }

        $files = [];
        $problem = null;
        foreach (plexFilesFor($rk, $s['type']) as $f) {
            $local = localPath($f['plex']);
            $inLib = $local !== null && array_filter($roots, fn($r) => isInside($local, $r));
            if (!$inLib) {
                $problem = "File outside the library folders: {$f['plex']}";
                break;
            }
            $exists = is_file($local);
            $files[$local] = ['local' => $local, 'size' => $exists ? (int) filesize($local) : $f['size'], 'kind' => 'video', 'exists' => $exists];
            if ($exists) {
                foreach (sidecarFiles($local) as $side) {
                    $files[$side] ??= ['local' => $side, 'size' => (int) filesize($side), 'kind' => 'sidecar', 'exists' => true];
                }
            }
        }
        if ($problem !== null) { $skip($problem); continue; }
        if (!array_filter($files, fn($f) => $f['exists'])) { $skip('No files found on the share'); continue; }

        $warnings = [];
        $meta = plex("/library/metadata/$rk")['MediaContainer']['Metadata'][0] ?? [];
        $lastViewed = isset($meta['lastViewedAt']) ? date('Y-m-d', (int) $meta['lastViewedAt']) : '';
        $row = array_values(array_filter(loadReportCache()['rows'] ?? [], fn($r) => $r['rating_key'] === $rk))[0] ?? null;
        $lastViewed = max($lastViewed, (string) ($row['last_viewed'] ?? ''));
        if ($lastViewed !== '' && $lastViewed >= $queued) {
            $warnings[] = 'Watched since queued (' . niceDate($lastViewed) . ')';
        }
        if ($missing = count(array_filter($files, fn($f) => !$f['exists']))) {
            $warnings[] = "$missing file" . ($missing === 1 ? '' : 's') . ' already missing from the share';
        }

        $plan['items'][$rk] = [
            'title'       => $s['title'],
            'type'        => $s['type'],
            'library'     => $s['library'],
            'section_key' => $s['section_key'],
            'queued_date' => $queued,
            'warnings'    => $warnings,
            'files'       => array_values($files),
            'bytes'       => array_sum(array_map(fn($f) => $f['exists'] ? $f['size'] : 0, $files)),
        ];
    }
    return $plan;
}

/** Append one JSON line to the deletion log. */
function logDeletion(array $entry): void
{
    $dir = dirname(DELETION_LOG);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents(DELETION_LOG, json_encode(['time' => date('c')] + $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Move the titles in a plan to the trash. Refuses while dry_run is true.
 * The plan must come from buildDeletionPlan() in the same request (it re-checks Plex).
 * Returns ['ok' => [rk => title], 'failed' => [rk => [title, error]], 'bytes' => moved bytes].
 */
function applyDeletionPlan(array $plan): array
{
    if (config('dry_run')) {
        throw new RuntimeException('Dry run is on, so nothing was moved. Turn on changes in the banner and try again.');
    }
    $share = shareRoot();
    $trash = trashRoot() . '\\' . $plan['date'];
    $result = ['ok' => [], 'failed' => [], 'bytes' => 0];
    $scanPaths = [];

    foreach ($plan['items'] as $rk => $item) {
        $errors = [];
        $roots = libraryRoots()[$item['section_key']] ?? [];
        $dirs = [];
        foreach ($item['files'] as $f) {
            if (!$f['exists'] || !is_file($f['local'])) {
                continue;
            }
            // Final guard: never move anything outside a library folder.
            if (!array_filter($roots, fn($r) => isInside($f['local'], $r))) {
                $errors[] = "refused (outside library): {$f['local']}";
                continue;
            }
            $dest = $trash . substr($f['local'], strlen($share));
            if (file_exists($dest)) {
                $dest = preg_replace('/(\.[^.\\\\]+)$/', '.' . uniqid() . '$1', $dest);
            }
            if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0777, true)) {
                $errors[] = "could not create trash folder for {$f['local']}";
                continue;
            }
            if (@rename($f['local'], $dest) && !file_exists($f['local']) && is_file($dest)) {
                $result['bytes'] += $f['size'];
                logDeletion(['action' => 'trash', 'rating_key' => $rk, 'title' => $item['title'], 'from' => $f['local'], 'to' => $dest, 'bytes' => $f['size']]);
                $dirs[dirname($f['local'])] = true;
            } else {
                $errors[] = "could not move {$f['local']}";
            }
        }

        // Remove folders left empty, walking up but never to (or above) a library root.
        foreach (array_keys($dirs) as $dir) {
            while (array_filter($roots, fn($r) => isInside($dir, $r))) {
                $left = array_diff(@scandir($dir) ?: ['x'], ['.', '..']);
                if ($left || !@rmdir($dir)) {
                    break;
                }
                logDeletion(['action' => 'remove_empty_folder', 'rating_key' => $rk, 'title' => $item['title'], 'folder' => $dir]);
                $dir = dirname($dir);
            }
        }

        $scanPaths[$item['section_key']] = true;
        if ($errors) {
            $result['failed'][$rk] = ['title' => $item['title'], 'error' => implode('; ', $errors)];
        } else {
            $result['ok'][$rk] = $item['title'];
        }
    }

    // Ask Plex to rescan, so the titles disappear (autoEmptyTrash removes missing items).
    foreach (array_keys($scanPaths) as $sectionKey) {
        try {
            plex("/library/sections/$sectionKey/refresh");
        } catch (RuntimeException $e) {
            $result['failed']['scan-' . $sectionKey] = ['title' => "Library scan ($sectionKey)", 'error' => $e->getMessage()];
        }
    }

    removeFromReportCache(array_keys($result['ok']));
    return $result;
}

/** Drop titles from the saved report once their files are gone. */
function removeFromReportCache(array $ratingKeys): void
{
    $cache = loadReportCache();
    if (!$cache || !$ratingKeys) {
        return;
    }
    $gone = array_flip(array_map('strval', $ratingKeys));
    $cache['rows'] = array_values(array_filter($cache['rows'], fn($r) => !isset($gone[$r['rating_key']])));
    $tmp = REPORT_CACHE . '.tmp';
    file_put_contents($tmp, json_encode($cache, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    rename($tmp, REPORT_CACHE);
}

/** Total size in bytes of everything under a folder. */
function folderBytes(string $dir): int
{
    $total = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) {
            $total += $f->getSize();
        }
    }
    return $total;
}

/**
 * Trash batches (one folder per day), oldest first:
 * [['date', 'path', 'bytes', 'age_days', 'can_empty']].
 */
function trashBatches(?DateTimeImmutable $today = null): array
{
    $today ??= new DateTimeImmutable('today');
    $root = trashRoot();
    $keep = config('deletion.trash_keep_days');
    $out = [];
    foreach (@scandir($root) ?: [] as $name) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $name) || !is_dir("$root\\$name")) {
            continue;
        }
        $age = (int) (new DateTimeImmutable($name))->diff($today)->format('%r%a');
        $out[] = ['date' => $name, 'path' => "$root\\$name", 'bytes' => folderBytes("$root\\$name"), 'age_days' => $age, 'can_empty' => $age >= $keep];
    }
    return $out;
}

/**
 * Permanently delete trash batches that are old enough. Refuses while dry_run is true.
 * Returns ['batches' => [date], 'bytes' => int].
 */
function emptyTrash(?DateTimeImmutable $today = null): array
{
    if (config('dry_run')) {
        throw new RuntimeException('Dry run is on, so nothing was deleted. Turn on changes in the banner and try again.');
    }
    $root = trashRoot();
    $done = ['batches' => [], 'bytes' => 0];
    foreach (trashBatches($today) as $batch) {
        // Only date-named folders directly inside the trash folder, and only once old enough.
        if (!$batch['can_empty'] || dirname($batch['path']) !== $root) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($batch['path'], FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            if (!isInside($f->getPathname(), $batch['path'])) {
                continue; // never follow anything outside the batch
            }
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($batch['path']);
        logDeletion(['action' => 'empty_trash', 'batch' => $batch['date'], 'bytes' => $batch['bytes'], 'complete' => !is_dir($batch['path'])]);
        $done['batches'][] = $batch['date'];
        $done['bytes'] += $batch['bytes'];
    }
    return $done;
}
