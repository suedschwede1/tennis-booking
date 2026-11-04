# Helloly cutover runbook (phase 1: Laravel replaces the ZF2 booking)

Spec: `docs/superpowers/specs/2026-11-04-helloly-laravel-cutover-design.md`
Plan: `docs/superpowers/plans/2026-11-04-helloly-laravel-cutover.md`
Tools: `scripts/deploy-helloly.sh` (read its header) and `php artisan db:baseline-legacy`.

**Architecture (revised 2026-11-04, same host):** there is no `turnier.` subdomain. The legacy ZF2 tournament pages are
served on the same host as the Laravel app: Laravel's `public/.htaccess` rewrites an allowlist of paths
(`tournament`, `mixed-tournament`, `pyramide`, `draw`, `service`, and ZF2's `user/login|logout|password[-reset]`) to
`public/zf2-bridge.php`, which runs the ZF2 `index.php` from a separate ZF2 install (`~/apps/zf2`). ZF2's static assets
are symlinked into Laravel's `public/` by the deploy script. Old URLs such as `https://tcbewegung.at/booking/mixed-tournament`
keep working. Members keep two logins (Laravel `/login`, ZF2 `/user/login`), same accounts and passwords. The ZF2 booking UI
is unreachable because nothing but the allowlist is routed to ZF2. The ZF2 repo's `turnier.` host gate
(`docs/turnier-htaccess-test-matrix.md`) is unused in this model; ignore that matrix.

## Ground rules

- **Every step that changes the server is done together with the club admin, one step at a time.** After each
  numbered step that touches the server, stop and report what happened before the next one.
- **Nothing touches the live database before Part A has passed on a copy.** In Part A every artisan/mysql command is
  preceded by the checks in "Staging safety check" below.
- No secrets in this repo, in chat, or in logs. DB credentials and SMTP passwords are typed or placed on the server by
  the club admin. `~/.tcb.cnf` on the server holds the **live** DB client credentials: never use it for staging, never
  copy it.
- **Never run `php artisan migrate:rollback` (or `migrate:fresh` / `migrate:reset`) against the live DB.** The
  `create_bs_*` migrations' `down()` uses `dropIfExists` on live ZF2 tables.
- ZF2 for the tournament pages lives in its own install directory (`~/apps/zf2`; staging: `~/apps/zf2-staging`), never
  inside the Laravel app, and its `config/autoload/local.php` names the DB that matches the environment.
- The ZF2 system keeps using the same DB for tournaments. Laravel only adds tables and indexes; it must never alter or
  drop the `bs_*` tables that ZF2 reads.
- SSH is `ssh helloly` from WSL. Do not pass Windows drive-letter paths (`C:/...`) to WSL programs.
- No cron job is needed in phase 1 (Laravel's scheduler is not used yet).

### Staging safety check (run before the first artisan/mysql command of every Part A session)

```bash
cd ~/apps/bookingnew-staging
grep -E '^(DB_DATABASE|DB_USERNAME|APP_URL|MAIL_MAILER)=' .env   # DB names must end in _staging, MAIL_MAILER=log
php artisan db:show                                               # the database shown must be the staging one
```

If either output names the live database (`helloly41567002_booking`), **stop**.

## Before you start (once)

1. The existing backups are confirmed fresh (the club admin confirms). Take one more dump of the live DB now as a
   rollback point (exact command in A1) and download it. It is a rollback point only; the final one is taken in B1.
2. Build the assets **on Windows** (`npm run build`), commit `public/build`, make sure the tree is clean and the branch
   to deploy is known. WSL's Node is too old and `node_modules` has only Windows binaries, so the deploy script is
   always run with `--skip-build`.
3. PHP version: cPanel MultiPHP for the document roots that serve the **Laravel** app must be PHP 8.3 or 8.4 (the CLI
   on the server is 8.4). Changing the handler on `public_html` also affects the Grav homepage, so re-test Grav
   afterwards. ZF2 now runs under the same PHP version as the domain (8.4), so the tournament pages must be
   checked on staging under PHP 8.4 (A6) before cutover; if they break, stop and decide with the club admin.
4. The five hand-placed, git-ignored override files (listed in `AGENTS.md`, "Per-installation overrides", and in the
   deploy script header) are collected; they are never deployed and must be copied to the server by hand.
5. Plan time: Part A is a few hours spread over a day or two; the B2-B4 window in Part B should take 30-45 minutes.

## Part A: staging against a copy of the live data

A1. **Create the staging database** (cPanel > MySQL Databases, club admin): `helloly41567002_booking_staging` plus a DB
    user with all privileges on **that database only**. Then copy the data (club admin runs these on the server; the
    first command reads live data with the live credentials, the second writes with the staging user only):

    ```bash
    # 1. dump the LIVE database (read-only); the DB name is the last argument, no --databases flag.
    #    On Helloly the mysqldump wrapper rejects --defaults-file/--defaults-extra-file ("unknown variable"),
    #    so read the credentials from ~/.tcb.cnf into variables on the server and pass them via -u/-h/MYSQL_PWD
    #    (never print them). The PROCESS-privilege warning about tablespaces is harmless.
    get() { sed -n "s/^$1=//p" "$HOME/.tcb.cnf" | head -1 | sed -E "s/^['\"]//; s/['\"]\$//"; }
    MYSQL_PWD="$(get password)" mysqldump -u "$(get user)" -h "$(get host)" --single-transaction \
      helloly41567002_booking > ~/dump-live.sql
    chmod 600 ~/dump-live.sql
    # 2. the dump must not switch databases: this must print 0
    grep -c -E '^(USE |CREATE DATABASE)' ~/dump-live.sql
    # 3. import into STAGING with the staging user's own credentials (typed at the prompt), staging DB named explicitly
    mysql -u <staging-db-user> -p helloly41567002_booking_staging < ~/dump-live.sql
    ```

    Never import with `--defaults-file=~/.tcb.cnf`. After the import check read-only on the staging DB:
    - `SHOW TABLE STATUS` for the `bs_*` tables: note the charset. ZF2 tables are expected to be `utf8` (utf8mb3);
      Laravel connects as utf8mb4 with strict mode. **Test an emoji** in a booking note and a user alias on staging.
      If it fails with "Incorrect string value", decide before cutover: convert the affected columns to utf8mb4 (ZF2
      keeps working) or strip 4-byte characters in input.
    - `SELECT LEFT(pw,4), COUNT(*) FROM bs_users GROUP BY 1;` Expected `$2y$`. Any other prefix needs a decision
      (those members could fail login or must reset their password).

A2. **Create the staging subdomain** `neu.tcbewegung.at` with SSL (cPanel). Its document root is
    `~/apps/bookingnew-staging/public`: run `mkdir -p ~/apps/bookingnew-staging` first (the deploy script does not
    create it). Staging runs at the subdomain root, so there is no `/booking` path. The tournament pages are served
    from the same host (no `turnier.` DNS, SSL or vhost is needed).

A3. **First deploy to staging** (the first deploy is always `--first-deploy`):
    1. On the server create `~/apps/bookingnew-staging/.env` by hand (the deploy pre-flight requires an `.env`; start
       from `.env.example`, which already has an empty `APP_KEY=` line that `key:generate` needs). Set: staging DB
       name/user/password, `DB_CONNECTION=mysql` (the pre-flight refuses sqlite), `APP_ENV=production`,
       `APP_DEBUG=false`, `APP_URL=https://neu.tcbewegung.at`, `SESSION_DRIVER=database`, `SESSION_PATH=/`,
       `SESSION_SECURE_COOKIE=true`, **`CACHE_STORE=file`** (the default `database` breaks `optimize:clear` and the
       login rate limiter), `QUEUE_CONNECTION=sync`, `TOURNAMENT_BASE_URL=` (empty = same host), and
       **`MAIL_MAILER=log`** (staging holds real member addresses; nothing may be mailed to them).
    2. From WSL: `scripts/deploy-helloly.sh --dry-run --first-deploy --skip-build --allow-branch '~/apps/bookingnew-staging'`
       and read the printed plan. Then the same command without `--dry-run`. `--allow-branch` is needed while
       deploying a feature branch; the script asks `Proceed? [y/N]`.
    3. Copy the five override files into place. Then, on the server:

       ```bash
       cd ~/apps/bookingnew-staging
       php artisan key:generate --force      # must print success; the APP_KEY line in .env must now be non-empty
       ```

    4. Run the staging safety check, then the manual first-deploy sequence **in this order**, reviewing each output
       (the script printed it too):
       - `php artisan migrate:install` (creates only the `migrations` table)
       - `php artisan db:baseline-legacy --dry-run` and **review the list: it must name all 15 `create_bs_*`
         migrations.** If it lists fewer, stop and report: the missing tables would be created by `migrate`.
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
    doubles, subscription (series) edit, daily limit, co-player picker, admin booking edit, the Members module opens
    (empty), championship highlighting (a booking whose note contains "Vereinsmeisterschaft" is orange), the header
    shows the tournament links (they open the same-host tournament pages, see A6). Booking mails go to the log
    (`storage/logs/laravel.log`), not to people; to test real mail delivery use a test mailbox you own. In the browser
    network tab check that no request goes to a root-absolute URL. **Never re-run `key:generate` after this point on
    a site with real sessions** (sessions are encrypted; a new key logs everyone out).

A6. **ZF2 tournament pages on the staging host (same host, no subdomain).** Order matters, because the ZF2 config of a
    copy still points at the LIVE database:
    1. Copy the live ZF2 install to `~/apps/zf2-staging` (outside the web root): `cp -a ~/public_html/booking ~/apps/zf2-staging`.
       **Do not copy** the one-off `public/*.php` maintenance scripts of the old install into the copy: delete
       `debug.php`, `diag-*.php`, `migrate*.php`, `reseed-fix-*.php`, `cleanup-*.php`, `add-player-*.php` (and any other
       loose `*.php` except `index.php`) from `~/apps/zf2-staging/public/`. Restore the git-ignored client files that
       are not in the repo (`public/css-client/default*.css`, the `public/imgs-client` logos) from the migration backup
       if they are missing.
    2. Edit `~/apps/zf2-staging/config/autoload/local.php`: staging DB name/user/password, mail disabled or pointing at a
       test mailbox. Then run `grep -n 'helloly41567002_booking' ~/apps/zf2-staging/config/autoload/local.php`; it must
       **not** match the live name (a match ending in `_staging` is fine). Double-check with `grep -rn 'helloly41567002_booking'
       ~/apps/zf2-staging/config` that no other file names the live DB.
    3. Tell the Laravel staging install where ZF2 lives (the default `~/apps/zf2/public` would be the live copy):
       `echo "$HOME/apps/zf2-staging/public" > ~/apps/bookingnew-staging/zf2.path`. Re-run the staging deploy (A3.2 without
       `--first-deploy` once migrations exist, otherwise just the asset linking step) so the assets are symlinked into
       `~/apps/bookingnew-staging/public`; the deploy output must say `ZF2 assets linked from .../zf2-staging/public`.
    4. PHP for `neu.tcbewegung.at` must be 8.4 (cPanel MultiPHP). ZF2 runs under it now: open the tournament, mixed,
       pyramid and draw pages and watch `~/logs/*.php.error.log` for deprecations that turn into errors.
    5. Run the routing test table below with header-only probes (`curl -sI --max-redirs 0`, add `--path-as-is` for
       traversal paths) against `https://neu.tcbewegung.at/<path>`.
    6. Log in on `/user/login` (ZF2) with a test account and open the tournament pages with the staging DB, which now
       holds Laravel's extra tables; confirm they work, including the css/js/images and the `/service/help` link.

    **Routing test table** (staging host root; on live prefix every path with `/booking`):

    | Path | Expected |
    |------|----------|
    | `/tournament`, `/mixed-tournament`, `/pyramide`, `/draw` (and sub-paths) | 200 ZF2 page, or 302 to `/user/login` (ZF2) when not logged in |
    | `/service/help`, `/service/info` | 200 ZF2 page |
    | `/user/login`, `/user/logout`, `/user/password`, `/user/password-reset` | ZF2 page / ZF2 redirect (not a redirect to Laravel `/login`) |
    | `/zf2-bridge.php` | 404 (the bridge is only reachable through the rewrites) |
    | `/calendar`, `/login`, `/register`, `/admin`, `/bookings...`, `/account...` | Laravel page (200, or 302 to Laravel `/login`) |
    | `/user/bookings`, `/user/settings`, `/user/registration`, `/user/activation/x` | 302 to the Laravel page |
    | `/square`, `/square/booking`, `/backend`, `/backend/config` | 302 to Laravel calendar / admin |
    | `/css/<zf2 asset>`, `/js/<zf2 asset>`, `/imgs/<zf2 asset>` | 200 (symlinked ZF2 asset) |
    | `/css/booking.css`, `/js/booking.js` | 200, still Laravel's own file |
    | `/tournament/../.env`, `/tournament%2e%2e/` (with `--path-as-is`) | 400/403/404, never file contents |

A7. Check `~/logs/*.php.error.log` and `storage/logs/laravel.log` on staging for new errors (the 2026-11-03
    `platform_check` error must not recur). `public/.htaccess` uses `Options -MultiViews -Indexes`, which needs
    `AllowOverride Options` on Helloly; if the host refuses it, requests return a 500 (it is not skipped silently).

**Go/no-go for Part B: the club admin decides, after A1-A7 are clean.**

## Part B: cutover (winter break, short announced window)

Order is chosen so `/booking` is never dead for long: B3 deploys Laravel **without linking it**; B2 and B4 are then done
back to back in one sitting.

B1. Announce the window to the members. Take the final `mysqldump` of the live DB with the A1 command, writing to
    `~/backup-pre-cutover-<date>.sql`, and download it.

B3. **Deploy Laravel (not yet linked to `/booking`).**
    1. `mkdir -p ~/apps/bookingnew`. Create `~/apps/bookingnew/.env` by hand (from `.env.example`) with the **live**
       DB, `DB_CONNECTION=mysql`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://tcbewegung.at/booking`,
       `SESSION_DRIVER=database`, `SESSION_PATH=/booking`, `SESSION_SECURE_COOKIE=true`, `CACHE_STORE=file`,
       `QUEUE_CONNECTION=sync`, `TOURNAMENT_BASE_URL=` (empty = same host), and the real SMTP settings.
    2. `scripts/deploy-helloly.sh --first-deploy --skip-build '~/apps/bookingnew'` (add `--allow-branch` only if not on
       `master`; answer the `Proceed? [y/N]` prompt yourself, do not use `--yes`). Copy the five override files.
    3. On the server:

       ```bash
       cd ~/apps/bookingnew
       grep -E '^(DB_DATABASE|DB_USERNAME)=' .env     # now this is the LIVE database; confirm with the club admin
       php artisan db:show
       php artisan key:generate --force
       ```

    4. Manual first-deploy sequence against the live DB: `migrate:install`, `db:baseline-legacy --dry-run` (must list
       all 15, reviewed with the club admin), `db:baseline-legacy`, `migrate --force`, the three cache commands, `up`.
    5. Success check: `php artisan about` runs without errors and `storage/logs/laravel.log` has no new errors.
    **Do not link `public_html/booking` to the app until this step is clean.** A first deploy does not use
    maintenance mode, so a linked but half-deployed app would answer every request with a 500.

B2 + B4. **Do these two steps back to back.**
    1. B2, install ZF2 for the same-host model: **copy** the live ZF2 install (its current `config/autoload/local.php`
       included; the DB stays the same): `cp -a ~/public_html/booking ~/apps/zf2`. The original stays where it is until
       step 3, where it becomes the rollback copy. In `~/apps/zf2/public/` delete the one-off maintenance scripts as in A6.1
       (`debug.php`, `diag-*.php`, `migrate*.php`, `reseed-fix-*.php`, `cleanup-*.php`, `add-player-*.php`) and make sure the
       gitignored client files (`css-client/default*.css`, `imgs-client` logos) are present. There is no `turnier.`
       subdomain, DNS or SSL step. The ZF2 `.htaccess` in that copy is never read (the document root is Laravel's `public`).
    2. Link the assets: re-run the deploy so the script symlinks `~/apps/zf2/public/{css,css-client,docs,docs-client,imgs,
       imgs-client,js,js-client,misc,misc-client,vendor}` into `~/apps/bookingnew/public` (B3 may already have printed
       `ZF2 public dir not found` because `~/apps/zf2` did not exist yet; in that case run the deploy again now, or the
       `ln -s`/`cp -rsn` commands from the script by hand).
    3. B4, switch `/booking`: `mv ~/public_html/booking ~/apps/zf2-booking-old`, then
       `ln -s ~/apps/bookingnew/public ~/public_html/booking`. If CageFS/Apache refuses the symlink, use a copy of the
       `public` directory with an adjusted `index.php` (and `zf2-bridge.php`) instead and note it here. Confirm the PHP
       handler for `/booking` is 8.4 and that `/booking/calendar` works, not just `/booking/`.
    4. Success check from WSL or any shell: header-only probes `https://tcbewegung.at/booking/` (expect 200 or 302)
       and `https://tcbewegung.at/booking/calendar` (expect 200), then the routing test table from A6 with every path
       prefixed by `/booking`, before announcing anything.

B5. **Smoke test on live** (repeat A5 on `https://tcbewegung.at/booking/` with the club admin's account; this time
    mail is real, so only send test bookings to the admin's own address), plus the old bookmarked URLs
    (`/booking/user/bookings`, `/booking/user/settings`, a `/booking/square` URL): each answers
    302 to the Laravel page; `/booking/user/login` and `/booking/user/logout` are now served by ZF2. The tournament links
    in the header open `https://tcbewegung.at/booking/pyramide` etc. (same host).

B6. Grav homepage links that point at tournament pages keep working (same host and paths); check them once and clear
    the Grav `cache/` if links were edited. After a logout on a ZF2 tournament page members land on the ZF2 login page
    (expected; the two logins are separate). The one-off `public/*.php` maintenance scripts of the old ZF2 install are not
    part of `~/apps/zf2` and are unreachable (use the rollback if one is ever needed).

B7. Announce that the new booking is live. Watch `storage/logs/laravel.log` and the PHP error log during the first
    days.

## Rollback

Do this if B5 fails or members cannot book:

1. Remove the symlink **without** `-r` and without a trailing slash: `rm ~/public_html/booking`. (`rm -r
   ~/public_html/booking/` can delete the Laravel app's `public/` contents.) Then restore the old directory:
   `mv ~/apps/zf2-booking-old ~/public_html/booking`. If the `public` copy variant was used in B4, remove that
   directory instead. The legacy redirects are 302s, so browsers do not cache a wrong target.
2. **Do not run any rollback/fresh/reset artisan command.** Laravel's extra tables and indexes are additive; ZF2 ignores
   them. Restore the pre-cutover dump only if data was actually damaged, and only after the club admin agrees.
3. `~/apps/zf2` and the asset symlinks in `~/apps/bookingnew/public` can stay as they are; with ZF2 served at `/booking`
   again the symlinks are simply unused.

## Open decisions to settle during Part A

- Charset/emoji handling (A1).
- Members whose `bs_users.pw` hash is not `$2y$` (A1).
- Whether Helloly refuses the symlink for `/booking` (B4).
- "Remember me" is now a no-op for members (no token column on `bs_users`); decide whether to hide the checkbox.
- Members module data import is done **after** the cutover (CSV or `members:import-nuliga-license`), not in this runbook.
