<!--
SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>

SPDX-License-Identifier: GPL-3.0-or-later
-->

# Open questions

Points that need a decision. Nothing here is settled: no code depends on an assumed answer. When a point is decided, move it
to the slice that implements it and delete it from this list.

## Settled (2026-11-25, by Eric)

- Name `wp-inaturalist`; public observations only (no OAuth, no secret); a list of species for now (no map, no gallery);
  refresh by daily cron, forced on publication and on demand from the editor.

## 1. Match observation photos with the WordPress media library

The photos of the observations are also in Eric's WordPress gallery. Using the local copy would avoid hot-linking iNaturalist
and give full-size images. How to find the match is unknown. Candidates, none tried:

1. Compare the observation's `time_observed_at` with the EXIF capture date of media library items (needs the camera clock and
   time zone to agree; several photos per second are possible).
2. Match by file name if the originals kept their camera names (the API probably does not return the original file name).
3. Compare image content (perceptual hash): robust but heavy, a job of its own.
4. A manual link: a custom field on the attachment holding the observation id or uuid, filled by hand or by a tool.

Questions: which fields does the API really return for a photo; how are the gallery photos named and dated; is a
best-effort automatic match with a manual override acceptable? Until decided, the plugin shows the iNaturalist photo with its
credit and licence.

## 2. Which observations to show

Quality grade threshold (research grade only, or also "needs id"; the setting exists, its default `research` is provisional.
On one real account over 2026-10-01 to 2026-11-24 "research" kept 13 of 28 observations), observations without photo, casual observations, and
duplicates of one species in a period (one entry with a count, or one per observation). Obscured or private coordinates are
not shown anyway.

Provisional choices made in slice 004 (see its document): quality follows the setting, observations without photo are kept,
one entry per observation is stored (grouping is for the renderer).

Slice 005 refreshes the period of a post, computed as in wp-whobird (N units ending on the post date), and a job running at
that moment queues the request (at most 20) instead of dropping it.

Slice 006 groups the cached observations by taxon (one entry per species with a count) and leaves out those without taxon.

## 3. Language of the names

Use the site locale for `preferred_common_name`, with a fallback to the Latin name. Is a per-block override wanted?

## 4. Cron details

Provisional (slice 004): the daily run looks at the last 14 days, a manual range can go back up to about ten years, and the
refresh on publication and from the editor (slice 005) will fetch the period of that post. Still open: the time of the daily
run is a setting (default 3 h, site time); is a full-account backfill wanted as a one-click action; what a refresh on
publication does when the post date is in the past.

## 5. Wikipedia link in the site language

iNaturalist gives only the English Wikipedia page (`http://en.wikipedia.org/wiki/...`). wp-whobird gets the pages from
Wikidata. Options: link to the English page as given (with `https`); or ask Wikidata for the page in the site language
(a second external service and a cache, as in wp-whobird); or link to the iNaturalist taxon page only.
