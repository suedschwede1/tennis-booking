# Phase 2 / Plan A: TournamentBase + Draw record page — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the shared `TournamentBase` module and a byte-exact, read-only Laravel port of the public draw record (`/draw/record/{slug}` + `/json`), verified against golden vectors from the old code and against live data, then carve `/draw/record/*` out of the ZF2 bridge.
**Architecture:** Two modules under `app/Modules` in the Members pattern (provider + `routes.php` + migrations + `resources/{views,lang/de,lang/en}`). `TournamentBase` holds the shared, framework-free services (ScoreService, WhatsApp, court names, test-mode session), the module-route group helper, the `Randomizer` binding and the `phase2:preflight` / `phase2:scrub-staging` commands with per-module registries (container tags). `Draw` holds the line-by-line `DrawService`/`TokenService` port, Eloquent models on the adopted `bs_draw*` tables, `DrawVerifier`, `draw:verify`, the record controller and a demo seeder; `public/.htaccess` gets one Laravel rule ahead of the bridge rule.
**Tech Stack:** PHP 8.4 (Helloly; composer constraint `^8.3`), Laravel 13 (`^13.8`), MySQL 8.0 / SQLite in-memory for tests, PHPUnit attributes, Blade + Tailwind (no new JS), Python 3 (`tools/verify_draw.py`, optional in tests).
**Spec:** docs/superpowers/specs/2026-11-04-phase2-tournament-port-design.md (+ the inventories under .superpowers/sdd/phase2/)

## Global Constraints

- Branch: new branch from `master` after phase 1 (`feature/helloly-cutover`) is merged; never commit on `master`.
- PHP/composer/artisan only via WSL from the repo root: `wsl php artisan test --filter=X`, `wsl composer lint`. Old repo inside WSL: `/mnt/c/development/booking`.
- Server: Helloly PHP 8.4, MySQL 8.0, no Node, `QUEUE_CONNECTION=sync`, base path `/booking` (only `route()`/`url()`, never root-absolute URLs; `RootAbsoluteUrlsTest` already scans `app/Modules/*/resources`).
- Code, comments, commit messages in English. User-facing text only via `__()` in the module namespaces `tournament-base::` and `draw::`; every key in `resources/lang/de` AND `resources/lang/en`; German wording verbatim from ZF2 with ASCII transliterations restored to umlauts (`muessen` -> `müssen`).
- Every behaviour commit stages a one-line, dated `CHANGELOG.md` entry (pre-commit hook). Test-only/docs-only commits may use `--no-verify`.
- Data adoption: guarded creates only (`if (Schema::hasTable(..)) return;`), `down()` is a no-op, no FKs, no charset conversion, no ENUM/type/NOT NULL changes, no dropped tables, no writes ZF2 cannot read. Module tables `utf8mb4` / `utf8mb4_unicode_ci`, `InnoDB`.
- Draw maths byte-exact with the old `DrawService`: `canonical()` = `json_encode($p, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)`; `commitment = sha256(canonical.'|'.serverSeedHex)`; `finalSeed = hash_hmac('sha256', beaconValue, serverSeedHex)`; Fisher-Yates `i = n-1..1`, `j = (unpack('J', substr(sha256(seed.'|'.i, true), 0, 8))[1] & 0x7FFFFFFFFFFFFFFF) % (i+1)`. Never "fix" these (anti-goal), even where Python disagrees (Review Focus 3).
- 4-byte stripping rule: the global `App\Http\Middleware\StripFourByteCharacters` (cutover branch; `web` + `api` groups, single opt-out array, only Members exempt) strips every module route. Plan A adds no own middleware or switch and never adds module routes to the opt-out array; the decommission plan does that after the bridge is gone.
- Record page and JSON: public, unauthenticated, no-cache (`Cache-Control` directives `no-store`, `no-cache`, `must-revalidate`, `max-age=0`, plus `Pragma: no-cache`), uids/tokens only, JSON keys and reveal condition unchanged.
- Tests: SQLite in memory by default (`LazilyRefreshDatabase`, `#[Test]`); MySQL-only tests carry `#[Group('mysql')]`, extend `Tests\MysqlTestCase`, run via `wsl composer test:mysql` (Task 15).
- No real tournament data in fixtures/seeders: fictional users `@example.test`; the only live data checked in are public draw-record JSON files (uids/tokens, no names).
- Models: no timestamps, `@property` docblocks, NO date casts on legacy DATETIME columns (Review Focus 1).
- Done = `wsl composer lint` + `wsl php artisan test` green; Blade changes also `wsl php artisan view:cache && wsl php artisan view:clear`.
- Never deploy or touch a server; steps marked **HUMAN** are done by the controller/club admin.

## Review Focus

1. **Eloquent date handling changes published values.** Any `$casts` of `created`/`executed`/`discarded`/`closed` (or `toArray()` serialization) turns `2026-03-01 10:00:00` into `2026-03-01T10:00:00.000000Z` in the JSON. Pinned by Task 9 `json_keeps_raw_datetime_strings` (asserts `created === '2026-03-01 10:00:00'`) and Task 13 compare-dir test.
2. **Symfony rewrites `Cache-Control`** (sorts directives, appends `private`), so an exact-string assertion is wrong and a forgotten header is invisible in manual tests; the 404 path must send it too. Pinned by Task 9/10 `*_sends_no_cache_headers` using `hasCacheControlDirective()` on 200 and 404.
3. **PHP vs Python canonical divergence.** PHP escapes U+2028/U+2029 even with `JSON_UNESCAPED_UNICODE` (`"a b"`), Python does not; numeric slot names (`"0","1"`) make PHP encode the assignment as a JSON list, which Python compares unequal to its dict; invalid UTF-8 makes `json_encode` return `false`. The port must keep PHP bytes. Pinned by Task 2 vectors `line-separator-rule`, `numeric-slots`, Task 3 `canonical_keeps_php_escaping_of_line_separators`, `canonical_throws_on_invalid_utf8`, and Task 2 `python_verifier_reports_the_known_divergences`.
4. **Assignment shape/order drift.** Comparing results with `==` (loose: `1 == "1"`) or after re-encoding loses list-vs-object and int-vs-string; the stored `result` is compared byte-exactly to `json_encode(assignment, flags)` and passed through decoded unchanged in the JSON. Pinned by Task 7 `verifier_flags_int_string_drift_in_result` and Task 9 `json_passes_list_shaped_assignment_through`.
5. **Adoption migration destroys or shadows live tables.** A Laravel-default `down()` drops `bs_draws` on `migrate:rollback`; bare live index names (`slug`, `tid`) collide in SQLite's global index namespace with later modules; a guarded create silently skips a live table with missing columns (that is preflight's job). Pinned by Task 6 `down_is_a_no_op_and_keeps_rows`, `up_leaves_an_existing_table_untouched`, Task 14 `missing_column_fails`.
6. **For Plan D:** the draw "commit" form must reject (or normalise) input that makes PHP and the Python verifier disagree (U+2028/U+2029 in the rule text, numeric slot names); pin with Plan A's vectors `line-separator-rule` and `numeric-slots`.

---

## Interfaces later plans consume (Pyramid = Plan B, Mixed = Plan C, Tournament + Draw admin = Plan D)

| Interface | Consumers |
|---|---|
| `App\Modules\TournamentBase\Routing\ModuleRoutes::middleware(): list<string>` (`['web']`; 4-byte stripping comes from the global `App\Http\Middleware\StripFourByteCharacters`) | B, C, D (every module route group); decommission adds module routes to the global middleware's opt-out array |
| Container binding `Random\Randomizer` (engine `Random\Engine\Secure`); test helper `Tests\Concerns\SeedsRandomizer::seedRandomizer(int $seed = 42): Randomizer` (binds `new Randomizer(new Mt19937($seed))`) | C, D |
| `App\Modules\TournamentBase\Services\ScoreService`: `evaluate(array $input): array`, `format(int $s1a, int $s1b, int $s2a, int $s2b, ?int $mtba = null, ?int $mtbb = null): string`, `describe(array $input, bool $hasSchedule = false): array{state:string, message:?string, replace:array, canSave:bool, winner?:string}`, `isValidSet(int $a, int $b): bool`, `isValidMatchTiebreak(int $a, int $b): bool`; `InvalidScoreException` (`public readonly string $key; public readonly array $replace`) | B, D |
| `App\Modules\TournamentBase\Support\WhatsApp::url(?string $phone, string $text = ''): string`, `::normalize(?string $phone): ?string` | B, D |
| `App\Modules\TournamentBase\Support\CourtNames::label(int\|string $court): string`, `::short(int\|string $court): string`; `PlayerName::first(string $label): string` | D (B for names) |
| `App\Modules\TournamentBase\Support\TestModeSession` (`simulate(User, string $mode, ?int $tpid = null): void`, `stop(): void`, `simulation(User): ?array{mode:string,tpid:?int}`, `selectTid(User, ?int $tid): void`, `selectedTid(User): ?int`; session key `tournament.test_mode`) | D |
| Preflight: `interface ModulePreflight { module(): string; run(SchemaInspector $schema): array /* list<PreflightResult> */ }`, tag `phase2.preflight`; `PreflightResult::{pass,warn,fail,skip}(string $check, string $detail = '')`; `SchemaInspector` (`isMysql`, `hasTable`, `compareColumns`, `collation`, `hasUniqueIndex`, `duplicateGroups`, `orphanCount`) | B, C, D |
| Scrub: `interface ModuleScrub { module(): string; tables(): array /* list<string> */; seeder(): string /* class-string<Seeder> */ }`, tag `phase2.scrub` | B, C, D |
| `Tests\MysqlTestCase`, `phpunit.mysql.xml`, `composer test:mysql` | B, C, D |
| `Tests\Support\HtaccessRouter::fromFile(string $path): self`, `->route(string $path): 'redirect'\|'laravel'\|'zf2'`; `Tests\Feature\BridgedPrefixRoutesTest` (every Laravel route under a bridged prefix must be carved out) | B, C, D, decommission |
| `App\Modules\Draw\Services\DrawService` (`canonical`, `isCanonical`, `generateServerSeed`, `commit`, `verifyCommitment`, `finalSeed`, `shuffle`, `execute`, `parseLines`) | D (Tournament lot tie-break uses `shuffle`) |
| `App\Modules\Draw\Services\TokenService` (`tokenNames`, `fallbackAssignment`, `assignSlots`, `payload`) | D |
| Models `App\Modules\Draw\Models\{Draw, DrawToken, DrawEligible}` (`Draw::isRevealed(): bool`, `tokens(): HasMany` ordered by `position`, `eligible(): HasMany`, `payloadArray(): array`, `resultArray(): ?array`); `Database\Factories\Modules\Draw\Models\DrawFactory` states `withPayload`, `token`, `executed`, `closed`, `discarded` | D |
| `App\Modules\Draw\Services\DrawVerifier::verify(Draw $draw): DrawVerification` | D (execute/close re-verify) |
| Routes `draw.record`, `draw.record.json`; `App\Modules\Draw\Support\DrawRecordPresenter::json(Draw $draw): array` | D (links from admin/token pages) |

