#!/bin/sh
# Builds the release tarball build/screenings-<version>.tar.gz.
#
# Works from a clean copy of the tracked (and untracked, not ignored) files,
# so the dev stack's watcher output in js/ and the dev vendor/ never end up in
# the package. Uses local npm/composer when available, otherwise the same
# Docker images as the dev stack (node:24-alpine, composer:2).
#
# The package ships only what Nextcloud loads at runtime: appinfo, lib,
# templates, img, the built js/ (without source maps) and
# vendor/maxmind-db/reader, which Application::registerVendorAutoloader()
# loads directly. vendor/autoload.php is never used, so it isn't shipped.
set -eu

ROOT=$(cd "$(dirname "$0")/.." && pwd)
VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$ROOT/appinfo/info.xml" | head -n 1)
WORK="$ROOT/build/work"
STAGE="$WORK/screenings"
OUT="$ROOT/build/screenings-$VERSION.tar.gz"

DOCKER=${DOCKER:-docker}
if ! command -v "$DOCKER" >/dev/null 2>&1 && [ -x "/c/Program Files/Docker/Docker/resources/bin/docker.exe" ]; then
	DOCKER="/c/Program Files/Docker/Docker/resources/bin/docker.exe"
fi

# Runs "$@" in $STAGE, natively or in the given Docker image.
run_in() {
	image=$1
	shift
	if command -v "$1" >/dev/null 2>&1; then
		(cd "$STAGE" && "$@")
	else
		# Git Bash: pass a Windows path to Docker and stop it rewriting /app.
		host=$(cd "$STAGE" && (pwd -W 2>/dev/null || pwd))
		MSYS_NO_PATHCONV=1 "$DOCKER" run --rm -v "$host:/app" -w /app "$image" "$@"
	fi
}

echo "Packaging screenings $VERSION"
rm -rf "$WORK"
mkdir -p "$STAGE"
(cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | xargs -0 tar -cf - --) | tar -xf - -C "$STAGE"

run_in node:24-alpine npm ci --no-audit --no-fund
run_in node:24-alpine npm run build
run_in composer:2 composer install --no-dev --no-interaction --no-progress --prefer-dist

# Keep only the runtime parts.
mv "$STAGE/vendor" "$WORK/vendor-all"
mkdir -p "$STAGE/vendor/maxmind-db"
mv "$WORK/vendor-all/maxmind-db/reader" "$STAGE/vendor/maxmind-db/reader"
rm -rf "$WORK/vendor-all"
(cd "$STAGE" && rm -rf node_modules src tests dev docs build .claude .gitignore .gitattributes \
	package.json package-lock.json vite.config.js composer.json composer.lock)
find "$STAGE" -name '*.map' -type f -delete
# Docs, tests and tooling that come with the MaxMind package.
(cd "$STAGE/vendor/maxmind-db/reader" && rm -rf ext tests .github phpstan* phpcs* package.xml CHANGELOG.md)

[ -f "$STAGE/vendor/maxmind-db/reader/src/MaxMind/Db/Reader.php" ] || { echo "MaxMind reader missing" >&2; exit 1; }
ls "$STAGE"/js/screenings-*.mjs >/dev/null || { echo "Frontend build missing" >&2; exit 1; }

tar -czf "$OUT" -C "$WORK" screenings
rm -rf "$WORK"
echo "Wrote $OUT"
echo "For the app store, sign it with: occ integrity:sign-app --path=<extracted>/screenings --privateKey=... --certificate=..."
