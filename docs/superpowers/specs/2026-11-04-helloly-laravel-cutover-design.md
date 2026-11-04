# Helloly cutover: replace the ZF2 booking with Laravel (phase 1)

Date: 2026-11-04
Status: draft, awaiting approval

## Intent

The club's live booking system moved from one.com to the new host **Helloly** on
2026-11-03 as a 1:1 copy of the old Zend Framework 2 system (`booking`, version 1.6.3
plus the custom `modulex` modules). Members are already booking there.

The Laravel rewrite in this repository (`bookingnew`) is meant to replace that ZF2
system. This first phase switches **court booking** to Laravel. The tournament modules
(`Tournament`, `MixedTournament`, `Pyramid`, `Draw`) don't exist in Laravel yet. They
keep running on the old ZF2 system until they are ported in a later phase.

## Goals

- Laravel serves court booking, the account area, the admin area, and the Members
  module at `https://tcbewegung.at/booking`.
- The old ZF2 system keeps serving the tournament modules at a separate address,
  `turnier.tcbewegung.at`, until phase 2.
- Both systems run at the same time against the **same** live database
  (`helloly41567002_booking`), so there is no data copy or sync between two databases.
- Members log in to Laravel with their existing email and password, with no password
  reset needed.
- **The tournament entry points keep working from the Laravel booking page** (the user
  asked for this). Today ZF2's `modulex/Tournament` adds two things to the booking UI,
  and Laravel has to reproduce both:
  - **User panel buttons** (`view/frontend/index/userpanel.online.phtml`):
    "Vereinsmeisterschaft" (shown only when the `tournamentLink()` helper returns a
    tournament, so the builder must replicate that condition), "Forderungspyramide", and
    "Mixed-/Fun-Turnier". In Laravel they link to the corresponding pages on
    `turnier.tcbewegung.at`.
  - **Calendar highlighting** (`src/Tournament/View/Helper/CalendarCellTurnier.php`): a
    booking whose `notes` meta contains the pattern `CalendarCellTurnier::MUSTER`
    (case-insensitive) gets an extra CSS class and colour. Laravel's calendar cell gets
    the same rule and the same look.
  These are thin links and read-only display. The tournament logic itself stays in ZF2
  until phase 2.
- No booking data is lost, and the downtime at cutover is short and announced.
- Rollback is possible: the ZF2 system stays installed and can take `/booking` back.

## Non-goals (this phase)

- Porting `Tournament`, `MixedTournament`, `Pyramid`, `Draw` to Laravel (phase 2,
  separate design).
- Single sign-on between Laravel and ZF2. During the transition, members log in again
  on `turnier.tcbewegung.at` (decided by the user, acceptable for the interim).
- Changes to the Grav homepage in `public_html`, apart from updating links if needed.
- Changes to mail accounts or DNS beyond adding the `turnier` subdomain.

## Anti-goals

- Bookings get made in two places. After cutover, ZF2 must not accept court bookings
  any more. Otherwise members end up in two different workflows with different
  rules.
- Laravel migrations break or alter the legacy `bs_*` tables in a way the ZF2
  tournament modules can't handle any more (they read `bs_users`, `bs_squares`,
  `bs_bookings`).
- Running anything against the live database before it has been tested against a copy.

## Current state (verified 2026-11-04)

**Helloly host** (`ssh helloly` from WSL; `cloud25.helloly.hosting`, cPanel, CageFS):

- PHP 8.4 CLI, Composer, Git, and the MySQL client are available over SSH. There is
  no Node, and no `crontab` binary in the shell (cron goes through cPanel).
- `~/public_html` is the Grav homepage. `~/public_html/booking` is ZF2 1.6.3 with
  `modulex`.
- The database is `helloly41567002_booking`. Client credentials are in `~/.tcb.cnf`
  (do not copy them anywhere).
- Mailboxes `booking@`, `canon@`, `verein@tcbewegung.at` exist on Helloly.
- DNS for `tcbewegung.at` / `www` already points to Helloly (IPv6 `2a13:6602:1::38`).
- The PHP error log shows a Composer `platform_check` failure from 2026-11-03 14:47.
  Check whether it is still current.
- Backups exist (the user confirmed this; there is also a local copy in
  `C:\development\tcbewegung-migration`).

**Laravel app** (`bookingnew`, branch `master`):

- Laravel 13, written for PHP 8.3. Eloquent models map to the legacy `bs_*` tables.
- Passwords: `User` authenticates against the bcrypt hash in `bs_users.pw`, the same
  format ZF2 writes (Zend `Bcrypt`).
- Its migrations *create* the `bs_*` tables and add new ones (`login_history`,
  `password_reset_tokens`, `personal_access_tokens`, `jobs`, Members tables, composite
  indexes). An admin "Database" page (`DatabaseSchemaChecker`) can repair or report
  schema drift without SSH.
- The README's deployment section still describes one.com/FTP.

**Old ZF2 repo** (`C:\development\booking`, branch `feature/forderungspyramide`):
the working tree has uncommitted changes in `MixedTournament` and `Tournament`, which
match changes on the live server. They matter for phase 2, not for this phase, but
must not be lost.

## Decisions made with the user