---

### Task 1: TournamentBase module skeleton and Randomizer binding

**Files:**
- Create: `app/Modules/TournamentBase/TournamentBaseServiceProvider.php`, `app/Modules/TournamentBase/config/tournament-base.php`, `app/Modules/TournamentBase/resources/lang/de/common.php`, `app/Modules/TournamentBase/resources/lang/en/common.php`, `tests/Concerns/SeedsRandomizer.php`
- Modify: `bootstrap/providers.php` (add `TournamentBaseServiceProvider::class` after `MembersServiceProvider::class`)
- Test: `tests/Unit/Modules/TournamentBase/TournamentBaseProviderTest.php`

**Interfaces:** Produces config keys `tournament-base.live_database` (`env('PHASE2_LIVE_DB_DATABASE', 'helloly41567002_booking')`, used by Task 17), `tournament-base.courts` (`[1 => 'Garagenplatz', 2 => 'Starplatz', 3 => 'Leitenplatz']`); translation namespace `tournament-base`; binding `Random\Randomizer`; trait `Tests\Concerns\SeedsRandomizer`. Hand-write the provider (do not run `make:module`: its lowercase namespace `tournamentbase` and dashboard stub do not fit).

- [ ] Step 1: Write the failing test `TournamentBaseProviderTest` (extends `Tests\TestCase`, uses `SeedsRandomizer`):
```php
#[Test] public function config_is_merged(): void {
    $this->assertSame('helloly41567002_booking', config('tournament-base.live_database'));
    $this->assertSame([1 => 'Garagenplatz', 2 => 'Starplatz', 3 => 'Leitenplatz'], config('tournament-base.courts'));
}
#[Test] public function randomizer_uses_the_secure_engine(): void {
    $this->assertInstanceOf(\Random\Engine\Secure::class, app(\Random\Randomizer::class)->engine);
    $this->assertNotSame(app(\Random\Randomizer::class), app(\Random\Randomizer::class)); // bind, not singleton
}
#[Test] public function seeded_randomizer_is_reproducible(): void {
    $a = $this->seedRandomizer(42)->getInt(1, 1000000);
    $b = $this->seedRandomizer(42)->getInt(1, 1000000);
    $this->assertSame($a, $b);
    $this->assertInstanceOf(\Random\Engine\Mt19937::class, app(\Random\Randomizer::class)->engine);
}
#[Test] public function translations_load_in_both_locales(): void {
    $this->assertSame('Turnierbereich', __('tournament-base::common.area', [], 'de'));
    $this->assertSame('Tournament area', __('tournament-base::common.area', [], 'en'));
}
```
- [ ] Step 2: Run `wsl php artisan test --filter=TournamentBaseProviderTest`, expected FAIL (class/config missing).
- [ ] Step 3: Implement `TournamentBaseServiceProvider` (`register()`: `mergeConfigFrom(__DIR__.'/config/tournament-base.php', 'tournament-base')`, `$this->app->bind(Randomizer::class, fn () => new Randomizer(new Secure()))`; `boot()`: `loadTranslationsFrom(__DIR__.'/resources/lang', 'tournament-base')`, `loadViewsFrom(__DIR__.'/resources/views', 'tournament-base')`); lang `common.area` = `Turnierbereich` / `Tournament area`; trait method `seedRandomizer(int $seed = 42): Randomizer` binds and returns `new Randomizer(new Mt19937($seed))` via `$this->app->instance()`.
- [ ] Step 4: Run the same command, expected PASS.
- [ ] Step 5: Commit `Add the TournamentBase module with config and a Randomizer binding` (CHANGELOG: `Phase 2 groundwork: shared TournamentBase module (config, translations, injectable Randomizer).`).

### Task 2: Golden vectors from the old code and the Python verifier

**Files:**
- Create: `tests/Fixtures/draw/generate_vectors.php`, `tests/Fixtures/draw/vectors.json` (generated), `tests/Fixtures/draw/synthetic-records/*.json` (generated), `tools/verify_draw.py` (byte copy of `/mnt/c/development/booking/tools/verify_draw.py`)
- Test: `tests/Unit/Modules/Draw/GoldenVectorsFileTest.php`, `tests/Unit/Modules/Draw/PythonVerifierTest.php`

**Interfaces:** Produces `tests/Fixtures/draw/vectors.json` with top-level keys `meta`, `draw`, `shuffle`, `errors`, `token_names`, `token_payload`, `assign_slots`, `fallback`, `parse_lines`, `score_evaluate`, `score_describe` (consumed by Tasks 3, 4, 5, 11). Generator CLI: `php tests/Fixtures/draw/generate_vectors.php <old-repo-root> <out-dir>`.

- [ ] Step 1: Write the generator `generate_vectors.php` (plain PHP, no Laravel). It `require`s `<old>/modulex/Draw/src/Draw/Service/DrawService.php`, `TokenService.php` and `<old>/modulex/Tournament/src/Tournament/Service/ScoreService.php`; aborts (exit 1) unless `git -C <old> status --porcelain -- modulex/Draw modulex/Tournament/src/Tournament/Service/ScoreService.php` is empty; writes `meta` = `{old_repo_commit: git rev-parse HEAD, php_version: PHP_VERSION, generated_at: date('c'), files: [the 3 paths]}`. Seeds per case: `server_seed = hash('sha256', 'vector-server-'.$name)`, `beacon_value = hash('sha256', 'vector-beacon-'.$name)` unless given. Cases (`draw[]`, each stores `name, payload, server_seed, beacon_value, canonical, commitment, final_seed, shuffled, assignment, assignment_json` where `assignment_json = json_encode(assignment, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)`):
  - `runphp-strings`: `{entries:['Q1','Q2','Q3'], slots:['KO_A:3','KO_A:5','KO_A:7'], rule:'test'}`, seed `str_repeat('ab',32)`, beacon `str_repeat('cd',32)`
  - `n2-int` entries `[101,102]` slots `['A2','B2']`; `n3-int` `[101,102,103]`/`['A2','B2','C2']`; `n16-int` `201..216` / `KO_A:1..KO_A:16`; `n24-int` `301..324` / `A1..F4` (letters A-F, positions 1-4, letter-major); rule `'Gruppenkoepfe'`
  - `mixed-int-string` `['Q1', 7, 'S2']`; `numeric-strings` `['1','2','3','4']` / `A2..D2`
  - `unicode-slash-rule` rule `'Gruppen A/B – Setzliste für Damen & Herren "2027" ✓'`; `emoji-rule` rule `'Losglück 😀'`; `line-separator-rule` rule `"a\u{2028}b"`
  - `numeric-slots` entries `[11,12,13]` slots `['0','1','2']`
  - `token-ac6`: payload = old `TokenService::payload(tokenNames(6,'AC'), ['A2','B2','C2','A3','B3','C3'], 'Tokenziehung AC')`
  - `beacon-non-hex`: n3-int payload, beacon `'drand-randomness-not-hex'`
  - `shuffle[]`: `{items, seed, result}` for items `[]`, `[5]`, `[{tpid:3,uid:30},{tpid:1,uid:10},{tpid:2,uid:20}]`, `range(1,10)`, seed `hash('sha256','vector-shuffle')`
  - `errors[]`: `{call, args, class, message}` from `execute` with lengths 2/3, entries `[1,'1']` (SORT_REGULAR duplicate), payload without `rule`, `commit` with seed `str_repeat('AB',32)`, `finalSeed` with beacon `''`
  - `token_names[]`: `(3,'T')`, `(12,'DG')`, `(0,'T')`, `(100,'X')`; `token_payload[]`: the `token-ac6` call; `assign_slots[]`: tokens `[{dtid:7,position:2},{dtid:5,position:1},{dtid:9,position:3}]` + slots `['C2','A2','B2']`, plus the length-mismatch error message
  - `fallback[]`: `{free_tokens, non_pickers, final_seed, partner_map, fixed_groups, result | error}` for the four `fallbackAssignment` calls in `/mnt/c/development/booking/modulex/tests/run.php` lines 43-83 (copy their arguments exactly), plus seeded: free tokens `dtid 21..26, position 1..6, group D,D,E,E,F,F`, non-pickers `tpid 4,2,6,1 / uid 40,20,60,10`, `final_seed = hash('sha256','vector-fallback')`, partner map `[2=>4, 4=>2, 6=>8, 8=>6]`, fixed groups `[8=>'E']`; and the error case 3 non-pickers / 2 free tokens
  - `parse_lines[]`: `"a\n# c\n\n b # x\r\nc\r"` and `""`
  - `score_evaluate[]`: `{input, result | error}` for (s1a,s1b,s2a,s2b,mtba,mtbb): `6,4,3,6,10,8`; `6,4,6,3,10,8`; `6,4,3,6,10,9`; `6,4,3,6,12,10`; `6,4,3,6,13,10`; `6,4,3,6,9,7`; `6,4,3,6,10,0`; `6,4,3,6,11,9`; `6,4,3,6,10,10`; `6,0,6,0`; `7,6,7,5`; `4,6,3,6`; `6,5,6,0`; `8,6,6,0`; `6,4,3,6` (no MTB); `6,4,3,6,10,''`; `6,4,'',''`; strings `'6','4','6','3'`; `' 6 ','4','6','3'`; `'x','4','6','3'`; `6,4,3,6,'-1',10`
  - `score_describe[]`: `{input, has_schedule, result}` for `[]` false, `[]` true, `6,4` only, `6,4,6,3`, `6,4,6,3,10,8`, `6,4,3,6`, `6,4,3,6,10,9`, `6,4,3,6,10,8`, `6,5,6,3`
  Write `vectors.json` with `JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES`, and for every `draw[]` case one `synthetic-records/<name>.json` in the record-JSON shape `{title:<name>, status:'executed', payload, commitment, beacon_source:'drand:round:1', created:'2026-01-01 00:00:00', discarded:null, discarded_reason:null, server_seed, beacon_value, final_seed, assignment, executed:'2026-01-01 00:00:00'}`.
