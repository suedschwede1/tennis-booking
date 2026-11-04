# Helloly cutover runbook (phase 1: Laravel replaces the ZF2 booking)

Spec: `docs/superpowers/specs/2026-11-04-helloly-laravel-cutover-design.md`
Plan: `docs/superpowers/plans/2026-11-04-helloly-laravel-cutover.md`
Tools: `scripts/deploy-helloly.sh` (read its header), `php artisan db:baseline-legacy`, and the ZF2 repo's
`docs/turnier-htaccess-test-matrix.md`.

## Ground rules

- **Every step that changes the server is done together with the club admin, one step at a time.** After each
  numbered step that touches the server, stop and report what happened before the next one.
- **Nothing touches the live database before Part A has passed on a copy.**
- No secrets in this repo, in chat, or in logs. DB credentials and SMTP passwords are typed or placed on the server by
  the club admin; the live DB client config is `~/.tcb.cnf` on the server and is never copied anywhere.
- **Never run `php artisan migrate:rollback` (or `migrate:fresh`/`reset`) against the live DB.** The `create_bs_*`
  migrations' `down()` uses `dropIfExists` on live ZF2 tables.
- The ZF2 system keeps using the same DB for tournaments. Laravel only adds tables and indexes; it must never alter or
  drop the `bs_*` tables that ZF2 reads.
- SSH is `ssh helloly` from WSL. Do not pass Windows drive-letter paths (`C:/...`) to WSL programs.

## Before you start (once)

1. The existing backups are confirmed fresh (the club admin confirms), plus a new `mysqldump` of the live DB is stored
   outside `public_html` and downloaded.
2. Build the assets **on Windows** (`npm run build`), commit `public/build`, make sure the tree is clean and the branch
   to deploy is merged/known. WSL's Node is too old and `node_modules` has only Windows binaries, so the deploy script
   is always run with `--skip-build`.
3. Decide the PHP version: cPanel MultiPHP for the document roots that serve the app must be **PHP 8.3 or 8.4**
   (the CLI on the server is 8.4). Check the handler that `public_html/.htaccess` applies to `/booking`.
4. The five hand-placed, git-ignored override files (listed in `AGENTS.md`, "Per-installation overrides", and in the
   deploy script header) are collected; they are never deployed and must be copied to the server by hand.

## Part A: staging against a copy of the live data

A1. **Create the staging database** (cPanel > MySQL Databases, club admin): `helloly41567002_booking_staging` plus a DB
    user with all privileges on it only. Load a fresh `mysqldump` of the live DB into it.
    Checks on the copy (read-only queries):
    - `SHOW TABLE STATUS` for the `bs_*` tables: note the charset. ZF2 tables are expected to be `utf8` (utf8mb3);
      Laravel connects as utf8mb4 with strict mode. **Test an emoji** in a booking note and a user alias on staging
      (booking/edit flow). If it fails with "Incorrect string value", decide before cutover: convert the affected
      columns to utf8mb4 (ZF2 keeps working) or strip 4-byte characters in input.
    - `SELECT LEFT(pw,4), COUNT(*) FROM bs_users GROUP BY 1;` Expected `$2y$`. Any `$2a$`/other prefix needs a decision
      (members with those hashes could fail login or must reset their password).

A2. **Create the staging subdomain** `neu.tcbewegung.at` with SSL (cPanel). Its document root is
    `~/apps/bookingnew-staging/public` (create the directory first; the app is deployed into
    `~/apps/bookingnew-staging`). Staging runs at the subdomain root, so there is no `/booking` path.

