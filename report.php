<?php
// report.php - read-only Plex library report
// Usage: php report.php > report.csv

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

function tmdb(string $path): ?array
{
    global $tmdbKey;
    if ($tmdbKey === '') {
        return null;
    }
    $url = 'https://api.themoviedb.org/3' . $path . '?api_key=' . urlencode($tmdbKey);
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

/** Pull the TMDB id out of Plex's Guid list (requires includeGuids=1). */
function tmdbIdFrom(array $item): ?int
{
    foreach ($item['Guid'] ?? [] as $g) {
        if (preg_match('#^tmdb://(\d+)$#', $g['id'] ?? '', $m)) {
            return (int) $m[1];
        }
    }
    return null;
}

/** Sum file sizes (bytes) from a Plex item's Media/Part list. */
function sizeOf(array $item): int
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
    'episodes_total',
    'episodes_watched',
    'size_gb',
    'tmdb_status',
    'tmdb_last_air_date',
    'tmdb_in_production',
]);

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
        $tmdbId  = tmdbIdFrom($item);
        $size    = 0;
        $epTotal = '';
        $epSeen  = '';

        if ($isShow) {
            // Shows: total size comes from all episodes.
            $leaves = plex("/library/metadata/{$item['ratingKey']}/allLeaves")['MediaContainer']['Metadata'] ?? [];
            foreach ($leaves as $ep) {
                $size += sizeOf($ep);
            }
            $epTotal = $item['leafCount'] ?? '';
            $epSeen  = $item['viewedLeafCount'] ?? 0;
        } else {
            $size = sizeOf($item);
        }

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
            fmtDate($item['lastViewedAt'] ?? null),
            $item['viewCount'] ?? 0,
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