- [ ] Step 2: Write the failing tests. `GoldenVectorsFileTest` (`PHPUnit\Framework\TestCase`):
```php
#[Test] public function vectors_carry_provenance(): void {
    $v = json_decode(file_get_contents(dirname(__DIR__, 4).'/tests/Fixtures/draw/vectors.json'), true, flags: JSON_THROW_ON_ERROR);
    $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $v['meta']['old_repo_commit']);
    $this->assertStringStartsWith('8.4.', $v['meta']['php_version']);
    $this->assertSame(['runphp-strings', 'n2-int', 'n3-int', 'n16-int', 'n24-int', 'mixed-int-string', 'numeric-strings',
        'unicode-slash-rule', 'emoji-rule', 'line-separator-rule', 'numeric-slots', 'token-ac6', 'beacon-non-hex'],
        array_column($v['draw'], 'name'));
    $this->assertCount(13, glob(dirname(__DIR__, 4).'/tests/Fixtures/draw/synthetic-records/*.json'));
}
``` `PythonVerifierTest` (`Tests\TestCase`; `markTestSkipped('python3 not found')` when `(new ExecutableFinder)->find('python3')` is null):
```php
#[Test] public function python_verifier_accepts_every_synthetic_record(): void // all except line-separator-rule, numeric-slots: exit 0, stdout contains "Commitment  : OK" and "Zuteilung   : OK"
#[Test] public function python_verifier_reports_the_known_divergences(): void // line-separator-rule: exit 1 + "Commitment  : ABWEICHUNG"; numeric-slots: exit 1 + "Zuteilung   : ABWEICHUNG"
#[Test] public function copied_verifier_is_byte_identical_to_the_old_one(): void // sha256 of tools/verify_draw.py === constant recorded in this test from the old file (skip if old repo absent)
```
- [ ] Step 3: Run `wsl php tests/Fixtures/draw/generate_vectors.php /mnt/c/development/booking tests/Fixtures/draw`; copy the verifier with `cp /mnt/c/development/booking/tools/verify_draw.py tools/verify_draw.py` (inside `wsl`). Expected `old_repo_commit` = `6b780d3324936bab3749237183c2dab161873612`; if HEAD moved, record the new hash and confirm `git -C /mnt/c/development/booking diff 6b780d3 -- modulex/Draw modulex/Tournament/src/Tournament/Service/ScoreService.php` is empty, else stop and report.
- [ ] Step 4: Run `wsl php artisan test --filter='GoldenVectorsFileTest|PythonVerifierTest'`, expected PASS (before step 3: FAIL, file missing).
- [ ] Step 5: Commit `Capture golden draw/token/score vectors from the old ZF2 services` with the old commit hash in the body (`--no-verify`, test fixtures only).

### Task 3: Draw module skeleton and the byte-exact DrawService

**Files:**
- Create: `app/Modules/Draw/DrawServiceProvider.php`, `app/Modules/Draw/Services/DrawService.php`, `app/Modules/Draw/resources/lang/{de,en}/record.php` (empty arrays for now)
- Modify: `bootstrap/providers.php` (add `DrawServiceProvider::class` after TournamentBase)
- Test: `tests/Unit/Modules/Draw/DrawServiceTest.php`, `tests/Unit/Modules/Draw/DrawGoldenVectorsTest.php`

**Interfaces:** Produces `final class DrawService` (no constructor args, no framework imports):
`canonical(array $payload): string`, `generateServerSeed(): string`, `commit(array $payload, string $serverSeedHex): string`, `verifyCommitment(array $payload, string $serverSeedHex, string $commitment): bool`, `finalSeed(string $serverSeedHex, string $beaconValue): string`, `shuffle(array $items, string $seedHex): array`, `execute(array $payload, string $serverSeedHex, string $beaconValue): array{final_seed: string, shuffled: list<mixed>, assignment: array<int|string, mixed>}`, `parseLines(string $text): list<string>`.

- [ ] Step 1: Write failing tests. `DrawGoldenVectorsTest` loops `vectors.json`: for each `draw[]` case `assertSame($c['canonical'], $s->canonical($c['payload']))`, same for `commit`, `finalSeed`, `execute()['shuffled']`, `execute()['assignment']`, and `json_encode(execute()['assignment'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) === $c['assignment_json']`; every `shuffle[]`, `parse_lines[]`; every `errors[]` case `expectExceptionObject(new $c['class']($c['message']))`. `DrawServiceTest` (run.php spec):
```php
#[Test] public function commitment_is_reproducible(): void        // run.php payload Q1..Q3, ab*32: verifyCommitment(...) true
#[Test] public function string_lots_are_drawn_deterministically(): void // execute twice === and count(assignment) === 3
#[Test] public function canonical_keeps_php_escaping_of_line_separators(): void { $this->assertSame('{"r":"a b/ä"}', (new DrawService)->canonical(['r' => "a\u{2028}b/ä"])); }
#[Test] public function canonical_throws_on_invalid_utf8(): void { $this->expectException(\JsonException::class); (new DrawService)->canonical(['r' => "\xff"]); }
#[Test] public function server_seed_is_64_lowercase_hex(): void { $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (new DrawService)->generateServerSeed()); }
```
- [ ] Step 2: Run `wsl php artisan test --filter='DrawServiceTest|DrawGoldenVectorsTest'`, expected FAIL.
- [ ] Step 3: Implement `app/Modules/Draw/Services/DrawService.php`, ported line by line from `/mnt/c/development/booking/modulex/Draw/src/Draw/Service/DrawService.php` (translate the German docblocks to English, keep their reasoning). The maths verbatim:
```php
public function canonical(array $payload): string
{
    // JSON_THROW_ON_ERROR does not change the bytes; the old code returned false on invalid UTF-8.
    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
public function generateServerSeed(): string { return bin2hex(random_bytes(32)); }
public function commit(array $payload, string $serverSeedHex): string
{
    $this->assertHex($serverSeedHex, 'serverSeed');
    return hash('sha256', $this->canonical($payload).'|'.$serverSeedHex);
}
public function verifyCommitment(array $payload, string $serverSeedHex, string $commitment): bool
{
    return hash_equals($commitment, $this->commit($payload, $serverSeedHex));
}
public function finalSeed(string $serverSeedHex, string $beaconValue): string
{
    $this->assertHex($serverSeedHex, 'serverSeed');
    if ($beaconValue === '') {
        throw new InvalidArgumentException('beaconValue must not be empty');
    }
    return hash_hmac('sha256', $beaconValue, $serverSeedHex);
}
public function shuffle(array $items, string $seedHex): array
{
    $this->assertHex($seedHex, 'seed');
    $a = array_values($items);
    for ($i = count($a) - 1; $i > 0; $i--) {
        $raw = hash('sha256', $seedHex.'|'.$i, true);
        $x = unpack('J', substr($raw, 0, 8))[1] & 0x7FFFFFFFFFFFFFFF;
        $j = $x % ($i + 1);
        [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
    }
    return $a;
}
public function execute(array $payload, string $serverSeedHex, string $beaconValue): array
{
    foreach (['entries', 'slots', 'rule'] as $key) {
        if (! isset($payload[$key])) {
            throw new InvalidArgumentException(sprintf('payload key "%s" is missing', $key));
        }
    }
    $entries = array_values($payload['entries']);
    $slots = array_values($payload['slots']);
    if (count($entries) != count($slots)) {
        throw new RuntimeException(sprintf('entries (%u) and slots (%u) must have the same length', count($entries), count($slots)));
    }
    if (count($entries) != count(array_unique($entries, SORT_REGULAR))) {
        throw new RuntimeException('entries must be unique');
    }
    $seed = $this->finalSeed($serverSeedHex, $beaconValue);
    $shuffled = $this->shuffle($entries, $seed);
    $assignment = [];
    foreach ($slots as $index => $slot) {
        $assignment[$slot] = $shuffled[$index];
    }
    return ['final_seed' => $seed, 'shuffled' => $shuffled, 'assignment' => $assignment];
}
public function parseLines(string $text): array
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $line = trim(preg_replace('/#.*$/', '', $line));
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}
private function assertHex(string $value, string $label): void
{
    if (! preg_match('/^[0-9a-f]{64}$/', $value)) {
        throw new InvalidArgumentException(sprintf('%s must be 64 lowercase hex chars', $label));
    }
}
```
`DrawServiceProvider` mirrors Members: `loadMigrationsFrom`, `loadViewsFrom(..., 'draw')`, `loadTranslationsFrom(..., 'draw')`; routes come in Task 9.
- [ ] Step 4: Run the Step 2 command, expected PASS.
- [ ] Step 5: Commit `Port the commit/reveal DrawService byte-exactly` (CHANGELOG: `Phase 2: Draw module with a byte-exact port of the commit/reveal draw maths (golden vectors from the old code).`).

### Task 4: Canonical round-trip invariant

**Files:** Modify `app/Modules/Draw/Services/DrawService.php`; Test `tests/Unit/Modules/Draw/CanonicalInvariantTest.php`

**Interfaces:** Produces `DrawService::isCanonical(string $stored): bool` = `is_array($d = json_decode($stored, true))` and `canonical($d) === $stored` (false on decode failure or `JsonException`). Used by `DrawVerifier` (Task 7) and preflight (Task 14).

- [ ] Step 1: Write the failing test:
```php
#[Test] public function every_golden_canonical_round_trips(): void // foreach vectors draw[] : assertTrue(isCanonical($c['canonical']))
#[Test] public function drift_is_detected(): void {
    $s = new DrawService();
    foreach (['{"entries":{},"slots":[],"rule":"x"}', '{"entries":[1],"slots":["A\/2"],"rule":"x"}', '{"rule":"ä"}',
              ' {"rule":"x"}', "{\n\"rule\":\"x\"}", '{"rule":"x"', '"x"', '[1]x'] as $stored) {
        $this->assertFalse($s->isCanonical($stored), $stored);
    }
    $this->assertTrue($s->isCanonical('{"entries":["1",2],"slots":["A2","B2"],"rule":"a/b ä"}'));
}
```
- [ ] Step 2: Run `wsl php artisan test --filter=CanonicalInvariantTest`, expected FAIL.
- [ ] Step 3: Implement `isCanonical(string $stored): bool` in `DrawService.php`.
- [ ] Step 4: Run, expected PASS.
- [ ] Step 5: Commit `Add the canonical round-trip invariant for stored draw payloads` (CHANGELOG: `Phase 2: stored draw payloads are checked to be exactly canonical JSON.`).

### Task 5: TokenService port

**Files:** Create `app/Modules/Draw/Services/TokenService.php`; Test `tests/Unit/Modules/Draw/TokenServiceTest.php`

**Interfaces:** `final class TokenService`: `tokenNames(int $count, string $prefix = 'T'): list<array{position:int, token:string}>`, `fallbackAssignment(array $freeTokens, array $nonPickers, ?string $finalSeed = null, ?DrawService $drawService = null, array $partnerMap = [], array $fixedGroups = []): list<array{dtid:int, tpid:int, uid:?int}>`, `assignSlots(array $tokens, array $shuffledSlots): array<int, string>`, `payload(array $tokenNames, array $slots, string $rule): array{entries:list<string>, slots:list<string>, rule:string}`.

- [ ] Step 1: Write the failing test: loop `vectors.json` `token_names`, `token_payload`, `assign_slots`, `fallback` (results `assertSame`, error cases `expectExceptionMessage`); plus run.php assertions as named tests: `token_names_are_stable` (`tokenNames(3)[0]['token'] === 'T-01'`, `[2]['position'] === 3`), `fallback_sorts_tokens_and_players` (first entry dtid 1 / tpid 4), `fallback_avoids_the_partner_group`, `partner_rule_is_dropped_when_unavoidable`, `fallback_allows_only_the_one_unavoidable_conflict` (`mixedConflicts === 1`), with the run.php arguments.
- [ ] Step 2: Run `wsl php artisan test --filter=TokenServiceTest`, expected FAIL.
- [ ] Step 3: Port `/mnt/c/development/booking/modulex/Draw/src/Draw/Service/TokenService.php` line by line (types added, `sprintf('%s-%02u', ...)`, the closure-based branch-and-bound search unchanged in candidate order and tie-breaks; the vectors pin it; docblocks translated to English).
- [ ] Step 4: Run, expected PASS.
- [ ] Step 5: Commit `Port TokenService (token names, slot assignment, partner-aware fallback)` (CHANGELOG: `Phase 2: token draw helpers ported unchanged (golden vectors).`).

