#!/bin/sh
# Builds the release tarball build/screenings-<version>.tar.gz.
#
# Works from a clean copy of the tracked (and untracked, not ignored) files,
# so the dev stack's watcher output in js/ and the dev vendor/ never end up in
# the package. Uses local npm/composer when available, otherwise the same
# Docker images as the dev stack (node:24-alpine, composer:2). The frontend
# is installed with npm ci from package-lock.json either way; see
# build_frontend for how that install is reused between builds.
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

# Docker named volumes for the frontend build (see build_frontend).
NODE_IMAGE=node:24-alpine
PKG_VOLUME=screenings-pkg-build
NPM_CACHE_VOLUME=screenings-pkg-npm-cache

# Builds js/ (and css/, if any) into $STAGE.
#
# In Docker, the build runs on the container's own disk, not in a folder
# shared with Windows: npm ci there took minutes (tens of thousands of small
# files over the file share). The sources go in and the built files come
# out as tar streams.
#
# node_modules is kept in a volume, but only ever written by "npm ci" here,
# and reused only while package-lock.json and the Node version are exactly
# the ones it was installed from (a stamp in the volume). Otherwise npm ci
# runs again, which checks every package against the lockfile's hashes.
# The dev stack's node_modules is never used: its "npm install" may drift.
build_frontend() {
	if command -v npm >/dev/null 2>&1; then
		(cd "$STAGE" && npm ci --no-audit --no-fund && npm run build)
		return
	fi
	mkdir -p "$WORK/frontend"
	(cd "$STAGE" && tar -cf - --exclude=./node_modules .) \
		| MSYS_NO_PATHCONV=1 "$DOCKER" run --rm -i \
			-v "$PKG_VOLUME:/pkg" -v "$NPM_CACHE_VOLUME:/root/.npm" "$NODE_IMAGE" sh -c '
				set -eu
				mkdir -p /pkg/stage && cd /pkg/stage
				# A clean copy of the sources; only node_modules stays.
				find . -mindepth 1 -maxdepth 1 ! -name node_modules -exec rm -rf {} +
				tar -xf -
				stamp="$(node --version) $(sha256sum package-lock.json | cut -d" " -f1)"
				if [ "$(cat node_modules/.screenings-stamp 2>/dev/null)" = "$stamp" ]; then
					echo "node_modules: reusing npm ci of this lockfile ($stamp)" >&2
				else
					npm ci --no-audit --no-fund >&2
					echo "$stamp" >node_modules/.screenings-stamp
				fi
				npm run build >&2
				tar -cf - js $(test -d css && echo css)
			' >"$WORK/frontend.tar" \
		|| { echo "Frontend build failed (see the output above)" >&2; exit 1; }
	tar -xf "$WORK/frontend.tar" -C "$WORK/frontend"
	rm "$WORK/frontend.tar"
	rm -rf "$STAGE/js" "$STAGE/css"
	mv "$WORK/frontend/js" "$STAGE/js"
	[ ! -d "$WORK/frontend/css" ] || mv "$WORK/frontend/css" "$STAGE/css"
	rm -rf "$WORK/frontend"
}

echo "Packaging screenings $VERSION"
# deploy.sh reuses a package whose stamp names the current clean commit; a
# package built here may contain uncommitted changes, so drop the stamp.
rm -f "$OUT.commit"
rm -rf "$WORK"
mkdir -p "$STAGE"
(cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | xargs -0 tar -cf - --) | tar -xf - -C "$STAGE"

build_frontend
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
