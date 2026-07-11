#!/usr/bin/env bash
# Builds a clean WordPress.org-ready zip with runtime files ONLY.
# Whitelist on purpose: a Finder/right-click zip of the working copy ships
# tests/, .claude/, old zips etc. and fails Plugin Check — this cannot.
set -euo pipefail
cd "$(dirname "$0")/.."

ver=$(sed -n 's/^ \* Version:[[:space:]]*//p' order-list-enhancer.php | tr -d '[:space:]')
out="dist/order-list-enhancer-${ver}.zip"

mkdir -p dist
rm -f "$out"
git archive --format=zip --prefix=order-list-enhancer/ -o "$out" HEAD -- \
	order-list-enhancer.php uninstall.php LICENSE readme.txt includes assets languages

echo "Built $out"
unzip -l "$out" | tail -2
