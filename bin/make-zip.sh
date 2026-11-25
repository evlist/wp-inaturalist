#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
#
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Build wp-inaturalist.zip from the committed files (no build step needed).
# Usage: bin/make-zip.sh [git-ref]   (default: HEAD)
# Files listed with export-ignore in .gitattributes are left out.
set -euo pipefail
cd "$(dirname "$0")/.."
ref="${1:-HEAD}"
rm -f wp-inaturalist.zip
git archive --format=zip --prefix=wp-inaturalist/ -o wp-inaturalist.zip "$ref"
echo "Created wp-inaturalist.zip from $ref"
