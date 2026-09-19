#!/usr/bin/env bash
# Publish a new version to every site.
# Usage: ./release.sh 1.0.1 "What changed"
set -euo pipefail

VERSION="${1:?Usage: ./release.sh <version> \"notes\"}"
NOTES="${2:-Release $VERSION}"
FILE="studio-olivers-admin.php"

cd "$(dirname "$0")"

if [[ -n "$(git status --porcelain)" ]]; then
  echo "Commit or stash your changes first." >&2
  exit 1
fi

sed -i '' -E "s/^( \* Version: +).*/\1$VERSION/" "$FILE"
sed -i '' -E "s/define\( 'SOA_VERSION', '[^']*' \);/define( 'SOA_VERSION', '$VERSION' );/" "$FILE"

git add "$FILE"
git commit -m "Release $VERSION"
git tag "v$VERSION"
git push origin main "v$VERSION"

# Zip for installing on a site by hand the first time.
git archive --format=zip --prefix=studio-olivers-admin/ -o "studio-olivers-admin.zip" "v$VERSION"

gh release create "v$VERSION" "studio-olivers-admin.zip" --title "$VERSION" --notes "$NOTES"
echo "Released $VERSION. Sites will see the update within 12 hours."
