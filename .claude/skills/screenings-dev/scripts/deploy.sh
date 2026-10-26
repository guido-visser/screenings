#!/bin/sh
# Deploys the app to the production server in one go, with short output.
#
#   sh .claude/skills/screenings-dev/scripts/deploy.sh --check
#       connection, installed version, ffmpeg, conversion setting; changes nothing
#   sh .claude/skills/screenings-dev/scripts/deploy.sh [--enable-transcode]
#       build, upload, swap the app folder (old one kept as ~/screenings-backup),
#       occ upgrade, check; --enable-transcode also allows video conversion
#   sh .claude/skills/screenings-dev/scripts/deploy.sh --rollback
#       puts ~/screenings-backup back and runs occ upgrade
#
# Server details come from dev/deploy.env (git-ignored; see dev/deploy.env.example).
# Only deploy when Guido asks for it.
set -eu

ROOT=$(cd "$(dirname "$0")/../../../.." && pwd)
CONFIG=${DEPLOY_CONFIG:-$ROOT/dev/deploy.env}
[ -f "$CONFIG" ] || { echo "Missing $CONFIG (copy dev/deploy.env.example)" >&2; exit 1; }
. "$CONFIG"
DEPLOY_APPS_DIR=${DEPLOY_APPS_DIR:-apps}

ACTION=deploy
ENABLE=0
for arg in "$@"; do
	case "$arg" in
		--check) ACTION=check ;;
		--rollback) ACTION=rollback ;;
		--enable-transcode) ENABLE=1 ;;
		*) echo "Unknown option: $arg" >&2; exit 1 ;;
	esac
done

SSH_OPTS="-o BatchMode=yes -o ConnectTimeout=10 -o LogLevel=ERROR -i $DEPLOY_KEY"
# Newer OpenSSH warns about post-quantum key exchange on every connection.
quiet() { grep -v -iE 'post-quantum|store now|be upgraded|pq.html' || true; }

# To test this script without the server, set DEPLOY_CONTAINER in the config
# to a local Nextcloud container; it then stands in for the SSH login.
DOCKER=${DOCKER:-docker}
command -v "$DOCKER" >/dev/null 2>&1 || DOCKER="/c/Program Files/Docker/Docker/resources/bin/docker.exe"

# Runs the remote part (stdin) with the settings as positional parameters.
remote() {
	if [ -n "${DEPLOY_CONTAINER:-}" ]; then
		MSYS_NO_PATHCONV=1 "$DOCKER" exec -i -u www-data -w /var/www "$DEPLOY_CONTAINER" \
			sh -c 'HOME=/var/www; export HOME; cd; sh -s -- "$@"' sh "$DEPLOY_NC_DIR" "$DEPLOY_APPS_DIR" "$DEPLOY_PHP" "$@" 2>&1
		return
	fi
	# shellcheck disable=SC2086
	ssh $SSH_OPTS "$DEPLOY_SSH" sh -s -- "$DEPLOY_NC_DIR" "$DEPLOY_APPS_DIR" "$DEPLOY_PHP" "$@" 2>&1 | quiet
}

upload() {
	if [ -n "${DEPLOY_CONTAINER:-}" ]; then
		MSYS_NO_PATHCONV=1 "$DOCKER" exec -i -u www-data "$DEPLOY_CONTAINER" sh -c "cat > /var/www/$2" < "$1"
		return
	fi
	# shellcheck disable=SC2086
	scp -q $SSH_OPTS "$1" "$DEPLOY_SSH:$2" 2>&1 | quiet
}

if [ "$ACTION" = check ]; then
	remote <<'EOF'
cd "$1"; OCC="$3 occ"
echo "nextcloud: $($OCC status 2>/dev/null | sed -n 's/.*versionstring: //p')"
echo "app: $($OCC config:app:get screenings installed_version 2>/dev/null || echo not installed), enabled: $($OCC config:app:get screenings enabled 2>/dev/null || echo no)"
echo "maintenance: $($OCC status 2>/dev/null | sed -n 's/.*maintenance: //p')"
echo "ffmpeg: $(command -v ffmpeg || echo missing) $(ffmpeg -hide_banner -version 2>/dev/null | head -1 | cut -d' ' -f3)"
echo "conversion enabled: $($OCC config:app:get screenings transcode_enabled 2>/dev/null || echo no)"
echo "backup: $(cat ~/screenings-backup/appinfo/info.xml 2>/dev/null | sed -n 's:.*<version>\(.*\)</version>.*:\1:p' || true)"
EOF
	exit 0
