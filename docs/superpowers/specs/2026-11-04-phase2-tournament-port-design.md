# Phase 2: port Draw, Tournament, Pyramid and MixedTournament to Laravel

Date: 2026-11-04. Status: all questions answered by the club admin, awaiting final approval of the document. Inputs: `.superpowers/sdd/phase2/inventory-{draw,tournament,pyramid,mixedtournament}.md` (INV-D/T/P/M), phase-1 spec `2026-11-04-helloly-laravel-cutover-design.md` incl. "Revision (same host)".

## Intent and why

The tournament paths are still served by ZF2 through `public/zf2-bridge.php` and an allowlist in `public/.htaccess`. That keeps a second framework, login and security boundary alive on PHP 8.4. Phase 2 ports the four modules into `app/Modules/*`, moves them off the bridge one at a time on the same URLs, adopts the live tables without loss, then removes the bridge, the ZF2 login and the ZF2 install.

## Goals / Non-goals / Anti-goals

**Goals.** All four modules in Laravel with unchanged rules; same URLs under `/booking`; live data adopted in place; every published draw still verifies with `tools/verify_draw.py`; UI per `docs/DESIGN.md`; German and English texts; staging on synthetic data; per-module rollback by one `.htaccess` line.

**Non-goals.** New features or rule changes (except flagged bugs); SSO with ZF2; porting the one-off `public/*.php` fix scripts; a tournament API.

**Anti-goals.** Destructive DDL on module tables while ZF2 can still read them; any change to commitment/seed/shuffle maths; writing data ZF2 cannot read while rollback is possible; real tournament data on staging after the rehearsal; real names/phones from `sql/tournament_schema.sql` in seeders or fixtures.

## Decisions by the club admin

1. All four modules are ported; new look, same rules; same URLs; one module at a time off the bridge.
2. One login: every ported module uses the Laravel login. Modules still on the bridge keep the ZF2 login (temporary double login accepted).
3. Per-event / per-ladder grants (`bs_pyramid_grants`, `bs_mixed_event_grants`) keep their tables and semantics.
4. Test mode stays: the Tournament admin "view as player" mode, and Mixed `is_test` events making every logged-in user event admin.
5. drand stays (HTTP dependency, `drand:round:N` parsing).
6. The WhatsApp helper is ported (shared base; used by Tournament and Pyramid).
7. Live data is adopted; staging must end up without real tournament data.
8. The staging rehearsal of each module may use the real tournament data already on staging, once per module, before `phase2:scrub-staging` replaces it with synthetic data.
9. The dates of the next season are not fixed, earliest April 2027 (championship start, first ladder challenge, next Mixed event). All go-lives are planned for the winter break (until about March), Tournament last, and never on an event day; the dates are confirmed with the club admin before each module's go-live.
10. Order accepted: shared base, Draw record page, Pyramid, MixedTournament, then Tournament together with the rest of Draw.
11. After the last module, a separate reviewed cleanup step may add foreign keys and drop the `*_schema_versions` tables.

## Constraints

- Helloly: PHP 8.4, MySQL 8.0, no Node, `QUEUE_CONNECTION=sync`, cron via cPanel, deploy via `scripts/deploy-helloly.sh`, base path `/booking` (no root-absolute URLs).
- Same `bs_users`; authorization = `User::can()` (status `admin`/`assist` + `allow.*` meta); `admin.tournament|mixed|pyramid|draw` already in `User::PRIVILEGES`; `Gate::before` maps abilities to `User::can()`.
- Both apps write the same DB until the last go-live. ZF2 bootstraps all its modules on every bridged request, so the Mixed/Pyramid `SchemaMigrator`s keep running while any path is bridged.
- Tests run on SQLite in memory; `FOR UPDATE`, `UPDATE … JOIN`, ENUMs need MySQL.
- Work starts on a new branch after phase 1 is merged.

## Architecture

### Module layout

Follow `app/Modules/Members` (`php artisan make:module`), one provider each in `bootstrap/providers.php`. Not `Modules/*` (nwidart is for non-UI modules).

| Module | View/lang ns | Content |
|---|---|---|
| `TournamentBase` | `tournament-base::` | access helpers, test-mode session, `ScoreService` (used by Tournament and Pyramid), WhatsApp helper, court names, shared tab bar/flash/test banner, `Randomizer` binding, `phase2:preflight`, `phase2:scrub-staging` |
| `Draw` | `draw::` | framework-free `DrawService`, `TokenService`, `BeaconService`; admin/token/record controllers |
| `Tournament` | `tournament::` | Format/Generator/Generation/Standings/Resolver, access levels, controllers |
| `Pyramid` | `pyramid::` | Rule/Challenge/Result/Seed services, Mailables, controllers |
| `MixedTournament` | `mixed::` | Rotation/Grouping/RoundGeneration/History/Mexicano/Standings/Wave/Scorecard, controllers, print views |

