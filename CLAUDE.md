<!--
SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>

SPDX-License-Identifier: GPL-3.0-or-later
-->

# CLAUDE.md

Context for Claude Code sessions on `wp-inaturalist`. The conventions shared by all of Eric's projects are in
[`evlist/shared-room`](https://github.com/evlist/shared-room) (`CLAUDE.md`): read them first. This file only
adds what is specific to this plugin, and stays short; details are in `docs/`.

## Rules that matter most (from shared-room)

- Talk to Eric in **French**; write everything stored in the repository (code comments, docs, commit messages) in **English**.
- Ask before modifying anything until Eric says to go ahead; no pull requests, and no push to other branches than the one agreed, without being asked.
- Never ask for, store or commit tokens or passwords.
- Say what only ran under automated tests and what was never tried on a real WordPress site or against the real iNaturalist API.

## What this plugin is

A WordPress plugin with one block, `wpinat/wp-inaturalist`, that lists the species Eric observed on iNaturalist during the
period around a post's date. It is the iNaturalist counterpart of [`wp-whobird`](https://github.com/evlist/wp-whobird) and
follows its structure. Data: the **public** iNaturalist web API (`api.inaturalist.org/v1`), read by a daily WP-Cron job and
stored in a local cache; page views never call the API. The map of the code is in [`docs/architecture.md`](docs/architecture.md).

## Working here

- **Slices**: one document per feature in `docs/slices/` (index in its `README.md`), written before the code. Points that need
  a decision go in [`docs/open-questions.md`](docs/open-questions.md); nothing in the code may depend on an assumed answer.
- **No build step**: block files are loaded as they are (plain JavaScript and CSS, no JSX, no bundler). Do not introduce one without a slice.
- **Tests**: PHPUnit for PHP (WordPress functions stubbed in `tests/bootstrap.php`, the API stubbed with recorded responses),
  jsdom or `node --test` for scripts (`npm install`, then `npm test`; `node_modules` is not committed), `phpcs` (WordPress standard) for style, **from the first commit**. Every entry point
  (block, REST route, cron job, admin screen, uninstall) has a test; check that a new test fails without the change it covers.
- **Set-up**: `composer install`, then `vendor/bin/phpunit` and `vendor/bin/phpcs`. If Composer plugins are disabled (as in cloud
  sessions), phpcs does not find the WordPress standard: run `vendor/bin/phpcs --config-set installed_paths` with the paths of
  `vendor/wp-coding-standards/wpcs`, `vendor/phpcsstandards/phpcsutils` and `vendor/phpcsstandards/phpcsextra`, comma separated.
- **Security**: every handler checks a capability and a nonce. The public side serves the cache only. No token is ever stored:
  only public observations are read.
- **Cron**: the refresh is a background job with progress, stop and resume, and a log; the server never trusts selections sent
  by the browser (the editor sends a post id, the server computes the period).
- **Hooks**: set explicit priorities in one place (`includes/Setup.php`) and prefix handles.
- **Translations**: text domain `wp-inaturalist`, never translate a variable; regenerate `.pot`, `.po`, `.mo`, `.l10n.php` and JSON
  files with WP-CLI whenever a string changes.
- **Cache schema** carries a version stored in an option and migrated on `plugins_loaded`: activation must never be the only
  place where tables are created (open question 1 of wp-whobird).
- Files carry SPDX headers (GPL-3.0-or-later); JSON and binary files use a `.license` file next to them.
- Releases: a zip built from git; tag `rollout-N` for the blog's rollouts (see shared-room).
