<?php
// config.php - rules and settings for Plex Cleanup.
// Credentials live in .env, not here. Values are checked by src/config.php.

return [

    // Candidate rules. A title is a candidate if any rule matches, unless it is
    // labelled keep, already queued, or a show protected by the TMDB rules below.
    // Candidates are only shown on the review page; nothing is queued or deleted
    // without the user pressing a button.
    'rules' => [
        // Movies and shows: never watched by anyone, and in the library longer than this.
        'unwatched' => [
            'enabled'          => true,
            'min_added_months' => 6,
        ],
        // Movies: watched at most `max_plays` times, last watched long ago, not recently added.
        // Movies watched more than `max_plays` times are never candidates.
        'movie_watched' => [
            'enabled'                => true,
            'max_plays'              => 1,
            'min_last_viewed_months' => 12,
            'min_added_months'       => 6,
        ],
        // Shows: no episode watched by anyone for this long, however much was watched.
        'show_watched' => [
            'enabled'                => true,
            'min_last_viewed_months' => 12,
            'min_added_months'       => 6,
        ],
    ],

    // Shows: TMDB decides whether a show is still airing. A protected show is never a candidate.
    'tmdb' => [
        'protect_in_production' => true,  // TMDB in_production = true
        'protected_statuses'    => ['Returning Series', 'In Production', 'Planned', 'Pilot'],
        'protect_unknown'       => true,  // no TMDB match or no status: never a candidate
    ],

    // Queue: days from being queued until a title is due for deletion.
    'countdown_days' => 30,

    // Plex labels and collections.
    'labels' => [
        'keep'         => 'keep',
        'queue_prefix' => 'queued-',  // followed by YYYY-MM-DD
    ],
    // Leaving Soon collection for each Plex library, keyed by library name.
    // The collections must already exist in Plex (create them by hand).
    // Titles in a library not listed here cannot be queued.
    'collections' => [
        'Movies'   => 'Movies Leaving Soon',
        'TV Shows' => 'Series Leaving Soon',
        'Anime'    => 'Anime Leaving Soon',
    ],

    // While true, Queue and Keep on the review page (and sync.php) only preview
    // what would change in Plex. Set to false to allow changes to be applied.
    'dry_run' => true,
];
