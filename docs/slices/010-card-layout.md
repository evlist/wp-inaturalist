<!--
SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>

SPDX-License-Identifier: GPL-3.0-or-later
-->

# Slice 010: the "card" layout, like wp-whobird

## Why

On the first real pages the block works, but its presentation differs from wp-whobird's (Eric, 2026-11-25 and 2026-11-26, open
question 6): wp-whobird shows each bird as a compact **card** (a small round-cornered photo on the left, the name in bold, the Latin
name in italic, one Wikipedia icon on the right) in a grid of one column on small screens and two on wider ones, while this block
shows a grid of large photos with a credit line and several links. The decision is to make the grid homogeneous with wp-whobird,
keeping the previous presentation as an option of the block.

In French the layout is called **"fiche"** (and "galerie" for the large-photo grid), not "carte", which also means a map and would
clash with a possible map extension of the two plugins. The stored value stays the English `card`, so the label can change freely.

## What

1. **Block attribute `layout`**: `card` (default) or `gallery`. A block saved before this slice has no value and therefore
   takes the new default. The sidebar of the block has a select "Layout" ("Cards" / "Gallery"; French "Fiches" / "Galerie").
   An unknown value means `card`.
2. **`card` markup**: `<li class="wpinat-entry">` with a **thumbnail** (80 × 80 px, cropped, rounded; a media library copy at the
   `thumbnail` size, else the iNaturalist photo at its `small` size), the **name** (bold), the **Latin name** (italic, when the
   common name is shown), `×N` when there are several observations, and a column of **at most two links on the right**:
   - **top: the observation on iNaturalist** (the observation whose photo is shown, else the first one);
   - **bottom: the Wikipedia article** (below).
   When there is only one link it is centred vertically in the card (as wp-whobird's single Wikipedia icon); with two they
   are at the top and the bottom. Neither the photo nor the name is a link. Both links open in a new tab (`rel="noopener
   noreferrer"`), have an accessible name and a tooltip, show a small "↗" external-link mark, and are hidden when printing.
3. **The Wikipedia link**: the article in the language of the site found through Wikidata (slice 007); when there is none, the
   English article that iNaturalist gives (turned to `https`), with a tooltip that names the language ("Wikipedia article (en)");
   otherwise no Wikipedia link.
4. **The credit** of the photo is no longer a visible line in the cards: the photos are those of the account holder's own
   observations. The author and the licence stay in the `title` attribute of the image (a tooltip) and the alternative text is the name.
5. **Style** (`blocks/wp-inaturalist/style.css`): the values of wp-whobird's cards (background `#f9f9f9`, 1 px `#ddd` border, 4 px
   radius, 16 px bold name, 14 px italic Latin name in the theme's neutral colour, one column below 768 px and two above, two
   columns when printing). The `gallery` layout keeps its previous markup and style, scoped to its own class.
6. **Icons** are drawn in the plugin (a small inline SVG eye for the observation, the letter "W" for Wikipedia): no icon font, no
   dependency on wp-whobird, and no use of the iNaturalist or Wikipedia logos (trademarks).
7. `data-index` (slice 007) stays on each `<li>`, in both layouts.

## Not in this slice

- Showing the period fields inside the block (they stay in the sidebar).
- A link to the list of observations of a species when there are several (the link goes to the shown observation).
- Sharing the style with wp-whobird through `wp-otherguise`: the values are copied here (open question 6, last point).

## Constraints

- Everything coming from the cache is escaped; the observation and article addresses are only used when they are `https` (a
  Wikipedia address must be `https://xx.wikipedia.org/wiki/...`).
- No API call, as for the rest of the block.

## Dependencies

006 (block), 007 (Wikipedia article), 008 (media library photos).

## Decisions taken while coding

- Thumbnails: the media library copy at the `thumbnail` size with `sizes="80px"`, else iNaturalist's `small` size (240 px, good for
  80 px at twice the density) with `width` and `height` set to avoid layout shifts.
- The icons are text and a small SVG, not an icon font: the "W" is a serif letter, the observation icon an eye drawn in the plugin.
- A block saved before the `layout` attribute existed takes the new default, `card`: pages already published change as soon as
  the plugin is updated; `gallery` restores the previous look block by block.
- Cards are `<li>` with spans (no `<figure>`); the whole card is not clickable (wp-whobird's cards are, for its audio player).

## Verification status

- **Automated tests**: 446 PHP tests (26 new) and 33 JavaScript tests (1 new): the default and the wrapper class, the thumbnail, the
  names and the absence of a visible credit, at most two links and no link on the photo or the name, their order, accessible
  names, `target` and `rel`, the single centred link, the site-language article against the English fallback (turned to `https`,
  language in the tooltip), a table of malformed Wikipedia addresses (look-alike hosts, not an article, `javascript:`...), the
  observation link (shown photo, else the first one, only `https`), the count, the media library copy and the option, escaping,
  the index mark on cards, the gallery unchanged, the block attribute and the layout control of the editor.
- Fourteen deliberate breakages: all detected.
- **Visual check** (Chromium, 760 and 380 px wide, rendering the real markup with the real stylesheet next to a copy of a wp-whobird
  card using its real stylesheet): same card size, border, radius, thumbnail, text sizes and one-to-two column behaviour; the
  two links are at the top and the bottom, a single one is centred; long names wrap; the gallery option still renders. The
  photos in that page were substitutes (unrelated iNaturalist photos), so the real look with Eric's photos is not checked.
- **Never tried**: in WordPress with the theme's styles (colours from `--wp--preset--color--neutral`, fonts, the width of the
  content column), on a touch screen (the icons are 1.5 em: size of the tap targets is a guess), printing, the editor preview of
  the layout, and the French labels on screen ("Fiches", "Galerie").
