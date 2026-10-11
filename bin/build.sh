#!/usr/bin/env bash
# Build the plugin zip from the committed tree (HEAD), never the working copy.
#
#   bin/build.sh          dist/letterwp-<version>.zip         the letterwp.com download
#   bin/build.sh wporg    dist/letterwp-<version>-wporg.zip   for WordPress.org
#
# Both unzip to a folder named letterwp/, so a site moves from one to the
# other by updating, not reinstalling. The WordPress.org build has no updater
# (guideline 8): no Update URI header, no inc/class-letterwp-updater.php.
set -euo pipefail
cd "$(dirname "$0")/.."
KIND="${1:-direct}"
VERSION="$(sed -nE "s/^ \* Version:[[:space:]]*([^[:space:]]+).*/\1/p" letterwp.php)"
[ -n "$VERSION" ] || { echo "no Version in letterwp.php" >&2; exit 1; }
git diff --quiet HEAD -- || echo "NOTE: uncommitted changes are not in the zip" >&2

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
git archive --format=tar --prefix=letterwp/ HEAD | tar -x -C "$TMP"
rm -rf "$TMP/letterwp/bin"

case "$KIND" in
	direct) OUT="dist/letterwp-$VERSION.zip" ;;
	wporg)
		OUT="dist/letterwp-$VERSION-wporg.zip"
		rm -f "$TMP/letterwp/inc/class-letterwp-updater.php"
		sed -i '/^ \* Update URI:/d' "$TMP/letterwp/letterwp.php"
		# The bootstrap's updater block: from its comment to the closing brace.
		sed -i '/^\/\/ Only in the copy from letterwp.com/,/^}$/d' "$TMP/letterwp/letterwp.php"
		php -l "$TMP/letterwp/letterwp.php" >/dev/null
		;;
	*) echo "usage: bin/build.sh [wporg]" >&2; exit 1 ;;
esac

mkdir -p dist
rm -f "$OUT"
(cd "$TMP" && zip -qr -X - letterwp) > "$OUT"
echo "$OUT"
