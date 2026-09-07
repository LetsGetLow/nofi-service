#!/usr/bin/env bash
#
# Development entrypoint. It exists for one reason.
#
# compose.override.yaml bind mounts the host source tree into /app, and one of
# those mounts is "./vendor:/app/vendor". ./vendor is gitignored, so on a fresh
# clone it does not exist, the Docker daemon creates it as an empty root owned
# directory, and that empty directory is mounted over the complete vendor this
# image built. bin/console then finds a vendor that exists but holds no
# autoload_runtime.php and throws "Symfony Runtime is missing" before the
# application ever boots — which is what every new machine hit between step 3
# and step 4 of the README.
#
# The dev stage therefore parks its vendor at /opt/vendor, out of reach of
# every mount in the overlay, and this script fills /app/vendor from it. Only
# the dev stage installs it: production mounts nothing over vendor and keeps
# the base image entrypoint.
#
# Written for the Debian based dunglas/frankenphp, which has bash, flock from
# util-linux and coreutils. On the Alpine variant this needs #!/bin/sh and no
# pipefail.
set -euo pipefail

VENDOR=/app/vendor
IMAGE_VENDOR=/opt/vendor
IMAGE_LOCK=/opt/composer.lock
HOST_LOCK=/app/composer.lock
PHPUNIT_CACHE=/app/.phpunit.cache
VAR_DIR=/app/var

# The same story as vendor, one mount over: compose.override.yaml mounts
# ./public over /app/public and public/bundles is gitignored, so on a fresh
# clone the mount hides the assets the image built and every stylesheet,
# script and image on /api/docs 404s — the docs page renders as bare HTML.
# The dev stage parks them at IMAGE_BUNDLES, out of reach of the mount.
BUNDLES=/app/public/bundles
IMAGE_BUNDLES=/opt/public-bundles

# Written before the copy and removed after it. Its presence is the only thing
# that tells our own half finished copy, which is safe to throw away, from a
# vendor the developer installed, which is never touched. autoload_runtime.php
# cannot play that part: cp walks in readdir order and creates it near the
# beginning of a 12,000 entry copy, so a container treating it as "ready" could
# boot against a tree that is still being written.
SEEDING=$VENDOR/.seeding
# Records the composer.lock this vendor was installed from. See vendor_is_current.
SEEDED_FROM=$VENDOR/.seeded-from-lock

# The lock deliberately lives outside the directory it guards. The recovery
# path deletes everything under /app/vendor, and deleting a locked file unlocks
# nothing: the next container would create a fresh inode at the same path and
# win a lock the repairing container still believes it holds. compose.yaml
# gives php and every messenger-worker replica the same var_data volume, so
# /app/var is the one writable path all of them share by inode — and flock
# locks inodes, which is the whole reason it works across containers at all.
LOCK=/app/var/.vendor-seed.lock
LOCK_TIMEOUT=300

log() { printf 'dev-entrypoint: %s\n' "$*" >&2; }

# Everything the daemon creates is root owned, but ./docker runs commands as
# the host developer, so a root owned vendor breaks the first write from
# "./docker composer require". A bind mounted path carries its host owner into
# the container, which is where the number comes from. composer.json is mounted
# by both services — read only in the worker, which stat reads perfectly well.
host_owner() {
    local path owner
    for path in "$HOST_LOCK" /app/composer.json /app/src; do
        if owner=$(stat -c '%u:%g' "$path" 2>/dev/null); then
            printf '%s' "$owner"
            return 0
        fi
    done
    return 1
}

hash_of() { sha256sum < "$1" | cut -d' ' -f1; }

# Current means composer.lock has not moved on since this vendor was installed.
# Two independent signals, because neither alone is right:
#
#   - the lock hash recorded at seed time, which a "git pull" invalidates.
#   - installed.json not being older than the lock, which is what a manual
#     "./docker composer install" leaves behind — it rewrites installed.json
#     and knows nothing about our marker — and is also true of every vendor
#     that predates this script, so existing machines stay quiet.
#
# Note the polarity of the second test: "the lock is not newer than
# installed.json", not "installed.json is newer than the lock", so that the
# equal mtimes a single composer run leaves behind read as current.
vendor_is_current() {
    [ -f "$HOST_LOCK" ] || return 0

    if [ -f "$SEEDED_FROM" ] && [ "$(cat "$SEEDED_FROM")" = "$(hash_of "$HOST_LOCK")" ]; then
        return 0
    fi

    ! [ "$HOST_LOCK" -nt "$VENDOR/composer/installed.json" ]
}

