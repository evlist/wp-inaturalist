<!--
SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>

SPDX-License-Identifier: GPL-3.0-or-later
-->

# Slice 006: the block and the species list

## Why

Everything before this slice fills the cache; nothing shows it. The block `wpinat/wp-inaturalist` lists the species observed
during the period around the post's date, as `wpwbd/wp-whobird` does for birds. Visitors never trigger an API call: the block
only reads the cache.

## What

1. **Block** `wpinat/wp-inaturalist` (`blocks/wp-inaturalist/block.json`, registered on `init` by `includes/Block.php`): a dynamic
   block with the attributes `periodNumber` (default 1) and `periodUnit` (`day`, `week`, `month`; default `day`), the same
   as wp-whobird. Server-side rendering only (`save` returns null); the render callback is in PHP, not a `render.php` file.
2. **Period**: `Period::range()` of slice 005 applied to the date of the post being rendered, so that what is refreshed
   (slice 005) and what is shown are computed by the same code.
3. **Reading**: `Cache::getBetween( login, from, to )` returns the cached observations of the configured login over the period,
   oldest first. Nothing else is read: no API call, no coordinates.
4. **Rendering** (`includes/Renderer.php`): observations are **grouped by taxon** (an observation without taxon cannot be named and
   is left out). Each species is one `<li class="wpinat-entry">` with its photo (the first photo of its first observation that
   has one, medium size, linked to that observation on iNaturalist, `loading="lazy"`), its name (common name in the language
   of the cache, else the Latin name) linked to the taxon page on iNaturalist, the Latin name when the common name is shown,
   a count when there are several observations, and the **credit of the photo** (the `attribution` text given by iNaturalist,
   which carries the author and the licence). Everything is escaped on output; an image URL is used only when it is `https`.
   With no observation the block prints **nothing** on the site.
5. **In the editor** the block shows a server-side preview. When the cache has nothing for the period, or no login is set, the
   preview says so (and points to the sidebar button of slice 005 or to the settings) instead of being empty. The block
   has controls for the number and the unit of the period in its sidebar (`blocks/wp-inaturalist/index.js`, plain JavaScript,
   no build). The preview reads the post being edited through the `post_id` it sends to the block renderer route.
6. **Style** (`blocks/wp-inaturalist/style.css`): a simple responsive grid, using the theme's colours.

## Not in this slice

- The `data-index` mark for `wp-otherguise` (slice 007).
- Using the photos of the WordPress media library instead of iNaturalist's (slice 008, open question 1); until then the photos
  are hot-linked from iNaturalist's open-data bucket.
- A Wikipedia link: iNaturalist only gives the English page (open question 5).
- A setting to print a sentence when there is nothing to show (wp-whobird has one).

## Constraints

- No API call from a page view or from the editor preview.
- Names, URLs and credits come from the cache, which comes from the API: they are untrusted text and are escaped.
- Same text domain; strings of the editor script are passed by PHP as for the other scripts.

## Dependencies

004 (cache), 005 (period).

## Decisions taken while coding

- The render callback is registered in PHP (`register_block_type()` with `render_callback`) and `block.json` has no `render`
  file; the editor script handle is registered in PHP (`wpinat-block-editor`) because the plugin has no build step and so no
  generated `.asset.php` with dependencies.
- `PostRefresh::dateOf()` now gives the date of a post for both the refresh and the block, so the period shown and the
  period refreshed come from the same two functions (`Period::range()` and `PostRefresh::dateOf()`).
- Observations are shown as cached: a change of the quality or language setting applies to a range at its next refresh.
- The `https` check on the observation link makes `esc_url()` redundant there; it is kept.

## Verification status

- **Automated tests**: 232 PHP tests (23 new) and 27 JavaScript tests (6 new): grouping, order, counts, names, the photo and its
  credit, hostile names, credits and URLs from the cache, non-https images, corrupted photo data, the period of the block
  (day, week, month, defaults, garbage attributes), login and account separation, no refresh and no job from a page view,
  the editor hint (in its own PHP process because `REST_REQUEST` is a constant), `block.json` consistent with `Period` and
  `Block`, and the editor script (registration, preview for the edited post, clamping, units).
- Twelve deliberate breakages: eleven made a test fail; the survivor is `esc_url()` on the observation link, redundant with the
  `https` check (see above).
- **Real data**: a throw-away script ran a real job against the real API for a real public account, then rendered the block
  from that cache: markup, French names, photo, credit and links as expected.
- **Never tried in WordPress**: registration from `block.json`, the block in a real page and in the editor, the editor preview
  through `wp.serverSideRender` and the block renderer route (does it pass `post_id` the way this script sends it?), the
  sidebar controls, the style, the French strings on screen, and the real look of the grid. Block variations of WordPress
  versions before 6.6 are untested too.

## Corrections after the first uses on Eric's test site (2026-11-26)

- **Wording**: the list said "We observed the following species on iNaturalist"; the species are not observed *on* iNaturalist but
  identified with it. It now says "We identified the following species with iNaturalist:" (French: "Nous avons identifié les
  espèces suivantes grâce à iNaturalist :"), close to wp-whobird's sentence.
- **Block inserted in a theme template (Site Editor)** showed "Erreur de chargement du bloc : [object Object]". Probable cause
  (**a deduction, not reproduced in a real Site Editor**): the editor script sent `post_id` = the id of the edited entity; in a
  template that id is text (`theme//single`) and the block renderer route only accepts an integer, so it answered an error that
  the default placeholder prints as `[object Object]`. Changes: `post_id` is sent only when it is a positive integer; an error
  placeholder prints a readable text (with the message of the route when it is a string); the block declares `usesContext:
  ["postId"]` and uses the post of its context when there is one (a Query Loop, the post content), else the current post; and
  in the editor, with no post, the preview says what the block will do on the site instead of staying empty. On the site, without
  any post, nothing is printed, as before.
- Tests: 340 PHP and 29 JavaScript. One of the new JavaScript guards (`typeof` and `isFinite` on the id) is redundant with the two
  checks after it and is kept as documentation.
