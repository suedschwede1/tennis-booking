#!/usr/bin/env bash
#
# Deploy the committed HEAD to the Helloly cPanel host (SSH alias "helloly").
# Run from WSL in the repository root:
#
#   scripts/deploy-helloly.sh [--dry-run] [target-dir]
#
# target-dir defaults to ~/apps/bookingnew (a path on the server, outside the web root).
# --dry-run prints every step without executing anything over ssh.
#
# Deliberately NOT done here (manual, one-time steps documented in the runbook and in
# docs/superpowers/specs/2026-11-04-helloly-laravel-cutover-design.md):
#   - php artisan migrate:install
#   - php artisan db:baseline-legacy
# Never add them to this script.
#
# Remote .env and storage/ are never overwritten: the remote tar extraction excludes
# both (--anchored, top-level only). Missing storage dirs are recreated remotely.
set -euo pipefail

EXPECTED_BRANCH="feature/helloly-cutover"
SSH_HOST="helloly"
DRY_RUN=0
TARGET="~/apps/bookingnew"

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        -*) echo "Unknown option: $arg" >&2; exit 2 ;;
        *) TARGET="$arg" ;;
    esac
done

run() {
    if [ "$DRY_RUN" -eq 1 ]; then
        echo "[dry-run] $*"
    else
        "$@"
    fi
}

step() { echo "==> $*"; }

# 1. Clean tree
if [ -n "$(git status --porcelain)" ]; then
    echo "Refusing to deploy: working tree is not clean (commit or stash first)." >&2
    git status --short >&2
    exit 1
fi

branch="$(git rev-parse --abbrev-ref HEAD)"
if [ "$branch" != "$EXPECTED_BRANCH" ]; then
    echo "WARNING: HEAD is on '$branch', expected '$EXPECTED_BRANCH'." >&2
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

# 3. Ship HEAD as a tar stream; never overwrite remote .env or storage/
step "Uploading $commit to $SSH_HOST:$TARGET"
# TARGET is intentionally expanded by the remote shell (so ~ resolves on the server).
remote_extract="mkdir -p $TARGET && tar -x -C $TARGET --anchored --exclude=.env --exclude=storage"
if [ "$DRY_RUN" -eq 1 ]; then
    echo "[dry-run] git archive HEAD | ssh $SSH_HOST \"$remote_extract\""
else
    git archive HEAD | ssh "$SSH_HOST" "$remote_extract"
fi

# 4. Remote build steps
step "Installing dependencies and refreshing caches"
remote_steps="cd $TARGET \
 && mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && composer install --no-dev --optimize-autoloader --no-interaction \
 && php artisan migrate --force \
 && php artisan config:cache \
 && php artisan route:cache \
 && php artisan view:cache"
if [ "$DRY_RUN" -eq 1 ]; then
    echo "[dry-run] ssh $SSH_HOST \"$remote_steps\""
else
    ssh "$SSH_HOST" "$remote_steps"
fi

step "Deployed commit: $(git rev-parse HEAD)"