### Task 6: Draw models, guarded adoption migrations, schema manifest, factories

**Files:**
- Create: `app/Modules/Draw/Database/Migrations/2026_11_05_090001_create_bs_draws_table.php`, `..._090002_create_bs_draw_tokens_table.php`, `..._090003_create_bs_draw_eligible_table.php`; `app/Modules/Draw/Models/{Draw,DrawToken,DrawEligible}.php`; `database/factories/Modules/Draw/Models/{DrawFactory,DrawTokenFactory}.php`
- Modify: `app/Services/DatabaseSchemaChecker.php` (`columnDefinitions()` + `tableLevelConstraints()` for the 3 tables)
- Test: `tests/Feature/Modules/Draw/DrawAdoptionMigrationTest.php`, `tests/Unit/Modules/Draw/DrawModelTest.php`; existing `tests/Unit/Services/DatabaseSchemaCheckerTest.php` must stay green (`every_table_in_the_database_is_tracked_by_the_manifest`, `drift_report_is_empty_for_a_healthy_schema`)

**Interfaces:** Live end state (create + `tournament_schema_03/07/09`), column order as live:
- `bs_draws`: `increments did`; `unsignedInteger tid` null; `string slug 32` unique; `string title 160`; `enum status [committed,picking,closed,executed]` default `committed`; `enum mode [direct,token]` default `token`; `mediumText payload`; `char commitment 64`; `string beacon_source 255`; `dateTime pick_deadline` null; `dateTime closed` null; `char server_seed 64` null; `string beacon_value 255` null; `char final_seed 64` null; `mediumText result` null; `unsignedInteger created_uid`; `dateTime created`; `dateTime executed` null; `dateTime discarded` null; `string discarded_reason 160` null; `dateTime applied_at` null; `unsignedInteger applied_uid` null; `index(tid)`.
- `bs_draw_tokens`: `increments dtid`; `unsignedInteger did`; `unsignedSmallInteger position`; `string token 16`; `string slot 8` null; `unsignedInteger claimed_tpid` null; `unsignedInteger claimed_uid` null; `dateTime claimed` null; `enum assigned_by [pick,fallback]` null; `unique(did,token)`, `unique(did,position)`, `unique(did,claimed_tpid)`, `index(did,claimed_tpid)`.
- `bs_draw_eligible`: `increments deid`; `unsignedInteger did`; `unsignedInteger tpid`; `unique(did,tpid)`.
- Every create: `$table->engine = 'InnoDB'; $table->charset = 'utf8mb4'; $table->collation = 'utf8mb4_unicode_ci';` Laravel default index names (NOT the live names `slug`/`tid`/`did_token`: SQLite index names are global). `up()` returns early if the table exists; `down()` has an empty body with a comment (adopted live tables are never dropped by Laravel).
- Models: `Draw` (`$table = 'bs_draws'`, `$primaryKey = 'did'`, `$timestamps = false`, `$guarded = []`, no `$casts`; `tokens(): HasMany` `->orderBy('position')`, `eligible(): HasMany` `->orderBy('deid')`; `isRevealed(): bool` = `status === 'closed' || (status === 'executed' && mode !== 'token')`; `payloadArray(): array` (decoded or `[]`); `resultArray(): ?array`; `scopeSlug(Builder $q, string $slug)`); `DrawToken` (`bs_draw_tokens`, `dtid`, `belongsTo(Draw, 'did')`); `DrawEligible` (`bs_draw_eligible`, `deid`). Full `@property` docblocks; datetime columns typed `string|null`.
- `DrawFactory` definition: `slug` 12 lowercase hex, `title` `'Testziehung'`, `status` `committed`, `mode` `direct`, `server_seed` 64 hex, payload `{entries:[101,102,103,104], slots:['A2','B2','C2','D2'], rule:'Testregel'}` stored via `DrawService::canonical`, matching `commitment`, `beacon_source` `'drand:round:1000'`, `created_uid` 1, `created` `now()->format('Y-m-d H:i:s')`. States: `withPayload(array $payload)` (recomputes payload+commitment), `token(int $count = 6, string $prefix = 'AC', array $slots = ['A2','B2','C2','A3','B3','C3'])` (mode token, payload via `TokenService::payload`, `afterCreating` creates the `DrawToken` rows with slot from `result` when executed), `executed(string $beacon = <64 hex>)` (beacon_value, final_seed, `result = json_encode(assignment, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)`, `executed`; status `executed` for direct, `picking` for token), `closed()` (status `closed`, `closed` timestamp, all tokens claimed), `discarded(string $reason = 'Testgrund')`.

- [ ] Step 1: Write failing tests. `DrawAdoptionMigrationTest`:
```php
#[Test] public function fresh_database_has_the_live_columns(): void // Schema::getColumnListing('bs_draws') === [did, tid, slug, title, status, mode, payload, commitment, beacon_source, pick_deadline, closed, server_seed, beacon_value, final_seed, result, created_uid, created, executed, discarded, discarded_reason, applied_at, applied_uid]; same for the two other tables
#[Test] public function up_leaves_an_existing_table_untouched(): void // drop bs_draw_eligible, create it with (deid, did, tpid, legacy_note) + one row, run (require migration)->up(); column listing still contains legacy_note and the row count is 1
#[Test] public function down_is_a_no_op_and_keeps_rows(): void // insert a Draw via factory, run each migration's down(); Schema::hasTable true for all 3 and Draw::count() === 1
#[Test] public function unique_keys_exist(): void // Schema::getIndexes: unique on [slug], [did,token], [did,position], [did,claimed_tpid], [did,tpid]
```
`DrawModelTest`: `is_revealed_truth_table` (`closed/token` true, `executed/direct` true, `executed/token` false, `picking/token` false, `committed/direct` false, `closed/direct` true); `datetime_columns_stay_raw_strings` (`Draw::factory()->create(['created' => '2026-03-01 10:00:00'])->fresh()->created === '2026-03-01 10:00:00'`); `tokens_are_ordered_by_position`; `factory_states_are_consistent` (`executed()` draw: `DrawService::verifyCommitment` true, `result` equals the json_encode of `execute()` assignment).
- [ ] Step 2: Run `wsl php artisan test --filter='DrawAdoptionMigrationTest|DrawModelTest|DatabaseSchemaCheckerTest'`, expected FAIL.
- [ ] Step 3: Implement the migrations, models, factories (`HasFactory` + `newFactory()` as in `Members\Models\Member`), and the manifest entries: same closures as the migrations in `columnDefinitions()`; composite uniques/index in `tableLevelConstraints()` with short names `dp_bs_draw_tokens_did_token`, `dp_bs_draw_tokens_did_position`, `dp_bs_draw_tokens_did_claimed`, `dp_bs_draw_tokens_did_claimed_idx`, `dp_bs_draw_eligible_did_tpid`; single-column `slug` unique and `tid` index via the column closures (`->unique()`, `->index()`).
- [ ] Step 4: Run, expected PASS.
- [ ] Step 5: Commit `Adopt the bs_draw* tables with guarded migrations and models` (CHANGELOG: `Phase 2: draw tables adopted in place (guarded create migrations, no-op rollback, schema manifest).`).

### Task 7: DrawVerifier and the `draw:verify` command

**Files:**
- Create: `app/Modules/Draw/Services/DrawVerifier.php`, `app/Modules/Draw/Services/DrawVerification.php`, `app/Modules/Draw/Console/DrawVerifyCommand.php`
- Modify: `app/Modules/Draw/DrawServiceProvider.php` (register the command)
- Test: `tests/Unit/Modules/Draw/DrawVerifierTest.php`, `tests/Feature/Modules/Draw/DrawVerifyCommandTest.php`

**Interfaces:** `final class DrawVerifier { __construct(DrawService $draws); verify(Draw $draw): DrawVerification }`. `final readonly class DrawVerification { string $slug; string $status; string $mode; array $checks /* array<'canonical'|'commitment'|'final_seed'|'result'|'tokens', ?bool> */; ok(): bool /* no false */; failures(): list<string>; executed(): bool /* final_seed !== null */ }`. Checks: `canonical` = `isCanonical(payload)`; `commitment` (when `server_seed` set) = `hash_equals(commitment, hash('sha256', <stored payload string>.'|'.server_seed))`; `final_seed` (when set) = `hash_equals(final_seed, finalSeed(server_seed, beacon_value))`; `result` (when executed) = `json_encode(execute(json_decode(payload, true), server_seed, beacon_value)['assignment'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) === result` (byte-exact); `tokens` (token mode and executed) = every `DrawToken` with a slot satisfies `(string) assignment[slot] === token`, and every token name in the assignment has a row. Exceptions inside a check count as `false`. Command: `draw:verify {slug? : Verify one draw} {--all : Verify every draw} {--compare-dir= : Directory of record JSON captured from ZF2 (<slug>.json)}`; output one line per draw `<slug> [<status>/<mode>] OK` / `... FAIL: commitment, result` / `... not executed (canonical OK)`; never prints `server_seed` or `final_seed`; exit 0 iff no FAIL; no slug and no `--all` -> error `Pass a slug or --all.`, exit 2; unknown slug -> `Draw <slug> not found.`, exit 1. `--compare-dir` is completed in Task 13.

- [ ] Step 1: Write failing tests. `DrawVerifierTest` (Feature-style DB, `LazilyRefreshDatabase`):
```php
#[Test] public function a_factory_draw_verifies(): void            // executed direct, token closed, committed: ok() true; committed has checks commitment true, final_seed null
#[Test] public function tampered_payload_fails_commitment(): void  // update payload to canonical of other entries: failures() === ['commitment', 'result']
#[Test] public function non_canonical_payload_fails(): void         // payload stored with "\/" escape: failures() contains 'canonical'
#[Test] public function verifier_flags_int_string_drift_in_result(): void // replace result '{"A2":101,...}' with '{"A2":"101",...}': failures() === ['result']
#[Test] public function token_slot_drift_fails(): void              // swap slot of two tokens of a closed token draw: failures() === ['tokens']
#[Test] public function golden_vectors_verify_when_stored(): void   // for each vectors draw[] case: create Draw with payload=canonical, server_seed, beacon_value, final_seed, result=assignment_json -> ok()
```
`DrawVerifyCommandTest`: `verifies_one_slug` (exit 0, output contains `<slug> [executed/direct] OK`, does not contain the server seed); `all_reports_failures_with_exit_1`; `requires_slug_or_all` (exit 2); `unknown_slug_exits_1`.
- [ ] Step 2: Run `wsl php artisan test --filter='DrawVerifierTest|DrawVerifyCommandTest'`, expected FAIL.
- [ ] Step 3: Implement the three classes and register the command.
- [ ] Step 4: Run, expected PASS.
- [ ] Step 5: Commit `Add DrawVerifier and draw:verify to recompute draws from the database` (CHANGELOG: `Phase 2: php artisan draw:verify {slug?} --all recomputes commitment, seed and assignment of stored draws.`).

