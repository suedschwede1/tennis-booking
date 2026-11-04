#!/usr/bin/env bash
#
# Deploy the committed HEAD to the Helloly cPanel host (SSH alias "helloly").
# Run from WSL in the repository root:
#
#   scripts/deploy-helloly.sh [--dry-run] [--allow-branch] [--yes] [--skip-build]
#                             [--first-deploy] [target-dir]
#
# target-dir defaults to ~/apps/bookingnew (a path on the server, outside the web root).
# A leading "~/" is expanded on the SERVER, so pass it quoted ('~/apps/x') or the local
# shell expands it first. Allowed characters: ^(~/)?[A-Za-z0-9._/-]+$
#
# Options / environment:
#   --dry-run       print every step without executing anything over ssh (no prompt)
#   --allow-branch  allow deploying a branch other than $EXPECTED_BRANCH
#   --yes           skip the "Proceed? [y/N]" prompt
#   --skip-build    do not run `npm run build` (e.g. WSL has no usable Node: build from
#                   Windows and commit public/build first). public/build/manifest.json
#                   must still be committed and the working tree clean.
#   --first-deploy  required for the very first deploy (no artisan on the server yet, or
#                   no `migrations` table in the DB, e.g. a DB created by the old ZF2
#                   system). Deploys the code and runs composer install + optimize:clear,
#                   but does NOT run `migrate --force` and does NOT bring the site back
#                   up (maintenance mode stays on if it was started). It then prints the
#                   manual steps you must run on the server.
#   EXPECTED_BRANCH branch that may be deployed without --allow-branch (default: master)
#
# Without --first-deploy the script REFUSES (exit 1, before `artisan down` and before
# extracting anything) when `php artisan migrate:status` fails on the server (migrations
# table missing, DB unreachable) or when there is no artisan yet. `migrate:status`
# exits non-zero with "Migration table not found" if the repository table is absent, so
# it is a read-only, reliable probe; a plain `migrate --force` must never be the thing
# that creates the migrations table on a ZF2-created database.
#
# Flow on the server: pre-flight (.env must exist; drop generated bootstrap/cache files;
# migrations-table check; maintenance mode `php artisan down` if the app is installed)
# -> remove repo-owned dirs (so deleted files/migrations do not linger) -> extract ->
# drop generated bootstrap/cache files again (they may list dev-only providers) ->
# composer install -> optimize:clear -> migrate --force -> config/route/view cache ->
# php artisan up. With --first-deploy the migrate/cache/up steps are skipped (see above).
# On any failure after maintenance mode started the site is LEFT IN MAINTENANCE MODE;
# fix the problem and run `php artisan up` by hand.
#
# Deliberately NOT done here (manual, one-time steps documented in the runbook and in
# docs/superpowers/specs/2026-11-04-helloly-laravel-cutover-design.md), on the server in
# the app directory, in this order, after a --first-deploy:
#   1. php artisan migrate:install
#   2. php artisan db:baseline-legacy --dry-run    (review the output before continuing)
#   3. php artisan db:baseline-legacy
#   4. php artisan migrate --force
# Never add them to this script.
#
# Never touched on the server: .env, storage/, vendor/ (composer updates it).
# The wipe never deletes *.sqlite* files; pre-flight refuses a server .env with DB_CONNECTION=sqlite.
# Not deployed (git-ignored per-installation overrides, place them on the server by hand;
# the wipe keeps them if present):
#   1. lang/{de,en}/booking/local.php
#   2. config/calendar.local.php
#   3. lang/{de,en}/booking/quotes.local.php
#   4. lang/{de,en}/booking/quotes_named.local.php
#   5. lang/de/booking/quote_groups.php
set -euo pipefail

EXPECTED_BRANCH="${EXPECTED_BRANCH:-master}"
SSH_HOST="helloly"
DRY_RUN=0
ALLOW_BRANCH=0
ASSUME_YES=0
SKIP_BUILD=0
FIRST_DEPLOY=0
TARGET="~/apps/bookingnew"

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        --allow-branch) ALLOW_BRANCH=1 ;;
        --yes) ASSUME_YES=1 ;;
        --skip-build) SKIP_BUILD=1 ;;
        --first-deploy) FIRST_DEPLOY=1 ;;
        -*) echo "Unknown option: $arg" >&2; exit 2 ;;
        *) TARGET="$arg" ;;
    esac
done

if ! [[ "$TARGET" =~ ^(~/)?[A-Za-z0-9._/-]+$ ]]; then
    echo "Invalid target dir '$TARGET' (allowed: ^(~/)?[A-Za-z0-9._/-]+\$)." >&2
    exit 2
