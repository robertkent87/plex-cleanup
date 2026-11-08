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

- **Keep marker:** a Plex label named `keep` on the movie or show. A kept title leaves the Leaving Soon collection, its queue label is removed, and it is excluded from future candidate checks. Plex is the source of truth, because not every title is in Radarr or Sonarr.
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
- **Local web review page:** a single page on localhost to review candidates and choose keep or delete. Decisions are stored in a local SQLite database.
- **Leaving Soon collections:** one collection per library, named `Movies Leaving Soon` and `Series Leaving Soon`, each holding only items of that library's type. Each is promoted to the home screen within its own library. The tool addresses collections by section, not by name.
- **Deletion timing:** a candidate enters a 30-day countdown when first flagged. The countdown is shown in the review page and the collection. Titles can be kept at any point during the countdown. Once it expires, the title is queued for deletion, subject to the dry-run and confirmation steps.
- **Queue date label:** when the tool adds a title to a Leaving Soon collection, it also adds a label of the form `queued-YYYY-MM-DD`, using the date it was queued. The label is the source of truth for how long a title has been queued, so no separate database is needed for the queue. Labels are visible in Plex's search and filters; this is accepted. On each run:
  - A title in the collection with a `queued-` label is counted from that date.
  - A title in the collection with no `queued-` label is treated as added manually, and gets a label dated the run's date.
  - A title with a `queued-` label but no longer in the collection was removed by hand, so the tool deletes the label.
  - A title that is kept has its `queued-` label and collection membership removed.
- **One collection per library:** the tool maintains a separate collection in each library section (Movies and TV Shows), each holding only items of that section's type. Each is promoted within its own library. Collections are addressed by section key, not by name. Plex appears to merge or filter collections that share a name across libraries, so names should differ (for example "Leaving Soon: Movies" and "Leaving Soon: TV").

## Current state

- `report.php`: working. Reads the Plex library and TMDB, and writes a CSV to stdout (`php report.php > report.csv`).
- `.gitignore`: in place. Excludes `vendor/`, `.env`, CSV and database files, and editor files.
- The CSV output has not yet been checked against the actual library.

## Next steps

1. Check `report.csv` against the Plex library: added dates, blank last-viewed values, TMDB matches, and show sizes.
2. Add a config file for the thresholds and TMDB rules.
3. Build the rule engine that reads the Plex data and applies the rules, skipping anything labelled `keep`.
4. Implement the queue sync: add and remove titles from the Leaving Soon collections, and manage the `queued-` and `keep` labels. Use the SQLite store only for the review page's history if it's needed.
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
