#!/usr/bin/env bash
# Runs on the production host after rsync uploaded a release: links shared state,
# migrates, warms caches, switches the live symlink atomically and prunes old releases.
set -euo pipefail

RELEASE_NAME="${1:?release name required}"
PHP="${PHP_BIN:-php8.5}"
BASE="$HOME/netvibe"
LIVE="$HOME/htdocs/netvibe.bydot.it"
KEEP_RELEASES=5
RELEASE="$BASE/releases/$RELEASE_NAME"

cd "$RELEASE"

# A release that never went live is useless: drop it so retention only counts real deploys.
trap 'echo "Deploy fallito: rimuovo $RELEASE"; rm -rf "$RELEASE"' ERR

ln -sfn "$BASE/shared/.env" .env
rm -rf storage
ln -sfn "$BASE/shared/storage" storage

"$PHP" artisan migrate --force
"$PHP" artisan optimize

# The CloudPanel vhost serves $LIVE/public, so $LIVE itself is the "current" symlink.
# On the first deploy $LIVE is still CloudPanel's placeholder directory.
[ -L "$LIVE" ] || rm -rf "$LIVE"
ln -sfn "$RELEASE" "$LIVE.next"
mv -T "$LIVE.next" "$LIVE"
trap - ERR

# Release names start with a UTC timestamp, so a reverse sort lists the newest first.
cd "$BASE/releases"
ls -1 | sort -r | tail -n +"$((KEEP_RELEASES + 1))" | while read -r old; do
    rm -rf -- "$old"
done

echo "Release attiva: $RELEASE_NAME"
