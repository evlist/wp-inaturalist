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

Quality grade threshold (research grade only, or also "needs id"), observations without photo, casual observations, and
duplicates of one species in a period (one entry with a count, or one per observation). Obscured or private coordinates are
not shown anyway.

## 3. Language of the names

Use the site locale for `preferred_common_name`, with a fallback to the Latin name. Is a per-block override wanted?

## 4. Cron details

Time of the daily run; how far back it looks (only the periods of recent posts, or the whole account once); what a forced
refresh on publication does when the post date is in the past.