A3. **First deploy to staging** (the first deploy is always `--first-deploy`):
    1. On the server create `~/apps/bookingnew-staging/.env` by hand (the deploy pre-flight requires an `.env`):
       staging DB name/user/password, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://neu.tcbewegung.at`,
       `SESSION_DRIVER=database`, `SESSION_PATH=/`, `SESSION_SECURE_COOKIE=true`, **`CACHE_STORE=file`** (the default
       is `database`, which breaks `optimize:clear` and the login rate limiter), `QUEUE_CONNECTION=sync`,
       `TOURNAMENT_BASE_URL=https://turnier.neu.tcbewegung.at`, SMTP for `booking@tcbewegung.at` (password entered by
       the club admin), and `DB_CONNECTION=mysql` (the pre-flight refuses sqlite).
    2. From WSL: `scripts/deploy-helloly.sh --dry-run --first-deploy --skip-build --allow-branch '~/apps/bookingnew-staging'`
       and read the printed plan. Then the same command without `--dry-run`. `--allow-branch` is needed while deploying a
       feature branch; the script asks `Proceed? [y/N]`.
    3. Copy the five override files into place; then `php artisan key:generate --force` on the server.
    4. Manual first-deploy sequence, in this order, reviewing each output (the script printed it too):
       - `php artisan migrate:install` (creates only the `migrations` table)
       - `php artisan db:baseline-legacy --dry-run` and **review the list** (expect the 15 `create_bs_*` migrations)
       - `php artisan db:baseline-legacy`
       - `php artisan migrate --force` (applies the additive migrations: composite indexes, sessions, jobs, login
         history, password reset tokens, API tokens, Members tables). Any "Duplicate key name" or similar error is a
         **stop**; report it before touching anything.
       - `php artisan config:cache && php artisan route:cache && php artisan view:cache`, and `php artisan up` if the
         site is in maintenance mode.

A4. **Drift report**: open the admin "Database" page on staging. `bs_*` tables must show no drift. Expect the ~22 ZF2
    tournament tables (`bs_tournament*`, `bs_mixed_*`, `bs_pyramid_*`, `bs_draw*`) to be listed as untracked; that is
    normal, do not "repair" them.

A5. **Smoke test on staging** with the club admin's account and one or two members who agree to it: log in with the
    existing password (tick "remember me" once; it must not error), calendar day/week, book and cancel singles and
    doubles, subscription (series) edit, daily limit, co-player picker, admin booking edit, booking mail arrives,
    the Members module opens (empty), championship highlighting (a booking whose note contains
    "Vereinsmeisterschaft" is orange), the header shows the tournament links (they point at `turnier.` hosts). Check
    that no request goes to a root-absolute URL (browser dev tools network tab).

A6. **ZF2 on a test host name**: create `turnier.neu.tcbewegung.at` (the name must start with `turnier.` because the gate
    matches `^turnier\.`) with a copy of the ZF2 install whose config points at the **staging** DB and whose document
    root is the install root. Upload `.htaccess` from `git show HEAD:.htaccess` of the ZF2 repo (not from a Windows
    working copy; then `cat -A .htaccess | head -3` on the server must show `$`, not `^M$`; run `dos2unix` if needed).
    Run every row of `docs/turnier-htaccess-test-matrix.md` with header-only probes (`-sI --max-redirs 0`, plus
    `--path-as-is` for the traversal rows). Open the tournament, mixed, pyramid and draw pages against the DB that now
    holds Laravel's extra tables and confirm they work. Force HTTPS for the `turnier.` host at the vhost/cPanel level.

A7. Check `~/logs/*.php.error.log` and `storage/logs/laravel.log` on staging for new errors (the 2026-11-03
    `platform_check` error must not recur). Check `AllowOverride` on Helloly: `public/.htaccess` uses
    `Options -MultiViews -Indexes`, which needs `AllowOverride Options`; if it is refused you get a 500, not a silent
    skip.

**Go/no-go for Part B: the club admin decides, after A1-A7 are clean.**

## Part B: cutover (winter break, short announced window)

B1. Announce the window to the members. Take a fresh `mysqldump` of the live DB (`~/backup-pre-cutover-<date>.sql`) and
    download it.

