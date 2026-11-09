<?php
// src/report.php - shared Plex/TMDB access and report building.
// Used by report.php (CLI) and public/index.php (review page).

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

const REPORT_COLUMNS = [
    'type',
    'title',
    'year',
    'rating_key',
    'tmdb_id',
    'added',
    'last_viewed',
    'view_count',
    'users_watched',
    'history_source',
    'episodes_total',
    'episodes_watched',
    'size_gb',
    'tmdb_status',
    'tmdb_last_air_date',
    'tmdb_in_production',
];

const REPORT_CACHE = __DIR__ . '/../data/report.json';

/** Minimal JSON GET with cURL. */
function getJson(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        throw new RuntimeException('cURL error: ' . curl_error($ch) . " ($url)");
    }
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($code >= 400) {
        throw new RuntimeException("HTTP $code from $url");
    }
    return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
}

function plex(string $path, array $query = []): array
{
    $plexUrl   = rtrim($_ENV['PLEX_URL'] ?? '', '/');
    $plexToken = $_ENV['PLEX_TOKEN'] ?? '';
    if ($plexUrl === '' || $plexToken === '') {
        throw new RuntimeException('PLEX_URL and PLEX_TOKEN must be set in .env');
    }
    $url = $plexUrl . $path . ($query ? '?' . http_build_query($query) : '');
    return getJson($url, ['X-Plex-Token: ' . $plexToken]);
}

function tmdb(string $path, array $query = []): ?array
{
    $tmdbKey = $_ENV['TMDB_API_KEY'] ?? '';
    if ($tmdbKey === '') {
        return null;
    }
    $url = 'https://api.themoviedb.org/3' . $path . '?' . http_build_query(['api_key' => $tmdbKey] + $query);
    try {
        return getJson($url);
    } catch (RuntimeException $e) {
        error_log('TMDB: ' . $e->getMessage());
        return null;
    }
}

function fmtDate(?int $ts): string
{
    return $ts ? date('Y-m-d', $ts) : '';
}

/** Pull an external id (tmdb, tvdb, imdb) out of Plex's Guid list (requires includeGuids=1). */
function externalIdFrom(array $item, string $scheme): ?string
{
    foreach ($item['Guid'] ?? [] as $g) {
        if (preg_match('#^' . preg_quote($scheme, '#') . '://(.+)$#', $g['id'] ?? '', $m)) {
            return $m[1];
        }
    }
    // Items matched by Plex's legacy agents have no Guid list, only e.g.
    // guid="com.plexapp.agents.thetvdb://78881?lang=en".
    $legacy = ['tmdb' => 'themoviedb', 'tvdb' => 'thetvdb', 'imdb' => 'imdb'][$scheme] ?? $scheme;
    if (preg_match('#^com\.plexapp\.agents\.' . preg_quote($legacy, '#') . '://([^?/]+)#', $item['guid'] ?? '', $m)) {
        return $m[1];
    }
    return null;
}

/** TMDB id for an item, falling back to a TMDB lookup by TVDB id for shows. */
function tmdbIdFor(array $item, bool $isShow): ?int
{
    $id = externalIdFrom($item, 'tmdb');
    if ($id !== null && ctype_digit($id)) {
        return (int) $id;
    }
    if ($isShow) {
        $tvdb = externalIdFrom($item, 'tvdb');
        if ($tvdb !== null && ctype_digit($tvdb)) {
            $found = tmdb("/find/$tvdb", ['external_source' => 'tvdb_id']);
            if (!empty($found['tv_results'][0]['id'])) {
                return (int) $found['tv_results'][0]['id'];
            }
        }
    }
    return null;
}

/** Sum file sizes (bytes) from a Plex item's Media/Part list. */
function mediaSizeBytes(array $item): int
{
    $total = 0;
    foreach ($item['Media'] ?? [] as $media) {
        foreach ($media['Part'] ?? [] as $part) {
            $total += (int) ($part['size'] ?? 0);
        }
    }
    return $total;
}

/**
 * Watch history for all accounts on the server (needs the server owner's token).
 * Returns [ratingKey => ['plays' => int, 'last' => int, 'accounts' => [id => true], 'episodes' => [key => true]]],
 * keyed by movie ratingKey or, for episodes, the show's ratingKey. Returns null if history can't be read.
 */
