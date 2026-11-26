<!--
SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>

SPDX-License-Identifier: GPL-3.0-or-later
-->

# Slice 009: initial load and incremental synchronisation

## Why

Two problems with the cache as built in slices 004 and 005 (raised by Eric on 2026-11-26):

1. **A new install, or a new login, starts empty.** Only the last 14 days (daily job), the period of a post when it is published
   and the ranges typed by hand are loaded. The block can be in a **template**, so it applies to every post: there is no way to
   know which periods are relevant. The volume is small (about 1 KB an observation), so the answer is to load **everything**, and to
   *propose* it instead of leaving it to be found on the Tools screen.
2. **The daily refresh only looks at the last 14 days.** An old observation that is identified differently, or gets a photo,
   later stays stale in the cache. The API can return what changed since a date (`updated_since`, checked on 2026-11-26: 37
   observations updated since 1 November on a test account of 860).

## What

### Proposing the initial load

1. **A notice** on the dashboard, the Plugins screen, Settings > iNaturalist and Tools > iNaturalist, for users who can
   `manage_options`, when a login is set and **no full load has been done for that login** (new install, or login changed).
   "Load all my observations" is a button of a form posted to `admin-post.php` (capability and nonce). Nothing starts by itself, and
   **no API call is made when the notice is displayed**.
2. **The action** `wpinat_load_all` (also a button in Tools > iNaturalist): one explicit click that asks the API for the number of
   observations and the date of the first one (two small requests: `per_page=0`, then `per_page=1` oldest first), then:
   - none found: nothing to load, the offer is closed and the screen says so (a mistyped login is likely);
   - more than **20,000**: nothing is started and the screen says why and shows the numbers, so that a start date is chosen with the
     existing "refresh these days" form;
   - otherwise a job starts from the first observation to today, **in chunks of 92 days** (about a third of the requests of 31-day
     chunks), with the trigger `initial`. If the login differs from the one of the last full load, the cache rows of the **other
     accounts are removed first** (they only waste space; the click is the explicit decision).
3. When an `initial` job **ends successfully**, the synchronisation state records the login, the time and the first date.
4. The Tools screen shows the last check of the account (numbers and time), the last full load, the last synchronisation and the last
   reconciliation.

### Incremental synchronisation

5. **State** (option `wpinat_sync`, not autoloaded): login, time of the last full load, of the last synchronisation and of the
   last reconciliation, date of the first observation, result of the last account check.
6. **The daily event** (`wpinat_refresh`) now chooses:
   - no full load for the current login: the **14-day window as before** (so that recent posts work before the full load is accepted);
   - a full load exists and the last **reconciliation is older than 7 days**: a **reconciliation**, i.e. the full load again
     (trigger `reconcile`, chunks of 92 days, from the first date). It is the only way to notice observations that were deleted or
     made private, which `updated_since` cannot report, and it replaces the rows of each range, so what is gone disappears;
   - otherwise a **synchronisation** (trigger `sync`): the observations **updated since** the last synchronisation (minus one
     hour of margin), whatever their date. The API is asked **without the quality filter**; an observation that no longer passes the
     filter of the setting is removed from the cache instead of being kept stale. Rows are updated in place (no range is
     replaced). Photos are linked and taxa resolved for the changed observations only.
7. The synchronisation time is recorded **only when the job ends successfully**, so a failure is retried from the same point the
   next day. If more than 4,000 observations changed (the page limit of one call), the job fails as "truncated" and the next daily
   run does a reconciliation instead.
8. The dates of observations older than the recorded first date lower it, so that a reconciliation does not miss them.

## Not in this slice

- Choosing the quality filter per block, or removing the old account's taxa (the taxa table does not depend on the account).
- Automatic start of the full load (it is only proposed).
- Deleting what was cached by a manual range outside the full load.

## Constraints

- No API call from a page view, an admin page view or the editor: only the explicit click, the cron job and the REST routes of
  earlier slices.
- All the new handlers check `manage_options` and a nonce; the notice shows to those who can act on it.
- The numbers shown come from the account check stored in the option, never from the URL.

## Dependencies

004 (job, cache), 005 (queue), 007 (taxa), 008 (photos).

## Decisions taken while coding

- A full load or a reconciliation may go back **100 years** (36,600 days); manual ranges keep the limit of about ten years. Found
  by a test: without this, an account older than ten years would have been refused as "invalid dates".
- A synchronisation is one chunk whose first value is the marker `@updated` (second value: the moment), so that it reuses the state
  machine of the job (retries, stop, resume, log, queue). The state keeps the login the job was started for: nothing is recorded in
  the synchronisation state if the login changed while it ran.
- Observations updated on iNaturalist are paged by id (`order_by=id`), which stays stable while pages are read.
- If a synchronisation is "truncated" (more than 20 pages of 200 observations changed), the next daily run is a reconciliation.
- The offer closes when a full load ends, or at once for an account that has no observation.

## Verification status

- **Automated tests**: 420 PHP tests (80 new): the client (updated observations: UTC moment, no quality filter, paging and
  truncation; account summary in two small requests, one for an empty account), the cache (in-place updates, removal by id and of
  other accounts, reading by id), the state (login, times that never go back, margin, weekly and forced reconciliation, first
  date that only goes back, corrupted option), the job (chunk sizes, marker validation, queueing, the three daily choices,
  quality filter on synchronisation, linking and resolving only what changed, bookkeeping at the START time of the job and only
  for a job that ended, login changed, truncation), the offer (screens, capability, login change, a loading job, no API call), the
  action (checks, limit, empty account, unavailable service, other accounts removed, queued while a job runs) and the Tools screen.
- Twenty-eight deliberate breakages: all detected after four tests were added or strengthened.
- **Real services** (throw-away script, real public account of 860 observations from 2019): the account check (860, first day
  2019-02-01), the load in 32 chunks (860 observations, 508 taxa, 483 with a Wikidata item, 69 s including Wikidata), then a
  synchronisation from 20 days back (29 observations changed, the cache count unchanged), then a reconciliation. The offer closed
  after the load. Two things were measured there, not guessed: `per_page=0` gives the total, and `updated_since` filters as expected.
- **Never tried**: in WordPress. The notice on the real screens, the form posting to `admin-post.php`, the redirect and notices, WP-Cron
  running the long chain of steps (about 30 steps of up to 20 s here: how many cron runs this takes on a quiet site is unknown),
  the French texts on screen, and a real account older than ten years or larger than 20,000 observations.
