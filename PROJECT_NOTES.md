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

- **Safety rules (agreed 2026-11-09, non-negotiable):**
  1. Nothing is ever deleted automatically. Every deletion needs explicit confirmation on the review page.
  2. Every title in the library can be reviewed and marked Keep, not only candidates. A kept title is never considered for removal again.
  3. The Leaving Soon collections are the only route to deletion. A title is only eligible for deletion after it has been in its library's Leaving Soon collection with a `queued-` label for the full countdown. The deletion step enforces this itself and refuses anything else, whatever asks for it.
- **Approve first (agreed 2026-11-09):** the rule engine only flags candidates on the review page. Nothing enters a Leaving Soon collection until the user presses Queue. Keep remains available before queueing, during the countdown, and after it expires until deletion is confirmed.
- **No schedule (agreed 2026-11-09):** everything is run manually from the review page: generating the report, queueing, keeping, syncing labels, deleting and emptying the trash. `report.php` and `sync.php` remain as optional command-line tools.
- **Keep marker:** a Plex label named `keep` on the movie or show. A kept title leaves its Leaving Soon collection, its `queued-` label is removed, and it is excluded from future candidate checks. Plex is the source of truth, because not every title is in Radarr or Sonarr.
- **Thresholds (configurable, starting values):**
  - Never watched and added more than 6 months ago: candidate
  - Movies: watched at most once, last watched more than 12 months ago, added more than 6 months ago: candidate. Movies watched more than once are never candidates.
  - Shows: last watched (any episode, any user) more than 12 months ago and added more than 6 months ago: candidate, however much was watched. This covers partly watched and fully watched shows; the play limit does not apply to shows, because their plays are counted per episode.
  - Fully watched titles are not exempt: they are flagged, and the user keeps archive titles with Keep.
  - A title marked as watched with no recorded plays counts as watched (use the watched episode count, or treat a movie as one play).
- **Shows are judged as a whole:**
  - Use Plex `leafCount` and `viewedLeafCount` (and all-account history) to see how much was watched
  - Use the latest play across all accounts for the last time anything in the show was played
  - Use TMDB `status` and `in_production` to decide whether the show is still airing; a show still in production is never a candidate
  - Size is the sum of all episode files
