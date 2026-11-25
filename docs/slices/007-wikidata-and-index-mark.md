<!--
SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>

SPDX-License-Identifier: GPL-3.0-or-later
-->

# Slice 007: Wikidata identifiers and the index mark

## Why

The Index module of `wp-otherguise` builds a topic index from the pages as WordPress renders them. It reads an explicit mark,
`data-index`, on an element. Its page reader (`Harvest/PageReader`) recognises as **identifiers** only `wikidata:`, `osm:`,
`geonames:` and `wikipedia:`; any other text is a free-text topic name. wp-whobird writes `wikidata:Q…`, so the same species
seen by whoBIRD and by iNaturalist must carry the same identifier to be one topic of the index. iNaturalist's API does not give
the Wikidata item of a taxon, but Wikidata knows the iNaturalist taxon id (property P3151). Decision (Eric, 2026-11-25): go
through Wikidata.

## What

1. **Schema version 3**: a table `{prefix}wpinat_taxa`: one row per iNaturalist taxon id with the language asked, the Wikidata
   item (`qid`, null when Wikidata has no item with that taxon id), the label of the item **in that language** (null when it has
   none), the address of the Wikipedia article **in that language** (null when there is none) and the time of the check.
2. **`WikidataClient`** (`includes/WikidataClient.php`): one SPARQL query for up to 50 taxon ids
   (`?item wdt:P3151 ?id`, the label with `LANG(?label) = "fr"`, the article from `schema:isPartOf <https://fr.wikipedia.org/>`),
   on `query.wikidata.org`, throttled (one request per second, its own transient) with a descriptive `User-Agent`. Answers are
   validated (item address, article address on the right Wikipedia); with several items for one id the lowest number wins.
   The HTTP part (throttle, transport errors, 429, 5xx) is shared with `INatClient` (`includes/Http.php`) and errors are the
   same typed `INatException`.
3. **`Taxa`** and **`TaxonResolver`**: the resolver asks Wikidata only for taxa that have no row, a row in another language, or an
   **old** row (found: 90 days; not found: 30 days) and stores the answers. A taxon with no Wikidata item is stored too, so
   that it is not asked again at every refresh.
4. **In the refresh job**, after the photos are linked, the taxa of the range are resolved. A failure (Wikidata down, throttled)
   is a warning in the log and never fails the job; the next refresh tries again.
5. **The mark**: on each `<li class="wpinat-entry">` whose taxon has an item, a JSON `data-index` like wp-whobird's:
   `{"topic":"wikidata:Q829683","kind":"species","label":"Moqueur polyglotte","source":"inaturalist"}` (built with
   `esc_attr( wp_json_encode( …, JSON_UNESCAPED_UNICODE ) )`). The `label` is the Wikidata label in the site language and is left
   out when there is none, never the iNaturalist common name (as wp-whobird, so that capitalisation is Wikidata's). `kind` is always
   `species`: the Index module maps the Wikidata class "taxon" to that kind. A taxon with no item gets no mark and the output is
   then unchanged.
6. The address of the Wikipedia article is **stored but not shown**: a visible link changes the presentation, which is open
   question 6.

## Not in this slice

- A visible Wikipedia link (open questions 5 and 6).
- Using the Wikidata label as the displayed name.
- Wikidata images or other properties.

## Constraints

- Wikidata is only called from the refresh job, never from a page view or the editor preview; the block only reads the table.
- Only integers (taxon ids) and a validated language code reach the SPARQL query; the answers are validated before being stored
  and again by escaping on output.
- No personal data is sent: taxon ids and the site language only.

## Dependencies

004 (job), 006 (renderer). Reader side: `wp-otherguise`, `Harvest/PageReader::read_marks()`.

## Decisions taken while coding

- The HTTP part shared by both clients is `includes/Http.php`; `INatClient` now delegates to it (its own tests are unchanged and
  still pass). Each service has its own `Throttle` transient (`wpinat_last_request`, `wpinat_last_wikidata`).
- Errors of Wikidata reuse the typed `INatException` (the name is historical: it is the typed error of both clients).
- A batch that fails is **not** marked as checked: only batches that were answered are stored, so the next refresh asks again.
- `kind` is always `species`, whatever the rank of the taxon: the Index module maps the Wikidata class "taxon" to that kind.

## Observations from the first real run (not decided)

- For some taxa the **label in the site language is the Latin name** (for example `Numenius hudsonicus`, `Phoca vitulina
  richardii`): Wikidata has no French common name for them. The mark then carries the Latin name as label. wp-whobird would do
  the same (it takes the Wikidata name). If the Index should show the iNaturalist common name instead for those, that is a
  decision for Eric and `wp-otherguise`.
- The capitalisation of Wikidata labels is irregular (`Moqueur polyglotte`, `wapiti`, `balbuzard pêcheur`).
- A subspecies (`Populus deltoides subsp. monilifera`) has its own item, so it is a topic of its own, separate from its species.

## Verification status

- **Automated tests**: 334 PHP tests (38 new) and 27 JavaScript tests: the recorded real answer, the query (ids cast to integers,
  language validated, nothing injectable), several items for one taxon, malformed items, labels and addresses, typed errors,
  `Taxa` (what is returned, the 90- and 30-day rules, another language, writes), the resolver (only what is needed, absences
  stored, batches, a failed batch not recorded), the job (resolution after each range, a failure only a warning), the renderer
  (mark format, label rules, hostile values, no mark without an item) and the block, the schema version 3 and the uninstaller.
- Sixteen deliberate breakages: fifteen detected; the survivor is which `Throttle` transient the job's Wikidata client uses
  (wiring in `Scheduler::job()`, harmless if wrong: the two services would then share one delay).
- **Real services**: a throw-away script ran a whole job with the real iNaturalist API **and** the real Wikidata endpoint for a
  real public account: 9 observations, 8 taxa, all 8 with an item, and the block rendered the marks.
- **Never tried**: the `wp-otherguise` Index module reading these marks (only its source code was read: `PageReader::read_marks()`
  and its identifier pattern), `dbDelta()` creating the second table on an existing site (migration 2 to 3), and the SQL of the taxa
  table on a real database (MariaDB or MySQL 8: only the observations and media queries were run there before).