1. The new host is Helloly, and the ZF2 system there is live.
2. Booking moves first. Tournaments follow later.
3. Laravel at `tcbewegung.at/booking`, ZF2 for tournaments at its own address
   (`turnier.tcbewegung.at`).
4. Members log in separately on the tournament site during the transition.
5. Existing backups are sufficient. No extra backup tooling is needed for this design.
6. The tournament buttons and the calendar highlighting must work again in Laravel (see
   Goals).
7. There is no existing Members-module data to carry over. The Members module goes live
   empty, and members/dues get imported (CSV or `members:import-nuliga-license`)
   **after** the cutover. That import isn't part of this phase.
8. No date constraint. It's the winter break now, so the cutover can happen whenever
   staging has passed. There's no running tournament to protect.

## Shape of the solution

1. **Install Laravel next to ZF2 on Helloly.** Use a separate directory outside the
   web root (e.g. `~/apps/bookingnew`), deployed with Git and Composer over SSH. Vite
   assets are built locally and committed, as today.
2. **Staging run against a copy.** Create a second database with a dump of the live
   DB. Point a staging Laravel instance (e.g. `neu.tcbewegung.at`) at it, bring the
   schema in line (see below), and test there: login with real accounts, calendar,
   booking/cancel, subscriptions, admin, mails, Members module. While Laravel's
   additions are in that copy, check that the ZF2 tournament pages still work against
   it too.
3. **Schema alignment on an existing database.** The `bs_*` tables already exist, so
   Laravel's create-table migrations must not run against them. They must be recorded
   as already applied, and only the additive migrations run. Whether to do that with
   `DatabaseSchemaChecker`, a dedicated artisan command, or a manual baseline insert
   into `migrations` is the builder's choice. It has to be repeatable (staging first,
   then live) and idempotent.
4. **Move ZF2 to `turnier.tcbewegung.at`.** Create the subdomain in cPanel, with its
   document root at the ZF2 `public` directory (or a copy of the current layout), plus
   SSL. Turn off court booking in ZF2 there: hide or redirect the calendar/booking
   routes to `https://tcbewegung.at/booking`, so only tournament, pyramid, and draw
   pages stay usable.
5. **Cutover.** Announce a short window. Freeze ZF2 booking, switch
   `public_html/booking` to Laravel's `public` (symlink or `.htaccess` rewrite into the
   app directory, depending on what cPanel/CageFS allows), apply the schema alignment
   to the live DB, smoke-test, then announce.
6. **Rollback.** Point `public_html/booking` back to ZF2. Laravel's additive tables
   don't disturb ZF2, so no database restore is needed unless data was damaged.

## Constraints

- Shared hosting: no root, no Node, cron only through cPanel, long-running processes
  not allowed (`QUEUE_CONNECTION=sync`, or a cPanel cron calling `schedule:run`).
- PHP is 8.4 on the server. `composer.json` and the lock file must install cleanly
  there (the 2026-11-03 `platform_check` error is a hint to check this).
- Mail goes out over Helloly SMTP using the existing `booking@tcbewegung.at` account.
- Both apps write `bs_bookings`, `bs_users`, etc. at the same time during the
  transition. ZF2 writes only tournament-related data plus whatever its tournament
  code touches.
- Code, comments, and docs in English (AGENTS.md). UI text through `lang/`.

## Calls made by Claude (not yet confirmed by the user)

- **Release = `bookingnew` `master`.** Unmerged branches (e.g.
  `worktree-member-anonymization`) are not part of this phase. The migration baseline and
  the `DatabaseSchemaChecker` manifest cover exactly what's on `master` at release.
- **Tournament privileges move to Laravel's admin.** ZF2 checks `allow.admin.tournament`,
  `allow.admin.mixed`, `allow.admin.pyramid`, `allow.admin.draw` in `bs_users_meta`.
  These four get added to `User::PRIVILEGES`, so they can be granted in Laravel's user
  admin. The ZF2 backend user and booking admin is **not** kept on `turnier.`, because
  that would reopen a second place to edit bookings. Laravel's `syncPrivileges` only
  touches its own keys today, so existing grants survive.

## Checked in code (builder review)

- Existing ZF2 bcrypt hashes in `bs_users.pw` work for Laravel login.
- With an empty `login_history`, the risk-based login check raises no flags at cutover.
- `peak_limit` defaults to off, so there are no surprise booking limits.
- The ZF2 tournament modules don't write `bs_bookings`. `CalendarCellTurnier` only
  reads booking notes.

## Left to the builder

- Exact directory layout on Helloly and the symlink-vs-rewrite choice for `/booking`.
- How to baseline the migrations on an existing database (see step 3).
- How ZF2's booking routes get disabled on `turnier.` (config, route override, or
  `.htaccess`).
- Redirects for old ZF2 booking URLs under `/booking/...` that members have bookmarked
  or that appear in old emails, mapped to their Laravel equivalents where one exists.
- Whether the Grav homepage needs link updates (e.g. links to tournaments).
- Updating the README/AGENTS deployment section from one.com/FTP to Helloly/SSH.
- Deploy script (`git pull` + `composer install --no-dev` + `artisan migrate --force`
  + cache warm-up) for Helloly.