# True when the image's own vendor was built from the composer.lock the working
# tree currently has. Re-seeding from an image that is itself behind would
# leave the dependencies wrong *and* make installed.json newer than the lock,
# silencing the staleness check on every later start.
image_matches_lock() {
    [ -f "$IMAGE_LOCK" ] && [ -f "$HOST_LOCK" ] \
        && [ "$(hash_of "$IMAGE_LOCK")" = "$(hash_of "$HOST_LOCK")" ]
}

# Set by seed() so the bundles below can follow vendor without the branches
# that call it having to say so twice: both come out of the same image, so a
# vendor that was just refreshed means assets that are one release behind.
SEEDED_VENDOR=

seed() {
    local owner=$1

    rm -f "$SEEDED_FROM"
    : > "$SEEDING"

    # "/." copies the contents rather than the directory, because /app/vendor
    # is a mount point: it can be filled but never replaced. rename(2) on a
    # mount point returns EBUSY, which is also why there is no staging
    # directory and atomic swap here.
    cp -a "$IMAGE_VENDOR/." "$VENDOR/"

    [ ! -f "$HOST_LOCK" ] || hash_of "$HOST_LOCK" > "$SEEDED_FROM"
    [ -z "$owner" ] || chown -R "$owner" "$VENDOR"

    # Removed last, once the content and the ownership are both final.
    rm -f "$SEEDING"

    SEEDED_VENDOR=1
}

bundles_are_missing() {
    [ ! -d "$BUNDLES" ] || [ -z "$(ls -A "$BUNDLES")" ]
}

seed_bundles() {
    local owner=$1 staging=$BUNDLES.seeding

    rm -rf "$staging" "$BUNDLES"
    cp -a "$IMAGE_BUNDLES" "$staging"
    [ -z "$owner" ] || chown -R "$owner" "$staging"

    # public/bundles is not itself a mount point — only /app/public is — so
    # unlike vendor the finished tree can be renamed into place rather than
    # filled in position, and no .seeding marker is needed to spot a copy that
    # died half way: it leaves no bundles at all, which the next start reads as
    # missing and seeds again. A half written one can never be left behind.
    mv "$staging" "$BUNDLES"
}

# Repairs a working vendor that has files owned by someone else scattered
# through it — the state an earlier "NOFI_USER=root ./docker composer ..."
# leaves behind. Seeding never touches a populated vendor, so nothing else
# would ever fix them, and the next "./docker composer install" fails on the
# first one. The find aborts at the first offender, so a healthy tree costs one
# directory walk and no writes.
repair_ownership() {
    local owner=$1 uid=${1%%:*}

    [ -n "$owner" ] || return 0
    [ "$uid" != 0 ] || return 0
    [ -n "$(find "$VENDOR" ! -uid "$uid" -print -quit)" ] || return 0

    log "vendor/ holds files owned by someone else; handing them to $owner"
    chown -R "$owner" "$VENDOR"
}

