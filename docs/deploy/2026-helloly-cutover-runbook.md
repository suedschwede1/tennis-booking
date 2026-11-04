# Helloly cutover runbook (phase 1: Laravel replaces the ZF2 booking)

Spec: `docs/superpowers/specs/2026-11-04-helloly-laravel-cutover-design.md`
Plan: `docs/superpowers/plans/2026-11-04-helloly-laravel-cutover.md`
Tools: `scripts/deploy-helloly.sh` (read its header), `php artisan db:baseline-legacy`, and the ZF2 repo's
`docs/turnier-htaccess-test-matrix.md`.

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
   afterwards. Do **not** raise the PHP version for the `turnier.` ZF2 document roots; keep what ZF2 runs on today.
4. The five hand-placed, git-ignored override files (listed in `AGENTS.md`, "Per-installation overrides", and in the
   deploy script header) are collected; they are never deployed and must be copied to the server by hand.
5. Plan time: Part A is a few hours spread over a day or two; the B2-B4 window in Part B should take 30-45 minutes.

## Part A: staging against a copy of the live data

A1. **Create the staging database** (cPanel > MySQL Databases, club admin): `helloly41567002_booking_staging` plus a DB
    user with all privileges on **that database only**. Then copy the data (club admin runs these on the server; the
    first command reads live data with the live credentials, the second writes with the staging user only):

    ```bash
    # 1. dump the LIVE database (read-only); the DB name is the last argument, no --databases flag
    mysqldump --defaults-file=~/.tcb.cnf --single-transaction helloly41567002_booking > ~/dump-live.sql
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
    create it). Staging runs at the subdomain root, so there is no `/booking` path.

A3. **First deploy to staging** (the first deploy is always `--first-deploy`):
    1. On the server create `~/apps/bookingnew-staging/.env` by hand (the deploy pre-flight requires an `.env`; start
       from `.env.example`, which already has an empty `APP_KEY=` line that `key:generate` needs). Set: staging DB
       name/user/password, `DB_CONNECTION=mysql` (the pre-flight refuses sqlite), `APP_ENV=production`,
       `APP_DEBUG=false`, `APP_URL=https://neu.tcbewegung.at`, `SESSION_DRIVER=database`, `SESSION_PATH=/`,
       `SESSION_SECURE_COOKIE=true`, **`CACHE_STORE=file`** (the default `database` breaks `optimize:clear` and the
       login rate limiter), `QUEUE_CONNECTION=sync`, `TOURNAMENT_BASE_URL=https://turnier.neu.tcbewegung.at`, and
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
    shows the tournament links (they point at `turnier.` hosts). Booking mails go to the log
    (`storage/logs/laravel.log`), not to people; to test real mail delivery use a test mailbox you own. In the browser
    network tab check that no request goes to a root-absolute URL. **Never re-run `key:generate` after this point on
    a site with real sessions** (sessions are encrypted; a new key logs everyone out).

A6. **ZF2 on a test host name** (the name must start with `turnier.` because the gate matches `^turnier\.`; use
    `turnier.neu.tcbewegung.at`). Order matters, because the ZF2 config of a copy still points at the LIVE database:
    1. Copy the ZF2 install to `~/apps/zf2-turnier-staging` (outside the web root, no subdomain yet).
    2. Edit its `config/autoload/local.php`: staging DB name/user/password, mail disabled or pointing at a test
       mailbox. Then run `grep -n 'helloly41567002_booking' config/autoload/local.php`; it must **not** match the live
       name (a match ending in `_staging` is fine).
    3. Only now create the `turnier.neu.tcbewegung.at` subdomain with SSL, document root = that directory.
    4. Upload `.htaccess` from `git show HEAD:.htaccess` of the ZF2 repo (not from a Windows working copy). On the
       server `cat -A .htaccess | head -3` must show lines ending in `$`, not `^M$` (`dos2unix` if needed).
    5. Force HTTPS for the host in cPanel. This may rewrite the `.htaccess` you just verified: afterwards `diff` the
       server file against `git show HEAD:.htaccess`; extra HTTPS lines are fine, anything else is not.
    6. Run every row of the ZF2 repo's `docs/turnier-htaccess-test-matrix.md` with header-only probes
       (`-sI --max-redirs 0`, plus `--path-as-is` for the traversal rows), against
       `https://turnier.neu.tcbewegung.at/<path>` directly. Rows for `tcbewegung.at/booking/...` cannot run on this
       host; they are for the rollback case only.
    7. Open the tournament, mixed, pyramid and draw pages against the staging DB, which now holds Laravel's extra
       tables, and confirm they work.

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
       `QUEUE_CONNECTION=sync`, `TOURNAMENT_BASE_URL=https://turnier.tcbewegung.at`, and the real SMTP settings.
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
    1. B2, ZF2 to `turnier.`: **copy** the live ZF2 install (its current `config/autoload/local.php` included; the DB
       stays the same): `cp -a ~/public_html/booking ~/apps/zf2-turnier`. The original stays where it is until step 3,
       where it becomes the rollback copy. Create the `turnier.tcbewegung.at` subdomain + SSL with its document root at
       `~/apps/zf2-turnier`. Upload `.htaccess` as in A6.4, force HTTPS and re-check as in A6.5, and run the matrix
       probes against `https://turnier.tcbewegung.at/<path>`.
    2. Check that the tournament pages work on `turnier.tcbewegung.at`.
    3. B4, switch `/booking`: `mv ~/public_html/booking ~/apps/zf2-booking-old`, then
       `ln -s ~/apps/bookingnew/public ~/public_html/booking`. If CageFS/Apache refuses the symlink, use a copy of the
       `public` directory with an adjusted `index.php` instead and note it here. Confirm the PHP handler for `/booking`
       is 8.3+ and that `/booking/calendar` works, not just `/booking/`.
    4. Success check from WSL or any shell: header-only probes `https://tcbewegung.at/booking/` (expect 200 or 302)
       and `https://tcbewegung.at/booking/calendar` (expect 200) before announcing anything.

B5. **Smoke test on live** (repeat A5 on `https://tcbewegung.at/booking/` with the club admin's account; this time
    mail is real, so only send test bookings to the admin's own address), plus the old bookmarked URLs
    (`/booking/user/login`, `/booking/user/bookings`, `/booking/user/settings`, a `/booking/square` URL): each answers
    302 to the Laravel page. The tournament links in the header open `turnier.tcbewegung.at`.

B6. Update the Grav homepage links that point at tournament pages so they use `turnier.tcbewegung.at`; clear the Grav
    `cache/`. After a logout on `turnier.` members land on the Laravel booking page (expected). One-off `public/*.php`
    maintenance scripts of the ZF2 repo are no longer reachable on `turnier.` (use the rollback URL if one is ever
    needed).

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
3. `turnier.tcbewegung.at` can stay as it is; its `.htaccess` gate is host-conditional, so the same files also work when
   ZF2 is served at `/booking` again.

## Open decisions to settle during Part A

- Charset/emoji handling (A1).
- Members whose `bs_users.pw` hash is not `$2y$` (A1).
- Whether Helloly refuses the symlink for `/booking` (B4).
- "Remember me" is now a no-op for members (no token column on `bs_users`); decide whether to hide the checkbox.
- Members module data import is done **after** the cutover (CSV or `members:import-nuliga-license`), not in this runbook.
