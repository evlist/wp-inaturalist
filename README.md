<!--
SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>

SPDX-License-Identifier: GPL-3.0-or-later
-->

# wp-inaturalist

A WordPress plugin with one block that lists the species observed on [iNaturalist](https://www.inaturalist.org/) by one
user during the period around a post's date. It is meant for a travel blog, next to
[`wp-whobird`](https://github.com/evlist/wp-whobird) (birds identified by the whoBIRD app).

**Status: design only.** There is no code yet; see [`docs/slices/`](docs/slices/README.md).

## Principles

- **Public data only.** The plugin reads public observations through the iNaturalist web API. No token, no OAuth, no secret.
- **Visitors never trigger an API call.** A daily WP-Cron job (and a refresh on publication or on demand from the editor)
  fills a local cache; pages are rendered from the cache.
- **No build step**, as in wp-whobird.
- **Capability and nonce on every handler**; the server computes the period from the post, never from the browser.
- **Honest verification**: tests stub WordPress and the API; what was never tried on a real site or against the real API is
  said in each slice.

## Documents

| Subject | Document |
| --- | --- |
| How it is meant to work | [`docs/architecture.md`](docs/architecture.md) |
| Slices of work | [`docs/slices/README.md`](docs/slices/README.md) |
| Open questions | [`docs/open-questions.md`](docs/open-questions.md) |
| Instructions for Claude Code sessions | [`CLAUDE.md`](CLAUDE.md) |
