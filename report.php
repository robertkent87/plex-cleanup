<?php
// report.php - read-only Plex library report
// Usage: php report.php > report.csv

// Warnings go to stderr so they can never end up in the CSV on stdout.
error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

Dotenv::createImmutable(__DIR__)->safeLoad();

$plexUrl   = rtrim($_ENV['PLEX_URL'] ?? '', '/');
$plexToken = $_ENV['PLEX_TOKEN'] ?? '';
$tmdbKey   = $_ENV['TMDB_API_KEY'] ?? '';

if ($plexUrl === '' || $plexToken === '') {
    fwrite(STDERR, "PLEX_URL and PLEX_TOKEN must be set in .env\n");
    exit(1);
}

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
    global $plexUrl, $plexToken;
    $url = $plexUrl . $path . ($query ? '?' . http_build_query($query) : '');
    return getJson($url, ['X-Plex-Token: ' . $plexToken]);
}

function tmdb(string $path, array $query = []): ?array
{
    global $tmdbKey;
    if ($tmdbKey === '') {
        return null;
    }
    $url = 'https://api.themoviedb.org/3' . $path . '?' . http_build_query(['api_key' => $tmdbKey] + $query);
    try {
        return getJson($url);
    } catch (RuntimeException $e) {
        fwrite(STDERR, "TMDB: " . $e->getMessage() . "\n");
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

/** TMDB id for an item, falling back to a TMDB lookup by TVDB id for shows on the older agent. */
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
        fwrite(STDERR, "History unavailable, using per-item watch data only: " . $e->getMessage() . "\n");
        return null;
    }
    return $history;
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

$out = fopen('php://stdout', 'w');
fputcsv($out, [
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
]);

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

        fputcsv($out, [
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

fclose($out);
