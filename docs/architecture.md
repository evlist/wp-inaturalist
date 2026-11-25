<!--
SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>

SPDX-License-Identifier: GPL-3.0-or-later
-->

# Architecture

**Slices 001 to 006 and 008 are coded (foundation, API client, settings page, cache and refresh job, refresh of a post, login check, the block, photos of the media library); the index mark (007) and the final documentation (009) are planned.** It changes as slices are written; wishes and undecided points are
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
| Block `wpinat/wp-inaturalist` (`includes/Block.php`, `includes/Renderer.php`, `blocks/wp-inaturalist/`) | everybody (render, cache only), editors (preview) |
| WP-Cron events `wpinat_refresh` (daily, starts the job over the last 14 days) and `wpinat_refresh_step` (single events, run the job by chunks) | WordPress |
| REST `POST /wpinat/v1/refresh` (`post_id`; the server computes the period from the saved post) | `edit_post` for that post + REST nonce |
| REST `POST /wpinat/v1/verify-login` (`login`) | `manage_options` + REST nonce |
| Hook `transition_post_status` (refresh of a post that becomes published and has the block) | WordPress |
| Editor panel `js/editor.js` and settings button `js/settings.js` | editors / `manage_options` |
| Settings page (Settings > iNaturalist), saved through `options.php` | `manage_options` (page and option group) + nonce of the option group |
| Tools > iNaturalist (status, log) and `admin-post.php` actions `wpinat_job_start`, `wpinat_job_stop`, `wpinat_job_resume`, `wpinat_cache_clear` | `manage_options` + one nonce per action |
| Activation, schema migration, uninstall | WordPress |

## Stored data (planned)

- Options (names, defaults, getters in `Config`): login, names language, quality, refresh hour; `wpinat_schema_version`, `wpinat_job_state`, `wpinat_job_log` (not autoloaded), `wpinat_job_lock`, `wpinat_job_queue` (ranges waiting for the running job, not autoloaded).
- Table `{prefix}wpinat_observations` (`includes/Schema.php`, migrated from the schema version on `plugins_loaded`): one row per observation (id, login, taxon and names, dates, quality, photos as JSON, fetch time, and the media library attachment linked to it, schema version 2). The manual link of a photo to an observation is the attachment's meta `_wpinat_observation_id`.
- Transient `wpinat_last_request` (throttle).
- Everything is removed by `uninstall.php`.