Each: `Database/Migrations`, `Models` (Eloquent on existing tables, no timestamps, `@property` docblocks), framework-free `Services`, `Http/{Controllers,Requests}`, `Policies`, `resources/{views,lang/de,lang/en}`, `routes.php`. ZF2's `forward()->dispatch()` between Tournament and Draw becomes service calls (`TokenDrawService::prepare`, `DrawApplier::apply` + `GenerationService::generate`) inside the same transactions ZF2 used.

### Routing and go-live

Exact paths and parameter patterns from the inventories; trailing slashes are already 301-normalised by `.htaccess`. Going live = removing the path from the bridge rule and updating `tests/Unit/PublicHtaccessTest.php`/`Zf2BridgeTest`. `/draw/record/*` can leave the bridge before the rest of `/draw` via a Laravel rule ahead of the bridge rule (narrowing exposure only); the matrix must cover `/draw`, `/draw/tokens/1`, `/draw/record/x/json`.

### Auth and permissions

| Old | Laravel |
|---|---|
| `LoginRedirectTrait` → ZF2 `user/login` | `auth` middleware → Laravel `/login`, `redirect()->intended()`, module flash text |
| `Authorize('admin.<m>')` | `can:admin.tournament` / `admin.draw` / `admin.pyramid` / `admin.mixed` |
| `TournamentAccess` MEMBER/PARTICIPANT/ADMIN + canWrite | `TournamentAccess::level(User, Tournament)` + `canWrite()` |
| Pyramid `canAdmin` (global or grant row) | `PyramidLadderPolicy`, same grant table |
| Mixed `roleFor` none/read/score/admin (`is_test` ⇒ admin) | `MixedEventPolicy`, same rules and grant table |
| ZF2 session `TournamentTest` (only lowers level) | session key `tournament.test_mode` |

Pyramid index pages and Draw record pages stay anonymous. `admin.mixed` is grantable to assist users in Laravel's user admin; ZF2 honoured the same meta key without UI (INV-M §6). Accepted (my call).

### CSRF, session, mail, i18n

Laravel `@csrf` replaces Draw's `CsrfService`/`TournamentCsrf`; a 419 on module routes redirects back with the old message as a translated flash (my call). Pyramid's eleven notifications become plain-text Mailables, sent synchronously only to players with a `uid`, failures logged and swallowed as before (INV-P §7); subject prefix "Forderungspyramide: ". Translations per module namespace as in Members, every key in `de` and `en`; German taken verbatim (ScoreService texts stay verbatim), transliterations (`ae`, `ue`) restored to umlauts (my call).

### UI

DESIGN.md tokens, public header, cards/tables/badges/form patterns; Tailwind + Alpine, no Livewire, `ui:lint` must pass. One shared tab-bar partial. Inline JS becomes Alpine (player filter, live score validator, swap picker, scroll restore, desktop/mobile toggle keeping `pyramidView`/`mixedTournamentView`). Mixed printables keep a standalone A4 print layout (page break per wave, wave number not printed). Mobile-first; module admin pages use the public layout, not the Members sidebar (my call: event staff, not office).

## Data adoption strategy

**Adopt, never recreate.** Each table migration is a guarded create (`if (! Schema::hasTable(...))`) producing the live end state (hand-applied SQL + final migrator state: Pyramid 20260806, Mixed 20260803) for fresh dev/test/staging DBs; on live it only records itself. No change to `db:baseline-legacy` needed (my call). All tables go into `DatabaseSchemaChecker`'s manifest.

**Schema freeze while rollback is possible.** Until the bridge is gone: no new ENUM values, type changes, NOT NULL columns or dropped tables; additive indexes only after preflight shows no duplicates; JSON columns (`format`, `lot_orders`, `payload`, `result`, event payloads) written in ZF2's shapes. No charset change is needed (see "Character set" below).

