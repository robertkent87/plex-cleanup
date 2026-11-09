# Plex Cleanup: Project Notes

## Goal

A monthly review tool for a Plex library stored on an external drive. It flags movies and TV shows that are candidates for removal so the drive space can be reclaimed, and lets the user permanently exclude titles they want to keep.

## Environment

- Plex server: `192.168.1.147:32400` (on a separate machine from the tool)
- The tool runs on a Windows machine with Laragon (`C:\laragon\www\plex-cleanup`)
- The media drive is reachable over the network from the tool's machine
- Plex is not running on the tool's machine
- Radarr and Sonarr are in use for some titles; not all titles are in them. They are already configured to unmonitor titles whose files are missing.
- About three regular users on the server

## Stack

- PHP 8.3, with cURL and JSON
- Composer for dependencies (`vlucas/phpdotenv`)
- Credentials in `.env` (never committed; see `.gitignore`)
- No Tautulli for now

## Decisions

- **Keep marker:** a Plex label named `keep` on the movie or show. A kept title leaves its Leaving Soon collection, its `queued-` label is removed, and it is excluded from future candidate checks. Plex is the source of truth, because not every title is in Radarr or Sonarr.
- **Thresholds (configurable, starting values):**
  - Never watched and added more than 6 months ago: candidate
  - Watched once, last watched more than 12 months ago, added more than 6 months ago: candidate
- **Shows are judged as a whole:**
  - Use Plex `leafCount` and `viewedLeafCount` to see how much was watched
  - Use Plex `lastViewedAt` for the last time anything in the show was played
  - Use TMDB `status` and `in_production` to decide whether the show is still airing; a show still in production is never a candidate
  - Size is the sum of all episode files
- **Ranking:** candidates sorted by size, largest first
- **Deletion:** the tool deletes the file directly from the network share, then refreshes the Plex library so the entry disappears. Radarr and Sonarr are already configured to unmonitor titles whose files are missing, so they won't re-download anything. No arr-specific deletion path is needed.
- **Dry run first:** every action that changes Plex or the file share must support a dry-run mode, which reports what would change and the space it frees, without changing anything. The review page's dry-run toggle uses the same code path.
- **Local web review page:** a single page on localhost, the main interface for the tool. It shows the candidates table (title, type, size, added date, last watched, play count, the rule that flagged it, TMDB status for shows), the due-for-deletion section with days remaining or overdue, and per-row actions: Keep, and Queue for recommendations. A "Run" action applies approved changes, with a dry-run toggle that reports what would be removed and the space it frees. The page is for one user, so it has no login and no hosting; styling is deferred until the logic works. Command-line entry points (`report.php`, `sync.php`) exist for scheduling, but the page is how decisions are made. Decisions are stored in a local SQLite database only where Plex can't hold the state.
- **Deletion timing:** a candidate enters a 30-day countdown when first queued. The countdown is shown in the review page and the collection. Titles can be kept at any point during the countdown. Once it expires, the title is due for deletion, subject to the dry-run and confirmation steps.
- **Leaving Soon collections:** one collection per library, named `Movies Leaving Soon` and `Series Leaving Soon`, each holding only items of that library's type. Each is promoted to the home screen within its own library. The tool addresses collections by section key, not by name.
- **Queue date label:** when the tool adds a title to a Leaving Soon collection, it also adds a label of the form `queued-YYYY-MM-DD`, using the date it was queued. The label is the source of truth for how long a title has been queued, so no separate database is needed for the queue. Labels are visible in Plex's search and filters; this is accepted. On each run:
  - A title in the collection with a `queued-` label is counted from that date.
  - A title in the collection with no `queued-` label is treated as added manually, and gets a label dated the run's date.
  - A title with a `queued-` label but no longer in the collection was removed by hand, so the tool deletes the label.
  - A title that is kept has its `queued-` label and collection membership removed.
- **First run:** the first monthly run is dry-run only. Before approving any deletions, check that the rules select titles you'd expect.

## Current state

- `report.php`: working. Reads the Plex library and TMDB, and writes a CSV to stdout (`php report.php > report.csv`).
- `.gitignore`: in place. Excludes `vendor/`, `.env`, CSV and database files, and editor files.
- `report.php` fixes (2026-11-09): show sizes were always 0 (typo); watch data now comes from all-account history (`/status/sessions/history/all`, ~8.4k plays, 7 accounts) merged with the token account's own fields, with new `users_watched` and `history_source` columns; TMDB ids are read from legacy-agent `guid` values and looked up by TVDB id when missing; warnings go to stderr. All shows except one local-only item (Berserk Redux) now have a TMDB status.
- The CSV output has not yet been fully checked against the actual library. About 600 of 852 movies still show as never watched, even with full history.
- Design agreed: Leaving Soon collections per library, `queued-YYYY-MM-DD` and `keep` labels, deletion direct from the share, and a localhost review page.

## Next steps

1. Check `report.csv` against the Plex library: added dates, blank last-viewed values, TMDB matches, and show sizes.
2. Add a config file for the thresholds, the 30-day countdown, and TMDB rules.
3. Build the rule engine that reads Plex data and applies the rules, skipping anything labelled `keep`.
4. Build the queue sync: add and remove titles from the Leaving Soon collections, and manage the `queued-` and `keep` labels. Implement dry-run here first.
5. Build the local review page on top of the rule engine and queue sync, with the candidates table, due-for-deletion section, row actions, and run button.
6. Add the deletion step: delete files from the share and refresh the Plex library, dry-run first.
7. Check whether Recommended promotion is available on this Plex version, and promote the two collections if so.
8. Schedule the monthly `report.php` and `sync.php` runs.

## Open questions

- Exact behaviour for shows that are partly watched.
- Whether Plex `addedAt` values are reliable after file moves.
- Whether Plex on this server supports promoting a collection to Recommended, and where it then appears.
- Whether the TV library UI shows collections on this Plex version, and where promoted TV collections appear.
