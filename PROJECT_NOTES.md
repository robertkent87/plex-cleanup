# Plex Cleanup: Project Notes

## Goal

A monthly review tool for a Plex library stored on an external drive. It flags movies and TV shows that are candidates for removal so the drive space can be reclaimed, and lets the user permanently exclude titles they want to keep.

## Environment

- Plex server: `192.168.1.147:32400` (on a separate machine from the tool)
- The tool runs on a Windows machine with Laragon (`C:\laragon\www\plex-cleanup`)
- The media drive is reachable over the network from the tool's machine
- Plex is not running on the tool's machine
- Radarr and Sonarr are in use for some titles; not all titles are in them
- About three regular users on the server

## Stack

- PHP 8.3, with cURL and JSON
- Composer for dependencies (`vlucas/phpdotenv`)
- Credentials in `.env` (never committed; see `.gitignore`)
- No Tautulli for now

## Decisions

- **Keep marker:** a Plex label named `keep` on the movie or show. Plex is the source of truth, because not every title is in Radarr or Sonarr.
- **Thresholds (configurable, starting values):**
  - Never watched and added more than 6 months ago: candidate
  - Watched once, last watched more than 12 months ago, added more than 6 months ago: candidate
- **Shows are judged as a whole:**
  - Use Plex `leafCount` and `viewedLeafCount` to see how much was watched
  - Use Plex `lastViewedAt` for the last time anything in the show was played
  - Use TMDB `status` and `in_production` to decide whether the show is still airing; a show still in production is never a candidate
  - Size is the sum of all episode files
- **Ranking:** candidates sorted by size, largest first
- **Deletion:** through Radarr/Sonarr where the title is managed there, so that they don't re-download it. Titles not in Radarr/Sonarr are deleted directly over the network share, after a confirmation step.
- **Dry run first:** deletion features must support a dry-run mode that reports what would be removed and how much space it frees.
- **Local web review page:** a single page on localhost to review candidates and choose keep, snooze, or delete. Decisions are stored in a local SQLite database.
- **Soon-to-be-removed list:** a Plex collection named `Soon to be removed`, holding every title that is a candidate and is within its 30-day countdown. The tool adds a title when it becomes a candidate and removes it when the title is kept, snoozed, or deleted. The collection is promoted to the library's Recommended page if the Plex version supports it. The local review page remains the full list, with sizes, dates, the rule that flagged each title, and days remaining.
- **Deletion timing:** a candidate enters a 30-day countdown when first flagged. The countdown is shown in the review page and the collection. Titles can be kept or snoozed at any point during the countdown. Once it expires, the title is queued for deletion, subject to the dry-run and confirmation steps.

## Current state

- `report.php`: working. Reads the Plex library and TMDB, and writes a CSV to stdout (`php report.php > report.csv`).
- `.gitignore`: in place. Excludes `vendor/`, `.env`, CSV and database files, and editor files.
- The CSV output has not yet been checked against the actual library.

## Next steps

1. Check `report.csv` against the Plex library: added dates, blank last-viewed values, TMDB matches, and show sizes.
2. Add a config file for the thresholds and TMDB rules.
3. Build the rule engine that reads the Plex data and applies the rules, skipping anything labelled `keep` or with a future snooze date.
4. Add the SQLite decisions store.
5. Build the local review page.
6. Add the Plex collection sync: create or update the `Soon to be removed` collection to match the current candidates, and check whether Recommended promotion is available on this Plex version.
7. Add Radarr/Sonarr deletion, dry-run first.
8. Schedule the monthly run.

## Open questions

- Whether to write the `keep` label to Plex from the review page, or only read it.
- Exact behaviour for shows that are partly watched.
- Whether Plex `addedAt` values are reliable after file moves.
- Whether the Plex version on this server supports promoting a collection to Recommended, and where it then appears.
- Whether a title that is kept during the countdown should be locked out of future candidate checks, or simply reset.