function loadHistory(): ?array
{
    $history = [];
    $start   = 0;
    $size    = 1000;
    try {
        do {
            $mc = plex('/status/sessions/history/all', [
                'X-Plex-Container-Start' => $start,
                'X-Plex-Container-Size'  => $size,
            ])['MediaContainer'] ?? [];
            $rows = $mc['Metadata'] ?? [];

            foreach ($rows as $row) {
                $isEpisode = ($row['type'] ?? '') === 'episode';
                $key = $isEpisode
                    ? ($row['grandparentRatingKey'] ?? basename($row['grandparentKey'] ?? ''))
                    : ($row['ratingKey'] ?? basename($row['key'] ?? ''));
                if ($key === '' || $key === null) {
                    continue; // item no longer in the library
                }
                $h = &$history[(string) $key];
                $h['plays']    = ($h['plays'] ?? 0) + 1;
                $h['last']     = max($h['last'] ?? 0, (int) ($row['viewedAt'] ?? 0));
                $h['accounts'][(string) ($row['accountID'] ?? '?')] = true;
                if ($isEpisode && isset($row['ratingKey'])) {
                    $h['episodes'][(string) $row['ratingKey']] = true;
                }
                unset($h);
            }
            $start += $size;
        } while (count($rows) === $size);
    } catch (RuntimeException $e) {
        error_log('History unavailable, using per-item watch data only: ' . $e->getMessage());
        return null;
    }
    return $history;
}

/**
 * Read the Plex library and TMDB and return one row per movie or show,
 * as associative arrays keyed by REPORT_COLUMNS.
 */
function buildReport(): array
{
    $report   = [];
    $history  = loadHistory();
    $sections = plex('/library/sections')['MediaContainer']['Directory'] ?? [];

    foreach ($sections as $section) {
        if (!in_array($section['type'], ['movie', 'show'], true)) {
            continue; // skip music, photos, etc.
        }

        $plexType = $section['type'] === 'movie' ? 1 : 2;
        $items = plex("/library/sections/{$section['key']}/all", [
            'type'         => $plexType,
            'includeGuids' => 1,
        ])['MediaContainer']['Metadata'] ?? [];

        foreach ($items as $item) {
            $isShow  = $section['type'] === 'show';
            $tmdbId  = tmdbIdFor($item, $isShow);
            $size    = 0;
            $epTotal = '';
            $epSeen  = '';
            $h       = $history[(string) $item['ratingKey']] ?? null;

            if ($isShow) {
                // Shows: total size comes from all episodes.
                $leaves = plex("/library/metadata/{$item['ratingKey']}/allLeaves")['MediaContainer']['Metadata'] ?? [];
                foreach ($leaves as $ep) {
                    $size += mediaSizeBytes($ep);
                }
                $epTotal = $item['leafCount'] ?? '';
                // viewedLeafCount is for the token's account only; history covers every account.
                $epSeen  = max((int) ($item['viewedLeafCount'] ?? 0), count($h['episodes'] ?? []));
            } else {
                $size = mediaSizeBytes($item);
            }

            // Combine all-account history with the token account's own fields (history can be pruned).
            $itemLast   = (int) ($item['lastViewedAt'] ?? 0);
            $itemCount  = (int) ($item['viewCount'] ?? 0);
            $lastViewed = max($itemLast, $h['last'] ?? 0);
            $viewCount  = max($itemCount, $h['plays'] ?? 0);
            $users      = count($h['accounts'] ?? []) ?: ($itemCount > 0 ? 1 : 0);
            $source     = $h ? 'history' : ($itemCount > 0 || $itemLast > 0 ? 'item' : '');

            $tmdbStatus = $tmdbLastAir = $tmdbInProd = '';
            if ($isShow && $tmdbId) {
                $tv = tmdb("/tv/$tmdbId");
                if ($tv) {
                    $tmdbStatus  = $tv['status'] ?? '';
                    $tmdbLastAir = $tv['last_air_date'] ?? '';
                    $tmdbInProd  = isset($tv['in_production']) ? ($tv['in_production'] ? 'yes' : 'no') : '';
                }
            }

            $report[] = array_combine(REPORT_COLUMNS, [
                $isShow ? 'show' : 'movie',
                $item['title'] ?? '',
                $item['year'] ?? '',
                $item['ratingKey'] ?? '',
                $tmdbId ?? '',
                fmtDate($item['addedAt'] ?? null),
                fmtDate($lastViewed ?: null),
                $viewCount,
                $users,
                $source,
                $epTotal,
                $epSeen,
                round($size / 1024 ** 3, 2),
                $tmdbStatus,
                $tmdbLastAir,
                $tmdbInProd,
            ]);
        }
    }

    return $report;
}

/** Write rows as CSV (with header) to an open stream. */
function writeReportCsv($stream, array $rows): void
{
    fputcsv($stream, REPORT_COLUMNS);
    foreach ($rows as $row) {
        fputcsv($stream, array_values($row));
    }
}

/** Save a generated report so the review page can show it without re-querying Plex. */
function saveReportCache(array $rows): void
{
    $dir = dirname(REPORT_CACHE);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $json = json_encode(['generated_at' => time(), 'rows' => $rows], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    // Write to a temp file then rename, so a half-written cache is never read.
    $tmp = REPORT_CACHE . '.tmp';
    file_put_contents($tmp, $json);
    rename($tmp, REPORT_CACHE);
}

/** Load the last saved report, or null if none has been generated. */
function loadReportCache(): ?array
{
    if (!is_file(REPORT_CACHE)) {
        return null;
    }
    return json_decode(file_get_contents(REPORT_CACHE), true, 512, JSON_THROW_ON_ERROR);
}
