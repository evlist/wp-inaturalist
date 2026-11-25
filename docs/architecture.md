<!--
SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>

SPDX-License-Identifier: GPL-3.0-or-later
-->

# Architecture

**Planned architecture: nothing below is implemented yet.** It changes as slices are written; wishes and undecided points are
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

iNaturalist API v1, `GET https://api.inaturalist.org/v1/observations`. The following is **from memory and must be checked
against the documentation and the real API in slice 002** (nothing has been called yet):

- filters `user_id` or `user_login`, `d1` / `d2` (observed date range), `per_page` (up to 200), `page`, `order_by`,
  `locale` (names), `quality_grade`;
- each result has `id`, `uuid`, `uri`, `observed_on`, `time_observed_at`, `quality_grade`, `geoprivacy`, `taxon`
  (`id`, `name`, `preferred_common_name`, `rank`, `iconic_taxon_name`, `default_photo`, `wikipedia_url`) and `photos`
  (`id`, `url`, `attribution`, `license_code`);
- polite use: about one request per second, a descriptive `User-Agent`.

## Planned entry points

| Entry point | Who may call it |
| --- | --- |
| Block `wpinat/wp-inaturalist` (render, editor) | everybody (render, cache only), editors |
| WP-Cron event `wpinat_refresh` (daily and single events) | WordPress |
| REST route "refresh this post's period" | `edit_post` for that post + REST nonce |
| Settings page | `manage_options` + nonce |
| Tools page (log, stop, resume, empty cache) | `manage_options` + nonce |
| Activation, schema migration, uninstall | WordPress |

## Stored data (planned)

- Options (names, defaults, getters in `Config`): iNaturalist login, names language, quality threshold, schema version, job state.
- Cache table with a schema version: one row per observation (id, taxon, dates, photos as JSON, fetch time).
- Everything is removed by `uninstall.php`.
