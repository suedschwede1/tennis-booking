# Helloly Cutover (Phase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Laravel (`bookingnew`) takes over court booking at `tcbewegung.at/booking` on Helloly. The ZF2 system keeps serving only the tournament modules at `turnier.tcbewegung.at`, and both share the live database.

**Architecture:** Code tasks 1–5 run in `bookingnew` (branch `feature/helloly-cutover`) and are fully testable locally. Task 6 restricts the ZF2 repo (`C:\development\booking`) to tournament routes. Task 7 is a server runbook: staging against a DB copy, then cutover. It's executed step by step with the user, never unattended.

**Tech Stack:** PHP 8.3 locally / 8.4 on Helloly, Laravel 13, MySQL, Blade + Tailwind + Alpine, PHPUnit. Helloly is cPanel shared hosting with SSH alias `helloly` from WSL.

**Spec:** `docs/superpowers/specs/2026-11-04-helloly-laravel-cutover-design.md`

## Global Constraints

- All `php` / `composer` / `artisan` commands run inside WSL (`wsl php artisan test`), per AGENTS.md.
- Code, comments, commits in English. User-facing text only via `__('booking....')`, with keys added to **both** `lang/de/booking/` and `lang/en/booking/`.
- Every commit needs a `CHANGELOG.md` entry (pre-commit hook in `.githooks`).
- Before a task counts as done: `wsl composer lint` and `wsl php artisan test` pass.
- Live DB `helloly41567002_booking` is never touched before the staging run (Task 7, Part A) has passed. Credentials live in `~/.tcb.cnf` on the server and in the server `.env` only. Never commit or print them.
- Tournament base URL: `https://turnier.tcbewegung.at` (config, not hard-coded).
- Championship note pattern: `vereinsmeisterschaft`, case-insensitive substring of booking meta `notes`.
- Championship colours (copied from ZF2 `public/css-client/default.css`): background `#E8720C`, hover `#C55F08`, text `#FFFFFF` bold, own booking adds `box-shadow: inset 0 0 0 3px #C8E89A`.
- Shared hosting: no Node on the server (assets built locally and committed), no long-running workers (`QUEUE_CONNECTION=sync`).

## Review Focus

1. **`bs_tournaments` missing or empty** on a database (fresh install, staging): the calendar must render normally with no championship button and no exception. Test in Task 2.
2. **Note-pattern casing** ("VEREINSMEISTERSCHAFT", "Vereinsmeisterschaft – Halbfinale"): highlighted. A booking without notes or with an unrelated note is not. Tests in Task 3.
3. **Baseline run twice, or on a fresh DB without `bs_*` tables**: a no-op the second time. On a fresh DB it records nothing, and a normal `migrate` still creates everything. Tests in Task 4.
4. **Saving a user in Laravel's admin** whose tournament privileges were granted in ZF2: the privileges show pre-selected and survive the save. Test in Task 1.
5. **Old ZF2 bookmarks** (`/booking/user/login`, `/booking/user/bookings`, `/booking/user/settings`): they land on the Laravel equivalent, not a 404. Test in Task 5.

---

### Task 1: Tournament privileges in Laravel's user admin

**Files:**
- Modify: `app/Models/User.php:36-41` (`PRIVILEGES`)
- Modify: `lang/de/booking/admin.php` (`users.privileges`, ~line 332), `lang/en/booking/admin.php` (same key)
- Test: `tests/Feature/Admin/UserManagementTest.php`

**Interfaces:**
- Produces: privilege slugs `admin.tournament`, `admin.mixed`, `admin.pyramid`, `admin.draw` in `User::PRIVILEGES` (stored as `bs_users_meta` key `allow.<slug>` = `'true'`, exactly as ZF2 reads them).

