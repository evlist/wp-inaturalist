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
   calling `GET https://api.inaturalist.org/v1/observations` with `user_login`, `d1`, `d2`, `per_page=200`, `order_by`,
   `locale`, and following `page` until `total_results` is reached or a page limit is hit. Uses `wp_remote_get()` with a
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

## First task when coding

Call the real API once (`api.inaturalist.org` must be allowed in the environment's network settings), save one real response
as a fixture, and **correct `docs/architecture.md`** where the remembered field names differ.

## Verification status (to be filled when coded)

Nothing run yet; the API has never been called.