fi

if [ "$ACTION" = rollback ]; then
	remote <<'EOF'
set -e
cd "$1"; OCC="$3 occ"
test -f ~/screenings-backup/appinfo/info.xml || { echo "No backup in ~/screenings-backup"; exit 1; }
rm -rf ~/screenings-rolledback
mv "$2/screenings" ~/screenings-rolledback
mv ~/screenings-backup "$2/screenings"
$OCC upgrade 2>&1 | grep -iE "screenings|successful|error" || true
echo "app: $($OCC config:app:get screenings installed_version), enabled: $($OCC config:app:get screenings enabled 2>/dev/null || echo no) (the replaced version is in ~/screenings-rolledback)"
EOF
	exit 0
fi

VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$ROOT/appinfo/info.xml" | head -n 1)
TARBALL="$ROOT/build/screenings-$VERSION.tar.gz"
LOG="$ROOT/build/package.log"

STAMP="$TARBALL.commit"
COMMIT=$(cd "$ROOT" && git rev-parse --short HEAD)
CLEAN=1
(cd "$ROOT" && git diff --quiet HEAD && [ -z "$(git ls-files --others --exclude-standard)" ]) || CLEAN=0
mkdir -p "$ROOT/build"
# Building takes minutes (npm ci); a package of this exact clean commit can be reused.
if [ "$CLEAN" = 1 ] && [ -f "$TARBALL" ] && [ "$(cat "$STAMP" 2>/dev/null)" = "$COMMIT" ]; then
	echo "build $VERSION ($COMMIT): reusing the existing package"
else
	echo "build $VERSION ($COMMIT$([ "$CLEAN" = 1 ] || echo ', with uncommitted changes'))"
	rm -f "$TARBALL" "$STAMP"
	sh "$ROOT/build/package.sh" >"$LOG" 2>&1 || { tail -n 20 "$LOG"; exit 1; }
	[ "$CLEAN" = 0 ] || echo "$COMMIT" >"$STAMP"
fi

echo "upload"
upload "$TARBALL" "screenings-$VERSION.tar.gz"

echo "install"
remote "$VERSION" "$ENABLE" <<'EOF'
set -e
NC="$1"; APPS="$2"; OCC="$3 occ"; VERSION="$4"; ENABLE="$5"
INSTALLED=$(cd "$NC" && $OCC config:app:get screenings installed_version 2>/dev/null || true)
cd ~
rm -rf screenings-new && mkdir screenings-new
tar -xzf "screenings-$VERSION.tar.gz" -C screenings-new
test -f screenings-new/screenings/appinfo/info.xml
rm -rf screenings-backup
if [ -d "$NC/$APPS/screenings" ]; then mv "$NC/$APPS/screenings" screenings-backup; fi
mv screenings-new/screenings "$NC/$APPS/screenings"
rmdir screenings-new
rm -f "screenings-$VERSION.tar.gz"
cd "$NC"
# Decided before unpacking: once the folder is there, app:list shows the app either way.
if [ -n "$INSTALLED" ]; then
	$OCC upgrade 2>&1 | grep -iE "screenings|successful|error" || true
fi
# A deployed app should run, also when it was installed but disabled.
$OCC app:enable screenings >/dev/null
if [ "$ENABLE" = 1 ]; then
	$OCC config:app:set screenings transcode_enabled --value=true --type=boolean >/dev/null
fi
echo "app: $($OCC config:app:get screenings installed_version) (was ${INSTALLED:-not installed}), enabled: $($OCC config:app:get screenings enabled 2>/dev/null || echo no), maintenance: $($OCC status | sed -n 's/.*maintenance: //p'), conversion enabled: $($OCC config:app:get screenings transcode_enabled 2>/dev/null || echo no)"
EOF

echo "check: viewer script $(curl -s -o /dev/null -w '%{http_code}' "$DEPLOY_URL/$DEPLOY_APPS_DIR/screenings/js/screenings-viewer.mjs") (want 200), portal $(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: text/html' "$DEPLOY_URL/index.php/apps/screenings/") (want 303, the login redirect)"
