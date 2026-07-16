#!/usr/bin/env bash
# Builds a clean WordPress.org-ready zip with runtime files ONLY.
# Whitelist on purpose: a Finder/right-click zip of the working copy ships
# tests/, .claude/, old zips etc. and fails Plugin Check — this cannot.
set -euo pipefail
cd "$(dirname "$0")/.."

# The zip folder is the WP.org slug: Plugin Check derives the expected text
# domain from it, and installs from the directory land in wp-content/plugins/ordelist/.
ver=$(sed -n 's/^ \* Version:[[:space:]]*//p' order-list-enhancer.php | tr -d '[:space:]')
out="dist/ordelist-${ver}.zip"

mkdir -p dist
rm -f "$out"
git archive --format=zip --prefix=ordelist/ -o "$out" HEAD -- \
	order-list-enhancer.php uninstall.php LICENSE readme.txt includes assets languages

echo "Built $out"
unzip -l "$out" | tail -2