fi
case "$TARGET" in
    *..*) echo "Invalid target dir '$TARGET' (no '..' allowed)." >&2; exit 2 ;;
esac

# Remote form of the target: validated charset, so double quotes are safe; ~/ becomes
# "$HOME/..." which the remote shell expands.
case "$TARGET" in
    "~/"*) RT="\"\$HOME/${TARGET#\~/}\"" ;;
    *) RT="\"$TARGET\"" ;;
esac

step() { echo "==> $*"; }

# 1. Clean tree, branch check
if [ -n "$(git status --porcelain)" ]; then
    echo "Refusing to deploy: working tree is not clean (commit or stash first)." >&2
    git status --short >&2
    exit 1
fi

branch="$(git rev-parse --abbrev-ref HEAD)"
if [ "$branch" != "$EXPECTED_BRANCH" ] && [ "$ALLOW_BRANCH" -ne 1 ]; then
    echo "Refusing to deploy: HEAD is on '$branch', expected '$EXPECTED_BRANCH' (use --allow-branch to override)." >&2
    exit 1
fi
commit="$(git rev-parse --short HEAD)"

# 2. Build assets locally (no Node on the server); the result must already be committed
if [ "$SKIP_BUILD" -eq 1 ]; then
    step "Skipping asset build (--skip-build); verifying the committed build"
else
    step "Building assets"
fi
if [ "$DRY_RUN" -eq 1 ]; then
    if [ "$SKIP_BUILD" -eq 1 ]; then
        echo "[dry-run] (skipped) npm run build"
    else
        echo "[dry-run] npm run build"
    fi
    echo "[dry-run] verify public/build/manifest.json is committed"
    echo "[dry-run] verify git status --porcelain is still empty (public/build committed)"
else
    if [ "$SKIP_BUILD" -ne 1 ]; then
        npm run build
    fi
    if ! git ls-files --error-unmatch public/build/manifest.json >/dev/null 2>&1; then
        echo "Refusing to deploy: public/build/manifest.json is not committed (run 'npm run build' from Windows and commit public/build)." >&2
        exit 1
    fi
    if [ -n "$(git status --porcelain)" ]; then
        echo "Refusing to deploy: the build changed tracked files (public/build not committed)." >&2
        git status --short >&2
        exit 1
    fi
fi

# 3. Confirmation
echo "Branch: $branch"
echo "Commit: $(git rev-parse HEAD)"
echo "Host:   $SSH_HOST"
echo "Target: $TARGET"
if [ "$FIRST_DEPLOY" -eq 1 ]; then
    echo "Mode:   FIRST DEPLOY (no migrate, site stays in maintenance mode)"
fi
if [ "$DRY_RUN" -eq 0 ] && [ "$ASSUME_YES" -ne 1 ]; then
    read -r -p "Proceed? [y/N] " answer
    case "$answer" in
        y|Y|yes|YES) ;;
        *) echo "Aborted." >&2; exit 1 ;;
    esac
fi

# Generated bootstrap caches must never survive a deploy: they can list dev-only providers
# (Dusk, Pail, ...) that do not exist in the server's --no-dev vendor/ and would fatal
# every artisan call. composer install regenerates them.
clear_generated_cmd='rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php bootstrap/cache/events.php bootstrap/cache/modules.php bootstrap/cache/routes-*.php'

# Pre-flight (runs BEFORE maintenance mode and before anything is extracted):
#   - .env must exist and must not be sqlite
#   - if the app is installed: drop stale generated caches, then (unless --first-deploy)
#     require a working `migrate:status`, then `artisan down`
#   - if no artisan exists yet it is a first deploy: refuse unless --first-deploy
preflight_body='test -f "$T/.env" || { echo "No .env in $T - wrong target dir or not set up yet." >&2; exit 1; }
if grep -Eq "^[[:space:]]*DB_CONNECTION=[\"[:space:]]*sqlite" "$T/.env"; then
  echo "Server .env uses DB_CONNECTION=sqlite; production must be MySQL. Refusing (the wipe would endanger database/*.sqlite)." >&2
  exit 1
fi
if [ -f "$T/artisan" ]; then
  cd "$T" || exit 1
  '"$clear_generated_cmd"'
  if [ "$FIRST" -ne 1 ]; then
    if ! status_out="$(php artisan migrate:status 2>&1)"; then
      echo "Refusing to deploy: php artisan migrate:status failed (migrations table missing or DB unreachable)." >&2
      echo "If this is the first deploy over a database created by the old ZF2 system, re-run with --first-deploy." >&2
      echo "$status_out" | tail -n 5 >&2
      exit 1
    fi
  fi
  php artisan down && echo MAINTENANCE_ON
