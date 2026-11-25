<!--
SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>

SPDX-License-Identifier: GPL-3.0-or-later
-->

# Slice 002: iNaturalist API client

## Why

Everything else reads observations through one small client, so that the throttle, the error handling and the shape of the data
live in one place and can be tested without the network.

## What

1. `includes/INatClient.php`: `fetchObservations( string $login, DateTimeInterface $from, DateTimeInterface $to, array $options )`
   calling `GET https://api.inaturalist.org/v2/observations` with `user_login`, `d1`, `d2`, `per_page=200`, `order_by`,
   `locale`, `quality_grade` and a `fields` list (v2, not v1: see `docs/architecture.md`), and following `page` until
   `total_results` is reached. Reaching the page limit with results left raises a `truncated` error: a partial list is never returned. Uses `wp_remote_get()` with a
   timeout and a descriptive `User-Agent` (plugin name and version, site URL).
2. A throttle (one request at a time, minimal delay between requests, about one per second), in the same spirit as wp-whobird's
   `FileLockThrottle`, but based on a transient or an option, not on a file in the temporary directory.
3. Errors: HTTP 429 and 5xx stop the job and report a retry time (`Retry-After` when present); malformed JSON is an error, not
   an empty result.
4. `includes/Observation.php`: a plain value object built from a result (id, uuid, uri, observed date and time, quality grade,
   taxon id, Latin name, common name, rank, iconic taxon, Wikipedia URL, photos with url, licence and attribution). Fields
   missing from the answer are `null`, never invented.
5. Tests with recorded JSON responses in `tests/fixtures/` (page with results, last page, empty, error 429, malformed).

## Constraints

- No request outside a cron job, an admin action or a REST call by a user with the capability (never from a page view).
- No token, no cookie; public data only.

## Dependencies

Slice 001.

## What the first real call changed

The first call of the real API (2026-11-25) showed that v1 answers are about 130 KB per observation and that v2 with a
`fields` list is about 1 KB. The client uses v2. `docs/architecture.md` was corrected from the real answers.

## Verification status

- **Automated tests** (PHPUnit, no network): request building, pagination, the throttle, the 422, 429, 503, transport and
  malformed cases, truncation, incomplete results. Each of seven deliberate breakages of the code made a test fail.
- **Real API**: a throw-away script (not in the repository) ran the client against `api.inaturalist.org` with a real public
  account: pagination over several pages with `per_page=2`, `research` against `any`, French common names, and the 422 for an
  unknown login. The recorded fixtures are real answers (`observations-page.json`, `empty.json`, `error-422.json`).
- **Never tried**: a 429 answer and its `Retry-After`; a 5xx answer; the `Throttle` between two PHP requests of a real
  WordPress site (only the clock and sleep stand-ins); `wp_remote_get()` itself (the real run used cURL through a stand-in).