### Task 8: Module route group helper

**Prerequisite:** the cutover branch's global `App\Http\Middleware\StripFourByteCharacters` (in `web` + `api`, opt-out array with only Members) is merged; if not, stop and report.

**Files:** Create `app/Modules/TournamentBase/Routing/ModuleRoutes.php`; Test `tests/Unit/Modules/TournamentBase/ModuleRoutesTest.php`

**Interfaces:** `final class ModuleRoutes { public static function middleware(): array }` returns `['web']` (one place for later shared module middleware; 4-byte stripping is global). No module route is ever added to the global opt-out array in Plans A-D; the decommission plan adds them after the bridge is gone.

- [ ] Step 1: Write the failing test `module_routes_use_the_web_group` (`assertSame(['web'], ModuleRoutes::middleware())`) and `global_stripping_middleware_exists` (`assertTrue(class_exists(\App\Http\Middleware\StripFourByteCharacters::class))`).
- [ ] Step 2: Run `wsl php artisan test --filter=ModuleRoutesTest`, expected FAIL.
- [ ] Step 3: Implement `ModuleRoutes`.
- [ ] Step 4: Run, expected PASS.
- [ ] Step 5: Commit `Add the shared module route group helper` (`--no-verify`, no behaviour change).

### Task 9: Public JSON record endpoint

**Files:**
- Create: `app/Modules/Draw/routes.php`, `app/Modules/Draw/Http/Controllers/RecordController.php`, `app/Modules/Draw/Support/DrawRecordPresenter.php`
- Modify: `app/Modules/Draw/DrawServiceProvider.php` (`Route::middleware(ModuleRoutes::middleware())->group(fn () => $this->loadRoutesFrom(__DIR__.'/routes.php'))`)
- Test: `tests/Feature/Modules/Draw/RecordJsonTest.php`, `tests/Unit/Modules/TournamentBase/ModuleRoutesTest.php` (extend)

**Interfaces:** Routes (no auth): `GET draw/record/{slug}` -> `RecordController@show` name `draw.record`; `GET draw/record/{slug}/json` -> `RecordController@json` name `draw.record.json`; both `->where('slug', '[A-Za-z0-9_-]{4,32}')`. `DrawRecordPresenter::json(Draw $draw): array` keys in this order: `title, status, payload (json_decode(payload, true)), commitment, beacon_source, created, discarded, discarded_reason`, and when `isRevealed()`: `server_seed, beacon_value, final_seed, assignment (json_decode(result, true)), executed`; all values raw DB strings/null. Controller sends `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` and `Pragma: no-cache` on every response (also 404); unknown slug JSON: `{"error":"not found"}` 404. Before implementing, re-verify design decision 12 with `wsl grep -rn "Draw\\\\" /mnt/c/development/booking/modulex/Pyramid /mnt/c/development/booking/modulex/MixedTournament` (expect only `Draw\Service\CsrfService`) and put the result in the commit body.

- [ ] Step 1: Write failing tests (`LazilyRefreshDatabase`, anonymous):
```php
#[Test] public function committed_draw_hides_secrets(): void // keys === [title,status,payload,commitment,beacon_source,created,discarded,discarded_reason]
#[Test] public function executed_direct_draw_reveals(): void // + server_seed, beacon_value, final_seed, assignment, executed; assignment === execute(...)['assignment']
#[Test] public function picking_token_draw_stays_hidden(): void // executed token draw in status picking: no server_seed key
#[Test] public function closed_token_draw_reveals(): void
#[Test] public function json_keeps_raw_datetime_strings(): void // created '2026-03-01 10:00:00', executed '2026-03-01 11:00:00' exactly
#[Test] public function json_passes_list_shaped_assignment_through(): void // slots ['0','1','2']: response body contains '"assignment":[' (list), equals json_decode(result)
#[Test] public function json_keeps_int_and_string_entries(): void // entries [101,'Q1']: payload.entries === [101,'Q1'] (assertSame on decoded)
#[Test] public function unknown_and_malformed_slugs_are_404(): void // 'zzzz9999' -> 404 {"error":"not found"}; 'abc' and 'a.bcd' -> 404
#[Test] public function json_sends_no_cache_headers(): void // on 200 and 404: hasCacheControlDirective no-store, no-cache, must-revalidate; getMaxAge() === 0; Pragma === 'no-cache'
#[Test] public function endpoint_output_passes_the_python_verifier(): void // skip without python3: write GET body to a temp file, run tools/verify_draw.py -> exit 0
```
`ModuleRoutesTest` add: `draw_routes_go_through_the_global_stripping_middleware` — `app('router')->gatherRouteMiddleware(Route::getRoutes()->getByName('draw.record.json'))` contains `App\Http\Middleware\StripFourByteCharacters`; and behaviourally: listen to `Illuminate\Foundation\Http\Events\RequestHandled`, GET `draw/record/<slug>/json?q=Ball%20%F0%9F%8E%BE%20ok`, assert the captured `$event->request->query('q') === 'Ball  ok'`.
- [ ] Step 2: Run `wsl php artisan test --filter='RecordJsonTest|ModuleRoutesTest'`, expected FAIL.
- [ ] Step 3: Implement routes, presenter, `RecordController::json(string $slug): JsonResponse` (`show` returns a 501 placeholder until Task 10 replaces it, not linked anywhere).
- [ ] Step 4: Run, expected PASS.
- [ ] Step 5: Commit `Serve the public draw record JSON from Laravel` (CHANGELOG: `Phase 2: /draw/record/{slug}/json implemented in Laravel with the same keys, reveal rule and no-cache headers (not yet routed by .htaccess).`).

### Task 10: Public HTML record page

**Files:**
- Create: `app/Modules/Draw/resources/views/record.blade.php`
- Modify: `app/Modules/Draw/Http/Controllers/RecordController.php` (`show`), `app/Modules/Draw/resources/lang/{de,en}/record.php`
- Test: `tests/Feature/Modules/Draw/RecordPageTest.php`, `tests/Unit/Modules/Draw/DrawViewsLintTest.php`

**Interfaces:** `RecordController::show(string $slug): Response` renders `draw::record` with `['draw' => ?Draw, 'payload' => array, 'result' => ?array, 'revealed' => bool]` (404 with `draw => null`), extends `layouts.app`, DESIGN.md card/table/badge patterns, no JS, no names (entries printed as-is). Lang keys `draw::record.*` (de verbatim ZF2 wording, umlauts restored / en): `title` Losprotokoll / Draw record; `not_found` Protokoll nicht gefunden / Record not found; `discarded` Diese Ziehung wurde verworfen. / This draw was discarded.; `no_reason` Kein Grund angegeben. / No reason given.; `status` Status; `status_executed` ausgeführt am :date / executed on :date; `status_pending` festgeschrieben, noch nicht gezogen / committed, not drawn yet; `committed_at` Festgeschrieben / Committed; `commitment` Commitment; `beacon_source` Zufallsquelle / Randomness source; `entries` Lose / Entries; `slots` Zielslots / Target slots; `rule` Regel / Rule; `pending_heading` Noch nicht gezogen / Not drawn yet; `pending_text`; `result` Ergebnis / Result; `slot` Slot; `entry` Los / Entry; `verify_heading` Nachrechnen / Verify; `server_seed` Server-Seed; `beacon_value` Beacon-Wert / Beacon value; `final_seed` Finaler Seed / Final seed; `json_hint` (with `:link` and the `tools/verify_draw.py` mention); `json_link` JSON; `method` Verfahren / Method; `method_text` (the pseudocode block, translated); `beacon_note`. Copy the German texts from `/mnt/c/development/booking/modulex/Draw/view/draw/record/index.phtml`.

- [ ] Step 1: Write failing tests:
```php
#[Test] public function anonymous_user_sees_a_committed_draw_without_secrets(): void // 200, see title, commitment, 'Noch nicht gezogen'; dontSee server_seed value
#[Test] public function revealed_draw_shows_result_and_seeds(): void // see each slot, entry, final_seed, beacon_value, server_seed
#[Test] public function string_lots_are_not_cast_to_zero(): void // entries ['Q1','Q2','Q3'] executed: see 'Q1', result cells never '0'
#[Test] public function discarded_draw_is_flagged(): void // see 'Diese Ziehung wurde verworfen.' and the reason
#[Test] public function unknown_slug_renders_404(): void // 404, see 'Protokoll nicht gefunden'
#[Test] public function page_sends_no_cache_headers(): void // 200 and 404, as in Task 9
#[Test] public function json_link_honours_the_booking_base_path(): void // server vars as BasePathUrlsTest; GET /booking/draw/record/<slug>: href matches #/booking/draw/record/<slug>/json#
#[Test] public function english_locale_is_translated(): void // locale en: see 'Draw record'
```
`DrawViewsLintTest`: `module_views_pass_the_ui_linter` = `assertSame([], (new \App\Services\UiLinter(app_path('Modules/Draw/resources/views'), \App\Services\UiRules::all()))->run())` (lint:ui only scans `resources/views`).
- [ ] Step 2: Run `wsl php artisan test --filter='RecordPageTest|DrawViewsLintTest'`, expected FAIL.
- [ ] Step 3: Implement the view, `show()`, lang files; run `wsl php artisan view:cache && wsl php artisan view:clear`.
- [ ] Step 4: Run, expected PASS.
- [ ] Step 5: Commit `Render the public draw record page in Laravel` (CHANGELOG: `Phase 2: public draw record page in the new design (German/English), same content and reveal rule as ZF2.`).

### Task 11: ScoreService port

**Files:**
- Create: `app/Modules/TournamentBase/Services/ScoreService.php`, `app/Modules/TournamentBase/Services/InvalidScoreException.php`, `app/Modules/TournamentBase/resources/lang/{de,en}/score.php`
- Test: `tests/Unit/Modules/TournamentBase/ScoreServiceTest.php`, `tests/Unit/Modules/TournamentBase/ScoreGoldenVectorsTest.php`

**Interfaces:** As in the interface table. `InvalidScoreException extends InvalidArgumentException` with `__construct(public readonly string $key, public readonly array $replace = [])` (`getMessage()` = key). Keys (`tournament-base::score.`): `sets_incomplete`, `mtb_after_two_sets`, `mtb_required`, `mtb_both_scores`, `set_range` (`:number`), `set_invalid` (`:number`, `:score`), `mtb_negative`, `mtb_min_ten` (`:score`), `mtb_ten_nine` (`:score`), `mtb_two_apart` (`:score`), `mtb_draw`, `not_integer` (`:field`); describe keys `describe.schedule_only`, `describe.enter_sets`, `describe.set1_invalid`, `describe.set2_invalid`, `describe.mtb_must_be_empty`, `describe.mtb_needed`, `describe.mtb_invalid`. `:score` = `"$a:$b"`. `describe()` returns `message` as a full key or `null` plus `replace => []`. Logic ported line by line from `/mnt/c/development/booking/modulex/Tournament/src/Tournament/Service/ScoreService.php` (input regex `/^[0-9]{1,2}$/` after `trim`).