elif [ "$FIRST" -ne 1 ]; then
  echo "Refusing to deploy: no artisan in $T - this is a first deploy; re-run with --first-deploy." >&2
  exit 1
fi'
remote_preflight="T=$RT; FIRST=$FIRST_DEPLOY; $preflight_body"

# WIPE-BEGIN
# Removes tracked-looking files only. Keeps the git-ignored per-installation overrides
# (local.php, *.local.php, lang/de/booking/quote_groups.php) and any *.sqlite*.
# Every find must succeed (explicit exit 1: set -e is ignored inside && lists).
wipe_cmd='for d in app database routes resources config lang Modules; do
  if [ -d "$d" ]; then
    find "$d" -type f ! -name local.php ! -name "*.local.php" ! -name quote_groups.php ! -name "*.sqlite*" -delete || exit 1
    find "$d" -depth -type d -empty -delete || exit 1
  fi
done'
# WIPE-END

remote_extract="T=$RT; cd \"\$T\"  && ( $wipe_cmd )  && tar -x --anchored --exclude=.env --exclude=storage"

# After extract: drop generated caches BEFORE any artisan call, then composer install
# (regenerates them against the server's --no-dev vendor/). No optimize:clear before it.
remote_common="T=$RT; cd \"\$T\" \
 && $clear_generated_cmd \
 && mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && composer install --no-dev --optimize-autoloader --no-interaction \
 && php artisan optimize:clear"

if [ "$FIRST_DEPLOY" -eq 1 ]; then
    remote_steps="$remote_common"
else
    remote_steps="$remote_common \
 && php artisan migrate --force \
 && php artisan config:cache \
 && php artisan route:cache \
 && php artisan view:cache \
 && php artisan up"
fi

print_first_deploy_next_steps() {
    cat <<EOF

FIRST DEPLOY: code is deployed, but NO migration was run and the site was NOT brought up.
Run these on the server, in the app directory, in this order:

  ssh $SSH_HOST
  cd $TARGET
  php artisan migrate:install
  php artisan db:baseline-legacy --dry-run     # REVIEW this output before the next command
  php artisan db:baseline-legacy
  php artisan migrate --force

Then: php artisan config:cache && php artisan route:cache && php artisan view:cache
and finally: php artisan up   (only needed if the site is in maintenance mode)
EOF
}

if [ "$DRY_RUN" -eq 1 ]; then
    step "Pre-flight and maintenance mode"
    echo "[dry-run] ssh $SSH_HOST '$remote_preflight'"
    step "Uploading $commit"
    echo "[dry-run] git archive HEAD | ssh $SSH_HOST '$remote_extract'"
    step "Installing dependencies and refreshing caches"
    echo "[dry-run] ssh $SSH_HOST '$remote_steps'"
    if [ "$FIRST_DEPLOY" -eq 1 ]; then
        print_first_deploy_next_steps
    fi
    step "Deployed commit (dry-run): $(git rev-parse HEAD)"
    exit 0
fi

# 4. Real run. Once maintenance mode may have started, a failure leaves the site down.
MAINTENANCE_STARTED=0
on_exit() {
    status=$?
    if [ "$status" -ne 0 ] && [ "$MAINTENANCE_STARTED" -eq 1 ]; then
        echo "" >&2
        echo "!!! DEPLOY FAILED - the site was LEFT IN MAINTENANCE MODE." >&2
        echo "!!! Fix the problem, then bring it up manually: ssh $SSH_HOST 'cd $RT && php artisan up'" >&2
    fi
}
trap on_exit EXIT

step "Pre-flight and maintenance mode"
preflight_out="$(ssh "$SSH_HOST" "$remote_preflight")"
echo "$preflight_out"
case "$preflight_out" in
    *MAINTENANCE_ON*) MAINTENANCE_STARTED=1 ;;
esac

step "Uploading $commit to $SSH_HOST:$TARGET"
git archive HEAD | ssh "$SSH_HOST" "$remote_extract"

step "Installing dependencies and refreshing caches"
ssh "$SSH_HOST" "$remote_steps"

MAINTENANCE_STARTED=0
step "Deployed commit: $(git rev-parse HEAD)"
if [ "$FIRST_DEPLOY" -eq 1 ]; then
    print_first_deploy_next_steps
fi
