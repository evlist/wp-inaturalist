<!--
SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>

SPDX-License-Identifier: GPL-3.0-or-later
-->

# Slices

One document per feature, written before the code. A slice is documented, then coded; its dependencies are listed.

| Slice | Title | Depends on | Status |
| --- | --- | --- | --- |
| [001](001-foundation.md) | Foundation: plugin header, `Config`, uninstall, text domain, test set-up | none | coded; tests and phpcs pass; not tried on a real site |
| [002](002-api-client.md) | iNaturalist API client with throttle | 001 | coded; tested with recorded real answers and once against the real API; not tried inside WordPress |
| [003](003-settings-security.md) | Settings page, capability and nonce checks | 001 | coded; tested (stubs); not tried inside WordPress |
| [004](004-cache-and-refresh-job.md) | Cache table (versioned schema) and the cron refresh job (progress, stop, resume, log) | 001, 002, 003 | coded; see its verification status |
| [005](005-post-refresh-and-login-check.md) | Refresh on publication and on demand from the editor (REST); check of the login on the settings page | 004 | coded; see its verification status |
| 006 | Block and rendering of the species list | 004 | to write |
| 007 | `data-index` mark for `wp-otherguise` | 006 | to write |
| 008 | Photos: credits, licences, match with the media library | 006, open question 1 | to write |
| 009 | Translations, release zip, documentation | all | to write |