- [ ] Step 1: Write failing tests. `ScoreServiceTest` (run.php): `mtb_counts_as_set_and_one_game` (`6,4,3,6,10,8`: sets_a 2, games_a 10, games_b 10), `mtb_after_two_sets_is_rejected` (key `tournament-base::score.mtb_after_two_sets`), `ten_nine_is_rejected` (key `mtb_ten_nine`, replace `['score' => '10:9']`), `twelve_ten_is_valid`. `ScoreGoldenVectorsTest`: for each `score_evaluate` case: result `assertSame`; error: `InvalidScoreException` thrown and `__($e->key, $e->replace, 'de') === strtr($old, ['muessen' => 'müssen', 'vollstaendig' => 'vollständig', 'Saetzen' => 'Sätzen', 'Punktestaende' => 'Punktestände', 'Gamestaende' => 'Gamestände', 'gueltiges' => 'gültiges', 'Moeglich' => 'Möglich', 'moeglich' => 'möglich', 'ueber' => 'über'])`; for each `score_describe` case: all keys except `message` equal the old result, `__(message, [], 'de') === old message` (or both null); every key also exists in `en` (`trans($key, [], 'en') !== $key`).
- [ ] Step 2: Run `wsl php artisan test --filter='ScoreServiceTest|ScoreGoldenVectorsTest'`, expected FAIL.
- [ ] Step 3: Implement the service, exception and both lang files.
- [ ] Step 4: Run, expected PASS.
- [ ] Step 5: Commit `Port ScoreService into TournamentBase with translated messages` (CHANGELOG: `Phase 2: match score rules (best of three, match tie-break) ported unchanged with German/English messages.`).

### Task 12: WhatsApp link, court names, first name

**Files:**
- Create: `app/Modules/TournamentBase/Support/{WhatsApp,CourtNames,PlayerName}.php`, `app/Modules/TournamentBase/resources/lang/{de,en}/courts.php`
- Test: `tests/Unit/Modules/TournamentBase/SupportHelpersTest.php`

**Interfaces:** As in the interface table. `CourtNames::label()` = `__('tournament-base::courts.label', ['number' => $court, 'name' => <config name>])` with de `Platz :number – :name`, en `Court :number – :name`; `''` for unknown/empty; `short()` = the configured name or `''`. `PlayerName::first()` = first whitespace-separated part of `trim($label)`, else `$label`. WhatsApp ported from `/mnt/c/development/booking/modulex/Tournament/src/Tournament/View/Helper/WhatsApp.php`.

- [ ] Step 1: Write the failing test:
```php
#[Test] public function whatsapp_normalizes_austrian_numbers(): void {
    $this->assertSame('436641234567', WhatsApp::normalize('0664 123 45 67'));
    $this->assertSame('436641234567', WhatsApp::normalize('+43 664/1234567'));
    $this->assertSame('436641234567', WhatsApp::normalize('0043 664 1234567'));
    $this->assertNull(WhatsApp::normalize('12345678'));
    $this->assertSame('123456789', WhatsApp::normalize('123456789'));
    $this->assertNull(WhatsApp::normalize(''));
    $this->assertNull(WhatsApp::normalize(null));
}
#[Test] public function whatsapp_url_encodes_text(): void {
    $this->assertSame('https://wa.me/436641234567?text=Hallo%20Max%21', WhatsApp::url('0664 1234567', 'Hallo Max!'));
    $this->assertSame('https://wa.me/436641234567', WhatsApp::url('06641234567'));
    $this->assertSame('', WhatsApp::url('abc'));
}
#[Test] public function court_labels(): void { // de: label(1) === 'Platz 1 – Garagenplatz', label('3') === 'Platz 3 – Leitenplatz', label(4) === '', short(2) === 'Starplatz'; en: label(1) === 'Court 1 – Garagenplatz'
}
#[Test] public function first_name(): void { $this->assertSame('Anna', PlayerName::first('  Anna  Beispiel ')); $this->assertSame('', PlayerName::first('')); }
```
- [ ] Step 2: Run `wsl php artisan test --filter=SupportHelpersTest`, expected FAIL.
- [ ] Step 3: Implement the three classes and lang files.
- [ ] Step 4: Run, expected PASS.
- [ ] Step 5: Commit `Add shared WhatsApp, court name and first-name helpers` (CHANGELOG: `Phase 2: shared WhatsApp link and court name helpers for the tournament modules.`).

### Task 13: Compare Laravel JSON with ZF2-captured records (`--compare-dir`) and live fixtures

**Files:**
- Create: `tests/Fixtures/draw/records/.gitkeep`
- Modify: `app/Modules/Draw/Console/DrawVerifyCommand.php`
- Test: `tests/Feature/Modules/Draw/DrawCompareDirTest.php`, `tests/Unit/Modules/Draw/LiveRecordFixturesTest.php`

**Interfaces:** `draw:verify --compare-dir=<dir>`: for each `<dir>/*.json`, slug = basename; FAIL `<slug> compare: missing in database` if no draw; else FAIL `<slug> compare: differs in <comma-separated top-level keys>` unless `json_decode(file, true) === json_decode(json_encode(presenter->json(draw)), true)` (strict, key order ignored per level via recursive ksort only for objects, lists compared in order); PASS line `<slug> compare: identical`. New option `--revealed-slugs`: prints only the slugs of revealed draws (`Draw::isRevealed()`), one per line, ordered by `did`, nothing else (no seeds, no titles); read-only.

- [ ] Step 1: Write failing tests. `DrawCompareDirTest`: `identical_record_passes` (write presenter output of a factory draw to a temp dir, exit 0, `identical`); `changed_value_fails` (fixture `created` altered: exit 1, `differs in created`); `int_vs_string_entry_fails` (fixture payload entry `"101"`: `differs in payload`); `missing_slug_fails`. `LiveRecordFixturesTest`: for each `tests/Fixtures/draw/records/*.json` (skip with `no live records captured yet` when none): record must contain `server_seed`; recompute with `DrawService` (`commit(payload, server_seed) === commitment` after asserting `isCanonical(canonical(payload))`, `finalSeed === final_seed`, `execute()['assignment'] === assignment`); with python3: `tools/verify_draw.py` exit 0; and assert no record contains a key outside the 13 known keys.
- [ ] Step 2: Run `wsl php artisan test --filter='DrawCompareDirTest|LiveRecordFixturesTest'`, expected FAIL (compare) / SKIP (fixtures).
Add to `DrawCompareDirTest`: `revealed_slugs_lists_only_revealed_draws` (factory committed/picking/closed/executed-direct: output lines === [closed slug, direct slug]; no 64-hex string in the output).
- [ ] Step 3: Implement `--compare-dir` and `--revealed-slugs`.
- [ ] Step 4: Run, expected PASS (fixtures test SKIP until the HUMAN step).
- [ ] Step 5: **HUMAN (controller/club admin), after Tasks 1-13 are deployed to staging WITHOUT the Task 18 carve-out** (so `/draw/record/*` on staging is still answered by ZF2 from the real staging copy, which is the reference output) **and BEFORE `phase2:scrub-staging`:**
  1. On staging (ssh): `cd ~/apps/bookingnew-staging && php artisan draw:verify --revealed-slugs`; paste the printed slugs into `$env:TEMP\draw-slugs.txt` on Windows (outside the repo).
  2. In PowerShell from the repo root (curl/wget are blocked here):
     ```powershell
     Get-Content "$env:TEMP\draw-slugs.txt" | Where-Object { $_ -match '^[A-Za-z0-9_-]{4,32}$' } | ForEach-Object {
         Invoke-WebRequest -UseBasicParsing -Uri "https://neu.tcbewegung.at/draw/record/$_/json" -OutFile "tests/Fixtures/draw/records/$_.json"
     }
     ```
  3. Check each file holds only the 13 known keys, uids/tokens in `payload`/`assignment` and no person names in `title` (if one does, do not edit the file, which would break `--compare-dir`: stop and ask the club admin); run `wsl php artisan test --filter=LiveRecordFixturesTest` (expected PASS, not SKIP). No credentials are used: the endpoint is public.
- [ ] Step 6: Commit `Compare Laravel draw records with ZF2-captured JSON; check in live public records` (CHANGELOG: `Phase 2: draw:verify --compare-dir proves the Laravel JSON equals the ZF2 JSON for every captured live draw.`).

### Task 14: Preflight framework and the Draw preflight

**Files:**
- Create: `app/Modules/TournamentBase/Preflight/{ModulePreflight,PreflightResult,SchemaInspector}.php`, `app/Modules/TournamentBase/Console/Phase2PreflightCommand.php`, `app/Modules/Draw/Preflight/DrawPreflight.php`
- Modify: `TournamentBaseServiceProvider` (register command), `DrawServiceProvider` (`$this->app->tag([DrawPreflight::class], 'phase2.preflight')`)
- Test: `tests/Feature/Modules/TournamentBase/Phase2PreflightCommandTest.php`, `tests/Feature/Modules/Draw/DrawPreflightTest.php`

**Interfaces:** As in the interface table. `SchemaInspector::compareColumns(string $table, array $expected /* column => [string $mysqlType, bool $nullable] */): list<PreflightResult>`: missing column FAIL, nullability mismatch FAIL, type mismatch FAIL only when `isMysql()` (types lowercased, int display widths `(\d+)` removed), extra column WARN, otherwise one PASS `columns:<table>`. `collation()` reads `information_schema.TABLES.TABLE_COLLATION` (MySQL) / `null`. `duplicateGroups()` counts groups with `COUNT(*) > 1` over rows where all columns are NOT NULL. `orphanCount()` uses `whereNotExists`. Only SELECTs. Command `phase2:preflight {module : draw|pyramid|mixed|tournament}`: runs the tagged preflight whose `module()` matches (unknown: `Unknown module <m>. Registered: draw`, exit 2); prints `[PASS] check: detail` per result and `Preflight <m>: <n> PASS, <n> WARN, <n> FAIL, <n> SKIP`; exit 1 if any FAIL. `DrawPreflight::COLUMNS` (MySQL types): `bs_draws` = did `int unsigned` NN, tid `int unsigned` N, slug `varchar(32)` NN, title `varchar(160)` NN, status `enum('committed','picking','closed','executed')` NN, mode `enum('direct','token')` NN, payload `mediumtext` NN, commitment `char(64)` NN, beacon_source `varchar(255)` NN, pick_deadline `datetime` N, closed `datetime` N, server_seed `char(64)` N, beacon_value `varchar(255)` N, final_seed `char(64)` N, result `mediumtext` N, created_uid `int unsigned` NN, created `datetime` NN, executed/discarded `datetime` N, discarded_reason `varchar(160)` N, applied_at `datetime` N, applied_uid `int unsigned` N; `bs_draw_tokens` = dtid/did `int unsigned` NN, position `smallint unsigned` NN, token `varchar(16)` NN, slot `varchar(8)` N, claimed_tpid/claimed_uid `int unsigned` N, claimed `datetime` N, assigned_by `enum('pick','fallback')` N; `bs_draw_eligible` = deid/did/tpid `int unsigned` NN. Checks in order: `tables`, `columns:<t>`, `charset:<t>` (FAIL unless collation starts with `utf8mb4`; SKIP off MySQL), `unique:<t>(<cols>)` for slug / (did,token) / (did,position) / (did,claimed_tpid) / (did,tpid) (FAIL if index missing or duplicates > 0), `orphans:` tokens.did and eligible.did -> bs_draws.did, bs_draws.tid -> bs_tournaments.tid, eligible.tpid and tokens.claimed_tpid -> bs_tournament_players.tpid (WARN with count; SKIP when the parent table is absent), `verify:<slug>` per draw via `DrawVerifier` (FAIL with failures; draws not executed only need `canonical`), final `verify: <n> draws checked`.

