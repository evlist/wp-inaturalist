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
3. Every admin handler checks `current_user_can( 'manage_options' )` **and** a nonce; a failed check does nothing and answers
   403 or shows an error notice.
4. Output is escaped on output (`esc_html`, `esc_attr`, `esc_url`); inputs are sanitized on input.
5. Tests for the sanitizers and for each handler: wrong capability, wrong nonce, right both.

## Constraints

- No secret is ever stored or displayed.
- The login is validated as an iNaturalist login (letters, digits, `-`, `_`).

## Dependencies

Slice 001.

## Verification status (to be filled when coded)

Nothing run yet.