- [ ] **Step 1: Write failing tests** in `UserManagementTest`:
  - `tournament_privileges_are_offered_in_the_user_form`: GET the edit page of a user as admin. Assert it sees `value="admin.tournament"`, `admin.mixed`, `admin.pyramid`, `admin.draw`.
  - `existing_zf2_tournament_privileges_survive_a_save`: set meta `allow.admin.pyramid` = `'true'` directly, PUT the edit form with `privileges[] = ['admin.pyramid']` plus required fields, assert `$user->fresh()->getMeta('allow.admin.pyramid') === 'true'`. A second PUT without it asserts the meta is gone.
- [ ] **Step 2: Run** `wsl php artisan test --filter=UserManagementTest`. Expected: the two new tests FAIL.
- [ ] **Step 3: Append the four slugs to `User::PRIVILEGES`.** Add labels. DE: "Darf die Vereinsmeisterschaft verwalten", "Darf das Mixed-/Fun-Turnier verwalten", "Darf die Forderungspyramide verwalten", "Darf Auslosungen verwalten". EN: "Can manage the club championship", "Can manage the mixed/fun tournament", "Can manage the challenge pyramid", "Can manage draws".
- [ ] **Step 4: Run** the filter again. Expected: PASS. Run the full suite. Expected: PASS (the multi-select `size` grows automatically).
- [ ] **Step 5: Commit** with a CHANGELOG entry: "Add tournament privileges (admin.tournament/mixed/pyramid/draw) to user admin".

### Task 2: Tournament entry buttons in the header

**Files:**
- Create: `app/Services/TournamentLinks.php`
- Modify: `config/services.php` (add `'tournament' => ['base_url' => env('TOURNAMENT_BASE_URL', 'https://turnier.tcbewegung.at')]`), `.env.example` (`TOURNAMENT_BASE_URL=`)
- Modify: `resources/views/components/layout/header.blade.php` (inside the `@auth` block at ~line 52, before `account.bookings`)
- Modify: `lang/de/booking/public.php`, `lang/en/booking/public.php` (labels)
- Test: `tests/Unit/TournamentLinksTest.php`, `tests/Feature/CalendarControllerTest.php`

**Interfaces:**
- Produces:
  - `TournamentLinks::currentChampionship(): ?array`, returning `['title' => string, 'url' => string]` or `null`
  - `TournamentLinks::pyramidUrl(): string`
  - `TournamentLinks::mixedUrl(): string`

  The class is registered as a singleton and resolved in Blade via `@inject('tournamentLinks', \App\Services\TournamentLinks::class)`.

