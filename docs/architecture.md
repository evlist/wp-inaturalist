<!--
SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>

SPDX-License-Identifier: GPL-3.0-or-later
-->

# Architecture

**Slices 001 to 003 are coded (foundation, API client, settings page); the rest is planned.** It changes as slices are written; wishes and undecided points are
in [`open-questions.md`](open-questions.md).

## Flow

1. **Refresh** (background): a daily WP-Cron event, plus a single event scheduled when a post is published, plus a REST route
   called from the editor, all run the same job: ask the iNaturalist API for the observations of the configured user over a
   date range, and store them in the local cache. The job is throttled, logged, and can be stopped and resumed.
2. **Render**: the block's `render.php` computes the period from the post's date and the block attributes (`periodNumber`,
   `periodUnit`: day, week, month; same as wp-whobird), reads the cache only, groups observations by taxon, and outputs one
   `<li>` per species (common name in the site language when available, Latin name, photo with its credit and licence, link to
   the observation or taxon on iNaturalist).
3. **Index mark** (later slice): each entry carries the same JSON `data-index` mark as wp-whobird (source `inaturalist`) for
   the `wp-otherguise` index, with no extra request.

## External service

iNaturalist API, `GET https://api.inaturalist.org/v2/observations`. Checked against the real API on 2026-11-25
(slice 002); recorded answers are in `tests/fixtures/`.

- **v2, not v1.** v1 returns about 130 KB per observation; v2 returns only the fields named in the `fields` parameter
  (rison syntax, constant `INatClient::FIELDS`), which is about 1 KB per observation. A `fields` value that is not valid
  rison gives HTTP 422.
- Filters used: `user_login`, `d1` / `d2` (inclusive dates), `per_page` (200 at most), `page`, `order_by=observed_on`,
  `locale` (language of `preferred_common_name`), `quality_grade=research` (omitted for "any").
- Answer: `total_results`, `page`, `per_page`, `results`. A result has `id`, `uuid`, `uri`, `observed_on`, `time_observed_at`
  (null when the observer gave no time), `observed_time_zone`, `quality_grade`, `geoprivacy`, `obscured`, `taxon`
  (`id`, `name`, `rank`, `preferred_common_name`, `wikipedia_url`, `iconic_taxon_name`) and `photos`
  (`id`, `url`, `attribution`, `license_code`). `taxon` is absent for an observation with no identification.
- `preferred_common_name` is **absent when the taxon has no name in the requested language** (seen: a spider with none in
  French): the Latin name is the fallback.
- `wikipedia_url` is the **English** page, with an `http://` scheme.
- Photo URLs are the `square` size; `small`, `medium`, `large` and `original` exist at the same path (answered 200 for one photo).
- Errors seen: 422 with `{"status","errors":[{"errorCode","message"}]}` for an unknown login. The 429 case and its
  `Retry-After` header were **never seen**: the code handles them from the HTTP convention only.
- No rate-limit header was seen. Polite use: one request per second (`Throttle`) and a descriptive `User-Agent` carrying the
  site URL.

## Planned entry points

| Entry point | Who may call it |
| --- | --- |
| Block `wpinat/wp-inaturalist` (render, editor) | everybody (render, cache only), editors |
| WP-Cron event `wpinat_refresh` (daily and single events) | WordPress |
| REST route "refresh this post's period" | `edit_post` for that post + REST nonce |
| Settings page (Settings > iNaturalist), saved through `options.php` | `manage_options` (page and option group) + nonce of the option group |
| Tools page (log, stop, resume, empty cache) | `manage_options` + nonce |
| Activation, schema migration, uninstall | WordPress |

## Stored data (planned)

- Options (names, defaults, getters in `Config`): iNaturalist login, names language, quality threshold, schema version, job state.
- Cache table with a schema version: one row per observation (id, taxon, dates, photos as JSON, fetch time).
- Everything is removed by `uninstall.php`.