**Preflight** `php artisan phase2:preflight {module}`, read-only, staging then live, any FAIL stops: columns/ENUMs/nullability vs manifest (Mixed legacy `birth_date` tolerated); schema-version rows; charset per table; expected unique keys (`bs_draws.slug`; `bs_draw_tokens` (did,token)/(did,position)/(did,claimed_tpid); `bs_draw_eligible` (did,tpid); `bs_tournament_matches` (tid,mkey) from `tournament_preflight_08.sql`; `bs_tournament_players` (tid,uid); `bs_tournament_lines` (tid,phase,line); `bs_pyramid_players` (ladder_id,uid)/(ladder_id,position); `bs_mixed_rounds` (event_id,round_no); `bs_mixed_participants` (event_id,uid); `bs_mixed_event_grants` (event_id,uid) + its FK); orphans reported, never fixed; Pyramid ranking gaps; every revealed draw recomputes.

**Uniqueness/FKs.** No new FKs in phase 2 (ZF2 deletes in its own order). `bs_mixed_round_players` stays without a unique key on purpose (filler = two rows, sitter = NULL group). Explicit `orderBy('id')` wherever ZF2 relied on row order.

**Auto-migrators.** Laravel never runs them; ZF2's stay idempotent against the adopted state (version rows match) and vanish with the ZF2 install.

**Character set (settled by a live check).** Verified read-only on the live database on 2026-11-04: all 22 module tables (`bs_tournament*`, `bs_draw*`, `bs_mixed*`, `bs_pyramid*`, including the `*_schema_versions` tables) are already `utf8mb4` (`utf8mb4_unicode_ci`, one `utf8mb4_general_ci`). Only the core booking tables `bs_*` (users, bookings, squares, ...) are `utf8mb3`. Consequences: no charset conversion is needed in phase 2; the columns can store emojis, but ZF2 connects with charset `UTF8` (= utf8mb3, `config/autoload/global.php`), so a ZF2 read or rewrite of a row containing a 4-byte character could turn it into `?` or fail while rollback to the bridge is possible. **Rule (my call):** the 4-byte stripping middleware also covers the module routes while any module path is still on the bridge; it exempts the module routes (like Members) only after the bridge is gone (decommission step), when tournament data can finally keep its emojis. Alternative if emojis are wanted earlier: change the ZF2 connection charset to `utf8mb4` in its server config (a live change to the old system; not planned). The adoption migrations create utf8mb4 tables on fresh DBs; the preflight keeps its per-table charset check as a guard (a FAIL if a module table is not utf8mb4). The staging copy has the same state.

**Live vs staging.** Live: guarded migrations, preflight, `draw:verify --all`. Staging: same, once as a rehearsal on the real copy present today, then `phase2:scrub-staging` (refuses unless `APP_ENV=staging` and DB name ends in `_staging`) truncates module tables and runs the seeders.

**Staging test data.** Factories plus a `<Module>DemoSeeder` with fictional players linked to fake `bs_users` (`@example.test`): tournaments in setup, token picking, half-played groups, KO with byes; a running ladder with open/overdue/resolved challenges and a waiting list; a Mixed Americano event with fillers and a Mexicano event with sitters; draws in every status with known seeds and a stubbed beacon. Seeders never read live tables; fixtures hold only public draw records (uids, no names).

**Rollback (data).** Thanks to the freeze, re-bridging lets ZF2 read what Laravel wrote; a dump of module tables is still taken before each go-live.

## Draw reproducibility plan