- [ ] Step 1: Write failing tests. `DrawPreflightTest`: `healthy_database_passes` (factory draws of every state; no FAIL; contains `[SKIP] charset:bs_draws` on SQLite); `missing_column_fails` (rebuild `bs_draw_eligible` without `tpid`: `FAIL columns:bs_draw_eligible`); `extra_column_warns`; `duplicate_slug_rows_fail` (drop the unique index on SQLite, insert two rows with the same slug: FAIL `unique:bs_draws(slug)`); `orphan_tokens_warn`; `tampered_draw_fails_verify`; `preflight_is_read_only` (`DB::listen` collects SQL; none matches `/^\s*(insert|update|delete|create|drop|alter|truncate|replace)\b/i`). `Phase2PreflightCommandTest`: `unknown_module_exits_2`; `draw_module_exit_codes` (0 healthy, 1 with a FAIL); `summary_line_is_printed`.
- [ ] Step 2: Run `wsl php artisan test --filter='Phase2PreflightCommandTest|DrawPreflightTest'`, expected FAIL.
- [ ] Step 3: Implement.
- [ ] Step 4: Run, expected PASS.
- [ ] Step 5: Commit `Add phase2:preflight with the Draw checks` (CHANGELOG: `Phase 2: read-only php artisan phase2:preflight draw checks columns, ENUMs, charset, unique keys, orphans and recomputes every draw.`).

### Task 15: MySQL test group and adoption on a live-shaped schema

**Files:**
- Create: `phpunit.mysql.xml`, `tests/MysqlTestCase.php`, `tests/Fixtures/draw/live-schema.sql`, `tests/Feature/Modules/Draw/DrawMysqlAdoptionTest.php`
- Modify: `phpunit.xml` (add `<groups><exclude><group>mysql</group></exclude></groups>`), `composer.json` (script `test:mysql`: `Composer\\Config::disableProcessTimeout`, `@php artisan config:clear --ansi`, `@php vendor/bin/phpunit -c phpunit.mysql.xml`), `README.md` (one line under testing: `composer test:mysql` needs a local MySQL database `booking_test`)

**Interfaces:** `phpunit.mysql.xml` = `phpunit.xml` with `DB_CONNECTION=mysql`, `DB_DATABASE=booking_test`, `DB_HOST=127.0.0.1`, `DB_USERNAME`/`DB_PASSWORD` taken from the environment (no value in the file), and `<groups><include><group>mysql</group></include></groups>`. `abstract class Tests\MysqlTestCase extends TestCase` skips in `setUp()` unless `DB::connection()->getDriverName() === 'mysql'` (message `needs MySQL: composer test:mysql`). `live-schema.sql` = DDL only: `CREATE TABLE bs_draws` from `/mnt/c/development/booking/sql/tournament_schema.sql` (lines 84-103), then the `bs_draws` ALTER and the two CREATEs from `tournament_schema_03_tokens.sql`, the `bs_draws` ALTER from `_07_lots.sql` and `_09_test_mode.sql`, each statement copied verbatim; no INSERTs, no other tables.

- [ ] Step 1: Write the failing test `DrawMysqlAdoptionTest` (`#[Group('mysql')]`, extends `MysqlTestCase`, `RefreshDatabase`):
```php
#[Test] public function guarded_migrations_adopt_the_live_shape(): void
// drop the 3 tables, run live-schema.sql (split on ';' outside comments), insert one draw row with a 4-byte rule via DB::table,
// run the 3 migrations' up(): no exception, row still there, Schema::getIndexes still shows the live names 'slug', 'did_token'
#[Test] public function preflight_passes_on_the_live_shape(): void // phase2:preflight draw exit 0; output contains '[PASS] charset:bs_draws'
#[Test] public function preflight_spec_matches_fresh_migrations(): void // fresh migrate (no live-schema): phase2:preflight draw has no FAIL and no WARN for columns
#[Test] public function utf8mb3_table_fails_charset(): void // ALTER TABLE bs_draw_eligible CONVERT TO CHARACTER SET utf8mb3: FAIL charset:bs_draw_eligible
#[Test] public function enum_drift_fails(): void // ALTER bs_draws status ENUM without 'closed': FAIL columns:bs_draws
```
- [ ] Step 2: Run `wsl composer test:mysql` (MySQL database `booking_test` must exist), expected FAIL; `wsl php artisan test` expected unaffected (group excluded).
- [ ] Step 3: Implement the files above.
- [ ] Step 4: Run `wsl composer test:mysql` and `wsl php artisan test`, expected PASS.
- [ ] Step 5: Commit `Add a MySQL test group and check Draw adoption against the live table shape` (`--no-verify` if only tests/config changed; otherwise CHANGELOG: `Tests: composer test:mysql runs MySQL-only adoption and preflight checks.`).

### Task 16: Test mode session

**Files:** Create `app/Modules/TournamentBase/Support/TestModeSession.php`; Test `tests/Feature/Modules/TournamentBase/TestModeSessionTest.php`

**Interfaces:** As in the interface table. Constructor `__construct(Illuminate\Contracts\Session\Session $session)`; stored under `tournament.test_mode` as `['mode' => ?string, 'tpid' => ?int, 'tid' => ?int]`. Non-admin (`! $user->can('admin.tournament')`) on `simulate`/`selectTid` -> `Illuminate\Auth\Access\AuthorizationException`; invalid request (mode not `member`/`player`, or `player` without `tpid > 0`) -> `InvalidArgumentException('invalid simulation request')`; `simulation()`/`selectedTid()` return `null` for non-admins; `selectTid()` with a different tid clears mode/tpid. The level lowering itself belongs to Plan D's `TournamentAccess`.

- [ ] Step 1: Write the failing test (access.php spec): `non_admin_cannot_simulate`, `member_simulation_is_stored`, `player_simulation_needs_a_tpid`, `stop_clears_the_simulation`, `admin_may_select_a_tournament`, `switching_tournament_stops_simulation`, `same_tournament_keeps_simulation`, `non_admin_sees_no_selection` (users from `User::factory()->create(['status' => 'admin'|'enabled'])`, plus an `assist` user with `allow.admin.tournament` = `'true'` treated as admin).
- [ ] Step 2: Run `wsl php artisan test --filter=TestModeSessionTest`, expected FAIL.
- [ ] Step 3: Implement.
- [ ] Step 4: Run, expected PASS.
- [ ] Step 5: Commit `Add the tournament test-mode session state to TournamentBase` (CHANGELOG: `Phase 2: admin "view as player" test mode state ported (used by the Tournament module).`).

### Task 17: Draw demo seeder and `phase2:scrub-staging`

**Files:**
- Create: `app/Modules/Draw/Database/Seeders/DrawDemoSeeder.php`, `app/Modules/TournamentBase/Staging/ModuleScrub.php`, `app/Modules/Draw/Staging/DrawScrub.php`, `app/Modules/TournamentBase/Console/Phase2ScrubStagingCommand.php`
- Modify: providers (register command; `$this->app->tag([DrawScrub::class], 'phase2.scrub')`)
- Test: `tests/Feature/Modules/Draw/DrawDemoSeederTest.php`, `tests/Feature/Modules/TournamentBase/Phase2ScrubStagingCommandTest.php`

**Interfaces:** `DrawScrub`: `module()` `draw`, `tables()` `['bs_draw_eligible', 'bs_draw_tokens', 'bs_draws']`, `seeder()` `DrawDemoSeeder::class`. `DrawDemoSeeder::run()`: `User::updateOrCreate(['email' => 'draw-demo@example.test'], ['alias' => 'Demo Auslosung', 'status' => 'enabled', 'created' => now()])` as `created_uid`; via `DrawFactory`, fixed slugs and seeds (`server_seed = hash('sha256', 'demo-server-'.$slug)`, beacon `hash('sha256', 'demo-beacon-'.$slug)`, `beacon_source` `drand:round:<1000+n>`): `demo-committed` (direct, committed), `demo-direct` (direct, executed), `demo-discarded` (direct, executed, discarded `Demo: verworfen`), `demo-picking` (token AC x6, executed -> picking, 2 tokens claimed `pick`, eligible tpids 9001-9006), `demo-closed` (token DG x6, closed, 4 `pick` + 2 `fallback`); never reads other tables. Command `phase2:scrub-staging {--force}` (APP_ENV is NOT checked: staging runs with `APP_ENV=production`): refuses (exit 1, nothing touched), in this order, with `Refusing: database '<name>' does not end in '_staging'.` unless `str_ends_with($db = DB::connection()->getDatabaseName(), '_staging')`; `Refusing: database '<name>' is the live database.` if `$db === config('tournament-base.live_database')`; `Refusing: pass --force.` without `--force`; then prints `Database: <db>` and `Tables: <comma-separated list>`; if `$this->input->isInteractive()` asks `Truncate these tables and reseed?` (default no -> `Aborted.`, exit 1), with `--no-interaction` proceeds; then per tagged module: `DB::table($t)->truncate()` for each table, `$this->call('db:seed', ['--class' => $seeder, '--force' => true])`, prints `Scrubbed <module>: <tables>`.

