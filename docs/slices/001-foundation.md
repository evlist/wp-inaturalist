<!--
SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>

SPDX-License-Identifier: GPL-3.0-or-later
-->

# Slice 001: foundation

## Why

The shared conventions say to start each plugin with `uninstall.php`, translatable strings, capability and nonce checks and
tests, instead of retrofitting them (wp-whobird needed slices 004 to 007 for that).

## What

1. `wp-inaturalist.php`: plugin header (GPL-3.0-or-later, text domain `wp-inaturalist`, `Requires at least`, `Requires PHP`),
   loads `includes/`, **no activation hook that creates data**.
2. `includes/config.php` (class `Config`): the single source of truth for option names (prefix `wpinat_`), defaults and
   getters; the settings page (slice 003) registers exactly these.
3. `includes/plugin-setup.php`: every hook registered in one place with an explicit priority; text domain loading.
4. `uninstall.php` and `includes/Uninstaller.php`: delete all options, the cache table (once it exists), and unschedule cron
   events. The plugin never touches the user's media library or posts.
5. Tests: `composer.json` (PHPUnit, phpcs with the WordPress standard), `phpunit.xml.dist`, `phpcs.xml.dist`,
   `tests/bootstrap.php` (WordPress stubs), a test of `Config` defaults and of `Uninstaller`.
6. `.editorconfig` (tabs, as the WordPress standard), `.gitignore`, `.gitattributes` (`export-ignore` for `tests/`, `docs/`),
   `bin/make-zip.sh`, SPDX headers and `LICENSES/`.
7. `languages/wp-inaturalist.pot` generated with WP-CLI.

## Constraints

- No build step, no runtime dependency.
- phpcs runs clean from the first commit: no "reformat later" debt.

## Dependencies

None.

## Verification status (to be filled when coded)

Nothing run yet.
