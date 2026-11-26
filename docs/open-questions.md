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

**Slice 008 (coded) tests one answer: match by the wall-clock time of the shot** (EXIF `created_timestamp` of the attachment
against the time of the observation, within 2 seconds), plus a manual link per attachment. Whether this finds the photos of
Eric's gallery is unknown: the report in Tools > iNaturalist tells. If it does not, the other options below (file name,
image content) remain open.

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

Slice 009 answers the first two points (see its document): the **initial load** of all the observations is proposed, and the daily
run becomes an incremental synchronisation with a weekly full reconciliation.

## 4. Cron details

Provisional (slice 004): the daily run looks at the last 14 days, a manual range can go back up to about ten years, and the
refresh on publication and from the editor (slice 005) will fetch the period of that post. Still open: the time of the daily
run is a setting (default 3 h, site time); is a full-account backfill wanted as a one-click action; what a refresh on
publication does when the post date is in the past.

Slice 007 now stores the Wikipedia article in the site language, found through Wikidata (not displayed yet): the choice below
is reduced to whether and how to show it.

Slice 010 shows the link: the article in the site language through Wikidata, else the English article iNaturalist gives (with its
language in the tooltip), else none.

## 5. Wikipedia link in the site language

iNaturalist gives only the English Wikipedia page (`http://en.wikipedia.org/wiki/...`). wp-whobird gets the pages from
Wikidata. Options: link to the English page as given (with `https`); or ask Wikidata for the page in the site language
(a second external service and a cache, as in wp-whobird); or link to the iNaturalist taxon page only.

**Slice 010 (coded) answers the grid part:** cards like wp-whobird's by default, the large-photo grid as the `gallery` option,
two links (the observation on top, Wikipedia at the bottom). Still open below: the period fields inside the block, and the shared
style with `wp-otherguise`.

## 6. Presentation closer to wp-whobird (raised by Eric on 2026-11-25, to keep in mind)

On the first real page the block works well. Eric wonders whether its presentation should be made more homogeneous with
wp-whobird, which shows each bird as a **card** (rounded photo on the left, name in bold, Latin name in italic, a Wikipedia icon
link) in a vertical list, followed by the audio player, while wp-inaturalist shows a **grid** of large photos with a credit under
each. Nothing is decided. Points to settle together: card or grid (or both, chosen by a block option); size of the photos
(the grid favours the photos, which are the point of iNaturalist observations); the credit line, which the licences of the photos
require and which the whobird cards do not have; a Wikipedia link (see question 5); and the shared markup and style, possibly
shared with wp-whobird through `wp-otherguise` so that both blocks follow the same design. No code depends on this.

## 7. Describe the link in the media library metadata (raised by Eric on 2026-11-25, possible improvement)

The report of slice 008 found photos for 30 observations out of 30 on Eric's gallery. Eric would like the information (which
iNaturalist observation a photo belongs to, its taxon, perhaps the licence) to be recorded as **metadata of the attachment** in the
media library, in a format to define. Today only the manual link exists (`_wpinat_observation_id`). Points to decide: the format
(a few post meta keys, a structured value, or taxonomy terms such as a `species` taxonomy on attachments); written by the
refresh job, by a button, or both; whether `wp-otherguise` or `wp-media-helper` should read it; and what to do when the link
changes later.

## 8. Several photos per observation (raised by Eric on 2026-11-25, possible improvement)

An observation can have several photos; the plugin handles only the first one (the one that carries the time of the
observation). To decide: show the other photos too (a small gallery per species, or a lightbox), match each of them with
the media library (each has its own EXIF time, so each can be matched on its own), and how this fits the card or grid
presentation of question 6. The cached data already keeps all the photos of an observation.
