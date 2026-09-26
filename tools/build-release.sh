#!/usr/bin/env bash
set -euo pipefail

# Builds a clean, distributable zip of Account Manager for either edition —
# a single `git archive` call, nothing more. See docs/BRANCHING.md for why
# there are two editions on two branches instead of one codebase with a
# runtime flag.
#
# Every exclusion (tools/, .github/, .claude/, .gitattributes, and every
# docs/ file except INSTALLATION*.md/ABOUT*.md) lives in .gitattributes as
# `export-ignore` — git archive applies those on its own, so this script
# does no post-processing of its own. There is deliberately no
# unzip/filter/re-zip step: `zip` does not exist in Git for Windows, and
# git archive's built-in --format=zip needs it either — it writes the zip
# itself.
#
# --worktree-attributes makes git archive read .gitattributes from the
# working tree instead of from $REF's own commit — required for any tag
# older than .gitattributes itself (e.g. v1.12.0, the single-user freeze
# point): without it, none of the export-ignore rules apply at all, and
# the archive ships docs/masster.md, ROADMAP-SAAS*, DEVELOPMENT*, I18N*,
# and IDENTITY-MODEL* alongside INSTALLATION*/ABOUT*.
#
# Usage:
#   tools/build-release.sh [ref] [single-user|saas]
#
#   ref      Branch, tag, or commit to build from. Defaults to HEAD.
#   edition  Overrides the auto-detected edition (which just checks whether
#            $ref's branch name contains "single-user"). Pass it explicitly
#            when building from a tag, e.g. v1.12.0 — `git name-rev`
#            resolves a tag to "tags/v1.12.0", not a branch name, so
#            auto-detection has nothing to match "single-user" against.
#
# Examples:
#   tools/build-release.sh v1.12.0 single-user
#   tools/build-release.sh v2.1.0 saas
#   tools/build-release.sh                     # HEAD, edition auto-detected

REF="${1:-HEAD}"
EDITION_OVERRIDE="${2:-}"

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

if [ -n "$EDITION_OVERRIDE" ]; then
    EDITION="$EDITION_OVERRIDE"
else
    BRANCH_NAME="$(git name-rev --name-only "$REF" 2>/dev/null || echo "")"
    case "$BRANCH_NAME" in
        *single-user*) EDITION="single-user" ;;
        *) EDITION="saas" ;;
    esac
fi

VERSION="$(git show "$REF:config.php" | grep -oP "APP_VERSION',\s*'\K[^']+" || true)"
if [ -z "$VERSION" ]; then
    echo "Error: could not read APP_VERSION from config.php at ref '$REF'." >&2
    exit 1
fi

OUTPUT="$REPO_ROOT/accountmanager-${VERSION}-${EDITION}.zip"

echo "Building $(basename "$OUTPUT") from ref '$REF' (edition: $EDITION) ..."

git archive --worktree-attributes --format=zip --output="$OUTPUT" "$REF"

echo ""
echo "Built: $OUTPUT"
echo ""
echo "Before publishing this file, verify by hand (any zip viewer works —"
echo "unzip/7-Zip/Explorer/Expand-Archive; the build above didn't need one):"
echo "  [ ] list the archive contents and confirm no *.sqlite / *.bak file appears"
echo "  [ ] confirm no .git/ directory appears"
echo "  [ ] data/.htaccess, data/index.php, data/imports/.gitkeep ARE present"
echo "  [ ] tools/, .github/, .claude/ are absent, and docs/ only has INSTALLATION*/ABOUT*/SECRETS* files"
echo "  [ ] config.php inside the archive reads APP_VERSION '$VERSION'"
