#!/usr/bin/env bash
#
# Deploy the committed HEAD to the Helloly cPanel host (SSH alias "helloly").
# Run from WSL in the repository root:
#
#   scripts/deploy-helloly.sh [--dry-run] [--allow-branch] [--yes] [target-dir]
#
# target-dir defaults to ~/apps/bookingnew (a path on the server, outside the web root).
# A leading "~/" is expanded on the SERVER, so pass it quoted ('~/apps/x') or the local
# shell expands it first. Allowed characters: ^(~/)?[A-Za-z0-9._/-]+$
#
# Options / environment:
#   --dry-run       print every step without executing anything over ssh (no prompt)
#   --allow-branch  allow deploying a branch other than $EXPECTED_BRANCH
#   --yes           skip the "Proceed? [y/N]" prompt
#   EXPECTED_BRANCH branch that may be deployed without --allow-branch (default: master)
#
# Flow on the server: pre-flight (.env must exist) -> maintenance mode (php artisan down,
# if the app is already installed) -> remove repo-owned dirs (so deleted files/migrations
# do not linger) -> extract -> optimize:clear -> composer install -> migrate --force ->
# config/route/view cache -> php artisan up. On any failure after maintenance mode started
# the site is LEFT IN MAINTENANCE MODE; fix the problem and run `php artisan up` by hand.
#
# Deliberately NOT done here (manual, one-time steps documented in the runbook and in
# docs/superpowers/specs/2026-11-04-helloly-laravel-cutover-design.md):
#   - php artisan migrate:install
#   - php artisan db:baseline-legacy
# Never add them to this script.
#
# Never touched on the server: .env, storage/, vendor/ (composer updates it).
# Not deployed (git-ignored, place on the server by hand): lang/*/booking/local.php,
# config/calendar.local.php. Existing *local.php files on the server are preserved.
set -euo pipefail

EXPECTED_BRANCH="${EXPECTED_BRANCH:-master}"
SSH_HOST="helloly"
DRY_RUN=0
ALLOW_BRANCH=0
ASSUME_YES=0
TARGET="~/apps/bookingnew"

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        --allow-branch) ALLOW_BRANCH=1 ;;
        --yes) ASSUME_YES=1 ;;
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
step "Building assets"
if [ "$DRY_RUN" -eq 1 ]; then
    echo "[dry-run] npm run build"
    echo "[dry-run] verify git status --porcelain is still empty (public/build committed)"
else
    npm run build
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
if [ "$DRY_RUN" -eq 0 ] && [ "$ASSUME_YES" -ne 1 ]; then
    read -r -p "Proceed? [y/N] " answer
    case "$answer" in
        y|Y|yes|YES) ;;
        *) echo "Aborted." >&2; exit 1 ;;
    esac
fi

remote_preflight="T=$RT; test -f \"\$T/.env\" || { echo \"No .env in \$T - wrong target dir or not set up yet.\" >&2; exit 1; }; \
if [ -f \"\$T/artisan\" ]; then cd \"\$T\" && php artisan down && echo MAINTENANCE_ON; fi"

remote_extract="T=$RT; cd \"\$T\" \
 && for d in app database routes resources config lang Modules; do \
      if [ -d \"\$d\" ]; then find \"\$d\" -type f ! -name local.php ! -name '*.local.php' -delete; find \"\$d\" -depth -type d -empty -delete; fi; \
    done \
 && tar -x --anchored --exclude=.env --exclude=storage"

remote_steps="T=$RT; cd \"\$T\" \
 && mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && if [ -f vendor/autoload.php ]; then php artisan optimize:clear; fi \
 && composer install --no-dev --optimize-autoloader --no-interaction \
 && php artisan optimize:clear \
 && php artisan migrate --force \
 && php artisan config:cache \
 && php artisan route:cache \
 && php artisan view:cache \
 && php artisan up"

if [ "$DRY_RUN" -eq 1 ]; then
    step "Pre-flight and maintenance mode"
    echo "[dry-run] ssh $SSH_HOST '$remote_preflight'"
    step "Uploading $commit"
    echo "[dry-run] git archive HEAD | ssh $SSH_HOST '$remote_extract'"
    step "Installing dependencies and refreshing caches"
    echo "[dry-run] ssh $SSH_HOST '$remote_steps'"
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
