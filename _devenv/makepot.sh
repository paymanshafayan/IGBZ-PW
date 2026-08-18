#!/usr/bin/env bash
#
# Rebuild igbz-suite/languages/igbz-suite.pot from the source.
#
# wp-cli cannot be installed in this sandbox, so the extraction is done by _devenv/makepot.php
# through the same php-wasm CLI the tests use. The output format matches the previously shipped
# template, so a rebuild shows only the strings that really changed.
#
# Usage:  bash _devenv/makepot.sh            # rewrite the template
#         bash _devenv/makepot.sh --check    # exit 1 if the template is out of date
#
set -Eeuo pipefail

DEVENV="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$DEVENV/.." && pwd)"
WORK="$DEVENV/.work"

die() { printf '\n\033[31merror:\033[0m %s\n' "$*" >&2; exit 1; }

[ -d "$WORK/node_modules/@php-wasm/cli" ] || die "environment not built — run: bash _devenv/setup.sh"

PHP_CLI="$WORK/node_modules/@php-wasm/cli/main.js"
[ -f "$PHP_CLI" ] || PHP_CLI="$(find "$WORK/node_modules/@php-wasm/cli" -maxdepth 1 -name '*.js' | head -1)"
[ -f "$PHP_CLI" ] || die "could not find the php-wasm CLI entry point"

echo "==> extracting translatable strings"
node "$PHP_CLI" "$DEVENV/makepot.php" "$REPO/igbz-suite" "$REPO/igbz-suite/languages/igbz-suite.pot" "$@"
