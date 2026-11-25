<!--
SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>

SPDX-License-Identifier: GPL-3.0-or-later
-->

# Slice 005: refresh for a post, and login check

## Why

The daily job only looks at the last 14 days. A post about an older trip must get its observations when it is published, and
the author must be able to ask for a refresh from the editing screen. In the same spirit, the settings page should tell the
author whether the iNaturalist login they typed exists (requested by Eric, not a priority, bundled here because both need a
small REST route and a small script).

## What

1. **`Period`** (`includes/Period.php`): the period of a post, computed **exactly as wp-whobird does** (end = post date + 1 day,
   start = end minus N units): for `periodNumber` N and `periodUnit` day, week or month the days covered are the N units
   ending on the post date, inclusive. `Period::forContent( content, postDate )` reads the attributes of the block
   `wpinat/wp-inaturalist` in the post content with `parse_blocks()` (inner blocks included; defaults 1 day) and returns the
   union of the periods of all such blocks, or null when the post has none. N is clamped to 1..100; an unknown unit means day.
   The post date is the local date of the post (`post_date`), as `get_the_date()` gives.
2. **Refresh on publication** (`includes/PostRefresh.php`): on `transition_post_status`, when a post becomes `publish` (and was
   not published before), is not a revision and contains the block, a refresh of its period is requested. A scheduled post
   triggers it when WordPress publishes it. Updating a post that is already published does not.
3. **Request queue** (`Job::request()`): when a job is already running, a requested range is **queued** (option
   `wpinat_job_queue`, not autoloaded, at most 20 distinct ranges) instead of being dropped, and the job starts the next one
   when it ends. The daily refresh uses it too. The Tools screen keeps refusing a manual start while a job runs, so that the
   user sees why (`busy`); a new notice `queued` exists for the other callers.
4. **REST route `POST /wp-json/wpinat/v1/refresh`** with `post_id`: allowed to users who can `edit_post` that post (the REST
   cookie authentication requires the `X-WP-Nonce` header, otherwise the user is anonymous and refused). The server reads the
   saved post and computes the period itself: **the browser sends no dates**. Answers `{ "status": "started" | "queued" |
   "no_block" | "no_login" | "bad_range" }`.
5. **Editor panel** (`js/editor.js`, plain JavaScript, no build): a "iNaturalist" panel in the document sidebar with a
   "Refresh observations" button. It saves the post first when it has unsaved changes (the server only sees saved content),
   calls the route and shows a notice from fixed texts passed by PHP.
6. **Login check**: `INatClient::fetchUser( login )` (`GET /v1/users/{login}`: 200 with the profile, 404 `User does not
   exist` for an unknown login, checked on 2026-11-25) and the REST route `POST /wp-json/wpinat/v1/verify-login` with `login`,
   allowed to `manage_options` (and the REST nonce). It answers `{ "status": "found" | "not_found" | "invalid" |
   "unavailable", "login": ... }`; `found` carries the exact login of the account. A button "Check" next to the login field
   (`js/settings.js`) sends the **typed** value, so it works before saving. It never blocks saving: an iNaturalist outage must
   not prevent changing settings.

## Constraints

- No dates and no ranges come from the browser; no free text travels in a URL or a notice.
- No API call from a page view; the login check is an explicit click by a user with `manage_options`.
- The scripts use no library and no build step; their texts come from PHP (translated in the `.pot`), not from JS i18n files.
- The editor panel and the check button do nothing without JavaScript (the button is hidden until the script runs).

## Dependencies

001 to 004. The block itself is slice 006: until it exists, a post "contains the block" when its content has the block comment
`<!-- wp:wpinat/wp-inaturalist ... -->`, which is what the editor will write.

## Decisions taken while coding

- The scripts use `wp.apiFetch`, which adds the REST nonce by itself; no nonce or URL is passed in the page data.
- The panel uses `wp.editor.PluginDocumentSettingPanel` (WordPress 6.6 and later) and falls back to `wp.editPost`.
- A failed job keeps its queue; the next request, or a resume, goes on from there. A stopped job also keeps its queue.
- Login matching is case-insensitive at iNaturalist: the check answers the exact login of the account, shown to the user
  (checked with `ZONATA`, which gave `zonata`). The setting itself is saved as typed.

## Verification status

- **Automated tests**: 209 PHP tests (69 new) and 21 JavaScript tests (jsdom for the settings script, a fake `wp` global for
  the editor script; `npm install` then `npm test`). They cover the period rule (units, clamping, month ends, several and
  nested blocks), publication transitions (published once, never on update or revision), the queue (order, duplicates, bound,
  failure), the routes (permissions, no dates from the browser, every status), the client's user request, and both scripts.
- Fourteen deliberate breakages each made a test fail, except one: removing `rawurlencode()` on the login in the user URL, which is
  redundant because `Config::isValidLogin()` already refuses every unsafe character (kept as defence in depth).
- **Real API**: `Rest::verifyLogin()` with the real client answered `found` (also for a different case), `not_found` and
  `invalid` as expected.
- **Never tried in WordPress**: the `transition_post_status` hook with a real publication or a scheduled post, `parse_blocks()`
  on real content, the REST registration and the cookie and nonce authentication, `wp.apiFetch`, the editor panel and its
  snackbar notices (on any WordPress version), the check button on the real settings page, the French strings on screen.
  The block `wpinat/wp-inaturalist` does not exist yet (slice 006): a post "has the block" when its content has the block
  comment, so the editor panel can be tried by typing that comment in a code editor view.