- **Ranking:** candidates sorted by size, largest first
- **Deletion (agreed 2026-11-09):** titles are moved, not deleted, to `_PlexCleanupTrash\YYYY-MM-DD\` on the share (next to the library folders, so Plex never scans it), keeping their folder structure so they can be moved back. Moving within the share is an instant rename, so it takes no extra space, but it frees none either: space is only reclaimed when the trash is emptied. A trash batch can be emptied (permanently deleted) once it is 14 days old, from the page, with typed confirmation. Then Plex is asked to rescan, and its automatic trash emptying removes the titles. Radarr and Sonarr unmonitor titles whose files are missing, so they won't re-download anything.
  - Plex sees the drive as `/storage/7C2C30781B05642C/NVIDIA_SHIELD/...` (the server is an NVIDIA Shield); this PC sees it as `\\192.168.1.147\Seagate Desktop Drive\NVIDIA_SHIELD\...`. The mapping is in `config.php`.
  - Only individual files are moved: every version of a movie, every episode of a show, and sidecar files with the same name (subtitles, `.nfo`). Folders are only removed when left empty, never a library root. Many movies are loose files directly in `Movies\`, so folder deletion would be catastrophic.
  - A title watched during its countdown is still deleted (giving users a last chance to watch is the point of Leaving Soon), but it is flagged "watched since queued" for review.
  - Deleting needs: `dry_run` false, a preview listing every file, and typing the number of titles. Every move and purge is logged to `data/deletions.log`.
- **Dry run first:** every action that changes Plex or the file share supports a dry-run mode, which reports what would change without changing anything. While `dry_run` is true, the page shows a full-width banner and an outline around the window.
- **Local web review page:** a single page on localhost, the main interface for the tool. It shows the candidates table (title, type, size, added date, last watched, play count, the rule that flagged it, TMDB status for shows), the due-for-deletion section with days remaining or overdue, and per-row actions: Keep, and Queue for recommendations. A "Run" action applies approved changes, with a dry-run toggle that reports what would be removed and the space it frees. The page is for one user, so it has no login and no hosting; styling is deferred until the logic works. Command-line entry points (`report.php`, `sync.php`) exist for scheduling, but the page is how decisions are made. Decisions are stored in a local SQLite database only where Plex can't hold the state.
- **Deletion timing:** a candidate enters a 30-day countdown when first queued. The countdown is shown in the review page and the collection. Titles can be kept at any point during the countdown. Once it expires, the title is due for deletion, subject to the dry-run and confirmation steps.
- **Leaving Soon collections:** one collection per library, set in `config.php` by library name: `Movies` → `Movies Leaving Soon`, `TV Shows` → `Series Leaving Soon`, `Anime` → `Anime Leaving Soon` (created by hand 2026-11-09). The tool never creates collections; titles in a library without one can't be queued. Each is promoted to the home screen within its own library. The tool addresses collections by section key, not by name.
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
- Review page, first slice (2026-11-09): `public/index.php` at `http://localhost/plex-cleanup/public/`. Generates the report (about 40 seconds), saves it to `data/report.json`, and shows a sortable, filterable table with totals and CSV download. No Keep or Queue actions yet. Shared logic lives in `src/report.php`; `report.php` (CLI) uses it and also refreshes the saved report. A root `.htaccess` blocks everything outside `public/`, because Laragon's localhost site was serving `.env`.
- Config (2026-11-09): `config.php` holds the rule thresholds, TMDB protection rules (in production, protected statuses, unknown status), the 30-day countdown, label names, collection names and the dry-run default. `src/config.php` validates it on load and lists every problem; read values with `config('rules.unwatched.min_added_months')`. No secrets, so it is committed.
- Rule engine (2026-11-09): `src/rules.php` gives every title a verdict (Keep, Queued, Protected, Candidate, Not a candidate) with a reason, applied on every page load so `config.php` changes take effect on reload. Rules: `unwatched`, `movie_watched` (movies only, play limit), `show_watched` (shows, last watched only). The report now records the `keep` label, `queued-` date and Leaving Soon membership. The review page shows a Candidates card, a Rules panel with per-rule counts and sizes, a Verdict column and filter, and verdicts in the CSV download. First results: 708 candidates (3.07 TB), 11 protected, 6 already in Leaving Soon collections without `queued-` labels.
- Queue sync (2026-11-09, step 4): `src/queue.php` is the only code that changes Plex. It only adds/removes Leaving Soon membership and `keep`/`queued-` labels; it never deletes media. Every action is planned from Plex's current state (preview), applied only when `dry_run` is false, then re-read from Plex to confirm, and the saved report is patched so the page updates without regenerating. Labels are added with the existing labels included (Plex replaces the list) and removed with the `label[].tag.tag-` form. The review page has checkboxes, select-all-shown, a bulk bar (Queue selected / Keep selected), per-row Queue and Keep buttons, a Sync labels button, and a preview screen with Apply; forms carry a session token so other websites can't submit to it. `sync.php` (`--dry-run` to preview) reconciles labels with collections and never queues titles. Tested for real by the user on 2026-11-09 (adding to a collection and adding a label with `dry_run` false): works as expected. `dry_run` is back to true as the committed default.
- Deletion (2026-11-09, step 6): `src/delete.php`. New verdict "Due for deletion"; Delete buttons on due rows and in the bulk bar; a move-to-trash preview listing every file with sizes, warnings and refused titles; typed confirmation; a Trash card and Review trash screen with typed confirmation to empty old batches. Write, move and delete on the share were tested from PHP (the web server runs as the same Windows user). Tested in dry run only: guards, path mapping, refused titles, and a plan built as if 40 days had passed (The Brutalist: one 15.3 GB file). The real move of a media file has not been run yet.
- The CSV output has not yet been fully checked against the actual library. About 600 of 852 movies still show as never watched, even with full history.
- Design agreed: Leaving Soon collections per library, `queued-YYYY-MM-DD` and `keep` labels, deletion direct from the share, and a localhost review page.

## Next steps

1. Check `report.csv` against the Plex library: added dates, blank last-viewed values, TMDB matches, and show sizes.
2. Add a config file for the thresholds, the 30-day countdown, and TMDB rules.
3. Build the rule engine that reads Plex data and applies the rules, skipping anything labelled `keep`.
4. Build the queue sync: add and remove titles from the Leaving Soon collections, and manage the `queued-` and `keep` labels. Implement dry-run here first.
5. Build the local review page on top of the rule engine and queue sync, with the candidates table, due-for-deletion section, row actions, and run button.
6. Add the deletion step: delete files from the share and refresh the Plex library, dry-run first.
7. Check whether Recommended promotion is available on this Plex version, and promote the Leaving Soon collections if so.
8. ~~Schedule monthly runs~~: dropped 2026-11-09; everything is run manually.

## Open questions

- Whether Plex `addedAt` values are reliable after file moves. The current added dates are spread across years, which looks healthy; spot-check a few long-held titles.
- Whether Plex on this server supports promoting a collection to Recommended, and where it then appears.
- Whether the TV library UI shows collections on this Plex version, and where promoted TV collections appear.
