#!/usr/bin/env bash
# Publishes the plugin to the WordPress.org SVN repo.
#
# Trunk is exported from git HEAD with the SAME whitelist as bin/build-zip.sh, so the
# published release matches the reviewed zip byte-for-byte. WordPress.org page assets
# (icon/banner/screenshots) come from .wordpress-org/ and land in SVN /assets.
#
# Usage:
#   bin/svn-deploy.sh               prepare trunk + tag + assets in the local SVN checkout,
#                                   show pending changes, but DO NOT commit (safe default)
#   bin/svn-deploy.sh --commit      do all of the above, then `svn ci`
#   bin/svn-deploy.sh --assets-only push ONLY the WordPress.org page assets (icon/banner/
#                                   screenshots) from .wordpress-org/ — no version bump/tag.
#                                   Combine with --commit to actually commit.
#
# First commit needs SVN credentials for winter2007d. Either run once interactively so
# Subversion caches them (~/.subversion/auth), or run the printed `svn ci` yourself.
set -euo pipefail
cd "$(dirname "$0")/.."

SVN_USER="winter2007d"
SVN_URL="https://plugins.svn.wordpress.org/ordelist"
WC=".svn-wc"                       # local SVN working copy (gitignored)
DO_COMMIT="no"
ASSETS_ONLY="no"
for arg in "$@"; do
	case "$arg" in
		--commit) DO_COMMIT="yes" ;;
		--assets-only) ASSETS_ONLY="yes" ;;
		*) echo "Unknown option: $arg"; exit 1 ;;
	esac
done

sync_assets() {  # rsync .wordpress-org/ -> SVN assets/ (only the published files, not sources/README)
	if [ -d ".wordpress-org" ] && [ -n "$(ls -A .wordpress-org 2>/dev/null | grep -vE '^(README|src)' || true)" ]; then
		rsync -a --delete --exclude='.svn/' --exclude='README*' --exclude='src/' .wordpress-org/ "$WC/assets/"
	fi
}

if [ "$ASSETS_ONLY" = "yes" ]; then
	if [ ! -d "$WC/.svn" ]; then
		svn co --depth immediates "$SVN_URL" "$WC"
	fi
	svn up "$WC/assets" --set-depth infinity >/dev/null 2>&1 || true
	mkdir -p "$WC/assets"
	sync_assets
	( cd "$WC/assets"
		svn add --force . >/dev/null 2>&1 || true
		svn status | awk '/^!/ { sub(/^![[:space:]]+/, ""); print }' | while IFS= read -r f; do svn rm --force "$f" >/dev/null; done
	)
	echo "Pending asset changes:"; svn status "$WC/assets" | sed "s#${WC}/##"
	if [ "$DO_COMMIT" = "yes" ]; then
		svn ci "$WC/assets" -m "Update plugin page assets" --username "$SVN_USER"
		echo "Assets committed."
	else
		echo; echo "Dry run. Commit with:  svn ci $WC/assets -m \"Update plugin page assets\" --username $SVN_USER"
	fi
	exit 0
fi

ver=$(sed -n 's/^ \* Version:[[:space:]]*//p' order-list-enhancer.php | tr -d '[:space:]')
[ -n "$ver" ] || { echo "ERROR: cannot read version from order-list-enhancer.php"; exit 1; }

stable=$(sed -n 's/^Stable tag:[[:space:]]*//p' readme.txt | tr -d '[:space:]')
if [ "$stable" != "$ver" ]; then
	echo "ERROR: readme.txt Stable tag ($stable) != plugin version ($ver). WordPress.org serves the Stable tag."; exit 1
fi

# Release from a committed state (mirrors build-zip.sh: SVN trunk == what's in git HEAD).
if [ -n "$(git status --porcelain)" ]; then
	echo "ERROR: working tree is dirty — commit first, then release."; exit 1
fi

# Refuse to overwrite a tag that already exists remotely (released tags are immutable).
if svn ls "$SVN_URL/tags/" 2>/dev/null | grep -qx "$ver/"; then
	echo "ERROR: tag $ver already exists on WordPress.org SVN — bump the version first."; exit 1
fi

# 1. Sparse checkout: root children only, then fill trunk + assets (leave tags shallow).
if [ ! -d "$WC/.svn" ]; then
	echo "Checking out $SVN_URL (sparse) ..."
	svn co --depth immediates "$SVN_URL" "$WC"
fi
svn up "$WC" --depth immediates >/dev/null
svn up "$WC/trunk" "$WC/assets" --set-depth infinity >/dev/null 2>&1 || true
mkdir -p "$WC/trunk" "$WC/assets" "$WC/tags"

# 2. Export runtime files from git HEAD into trunk (identical whitelist to build-zip.sh).
tmp=$(mktemp -d)
git archive --format=tar HEAD -- \
	order-list-enhancer.php uninstall.php LICENSE readme.txt includes assets languages \
	| tar -x -C "$tmp"
rsync -a --delete --exclude='.svn/' "$tmp/" "$WC/trunk/"
rm -rf "$tmp"

# 3. Sync WordPress.org page assets (icon / banner / screenshots), if provided.
sync_assets

# 4. Reconcile SVN adds/removes to match the working tree.
(
	cd "$WC"
	svn add --force trunk assets tags >/dev/null 2>&1 || true
	svn status | awk '/^!/ { sub(/^![[:space:]]+/, ""); print }' | while IFS= read -r f; do
		svn rm --force "$f" >/dev/null
	done
)

# 5. Tag the release: copy the prepared trunk to tags/<ver> (committed in the same revision).
svn cp "$WC/trunk" "$WC/tags/$ver"

echo
echo "Prepared release $ver. Pending SVN changes:"
svn status "$WC" | sed "s#${WC}/##"

MSG="Release $ver"
if [ "$DO_COMMIT" = "yes" ]; then
	echo
	echo "Committing to WordPress.org ..."
	svn ci "$WC" -m "$MSG" --username "$SVN_USER"
	echo "Done. Live shortly at https://wordpress.org/plugins/ordelist (Stable tag $ver)."
else
	echo
	echo "Nothing committed (dry run). Review the changes above, then commit with:"
	echo "  svn ci $WC -m \"$MSG\" --username $SVN_USER"
	echo "or re-run: bin/svn-deploy.sh --commit"
fi
