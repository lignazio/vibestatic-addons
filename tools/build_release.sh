#!/usr/bin/env bash
#
# Builds one add-on's distributable ZIP into dist/.
#
#   tools/build_release.sh <add-on>
#
# where <add-on> is the tag prefix: bunnycdn, advanced-crawling, and so on.
# The version comes from the plugin's own header, which is the single source of
# truth for it.
#
# Much shorter than the core's, and the reason is the whole design: **an add-on
# has no runtime dependencies.** No Composer, no Strauss, no vendor/ — the HTTP
# client it uses is the core's prefixed Guzzle, because two plugins shipping
# incompatible copies of the same library under the same class names break each
# other. There is nothing to install and nothing to prefix; there is a directory
# to zip.

set -euo pipefail

command -v zip > /dev/null || { echo "Serve 'zip'. Installalo e riprova." >&2; exit 1; }

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

ADDON="${1:-}"
[ -n "$ADDON" ] || { echo "Uso: tools/build_release.sh <add-on>" >&2; exit 1; }

SLUG="vibestatic-addon-$ADDON"
SRC="$ROOT/addons/$SLUG"
PLUGIN_FILE="$SRC/$SLUG.php"
DIST="$ROOT/dist"

[ -f "$PLUGIN_FILE" ] || { echo "Non esiste: $PLUGIN_FILE" >&2; exit 1; }

VERSION="$(grep -m1 -E '^\s*\*\s*Version:' "$PLUGIN_FILE" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"
[ -n "$VERSION" ] || { echo "Versione non trovata in $PLUGIN_FILE" >&2; exit 1; }

NAME="${2:-$SLUG-$VERSION}"
BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT

echo "Costruisco $NAME.zip (versione $VERSION)"

# The directory inside the zip is the plugin's slug, and that is the part that
# matters: WordPress installs a plugin wherever the zip puts it. GitHub's own
# zipball unpacks into `lignazio-vibestatic-addons-<sha>`, which would install
# the entire monorepo as one plugin — the reason Addon\Updater looks for an
# attached asset and never falls back on zipball_url.
mkdir -p "$BUILD/$SLUG"

for item in src views languages; do
    [ -e "$SRC/$item" ] && cp -R "$SRC/$item" "$BUILD/$SLUG/"
done

for file in "$SLUG.php" autoload.php uninstall.php LICENSE README.md readme.txt; do
    [ -f "$SRC/$file" ] && cp "$SRC/$file" "$BUILD/$SLUG/"
done

# Development material never reaches a user: no tests, no composer.json (there
# is nothing to install), no analysis configuration.
rm -rf "$BUILD/$SLUG/tests"

# macOS leaves one of these in every directory it has been looked at in.
find "$BUILD" -name '.DS_Store' -delete

mkdir -p "$DIST"
rm -f "$DIST/$NAME.zip"

( cd "$BUILD" && zip -rq "$DIST/$NAME.zip" "$SLUG" )

echo "Fatto: dist/$NAME.zip"
unzip -Z1 "$DIST/$NAME.zip" | sed 's/^/  /'