# The same disagreement about ownership as vendor, but in the one directory no
# bind mount reaches: /app/var is the var_data volume, so nothing carries a host
# owner into it. Symfony creates var/cache/<env> the first time that
# environment's kernel boots, owned by whoever booted it — root for frankenphp
# and the workers, the host developer for anything under ./docker. The
# Dockerfile leaves var, var/cache and var/log world writable so the two can
# share, and a kernel booting in a fresh environment normally creates its own
# cache directory 0777 as well, which keeps that going. What is not survivable
# is a root owned one that is *not* world writable: this machine was found with
# var/cache/test at 0755 root:root, and "./docker vendor/bin/phpunit" then died
# in bootKernel() with `Unable to write in the "cache" directory
# (/app/var/cache/test)` before a single test ran. It fails only when the cache
# actually has to be rebuilt — a warm one is merely read — so it waits for the
# next source change and looks like the change broke the suite.
# Which command leaves that behind was never pinned down — running the suite as
# root does not, it creates 0777 — so this repairs the state rather than
# policing the cause.
#
# Handing those directories over costs the server nothing, because root writes
# a directory owned by the developer perfectly well. Only the ones that are
# both root owned and not world writable are touched, so the shared 0777
# directories are left exactly as the image made them and a healthy tree costs
# one stat per environment.
repair_var_ownership() {
    local owner=$1 uid=${1%%:*} path

    [ -n "$owner" ] || return 0
    [ "$uid" != 0 ] || return 0

    for path in "$VAR_DIR"/cache/* "$VAR_DIR/log"; do
        [ -d "$path" ] || continue
        [ -n "$(find "$path" -maxdepth 0 -uid 0 ! -perm -o=w -print -quit)" ] || continue

        log "handing ${path#/app/} to $owner"
        chown -R "$owner" "$path"
    done
}

# None of this applies to a container that is not root: it could neither write
# a root owned mount nor chown the result, and a vendor owned by the wrong user
# is worse than an obviously missing one. ./docker never reaches this script —
# "docker compose exec" bypasses the entrypoint — so this only concerns
# "docker compose run --user".
if [ "$(id -u)" -ne 0 ]; then
    log "not running as root; leaving vendor/ alone"
elif [ ! -d "$IMAGE_VENDOR" ]; then
    log "WARNING $IMAGE_VENDOR is missing; leaving vendor/ alone"
else
    OWNER=$(host_owner || true)
    [ -n "$OWNER" ] || log "WARNING could not determine the host owner; not changing ownership"

    mkdir -p "$VENDOR" "$(dirname "$LOCK")"

    # ">>" creates the file without truncating it, so the inode stays stable
    # across containers. There is deliberately no lock free fast path: the
    # obvious one would test autoload_runtime.php, and that file is visible
    # long before a copy finishes. In the steady state the lock is held for a
    # directory walk, so serialising nine containers on it costs nothing.
    exec 9>>"$LOCK"
    if ! flock -x -w "$LOCK_TIMEOUT" 9; then
        log "ERROR timed out after ${LOCK_TIMEOUT}s waiting for $LOCK"
        log "      another container appears to be stuck seeding vendor/"
        exit 1
    fi

    # Serialised from here, so what follows is never a copy in progress: it is
    # either a final state or the remains of a container that died mid copy.
    if [ -e "$SEEDING" ]; then
        log "a previous seed did not finish; replacing vendor/"
        # -mindepth 1 keeps the mount point, which cannot be removed anyway.
        # Deleting the content is only safe because the marker proves this
        # script wrote it, never the developer.
        find "$VENDOR" -mindepth 1 -delete
        seed "$OWNER"
        log "seeded vendor/ from the image"
    elif [ -z "$(ls -A "$VENDOR")" ]; then
        log "vendor/ is empty; seeding it from the image"
        seed "$OWNER"
    elif [ ! -f "$VENDOR/autoload_runtime.php" ]; then
        log "WARNING vendor/ is not empty but has no autoload_runtime.php."
        log "        This script did not create it, so it is left as it is."
        log "        Run: ./docker composer install"
    elif ! vendor_is_current; then
        if image_matches_lock; then
            log "composer.lock changed; refreshing vendor/ from the image"
            seed "$OWNER"
        else
            log "WARNING vendor/ is older than composer.lock, and this image"
            log "        predates it too. Run: ./docker composer install"
            repair_ownership "$OWNER"
        fi
    else
        repair_ownership "$OWNER"
    fi

    # Assets are cheap to copy and nobody edits them by hand, so this needs
    # none of the staleness reasoning vendor gets: they ship in the same image,
    # so refreshing them whenever vendor is refreshed keeps the two in step.
    # The messenger workers mount no ./public and so seed their own container
    # copy, which costs a few megabytes once and is never served.
    if [ ! -d "$IMAGE_BUNDLES" ]; then
        log "WARNING $IMAGE_BUNDLES is missing; leaving public/bundles alone"
    elif bundles_are_missing; then
        log "public/bundles is empty; seeding it from the image"
        seed_bundles "$OWNER"
    elif [ -n "$SEEDED_VENDOR" ]; then
        log "refreshing public/bundles from the image"
        seed_bundles "$OWNER"
    fi

    # Close the lock before the exec below, or frankenphp would inherit fd 9
    # and hold it for the life of the container, blocking every container that
    # starts after this one.
    exec 9>&-

    # The same empty root owned directory problem as vendor, one mount over:
    # the php service mounts ./.phpunit.cache, gitignored too, so on a fresh
    # clone PHPUnit — which ./docker runs as the host user — cannot write its
    # cache there. The worker has no such mount and the directory is absent.
    if [ -d "$PHPUNIT_CACHE" ] && [ -n "$OWNER" ] && [ "${OWNER%%:*}" != 0 ] \
        && [ "$(stat -c %u "$PHPUNIT_CACHE")" = 0 ]
    then
        log "handing .phpunit.cache to $OWNER"
        chown -R "$OWNER" "$PHPUNIT_CACHE"
    fi

    repair_var_ownership "$OWNER"
fi

# Hand over exactly the way the base image would. docker-php-entrypoint turns a
# first argument starting with "-" into "frankenphp run ...", which is how the
# php service's CMD works, and execs anything else unchanged, which is how the
# worker's "php bin/console messenger:consume" from compose.yaml works.
exec docker-php-entrypoint "$@"
