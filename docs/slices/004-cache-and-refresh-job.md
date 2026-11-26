<!--
SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>

SPDX-License-Identifier: GPL-3.0-or-later
-->

# Slice 004: cache table and refresh job

## Why

Pages must never call the API (slice 002 only provides the client). Observations are copied into a local table by a
background job that can be watched, stopped and resumed, and whose log explains what happened. The shared conventions ask for
exactly this for long-running work, and for a schema that does not depend on activation (open question 1 of wp-whobird).

## What

1. **Table `{prefix}wpinat_observations`** (`includes/Schema.php`), created and migrated with `dbDelta()` from a schema version
   stored in the option `wpinat_schema_version` and checked on `plugins_loaded` (not on activation, which does nothing). One row
   per observation: id (primary key), login, uuid, uri, date, time, time zone, quality grade, taxon id, Latin and common names,
   the language of the names, rank (`taxon_rank`: `rank` is a reserved word in MySQL 8), iconic taxon, Wikipedia URL, photos as
   JSON, fetch time. Keys: (login, date) and taxon.
2. **`Cache`** (`includes/Cache.php`): `replaceRange( login, from, to, observations, locale )` upserts what the API returned for
   a date range **and deletes the rows of that login and range that the API no longer returns** (observation deleted, made
   private, or no longer matching the quality filter). Also `count()` and `clear()`.
3. **`Job`** (`includes/Job.php`): the state (option `wpinat_job_state`) holds the status (`idle`, `running`, `stopped`,
   `done`, `failed`), the list of date ranges split in chunks of at most 31 days, the index of the next chunk, counters, the
   attempt number, the last error and who started it (`cron`, `manual`).
   - `start( from, to, trigger )` refuses when a job is running or when no login is configured, otherwise schedules the first step.
   - A **step** (event `wpinat_refresh_step`) takes a lock (an option added atomically, stale after 10 minutes), then fetches and
     stores chunks one after the other for at most 20 seconds, and schedules the next step if chunks remain. Between chunks it
     re-reads the state, so a stop is seen after the chunk in progress.
   - `stop()` sets `stopped` and removes the scheduled step; `resume()` goes back to `running` from the same chunk.
   - Retryable errors (network, 429, 5xx) are tried again later (`Retry-After` when given, else 2, 4, 8... minutes up to 15), at most five
     times in a row, then the job is `failed` and can be resumed. Other errors (invalid answer, truncation, database) make it
     `failed` at once. A failed chunk never leaves a half-written range: the range is written only after the whole fetch succeeded.
   - `JobLog`: the last 200 messages (time, level, text) in the option `wpinat_job_log`, not autoloaded. Messages are plain
     English, not translated, and escaped where they are displayed.
> **Superseded in part by [slice 009](009-initial-load-and-incremental-sync.md):** once a full load exists for the login, the daily
> event synchronises what changed (and reconciles weekly) instead of reloading the last 14 days.

4. **Scheduling** (`includes/Scheduler.php`): a daily event `wpinat_refresh` at the configured hour (site time), scheduled on
   `init` when missing and rescheduled when the hour option changes; it starts a job over the **last 14 days**. The events are
   removed on deactivation and on uninstall.
5. **Tools > iNaturalist** (`includes/ToolsPage.php`): status and progress, the log, the number of cached observations, and four
   actions through `admin-post.php`: refresh a date range (default: the last 14 days), stop, resume, empty the cache. Each
   checks `manage_options` and its own nonce, then redirects with a notice code (never free text).

## Decisions taken here (provisional, to confirm)

These touch open questions 2 and 4; they are the simplest choices that can be changed later without a schema change.

- Quality: the stored rows follow the setting at the time of the refresh (`research` by default). Changing the setting takes
  effect on the next refresh of a range.
- Daily window: the last 14 days only. Older observations are fetched by a manual range here, and later by the refresh of a post
  (slice 005), which knows the post date and the block's period.
- Observations without photo are kept.
- No multisite support.

## Constraints

- No API call outside a cron step or an admin action (never from a page view); the step is the only code that calls the client.
- The table name and every value reaching SQL go through `$wpdb->prepare()` or the formats of `$wpdb->replace()`.
- Dates are checked (`Y-m-d`, from not after to, at most 3660 days) before they reach the job.

## Dependencies

001, 002, 003 (login, locale, quality and hour settings).

## Decisions taken while coding

- `rank` is a reserved word in MySQL 8: the column is `taxon_rank`.
- A stop is seen after the chunk in progress; that chunk is stored in the cache but not counted, so it is fetched again on
  resume (harmless: rows are replaced, not added).
- The scheduled step is a WP-Cron single event; on a site with little traffic WP-Cron only runs when a page is visited. Nothing
  here depends on a system cron, but one calling `wp-cron.php` makes the daily refresh punctual.
- `Tools > iNaturalist` has no automatic refresh of its own: reload the page to see progress.
- The log is plain English (not translated); the screen texts are translated (French written by Claude, to be reviewed).

## Verification status

- **Automated tests**: 140 in all (71 new): schema versions, cache upsert and range replacement, job (chunks, budget, retries
  with `Retry-After`, five attempts then failure, permanent errors, database error, stop, resume, stop during a fetch, fresh
  and stale locks), scheduling (next run in the site time zone, one event, reschedule, deactivation), every Tools action
  (403 without capability, nothing done with a wrong nonce, notices from a fixed list, escaped log), translations.
- Twelve deliberate breakages of `Job`, `Cache`, `ToolsPage`, `Schema` and `Scheduler` each made a test fail.
- **Real API, real client, stub database**: a throw-away script ran a whole job (3 chunks, 2026-09-01 to 2026-11-24) for a real
  public account: 34 observations fetched and cached, French names, log as expected.
- **Real database**: the `CREATE TABLE` statement and the cache queries (replace, delete of what is gone, count, a hostile
  login) ran on **MariaDB 10.11** through a thin `mysqli` adapter. **Not tried on MySQL 8, nor through `$wpdb` and `dbDelta()`.**
- **Never tried in WordPress**: `dbDelta()` on this statement, the real `wp_schedule_*` and WP-Cron execution (daily event,
  step events, `Retry-After` delays), `wp_timezone()`, the Tools screen, `admin-post.php` routing and the redirect after an
  action, the nonce checks, deactivation, the French screen. A 429 answer was never seen from the real service.