- [ ] Step 1: Write failing tests. `DrawDemoSeederTest`: `seeds_draws_in_every_state` (5 slugs, statuses committed/executed/executed/picking/closed, `isRevealed()` false/true/true/false/true); `every_seeded_draw_verifies` (`DrawVerifier` ok); `seeding_twice_after_truncate_is_stable` (same commitments); `only_example_test_users_are_created` (every new `bs_users.email` ends with `@example.test`). `Phase2ScrubStagingCommandTest` (a factory draw with slug `real-draw-1` exists in every test; "untouched" = it still exists): `refuses_without_staging_database_name` (db name `:memory:`, `--force`); `refuses_the_live_database` (`config(['tournament-base.live_database' => 'x_staging'])`, `DB::connection()->setDatabaseName('x_staging')`, `--force`); `refuses_without_force` (`setDatabaseName('booking_staging')`); `aborts_when_confirmation_is_declined` (`booking_staging`, `--force`, `expectsOutput('Database: booking_staging')`, `expectsConfirmation('Truncate these tables and reseed?', 'no')`, exit 1, untouched); `scrubs_and_reseeds_without_interaction` (`booking_staging`, `--force --no-interaction`, env stays `production`-agnostic: `$this->app['env'] = 'production'`; exit 0, `real-draw-1` gone, `demo-closed` present).
- [ ] Step 2: Run `wsl php artisan test --filter='DrawDemoSeederTest|Phase2ScrubStagingCommandTest'`, expected FAIL.
- [ ] Step 3: Implement.
- [ ] Step 4: Run, expected PASS.
- [ ] Step 5: Commit `Add the Draw demo seeder and phase2:scrub-staging` (CHANGELOG: `Phase 2: php artisan phase2:scrub-staging (staging only) replaces module data with fictional demo draws.`).

### Task 18: Go live: carve `/draw/record/*` out of the bridge

**Files:**
- Create: `tests/Support/HtaccessRouter.php`, `tests/Feature/BridgedPrefixRoutesTest.php`, `docs/deploy/2026-phase2-runbook.md`
- Modify: `public/.htaccess`, `tests/Unit/PublicHtaccessTest.php`, `docs/deploy/2026-helloly-cutover-runbook.md` (routing test table rows), `CHANGELOG.md`
- Test: `tests/Unit/PublicHtaccessTest.php`, `tests/Feature/BridgedPrefixRoutesTest.php`; `tests/Unit/Zf2BridgeTest.php` unchanged and green

**Interfaces:** `.htaccess`: directly after the trailing-slash `[L,R=301]` rule and before the first `zf2-bridge.php` rule:
```
    # Phase 2: ported module paths go to Laravel although their prefix is still bridged.
    # Rollback for one module = delete its line.
    RewriteRule ^draw/record(/|$) index.php [L]
```
(`[L]` restarts rewriting with `index.php`, which no later rule touches; Laravel answers 404 for slugs outside `[A-Za-z0-9_-]{4,32}`). `HtaccessRouter::route()` walks the file's `RewriteRule` lines in order up to `RewriteRule ^ index.php [L]`: a `%1 [L,R=301]` rule -> `redirect`, an `index.php [L]` rule -> `laravel`, a `zf2-bridge.php [L]` rule -> `zf2`; first match wins, default `laravel` (paths without leading slash).

- [ ] Step 1: Write failing tests. `PublicHtaccessTest` add:
```php
public function test_draw_record_is_served_by_laravel_and_the_rest_of_draw_stays_bridged(): void {
    $r = HtaccessRouter::fromFile(dirname(__DIR__, 2).'/public/.htaccess');
    foreach (['draw/record', 'draw/record/x', 'draw/record/x/json', 'draw/record/0123456789ab', 'draw/record/0123456789ab/json'] as $p) $this->assertSame('laravel', $r->route($p), $p);
    foreach (['draw', 'draw/1', 'draw/tokens/1', 'draw/tokens/1/pick', 'draw/commit', 'draw/open/3', 'draw/close/3', 'draw/execute/3', 'draw/delete/3', 'draw/recordx', 'draw/records/x'] as $p) $this->assertSame('zf2', $r->route($p), $p);
    foreach (['draw/record/x/', 'draw/record/x/json/'] as $p) $this->assertSame('redirect', $r->route($p), $p);
    foreach (['calendar', 'members/dashboard', 'drawing'] as $p) $this->assertSame('laravel', $r->route($p), $p);
}
public function test_carve_outs_sit_between_the_slash_redirect_and_the_bridge(): void // strpos order: '%1 [L,R=301]' < 'RewriteRule ^draw/record(/|$) index.php [L]' < first 'zf2-bridge.php [L]'
```
(existing allowlist tests stay unchanged). `BridgedPrefixRoutesTest` (`Tests\TestCase`): `every_laravel_route_under_a_bridged_prefix_is_carved_out` — for each `Route::getRoutes()` uri matching `#^(tournament|mixed-tournament|pyramide|draw|service)(/|$)#`, replace `{...}` with `abcd1234` and assert `HtaccessRouter` says `laravel`; assert at least `draw/record/{slug}` and `draw/record/{slug}/json` were checked.
- [ ] Step 2: Run `wsl php artisan test --filter='PublicHtaccessTest|BridgedPrefixRoutesTest|Zf2BridgeTest'`, expected FAIL.
- [ ] Step 3: Add the `.htaccess` rule and `HtaccessRouter`. Routing table rows in the cutover runbook: `/draw/record/<slug>`, `/draw/record/<slug>/json` -> Laravel 200 (or 404 JSON/page for unknown slugs), no login, no-cache; `/draw`, `/draw/tokens/1` -> unchanged ZF2. Write `docs/deploy/2026-phase2-runbook.md` "Module 1: Draw record": staging (deploy, `migrate --force`, `phase2:preflight draw`, `draw:verify --all --compare-dir=tests/Fixtures/draw/records`, routing rows, rehearsal on the real copy (fixtures captured per Task 13 Step 5 before any scrub), then `php artisan phase2:scrub-staging --force` (confirm interactively) and smoke test of the 5 demo slugs); live (dump `bs_draws bs_draw_tokens bs_draw_eligible`, deploy, `migrate --force`, the same preflight/verify/compare commands, routing rows, `python3 tools/verify_draw.py` on one fetched record; any FAIL -> delete the carve-out line in the server's `public/.htaccess` and report); rollback (delete the line, redeploy); event-free date confirmed with the club admin.
- [ ] Step 4: Run the Step 2 command, then `wsl composer lint` and `wsl php artisan test`, expected PASS.
- [ ] Step 5: Commit `Serve /draw/record from Laravel ahead of the ZF2 bridge` (CHANGELOG: `Phase 2 go-live (module 1): the public draw record pages /draw/record/* are served by Laravel; the rest of /draw stays on ZF2.`).

## Rollout (actual go-live order for the record route)

The deploy ships `.htaccess` and code together, so the carve-out goes live with the deploy (one deploy, not two): dump the `bs_draw*` tables, deploy, `migrate --force`, then immediately `phase2:preflight draw` and `draw:verify --all --compare-dir=tests/Fixtures/draw/records`. Rollback on any FAIL = delete the `RewriteRule ^draw/record(/|$) index.php [L]` line from `public/.htaccess` (on the server, or in the repo and redeploy); no data restore. The staging rehearsal on the real copy happens first.

---

## Self-review

**Spec coverage (design items for modules 0-1 -> task):**
- TournamentBase module layout, provider, lang ns `tournament-base::` -> 1; Randomizer binding + seeded tests -> 1; ScoreService (verbatim texts, umlauts restored, run.php Score part) -> 2 (vectors), 11; WhatsApp helper (decision 6) -> 12; court names -> 12; test-mode session `tournament.test_mode` -> 16; `phase2:preflight` -> 14, 15; `phase2:scrub-staging` (`_staging` + not-live + `--force` + confirmation guard, truncate + seeders) -> 17.
- Draw: framework-free `DrawService` line by line -> 3; `TokenService` -> 5; invariant `canonical(json_decode(stored, true)) === stored` -> 4 (used in 7, 14); golden vectors (a) run.php -> 3, 5, 11, (b) generated matrix with old commit hash -> 2, (c) live public records -> 13; Python verifier copied and run when `python3` exists -> 2, 9, 13; models on existing tables, no timestamps, `@property` -> 6; guarded adoption migrations, manifest -> 6; `draw:verify {slug?} --all` -> 7 (+ `--compare-dir` 13); JSON keys, reveal condition, no-cache, public -> 9; record page (DESIGN.md, de/en, base path) -> 10; demo seeder with known seeds/stubbed beacon, `@example.test` -> 17; design decision 12 grep -> 9 (recorded in the commit body); live Draw code equals the repo (decision 7), so no pre-port diff is needed for Draw.
- Character set / 4-byte rule (decision 13): global middleware from the cutover branch, Draw route covered -> 8 (prerequisite), 9; opt-out of module routes -> decommission plan; preflight charset FAIL guard -> 14, 15.
- Routing/go-live: carve-out ahead of the bridge, matrix covers `/draw`, `/draw/tokens/1`, `/draw/record/x/json` -> 18; per-module rollback by one line -> 18 (runbook).
- Testing strategy: `#[Group('mysql')]` suite, migrations on both drivers -> 15; `ui:lint` for module views -> 10.
- Deferred on purpose (no consumer in Plan A): shared tab bar/flash/test banner partials, login-redirect middleware with module flash, translated 419 flash, `TournamentAccess` levels -> Plan B (first UI module) / Plan D; `BeaconService` and Draw admin/token/close/execute -> Plan D; Tournament lot tie-break vectors -> Plan D (generator from Task 2 is extended there).

**Design gaps noticed:**
1. Resolved (controller): the 4-byte stripping is the global `App\Http\Middleware\StripFourByteCharacters` from the cutover branch (all routes, opt-out array, only Members exempt); Plan A depends on it (Task 8 prerequisite) and the decommission plan adds module routes to the opt-out array.
2. Superseded (controller): the design sentence "refuses unless `APP_ENV=staging`" is replaced by: DB name ends in `_staging`, is not `tournament-base.live_database`, `--force` given, DB name and tables printed and confirmed unless `--no-interaction` (staging runs with `APP_ENV=production`).
3. `lint:ui` only scans `resources/views`, so "`ui:lint` must pass" does not cover module views; Task 10 adds a UiLinter test over module views (a later change could extend the command).
4. Resolved: live record fixtures are captured from staging's real copy (ZF2 still answering `/draw/record/*`) with `draw:verify --revealed-slugs` + PowerShell `Invoke-WebRequest`, before the scrub (Task 13 Step 5).
5. Latent PHP/Python divergences (U+2028/U+2029, numeric slot names): see Review Focus 6 for Plan D.
6. Preflight must be read-only, but `DatabaseSchemaChecker::driftReport()` creates probe tables, so the Draw schema lives in three places (migration, manifest, preflight spec), kept consistent by Task 6 and Task 15 tests.
7. Go-live order: see "Rollout" (single deploy, checks right after, one-line rollback).
11. New: the live DB name guard needs `tournament-base.live_database` (default `helloly41567002_booking` from the cutover runbook); if staging's `.env` ever sets `PHASE2_LIVE_DB_DATABASE` wrongly the guard weakens to the `_staging` suffix check alone.
12. New: the fixture capture relies on staging's real copy matching live draws; draws executed on live after the staging copy was taken are only covered by `draw:verify --all` on live, not by `--compare-dir`.
8. `draw:verify --all` also recomputes executed-but-unrevealed token draws (status `picking`) because their seeds are in the DB; it prints no secrets.
9. AGENTS.md still says PHP 8.3; vectors are generated with WSL PHP 8.4.22 (as the design asks); `composer.json` stays `^8.3`.
10. MySQL test credentials/database are not specified anywhere; Plan A picks `booking_test` with credentials from the environment.