- `DrawService` ported line by line: `canonical()` = `json_encode($p, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)`; `commitment = sha256(canonical.'|'.serverSeedHex)`; `finalSeed = hash_hmac('sha256', beaconValue, serverSeedHex)` (key = 64-char hex string); Fisher-Yates `i = n-1..1`, `j = (unpack('J', first 8 bytes of sha256(seed.'|'.i, binary)) & 0x7FFFFFFFFFFFFFFF) % (i+1)`; numeric entries cast to int.
- Commitment checks hash the stored payload string; an invariant test asserts `canonical(json_decode(stored, true)) === stored` (catches `{}`/`[]` and int/string drift).
- Golden vectors: (a) those in `modulex/tests/run.php`; (b) a matrix generated once with the old `DrawService`/`TokenService` under WSL PHP 8.4 (n = 2, 3, 16, 24; int/string entries; Unicode and `/` in rule; token names; fallback with partner conflicts); (c) the public JSON of every revealed live draw in `tests/Fixtures/draw/records/`. PHPUnit recomputes all; a test runs `tools/verify_draw.py` (copied in) over (c) when `python3` exists.
- Tournament lot tie-break (HMAC of the first executed non-discarded draw's final seed with a scope, then shuffle) gets its own vectors; the builder must take the exact key/message order from the old `TournamentManager`.
- `php artisan draw:verify {slug?} --all` recomputes every revealed draw from the DB, before and after go-live.
- JSON endpoint keeps keys, reveal condition (`closed`, or `executed` and mode ≠ token) and no-cache headers; public, unauthenticated, uids only.
- `BeaconService` behind an interface, Laravel `Http` with both drand endpoints and 10 s timeout, faked in tests; `assertFutureRound` kept.

## Per-module scope (proposed order)

| # | Module | Contains | Size (old) | Main risks | Tests to port | Depends on |
|---|---|---|---|---|---|---|
| 0 | TournamentBase | policies scaffolding, test mode, ScoreService, WhatsApp, preflight, scrub, shared UI | ~0.6k extracted | access rules subtly wrong | Score part of `run.php`, generic `access.php` | phase 1 merged |
| 1 | Draw record | `DrawService`, models, `/draw/record/*`, `draw:verify` | part of 2.3k | byte-exactness | Draw part of `run.php`, vectors | 0 |
| 2 | Pyramid | ladders, challenges, results, moves, mails, admin, grants | 5.4k | lock order, NULL-then-set positions under UNIQUE(ladder_id,position) | `pyramid.php`, `pyramid-permissions.php`, `pyramid-integration-db.php` | 0 |
| 3 | MixedTournament | events, participants, Americano/Mexicano rounds, scoring, standings, printables, CSV import | 7.5k | randomised generation, filler/sitter rows, print, CSV encoding | `mixed.php`, `mixed-permissions.php`, `mixed-integration-db.php`, Mixed `routes.php` | 0 |
| 4 | Tournament + Draw admin/tokens | championship, draw admin, token picks, close/fallback, apply | 11.4k + rest of 2.3k | Draw↔Tournament coupling, pick concurrency, Format/Resolver | rest of `run.php`, `access.php`, `routes.php`, `integration-db.php` | 0, 1 |
| 5 | Decommission | remove bridge, ZF2 login/`service` paths, ZF2 install | small | stale links | htaccess/bridge/redirect tests | 1–4 |

**Why this order (my call).** Draw and Tournament cannot go live separately: token pages read tournament players and Draw's close calls Tournament's `applyDraw` and generation. So Draw's maths goes first (read-only, public, proves byte-exactness on live data at lowest risk) and its interactive parts go with Tournament. Pyramid is the smallest self-contained module and exercises ScoreService, mail, anonymous pages and grants first. Tournament is last: largest, most coupled, seasonal (my guess: not running in winter).

## Testing strategy

- Old scripts are the spec: every `ok()`/`throws()` becomes a `#[Test]`. Source-grep assertions become behavioural tests (pick outside `picking` fails, second execute refused, confirmed result locked for participants).
- Characterisation golden files generated once from the old framework-free services: standings/tie-breaks incl. `needs_lot` and runner-up modes, Format/Generator/Resolver, Mixed rotation/groupSizes/waves/scorecards/mixing score/standings, Pyramid challengeable sets, shifts, waitlist entry, seeding.
- Randomness injectable via `Random\Randomizer` (tests: seeded `Mt19937`); randomised outputs tested by invariants plus seeded snapshots of the new code, not against old outputs.
- `#[Group('mysql')]` suite for locking, races and adoption against a local MySQL loaded from old `sql/*.sql`; default SQLite suite skips it. Migrations run on both drivers.
- Feature tests for role × action, routes incl. base path and trailing slash, `.htaccess` matrix, `ui:lint`, `view:cache`.

## Rollout and rollback per module

1. Branch: port, tests green, `composer check`.
2. Diff live `~/apps/zf2/modulex/<M>` against the spec commit (INV-T: repo may differ); port differences.
3. Staging: deploy, preflight, `draw:verify --all`, rehearsal on the real copy, scrub + seed, smoke test per role and write action (mails in log, print preview); remaining bridged paths still work.
4. Live: dump module tables, deploy, `migrate --force`, preflight, remove the path from the allowlist, smoke test, announce; never on an event day.
5. Rollback: put the path back into the allowlist and redeploy (or edit `.htaccess` on the server); no restore needed.

Decommission: remove bridge rules, `zf2-bridge.php`, static symlinks in the deploy script, `~/apps/zf2`; restore Laravel redirects for `/user/login|logout|password|password-reset`; Laravel page or redirect for `/service/help`, `/service/info`; then post-ZF2 cleanup migrations.

## Risks

| Risk | Mitigation |
|---|---|
| Draw maths drift | vectors, live fixtures, `draw:verify`, Python verifier |
| Server ZF2 code differs from repo | diff before each port |
| Go-live during a running event | event-free window, allowlist rollback |
| Laravel writes unreadable by ZF2 | schema freeze, ZF2 JSON shapes, rollback rehearsal |
| Concurrency regressions | same `FOR UPDATE` order and conditional updates, MySQL suite |
| Access rules drift | permission matrices ported 1:1 |
| Member picker embeds all emails/phones (INV-P §11, INV-M §3) | authenticated search endpoint (my call) |
| Real data left on staging | scrub command, checklist item |

## Decisions the design makes on its own (my call)

1. Order Draw-record → Pyramid → Mixed → Tournament+Draw-admin after a shared base.
2. Guarded create migrations; no FKs and no destructive DDL until ZF2 is gone; no charset conversion (module tables are already utf8mb4).
3. Bugs fixed, behaviour otherwise unchanged: Mixed `swap` gets a mode guard (on Mexicano it regenerates from the Americano rotation, INV-M §11); Mixed `createRound`+`generateProposal` and member import run in transactions; Pyramid seeding ties use a fixed collation instead of `strcoll`; Mixed standings ties ordered by id.
4. Kept as rules: Pyramid `correctResult` does not recompute ranking; Tournament `status` ENUM unmaintained; deadlines never move on rescheduling.
5. Translated 419 flash; module admin pages in the public layout; umlauts restored; member picker replaced by search.
6. Public live draw records checked in as fixtures.
7. **Live code is OLDER than the repo.** A content comparison (line endings ignored) on 2026-11-04 showed the live ZF2 `modulex` equals the repo for all of Draw, but 14 files differ in the other three modules, and in every one the repo has more lines: Mixed (`module.config.php`, `AdminController`, `MixedManager`, `RotationService`, `round.phtml`, `round-mexicano.phtml`, `index/info.phtml`), Pyramid (`AdminController`, `Service/SchemaMigrator`, `admin/ladder.phtml`), Tournament (`IndexController`, `StandingsService`, `index/groups.phtml`, `index/index.phtml`). So the repo (HEAD of the old repo, which includes the recent Pyramid email matching and member picker work) holds changes that are NOT live yet. Consequences: port from the repo version; the Pyramid adoption migration must add the `email` column additively if absent (the repo's `SchemaMigrator` adds it at the first bridged request after a ZF2 deploy; live does not have it yet); whether the newer ZF2 code is deployed to the live ZF2 before the port is a decision of the club admin (recommendation: no, the modules are replaced anyway, but the Pyramid email feature then only exists after the Pyramid go-live).
8. **No assist user holds `allow.admin.*` meta on live** (read-only check: zero rows); `bs_users` status counts: enabled 108, placeholder 46, blocked 31, deleted 3, disabled 2, admin 1. So `admin.tournament|mixed|pyramid|draw` effectively belong to the single admin account today; granting them in Laravel's user admin is a new capability, not a change for existing users.
9. Test-mode semantics are ported as in ZF2: "any logged-in user" means any user who can log in under Laravel's login rules (same `bs_users` accounts; blocked/disabled/deleted/placeholder users cannot log in).
10. `phase2:scrub-staging` also truncates `bs_mixed_members` (the real member roster) and the demo seeder provides a fictional roster.
11. Characterisation of the Tournament lot tie-break includes its edge cases (no executed non-discarded draw, first draw later discarded); the builder captures the exact old behaviour as golden vectors, whether or not live data can reach the case.
12. Draw record page (module 1) needs no session or login; Pyramid and Mixed use only Draw's CsrfService (replaced by Laravel CSRF) and never call Draw admin routes: the builder re-verifies this with a grep before module 1 and records the result.
13. While modules are split, sessions are independent: a Laravel logout does not log out ZF2 and vice versa; the release note tells staff that a bridged module may ask for the old login once more.

## Open questions for the club admin

None open. Answered on 2026-11-04 (recorded under "Decisions by the club admin", items 8-11).

## Left to the builder

- Class names, form requests, Blade/Alpine structure, translation key layout.
- `.htaccess` mechanics for carving out `/draw/record/` and its tests.
- Golden-file generation script and format; record the old-code commit hash used.
- How module tables enter `DatabaseSchemaChecker` (per-module hook or central list).
- Preflight output format and per-module smoke checklists.
- Pagination for unpaginated public lists (Pyramid challenges), defaults showing the same items.
- Redirect targets for `/service/help` and `/service/info`.