`currentChampionship()` mirrors ZF2's `TournamentManager::current()` + `TournamentLink`. It runs `SELECT title, status FROM bs_tournaments WHERE is_test = 0 ORDER BY active DESC, season DESC, tid DESC LIMIT 1`. It returns `null` if there's no row, if `status === 'finished'`, or on **any** `\Throwable` (table missing). The URL is `{base_url}/tournament`. The pyramid and mixed URLs are `{base_url}/pyramide` and `{base_url}/mixed-tournament`. Verify the pyramid path against the route named `pyramide` in `C:\development\booking\modulex\Pyramid\config\module.config.php` and use the literal path found there. `bs_tournaments` has no Laravel migration (it's ZF2-owned). Tests create it in `setUp` with `Schema::create` if missing (columns `tid` PK, `title`, `status`, `season` int, `active` tinyint, `is_test` tinyint) and drop it in `tearDown`.

- [ ] **Step 1: Write failing unit tests** in `TournamentLinksTest`:
  - `returns_null_when_table_is_missing` (drop the table first)
  - `returns_null_when_no_tournament`
  - `ignores_test_tournaments` (only an `is_test=1` row exists → null)
  - `returns_null_when_current_is_finished`
  - `prefers_active_then_latest_season` (rows: season 2025 active=1 status `running`, season 2026 active=0 status `running` → title of the 2025 row; url `https://turnier.tcbewegung.at/tournament`)
  - `builds_pyramid_and_mixed_urls_from_config` (set `config(['services.tournament.base_url' => 'https://t.example'])`, assert `https://t.example/pyramide` and `https://t.example/mixed-tournament`)
- [ ] **Step 2: Write failing feature tests** in `CalendarControllerTest`:
  - `logged_in_user_sees_tournament_buttons`: with a running tournament "VM 2026", GET `/` as a user. Assert it sees "VM 2026", the pyramid label, and the mixed label.
  - `championship_button_hidden_without_tournament`: no rows. The pyramid and mixed buttons are still shown, and "VM" is not.
  - `guests_see_no_tournament_buttons`.
- [ ] **Step 3: Run** `wsl php artisan test --filter="TournamentLinksTest|CalendarControllerTest"`. Expected: the new tests FAIL.
- [ ] **Step 4: Implement `TournamentLinks`, the config, the labels** (DE "Forderungspyramide", "Mixed-/Fun-Turnier"; EN "Challenge pyramid", "Mixed/fun tournament"). **Add the header links**, styled like the existing `account.bookings` link, opening in the same tab.
- [ ] **Step 5: Run** the tests. Expected: PASS. Then run `wsl php artisan view:cache && wsl php artisan view:clear`.
- [ ] **Step 6: Commit** with a CHANGELOG entry.

### Task 3: Championship highlighting in the calendar

**Files:**
- Modify: `app/Models/Booking.php` (new method)
- Modify: `resources/views/components/calendar/grid.blade.php` (~lines 103–135, where `$cellClass` is set)
- Modify: the app stylesheet that defines `cc-own` / `cc-single-future` (find with `grep -rn "cc-single-future" resources/css`)
- Test: `tests/Unit/BookingChampionshipTest.php`, `tests/Feature/CalendarControllerTest.php`

**Interfaces:**
- Produces: `Booking::isChampionshipMatch(): bool` (true if meta `notes` contains `vereinsmeisterschaft`, case-insensitive, `mb_stripos`) and the CSS class `cc-turnier`.

- [ ] **Step 1: Write failing unit tests:** `notes "Vereinsmeisterschaft Runde 1"` → true, `"VEREINSMEISTERSCHAFT"` → true, `"Training"` → false, no notes meta → false.
- [ ] **Step 2: Write a failing feature test** `championship_booking_gets_cc_turnier_class`: a future booking by another user with that note → the response contains `cc-turnier`. Also a booking without the note → `cc-turnier` doesn't appear. An own championship booking → both `cc-own` and `cc-turnier` are on the same element.
- [ ] **Step 3: Run** the tests. Expected: FAIL.
- [ ] **Step 4: Implement.** In the grid, append `' cc-turnier'` to `$cellClass` whenever `$reservation?->booking?->isChampionshipMatch()` is true, and the cell isn't an event block. Make sure the booking meta needed here is eager-loaded by the calendar query, so there's no N+1 (check `CalendarController`'s `with(...)`). Add the CSS with the Global Constraints colours. It must also win on mobile widths, not just desktop, which was a known ZF2 issue.
- [ ] **Step 5: Run** the tests plus `npm run build`. Expected: PASS and a build without errors. Commit `public/build`.
- [ ] **Step 6: Commit** with a CHANGELOG entry.

### Task 4: Baseline command for an existing legacy database

**Files:**
- Create: `app/Console/Commands/BaselineLegacySchemaCommand.php`
- Test: `tests/Feature/Console/BaselineLegacySchemaCommandTest.php`

**Interfaces:**
- Produces: `php artisan db:baseline-legacy {--dry-run}`. For each migration in `database/migrations` whose filename matches `create_(bs_[a-z_]+)_table`, if the `bs_*` table exists **and** the migration isn't in the `migrations` table yet, it inserts it with batch `1` (or `max(batch)` if rows exist). It records nothing for missing tables. It prints one line per recorded migration and a total. `--dry-run` prints without writing. Exit code 0.

Afterwards `php artisan migrate --force` applies only the additive migrations: indexes, `jobs`, `login_history`, `password_reset_tokens`, `personal_access_tokens`, and the Members module.

- [ ] **Step 1: Write failing tests:**
  - `records_create_migrations_for_existing_legacy_tables`: after `RefreshDatabase`, delete the `create_bs_*` rows from `migrations`. Run the command. Assert exactly those 15 rows are back, and `migrate --pretend` shows nothing for them.
  - `is_idempotent`: a second run records 0 and leaves the row count unchanged.
  - `dry_run_writes_nothing`.
  - `skips_tables_that_do_not_exist`: drop `bs_squares_coupons` and delete its row. The command doesn't record it.
- [ ] **Step 2: Run** `wsl php artisan test --filter=BaselineLegacySchemaCommandTest`. Expected: FAIL.
- [ ] **Step 3: Implement the command.** Use `Schema::hasTable` and `DB::table('migrations')`. Don't touch `DatabaseSchemaChecker`, but run its drift report on staging afterwards (Task 7).
- [ ] **Step 4: Run** the tests. Expected: PASS.
- [ ] **Step 5: Commit** with a CHANGELOG entry.

### Task 5: Legacy URL redirects, Helloly deploy script, and docs

**Files:**
- Modify: `routes/web.php`
- Create: `scripts/deploy-helloly.sh`
- Modify: `README.md` (section "Deployment (one.com)"), `AGENTS.md` (add a deployment pointer)
- Test: `tests/Feature/LegacyRedirectTest.php`

**Interfaces:**
- Produces permanent (301) redirects: `/user/login` → `route('login')`, `/user/logout` → `route('calendar.index')`, `/user/bookings` → `route('account.bookings')`, `/user/settings` → `route('account.edit')`, `/square` and `/square/{any}` → `route('calendar.index')`, `/backend` and `/backend/{any}` → `route('admin.dashboard')`.
- Produces `scripts/deploy-helloly.sh <target-dir>` (default `~/apps/bookingnew`). It runs from WSL in the repo root:
  1. refuses if the git tree is dirty
  2. `npm run build`
  3. `git archive HEAD` plus `public/build` as a tarball over `ssh helloly`, unpacked into `<target-dir>`, never overwriting `.env` or `storage/`
  4. remotely: `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`, `php artisan config:cache route:cache view:cache`
  5. prints the deployed commit hash

  It must not run `db:baseline-legacy`, which stays a deliberate manual step in Task 7.

- [ ] **Step 1: Write failing tests** in `LegacyRedirectTest`, one per redirect, each asserting status 301 and the `Location` target.
- [ ] **Step 2: Run** them. Expected: FAIL. **Implement the routes.** Run again. Expected: PASS.
- [ ] **Step 3: Write the deploy script.** Check it with `bash -n scripts/deploy-helloly.sh`. Expected: no output.
- [ ] **Step 4: Rewrite the README deployment section for Helloly:** SSH alias `helloly`, the app directory outside the web root, `/booking` served via symlink or rewrite, cPanel cron for `schedule:run` if the scheduler is used, mail via `booking@tcbewegung.at` SMTP, `TOURNAMENT_BASE_URL`. Keep one.com only as historical.
- [ ] **Step 5: Commit** with a CHANGELOG entry.

### Task 6: Restrict ZF2 to tournament routes on `turnier.` (repo `C:\development\booking`)

**Files:**
- Modify: `C:\development\booking\public\.htaccess` (or the root `.htaccess` the ZF2 front controller uses; check which one Apache reads first)

**Interfaces:**
- Consumes: Laravel URL `https://tcbewegung.at/booking/`.
- Produces: on host `turnier.tcbewegung.at`, only these paths are served:
  - `/tournament*`, `/mixed-tournament*`, `/pyramide*` (literal path from Task 2), `/draw*`
  - `/user/login`, `/user/logout`, `/user/password*` (login stays needed, per spec decision 4)
  - static assets (`/css`, `/css-client`, `/js`, `/imgs`, `/imgs-client`, `/docs-client`, `/misc`)

  Every other path gets a 302 to `https://tcbewegung.at/booking/`, including `/`, `/square*`, `/backend*`, `/user/bookings`, `/user/settings`, and `/calendar`. Gate the rules on `%{HTTP_HOST} ^turnier\.`, so the same files keep working unchanged if they're ever served at `/booking` again (rollback).

- [ ] **Step 0: Confirm with the user** before committing in this repo. Its working tree has uncommitted live-matching changes in `MixedTournament`/`Tournament` on `feature/forderungspyramide`. Ask whether to commit those first as their own commit.
- [ ] **Step 1: Write the rewrite rules.**
- [ ] **Step 2: Verify** on staging (Task 7, Part A step 6). There's no local Apache test harness for this repo.
- [ ] **Step 3: Commit** in the `booking` repo.

### Task 7: Server runbook (with the user, step by step)

Write it as `docs/deploy/2026-helloly-cutover-runbook.md` (commit in `bookingnew`), then execute it with the user.

**Part A: staging**
1. Create database `helloly41567002_booking_staging` and a DB user via cPanel (user action, or `uapi Mysql create_database` over SSH if allowed). Fill it from a fresh `mysqldump` of the live DB.
2. Create subdomain `neu.tcbewegung.at` with SSL. Its docroot is a symlink to `~/apps/bookingnew-staging/public`.
3. `scripts/deploy-helloly.sh ~/apps/bookingnew-staging`, after a hand-written server `.env`: staging DB, `APP_URL=https://neu.tcbewegung.at`, `APP_ENV=production`, `APP_DEBUG=false`, `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=database`, SMTP for `booking@` (the user enters the password themselves), `TOURNAMENT_BASE_URL=https://turnier.tcbewegung.at`. Then `php artisan key:generate`.
   Order matters on the first deploy: run `php artisan db:baseline-legacy --dry-run`, then for real, **then** `migrate --force`.
4. Run the Laravel admin "Database" page drift report. It must report no drift on `bs_*`. Fix or record anything it finds before continuing.
5. Smoke test with real accounts (the user and one or two members): login with the existing password, calendar day/week, book/cancel singles and doubles, subscription edit, daily limit, admin booking edit, booking mail arrives, tournament buttons, orange championship cells, Members module opens (empty).
6. Create `turnier.tcbewegung.at` pointing to a **copy** of the ZF2 install configured against the staging DB. Check that the tournament, mixed, pyramid, and draw pages work against the DB that now holds Laravel's extra tables and indexes, and that `/` and `/square/...` redirect (Task 6).
7. Check that `~/logs/*.php.error.log` has no new entries (and that the 2026-11-03 `platform_check` error isn't recurring).

**Part B: cutover** (winter break, short announced window)
1. Announce. Back up the live DB (`mysqldump` to `~/backup-pre-cutover-<date>.sql`).
2. Move ZF2 out of the web root to `~/apps/zf2-turnier`. Point `turnier.tcbewegung.at` there (live DB config).
3. Deploy Laravel to `~/apps/bookingnew` with the live `.env`. Run `db:baseline-legacy`, then `migrate --force`.
4. Make `public_html/booking` a symlink to `~/apps/bookingnew/public`, or use the rewrite variant if CageFS refuses symlinks. Set `APP_URL=https://tcbewegung.at/booking`.
5. Repeat the smoke test from A5 against live, plus the turnier redirects.
6. Grav: update any homepage links to tournament pages so they point at `turnier.`. Clear the Grav `cache/`.

**Rollback:** point `public_html/booking` back at the ZF2 install and remove the host gate's effect by visiting via `/booking`. The DB stays as is, because Laravel's tables are additive. Restore the pre-cutover dump only if data was damaged.

- [ ] **Step 1: Write the runbook file.** Commit it with a CHANGELOG entry.
- [ ] **Step 2: Execute Part A with the user.** Stop and report after each numbered step that changes the server.
- [ ] **Step 3: The user gives the go for Part B.** Execute it, then report.
