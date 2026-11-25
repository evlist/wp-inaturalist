<!--
SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>

SPDX-License-Identifier: GPL-3.0-or-later
-->

# Slice 003: settings page and security

## Why

Settings need a single source of truth, and wp-whobird found late that the options registered by the settings page and those
read by the renderer had different names (slice 004 of wp-whobird). Here the page and the code read the same `Config`.

## What

1. Settings > iNaturalist (`manage_options`): iNaturalist login, names language (default: the site locale), quality threshold
   (research grade, or also "needs id"), refresh time of day. Registered with `register_setting()` and a sanitize callback for
   each option; the values are read only through `Config` getters.
2. A "Tools > iNaturalist" screen is left to slice 004 (log, stop, resume, empty cache).
3. Saving goes through the Settings API (`wp-admin/options.php`), which checks the nonce of the option group; the capability
   it requires is set explicitly with the filter `option_page_capability_wpinat_settings` (`manage_options`). The page itself
   checks `manage_options` again and answers 403 without any output otherwise. No handler of the plugin reads `$_POST`.
4. Output is escaped on output (`esc_html`, `esc_attr`, `esc_url`); inputs are sanitized on input.
5. Tests for the sanitizers, the registration, the escaping and the capability check of the page. The nonce is checked by
   `options.php`, so it cannot be tested without WordPress (see the verification status).

## Constraints

- No secret is ever stored or displayed.
- The login is validated as an iNaturalist login (letters, digits, `-`, `_`).

## Dependencies

Slice 001.

## Decisions taken while coding

- A value that fails validation keeps the previous value and shows an error notice (no silent correction). The names language
  accepts a two or three letter code (`fr`), not `fr_FR`: the API was only tried with `fr`.
- The refresh hour is stored and validated, but **nothing uses it yet**: the cron job comes with slice 004.
- WordPress calls the sanitize callback twice when an option is saved for the first time (a known quirk of `add_option()`),
  so an error notice may appear twice then.
- The French translation (`languages/wp-inaturalist-fr_FR.po`, `.mo`, `.l10n.php`) was written by Claude and has to be
  reviewed by Eric. The `.pot` and the binary files were generated with WP-CLI 2.12.0 (`i18n make-pot`, `make-mo`, `make-php`).

## Verification status

- 69 tests in all, 28 of them new (`SettingsPageTest`, `TranslationsTest`, hooks in `SetupTest`): registration of exactly the
  options of `Config` with a sanitize callback each, menu capability, 403 without output, escaped values, each sanitizer
  with valid and invalid input, hook and filter priorities, every string of the code present in the `.pot`, French
  translation complete. phpcs is clean.
- Seven deliberate breakages of `SettingsPage` and `Setup` were tried: six made a test fail. The seventh (removing `esc_attr`
  on the login) did not, because `Config::getLogin()` only returns safe characters; the escape stays as defence in depth. The
  escaping of the language field, which shows the raw stored value, is tested with a hostile value.
- **Never tried in a real WordPress**: the menu entry, the form, the nonce and the capability checks of `options.php`, the
  notices, the French translation on screen. The tests replace the Settings API functions with recorders.
