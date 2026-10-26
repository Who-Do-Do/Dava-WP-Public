#!/usr/bin/env bash
# Builds build/dava-tracking.zip from this repo, ready to upload via WordPress admin
# (Plugins > Add New > Upload Plugin) and to attach to a GitHub Release.
#
# The asset name is fixed: dava-tracking.php enables release assets with the regex
# /^dava-tracking\.zip$/, so a zip with a version in its name is never installed
# by the updater. The zip holds one top-level "dava-tracking/" folder.
set -euo pipefail

PLUGIN_SLUG="dava-tracking"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PLUGIN_FILE="$REPO_ROOT/$PLUGIN_SLUG.php"
README_FILE="$REPO_ROOT/readme.txt"
BUILD_DIR="$REPO_ROOT/build"
ZIP_PATH="$BUILD_DIR/$PLUGIN_SLUG.zip"

if [[ ! -f "$PLUGIN_FILE" ]]; then
  echo "Plugin file not found: $PLUGIN_FILE" >&2
  exit 1
fi

VERSION=$(grep -m1 -i '^\s*\*\s*Version:' "$PLUGIN_FILE" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
CONSTANT=$(grep -m1 "define('DAVA_TRACKING_VERSION'" "$PLUGIN_FILE" | sed -E "s/.*'DAVA_TRACKING_VERSION', *'([^']*)'.*/\1/")
STABLE=$(grep -m1 -i '^Stable tag:' "$README_FILE" | sed -E 's/.*Stable tag:[[:space:]]*//' | tr -d '[:space:]')

if [[ -z "$VERSION" || "$VERSION" != "$CONSTANT" || "$VERSION" != "$STABLE" ]]; then
  echo "Version mismatch: header='$VERSION' constant='$CONSTANT' readme Stable tag='$STABLE'" >&2
  exit 1
fi

mkdir -p "$BUILD_DIR"
STAGE_DIR="$(mktemp -d "$BUILD_DIR/stage.XXXXXX")"
trap 'rm -rf "$STAGE_DIR"' EXIT

mkdir -p "$STAGE_DIR/$PLUGIN_SLUG"
rsync -a \
  --exclude='.git' --exclude='.github' --exclude='.gitignore' \
  --exclude='build' --exclude='scripts' \
  --exclude='node_modules' --exclude='.DS_Store' \
  "$REPO_ROOT/" "$STAGE_DIR/$PLUGIN_SLUG/"

rm -f "$ZIP_PATH"
(
  cd "$STAGE_DIR"
  zip -rq "$ZIP_PATH" "$PLUGIN_SLUG"
)

echo "Created $ZIP_PATH (version $VERSION)"
echo "Release: tag v$VERSION, then attach this file as the asset dava-tracking.zip"