B2. **Move ZF2 to `turnier.tcbewegung.at`.** Move the ZF2 install out of the web root to `~/apps/zf2-turnier` (keep its
    current live `config/autoload/local.php`; the DB stays the same). Create the `turnier.tcbewegung.at` subdomain +
    SSL with its document root at the install root. Upload `.htaccess` as in A6 and force HTTPS. Do not delete the old
    copy; keep it for rollback. Check the tournament pages and run the header-only probe matrix against
    `turnier.tcbewegung.at`.

B3. **Deploy Laravel (not yet linked to `/booking`).** Create `~/apps/bookingnew/.env` by hand with the **live** DB and
    these settings: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://tcbewegung.at/booking`,
    `SESSION_DRIVER=database`, `SESSION_PATH=/booking`, `SESSION_SECURE_COOKIE=true`, `CACHE_STORE=file`,
    `QUEUE_CONNECTION=sync`, `TOURNAMENT_BASE_URL=https://turnier.tcbewegung.at`, SMTP, `DB_CONNECTION=mysql`. Then
    `scripts/deploy-helloly.sh --first-deploy --skip-build '~/apps/bookingnew'` (add `--allow-branch` only if not on
    `master`; answer the `Proceed? [y/N]` prompt yourself, do not use `--yes`), copy the five override files,
    `php artisan key:generate --force`,
    and run the manual first-deploy sequence from A3.4 against the live DB (`migrate:install`, `db:baseline-legacy
    --dry-run` reviewed, `db:baseline-legacy`, `migrate --force`, the three cache commands, `up`).
    **Do not link `public_html/booking` to the app until this step is done and the log is clean.** A first deploy does
    not use maintenance mode, so a linked but half-deployed app would answer every request with a 500.

B4. **Switch `/booking`.** Make `public_html/booking` a symlink to `~/apps/bookingnew/public` (the old ZF2 files are
    already out of that directory from B2). If CageFS/Apache refuses the symlink, use a rewrite or a copy of the
    `public` directory with an adjusted `index.php` instead. Confirm the PHP handler for `/booking` is 8.3+ and that
    `public/.htaccess` is honored (`/booking/calendar` must work, not just `/booking/`).

B5. **Smoke test on live** (repeat A5 on `https://tcbewegung.at/booking/` with the club admin's account), plus the old
    bookmarked URLs (`/booking/user/login`, `/booking/user/bookings`, `/booking/user/settings`, a `/booking/square`
    URL): each answers 302 to the Laravel page. The tournament links in the header open `turnier.tcbewegung.at`.

B6. Update the Grav homepage links that point at tournament pages so they use `turnier.tcbewegung.at`; clear the Grav
    `cache/`. After a logout on `turnier.` members land on the Laravel booking page (expected). One-off `public/*.php`
    maintenance scripts of the ZF2 repo are no longer reachable on `turnier.` (use the rollback URL if one is ever needed).

B7. Announce that the new booking is live. Watch `storage/logs/laravel.log` and the PHP error log during the first days.

## Rollback

Do this if B5 fails or members cannot book:

1. Point `public_html/booking` back to the ZF2 install (move it back or re-link; it is untouched). The legacy redirects
   are 302s, so browsers do not cache a wrong target.
2. **Do not run any rollback/fresh/reset artisan command.** Laravel's extra tables and indexes are additive; ZF2 ignores
   them. Restore the pre-cutover dump only if data was actually damaged, and only after the club admin agrees.
3. `turnier.tcbewegung.at` can stay as it is; its `.htaccess` gate is host-conditional, so the same files also work when
   ZF2 is served at `/booking` again.

## Open decisions to settle during Part A

- Charset/emoji handling (A1).
- Members whose `bs_users.pw` hash is not `$2y$` (A1).
- Whether Helloly refuses the symlink for `/booking` (B4).
- "Remember me" is now a no-op for members (no token column on `bs_users`); decide whether to hide the checkbox.
- Members module data import is done **after** the cutover (CSV or `members:import-nuliga-license`), not in this runbook.
