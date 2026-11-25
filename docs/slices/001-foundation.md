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
3. `includes/Setup.php`: every hook registered in one place with an explicit priority; text domain loading.
4. `uninstall.php` and `includes/Uninstaller.php`: delete all options, the cache table (once it exists), and unschedule cron
   events. The plugin never touches the user's media library or posts.
5. Tests: `composer.json` (PHPUnit, phpcs with the WordPress standard), `phpunit.xml.dist`, `phpcs.xml.dist`,
   `tests/bootstrap.php` (WordPress stubs), a test of `Config` defaults and of `Uninstaller`.
6. `.editorconfig` (tabs, as the WordPress standard), `.gitignore`, `.gitattributes` (`export-ignore` for `tests/`, `docs/`),
   `bin/make-zip.sh`, SPDX headers and `LICENSES/`.
7. `languages/` and the `.pot`: the plugin has no translatable string yet (they start with the settings page, slice 003), so the
   `.pot` is generated then with WP-CLI, which is not installed in this environment.

## Constraints

- No build step, no runtime dependency.
- phpcs runs clean from the first commit: no "reformat later" debt.

## Dependencies

None.

## Decisions taken while coding

- Namespaced classes (`Evlist\WpInaturalist`), one per file named after the class, loaded with `require_once` (no autoloader);
  PHP 7.4 syntax (no constructor promotion, no union types) because the target PHP version of the blog is not known.
  The code only ran on PHP 8.3.
- Option names have the prefix `wpinat_`; `Config` also lists the options of later slices (schema version, job state, job log)
  so that the uninstaller already knows them.
- `uninstall.php` cleans the site it runs on only (no multisite loop).

## Verification status

- 12 tests (PHPUnit, WordPress functions stubbed in `tests/bootstrap.php`): `Config` defaults and validation, hook
  registration, `Uninstaller::run()`, `uninstall.php` with the WordPress constant, and without it (in a separate PHP process).
- `phpcs` (WordPress standard) is clean on the whole plugin, tests included. The rule exceptions are in `phpcs.xml.dist`
  with their reason.
- Deliberately removing a line of `Uninstaller`, `Setup` or `Config` makes a test fail.
- **Never tried on a real WordPress site**: plugin header, activation, text domain loading, the real uninstall.
