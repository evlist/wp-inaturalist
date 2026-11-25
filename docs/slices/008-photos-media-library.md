<!--
SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>

SPDX-License-Identifier: GPL-3.0-or-later
-->

# Slice 008: photos from the WordPress media library

## Why

The photos of Eric's observations are also in his WordPress media library. Showing the local copy rather than hot-linking
iNaturalist's bucket makes pages independent of iNaturalist's file server, gives responsive sizes (`srcset`) and keeps the
photos with the rest of the blog. The API does not tell which attachment an observation photo is, so the match has to be
found (open question 1).

## The match, and what it relies on

An attachment of the media library has, when the file had EXIF data, `image_meta.created_timestamp`: the **wall-clock time of
the shot** (WordPress reads the camera's local time and stores it as if it were UTC). iNaturalist takes the time of an
observation from the same EXIF data when the photo is uploaded, and returns it in `time_observed_at` with the offset of the
observation's time zone. So **the wall-clock part of `time_observed_at` equals the attachment's `created_timestamp`** when both
come from the same file. This is an assumption, not a fact: it fails when the gallery copies have no EXIF (exports, edited
files), when the observation's time was typed by hand or has no time of day, and for bursts of shots in the same second.
Whether it holds on Eric's gallery is **unknown**; the report below is there to tell.

## What

1. **Schema version 2**: a column `attachment_id` (nullable) in the cache table, added by `dbDelta()` from the version check on
   `plugins_loaded` (the first real migration of the plugin).
2. **`AttachmentIndex`** (`includes/AttachmentIndex.php`): finds image attachments whose `created_timestamp` is within a few
   seconds of a given time. One query on `wp_postmeta` (`_wp_attachment_metadata` of image attachments) narrowed with a `LIKE` on
   the first digits of the timestamp, then exact filtering in PHP after unserialising. No new index table, no backfill job.
   Tolerance: 2 seconds; the closest attachment wins, then the lowest id.
3. **`PhotoLinker`** (`includes/PhotoLinker.php`): for the observations of a date range that have a taxon and a photo, chooses an
   attachment (a **manual link first**, then the automatic match) and stores its id in the cache (`Cache::setAttachment()`).
   Called by the refresh job right after a range is stored, so the daily refresh (last 14 days), a refresh on publication
   and a manual refresh all re-link; a link failure is logged as a warning and never fails the job.
4. **Manual link**: a field "iNaturalist observation" (number or observation URL) on the attachment edit screen and in the media
   modal; stored in the attachment's meta `_wpinat_observation_id`. A manual link wins over the automatic match. Saving needs
   `edit_post` on the attachment.
5. **Setting** "Use the photos of the media library when they match" (default: yes). Off, nothing is matched and the iNaturalist
   photos are used.
6. **Rendering**: when an entry's photo has a linked attachment and WordPress can still output it
   (`wp_get_attachment_image()`, size `medium`, with the name as alt text), that image is used; otherwise the iNaturalist photo, as
   before. The credit line under the photo stays in both cases.
7. **Report** in Tools > iNaturalist: for the 30 latest cached observations, the date and time, the name and whether a media
   library photo is linked (with its thumbnail and a link to it), or why not (no time of day in the observation; no photo of the
   media library at that time), with a count. Read-only; it never calls the API.

## Not in this slice

- Matching by file name or by image content (not possible from the API data, or too heavy).
- A "re-match everything" action: refreshing a range re-links it (new uploads to the media library are picked up by the next
  refresh of that period).
- Importing missing photos into the media library.

## Constraints

- No API call from the report or the rendering. No new external service.
- The manual link value is validated (digits or an `.../observations/<digits>` URL) and the capability is checked.
- The attachment id is never trusted at render time: `wp_get_attachment_image()` decides whether it still exists and is an image.

## Dependencies

004 (job and cache), 006 (rendering), open question 1.

## Decisions taken while coding

- **The match needs no new table and no backfill**: one query on `wp_postmeta` per observation narrows by the first five digits of
  the timestamp and the rest is exact. It handles both ways the metadata can be serialised (the timestamp as a string or as an
  integer). The cost grows with the number of attachments (the `LIKE` starts with a wildcard, so it scans the image metadata
  rows); at the scale measured below this is small, and it only runs in the refresh job, never when a page is shown.
- The manual link lives in the attachment's own meta (`_wpinat_observation_id`), not in the cache, because the refresh replaces
  cache rows: the link survives refreshes and is re-applied to the cache at each one.
- `PhotoLinker::wallClock()` rejects impossible dates (month 13, second 61) that PHP would otherwise roll over; found by a test.
- The photo shown for a species is the first observation with a linked media library copy, else the first iNaturalist photo.
- The setting is applied at the next refresh of a period, not retroactively: turning it off does not unlink the cache rows already
  linked (the renderer does not look at the setting); refresh the period to apply it. *(Open point: should the renderer also
  check the setting? Left as is until Eric decides.)*

## Verification status

- **Automated tests**: 295 PHP tests (63 new) and 27 JavaScript tests: schema version 2, `Cache::setAttachment()` and `latest()`,
  the index (tolerance, order, integer and string timestamps, unusable metadata, SQL shape, two prefixes at a boundary, manual
  lookup), the linker (wall-clock parsing including impossible dates, manual before automatic, no time, no match, only
  observations that will be shown, setting off), the job (linking after each range, a failure only a warning), the renderer (linked
  photo used, credit kept, gone attachment falls back, hostile ids), the media field (parsing of numbers and URLs including
  look-alike hosts, capability, empty and invalid values), the settings checkbox, and the Tools report.
- Eighteen deliberate breakages: all detected after two tests were strengthened; the one that stays invisible is `esc_like()` on
  a prefix made of digits only, kept as defence in depth.
- **Real database**: the media query and the manual-link query ran on **MariaDB 10.11** over 2,000 generated attachments plus the
  edge cases (string and integer timestamps, a PDF, a non-attachment, a missing EXIF time, a window straddling a prefix
  boundary, a manual link): the expected candidates came back, and 30 queries took about 56 ms. **Not tried on MySQL 8, nor on
  real WordPress metadata.**
- **Never tried**: any real media library. Whether Eric's gallery photos carry the EXIF time of the shot, whether it equals the
  time iNaturalist recorded, and how many observations match are **unknown**; the report in Tools > iNaturalist answers this on
  the real site. Also untried: `dbDelta()` adding the column on an existing table (the first migration), the attachment field in
  the media screen and modal, `wp_get_attachment_image()` output in the grid, and the French strings on screen.
