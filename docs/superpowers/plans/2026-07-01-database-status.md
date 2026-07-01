# Database Status & Validation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give admins a page that shows which database migrations are pending, flags tables/columns that don't match the app's expected schema, and lets an admin run pending migrations from a button — the only execution path available on the one.com production host (no SSH/cron).

**Architecture:** A new `App\Services\DatabaseSchemaChecker` reads Laravel's own migration repository (via the `migrator` service) to compute migration status, and diffs `Schema::getColumnListing()` against a small hardcoded manifest of expected columns per `bs_*` table (mirrored from the migration files). A new `Admin\DatabaseController` renders this on one page and exposes a POST action that calls `Artisan::call('migrate', ['--force' => true])` in-process.

**Tech Stack:** Laravel 13, PHPUnit (sqlite testing connection), Blade, existing `can:admin.config` authorization.

Spec: `docs/superpowers/specs/2026-07-01-database-status-design.md`

---

### Task 1: `DatabaseSchemaChecker` service — migration status

**Files:**
- Create: `app/Services/DatabaseSchemaChecker.php`
- Test: `tests/Unit/Services/DatabaseSchemaCheckerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/DatabaseSchemaCheckerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\DatabaseSchemaChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DatabaseSchemaCheckerTest extends TestCase
{
    use RefreshDatabase;

    private DatabaseSchemaChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker = new DatabaseSchemaChecker();
    }

    #[Test]
    public function migration_status_lists_all_migration_files(): void
    {
        $status = $this->checker->migrationStatus();

        $names = array_column($status, 'name');
        $this->assertContains('2026_06_26_073602_create_bs_users_table', $names);
        $this->assertContains('2026_06_28_155630_create_jobs_table', $names);
    }

    #[Test]
    public function migration_status_marks_already_run_migrations_as_ran(): void
    {
        // RefreshDatabase has already migrated everything in the test database.
        $status = $this->checker->migrationStatus();

        $usersMigration = collect($status)->firstWhere('name', '2026_06_26_073602_create_bs_users_table');

        $this->assertNotNull($usersMigration);
        $this->assertTrue($usersMigration['ran']);
    }

    #[Test]
    public function migration_status_marks_a_removed_migration_record_as_pending(): void
    {
        DB::table('migrations')->where('migration', '2026_06_28_155630_create_jobs_table')->delete();

        $status = $this->checker->migrationStatus();
        $jobsMigration = collect($status)->firstWhere('name', '2026_06_28_155630_create_jobs_table');

        $this->assertNotNull($jobsMigration);
        $this->assertFalse($jobsMigration['ran']);
        $this->assertTrue($this->checker->hasPendingMigrations());
    }

    #[Test]
    public function has_pending_migrations_is_false_when_everything_ran(): void
    {
        $this->assertFalse($this->checker->hasPendingMigrations());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run (via WSL, per this project's convention — never run `php`/`composer` directly in PowerShell):

```bash
wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=DatabaseSchemaCheckerTest"
```

Expected: FAIL — `Class "App\Services\DatabaseSchemaChecker" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/Services/DatabaseSchemaChecker.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Schema;

/**
 * Reports database migration status and validates that the bs_* tables
 * this app relies on actually have the columns the code expects. Exists
 * because the production host (one.com shared hosting) has no SSH/cron
 * access — this is consumed by an admin page instead of `artisan migrate:status`.
 */
final class DatabaseSchemaChecker
{
    /**
     * Expected columns per table, mirrored from database/migrations/*.
     * Kept here as a flat manifest (not derived from the migrations at
     * runtime) so this works without a second DB connection or extra PHP
     * extensions on shared hosting.
     *
     * @var array<string, array<int, string>>
     */
    private const EXPECTED_COLUMNS = [
        'bs_users' => ['uid', 'alias', 'status', 'email', 'pw', 'login_attempts', 'login_detent', 'last_activity', 'last_ip', 'created'],
        'bs_users_meta' => ['umid', 'uid', 'key', 'value'],
        'bs_squares' => ['sid', 'name', 'status', 'priority', 'capacity', 'capacity_heterogenic', 'allow_notes', 'time_start', 'time_end', 'time_block', 'time_block_bookable', 'time_block_bookable_max', 'min_range_book', 'range_book', 'max_active_bookings', 'range_cancel'],
        'bs_squares_meta' => ['smid', 'sid', 'key', 'value', 'locale'],
        'bs_squares_products' => ['spid', 'sid', 'priority', 'date_start', 'date_end', 'name', 'description', 'options', 'price', 'rate', 'gross', 'locale'],
        'bs_squares_pricing' => ['spid', 'sid', 'priority', 'date_start', 'date_end', 'day_start', 'day_end', 'time_start', 'time_end', 'price', 'rate', 'gross', 'per_time_block', 'per_quantity'],
        'bs_squares_coupons' => ['scid', 'sid', 'code', 'date_start', 'date_end', 'discount_for_booking', 'discount_for_products', 'discount_in_percent'],
        'bs_bookings' => ['bid', 'uid', 'sid', 'status', 'status_billing', 'visibility', 'quantity', 'created'],
        'bs_bookings_bills' => ['bbid', 'bid', 'description', 'quantity', 'time', 'price', 'rate', 'gross'],
        'bs_bookings_meta' => ['bmid', 'bid', 'key', 'value'],
        'bs_reservations' => ['rid', 'bid', 'date', 'time_start', 'time_end'],
        'bs_events' => ['eid', 'sid', 'status', 'datetime_start', 'datetime_end', 'capacity'],
        'bs_events_meta' => ['emid', 'eid', 'key', 'value', 'locale'],
        'bs_options' => ['oid', 'key', 'value', 'locale'],
        'bs_reservations_meta' => ['rmid', 'rid', 'key', 'value'],
    ];

    /**
     * @return array<int, array{name: string, ran: bool}>
     */
    public function migrationStatus(): array
    {
        $migrator = app('migrator');

        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
        $files = $migrator->getMigrationFiles([database_path('migrations')]);

        return array_map(
            static fn (string $name): array => ['name' => $name, 'ran' => in_array($name, $ran, true)],
            array_keys($files),
        );
    }

    public function hasPendingMigrations(): bool
    {
        foreach ($this->migrationStatus() as $migration) {
            if (! $migration['ran']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{table: string, exists: bool, missing_columns: array<int, string>}>
     */
    public function checkTables(): array
    {
        $results = [];

        foreach (self::EXPECTED_COLUMNS as $table => $expectedColumns) {
            $exists = Schema::hasTable($table);
            $missing = $exists
                ? array_values(array_diff($expectedColumns, Schema::getColumnListing($table)))
                : $expectedColumns;

            $results[] = [
                'table' => $table,
                'exists' => $exists,
                'missing_columns' => $missing,
            ];
        }

        return $results;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=DatabaseSchemaCheckerTest"
```

Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/DatabaseSchemaChecker.php tests/Unit/Services/DatabaseSchemaCheckerTest.php
git commit -m "Add DatabaseSchemaChecker for migration status and column validation"
```

---

### Task 2: `DatabaseSchemaChecker` — column validation tests

**Files:**
- Test: `tests/Unit/Services/DatabaseSchemaCheckerTest.php` (append)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Services/DatabaseSchemaCheckerTest.php` (inside the class, needs `use Illuminate\Support\Facades\Schema;` and `use Illuminate\Database\Schema\Blueprint;` added to the imports):

```php
    #[Test]
    public function check_tables_reports_no_missing_columns_after_a_normal_migration(): void
    {
        foreach ($this->checker->checkTables() as $table) {
            $this->assertTrue($table['exists'], "{$table['table']} should exist");
            $this->assertSame([], $table['missing_columns'], "{$table['table']} should have no missing columns");
        }
    }

    #[Test]
    public function check_tables_flags_a_column_that_was_dropped(): void
    {
        Schema::table('bs_users', function (Blueprint $table): void {
            $table->dropColumn('last_ip');
        });

        $result = collect($this->checker->checkTables())->firstWhere('table', 'bs_users');

        $this->assertTrue($result['exists']);
        $this->assertSame(['last_ip'], $result['missing_columns']);
    }

    #[Test]
    public function check_tables_flags_a_table_that_does_not_exist(): void
    {
        Schema::drop('bs_events_meta');

        $result = collect($this->checker->checkTables())->firstWhere('table', 'bs_events_meta');

        $this->assertFalse($result['exists']);
        $this->assertSame(['emid', 'eid', 'key', 'value', 'locale'], $result['missing_columns']);
    }
```

Add the two missing imports at the top of the file:

```php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
```

- [ ] **Step 2: Run the tests to verify they fail or pass appropriately**

```bash
wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=DatabaseSchemaCheckerTest"
```

Expected: `check_tables_reports_no_missing_columns_after_a_normal_migration` PASSES immediately (the implementation from Task 1 already handles this — this test is a regression guard for the manifest matching the real migrations). The other two also pass immediately since `checkTables()` was already implemented in Task 1. This task exists to lock in behavior with explicit column-mutation tests; if any of them fail, fix `DatabaseSchemaChecker::EXPECTED_COLUMNS` to match the actual migration files in `database/migrations/`.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Services/DatabaseSchemaCheckerTest.php
git commit -m "Add column-validation regression tests for DatabaseSchemaChecker"
```

---

### Task 3: Route + `DatabaseController` + view

**Files:**
- Create: `app/Http/Controllers/Admin/DatabaseController.php`
- Create: `resources/views/admin/database/index.blade.php`
- Modify: `routes/web.php`
- Modify: `lang/en/booking/admin.php`, `lang/de/booking/admin.php`
- Modify: `resources/views/components/layout/admin-sidebar.blade.php`
- Test: `tests/Feature/Admin/DatabaseControllerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Admin/DatabaseControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DatabaseControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['status' => 'admin']);
    }

    #[Test]
    public function admin_can_view_database_status_page(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/database')
            ->assertOk()
            ->assertViewIs('admin.database.index')
            ->assertSee('2026_06_26_073602_create_bs_users_table');
    }

    #[Test]
    public function regular_member_cannot_access_database_status_page(): void
    {
        $this->actingAs(User::factory()->create(['status' => 'enabled']))
            ->get('/admin/database')
            ->assertForbidden();
    }

    #[Test]
    public function admin_can_run_pending_migrations(): void
    {
        DB::table('migrations')->where('migration', '2026_06_28_155630_create_jobs_table')->delete();

        $this->actingAs($this->admin())
            ->post('/admin/database/migrate')
            ->assertRedirect('/admin/database')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('migrations', ['migration' => '2026_06_28_155630_create_jobs_table']);
    }

    #[Test]
    public function regular_member_cannot_run_migrations(): void
    {
        $this->actingAs(User::factory()->create(['status' => 'enabled']))
            ->post('/admin/database/migrate')
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=DatabaseControllerTest"
```

Expected: FAIL — route `admin.database.index` / `/admin/database` not found (404).

- [ ] **Step 3: Add the routes**

In `routes/web.php`, add the import near the other `Admin\*` controller imports:

```php
use App\Http\Controllers\Admin\DatabaseController;
```

Inside the existing `Route::middleware('can:admin.config')->group(...)` block (the one containing `config`, `squares`, `testmail`), add:

```php
        Route::get('database', [DatabaseController::class, 'index'])->name('database.index');
        Route::post('database/migrate', [DatabaseController::class, 'migrate'])->name('database.migrate');
```

- [ ] **Step 4: Create the controller**

Create `app/Http/Controllers/Admin/DatabaseController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DatabaseSchemaChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

final class DatabaseController extends Controller
{
    public function __construct(
        private readonly DatabaseSchemaChecker $checker,
    ) {}

    public function index(): View
    {
        return view('admin.database.index', [
            'migrations' => $this->checker->migrationStatus(),
            'tables' => $this->checker->checkTables(),
            'hasPending' => $this->checker->hasPendingMigrations(),
        ]);
    }

    public function migrate(): RedirectResponse
    {
        Artisan::call('migrate', ['--force' => true]);

        return redirect()->route('admin.database.index')
            ->with('success', __('booking.admin.database.migrate_ran'));
    }
}
```

- [ ] **Step 5: Add the language keys**

In `lang/en/booking/admin.php`, insert after the `testmail` block (after its closing `],` on line 24, before `'behavior' => 'Behavior',`):

```php
        'database' => [
            'title' => 'Database',
            'migrations_heading' => 'Migrations',
            'migration_name' => 'Migration',
            'migration_status' => 'Status',
            'status_ran' => 'Ran',
            'status_pending' => 'Pending',
            'no_pending' => 'No pending migrations.',
            'migrate_button' => 'Run pending migrations',
            'migrate_confirm' => 'This will run pending database migrations against the live database. Continue?',
            'migrate_ran' => 'Migrations executed.',
            'tables_heading' => 'Tables',
            'table_name' => 'Table',
            'table_exists' => 'Exists',
            'table_missing_columns' => 'Missing Columns',
            'exists_yes' => 'Yes',
            'exists_no' => 'No',
        ],
```

In `lang/de/booking/admin.php`, insert at the equivalent spot (after the `testmail` block's closing `],` on line 24, before `'behavior' => 'Verhalten',`):

```php
        'database' => [
            'title' => 'Datenbank',
            'migrations_heading' => 'Migrationen',
            'migration_name' => 'Migration',
            'migration_status' => 'Status',
            'status_ran' => 'Ausgeführt',
            'status_pending' => 'Ausstehend',
            'no_pending' => 'Keine ausstehenden Migrationen.',
            'migrate_button' => 'Ausstehende Migrationen ausführen',
            'migrate_confirm' => 'Dies führt ausstehende Datenbank-Migrationen gegen die Live-Datenbank aus. Fortfahren?',
            'migrate_ran' => 'Migrationen ausgeführt.',
            'tables_heading' => 'Tabellen',
            'table_name' => 'Tabelle',
            'table_exists' => 'Vorhanden',
            'table_missing_columns' => 'Fehlende Spalten',
            'exists_yes' => 'Ja',
            'exists_no' => 'Nein',
        ],
```

- [ ] **Step 6: Create the view**

Create `resources/views/admin/database/index.blade.php`:

```blade
@extends('layouts.admin')
@section('admin-title', __('booking.admin.database.title'))
@section('admin-content')
<div class="flex flex-col gap-6">

    <h1 class="text-2xl font-bold text-[#151515]" style="font-family: var(--font-display)">{{ __('booking.admin.database.title') }}</h1>

    <div class="bg-white rounded-xl border border-[#e0ddd7] shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-[#f0ede6] flex items-center justify-between">
            <h2 class="text-base font-semibold text-[#151515]" style="font-family: var(--font-display)">{{ __('booking.admin.database.migrations_heading') }}</h2>
            @if($hasPending)
                <form method="POST" action="{{ route('admin.database.migrate') }}" onsubmit="return confirm({{ Js::from(__('booking.admin.database.migrate_confirm')) }})">
                    @csrf
                    <button type="submit" class="bg-[#bf4316] hover:bg-[#9e3412] text-white text-sm font-medium px-4 py-2 rounded transition-colors">{{ __('booking.admin.database.migrate_button') }}</button>
                </form>
            @endif
        </div>
        <div class="px-6 py-5">
            @if(empty($migrations))
                <p class="text-sm text-[#6a6e73]">{{ __('booking.admin.database.no_pending') }}</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-[#6a6e73] border-b border-[#f0ede6]">
                            <th class="py-2 pr-4">{{ __('booking.admin.database.migration_name') }}</th>
                            <th class="py-2">{{ __('booking.admin.database.migration_status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($migrations as $migration)
                            <tr class="border-b border-[#f5f3ef]">
                                <td class="py-2 pr-4 font-mono text-xs">{{ $migration['name'] }}</td>
                                <td class="py-2">
                                    @if($migration['ran'])
                                        <span class="text-[#3e8635]">✓ {{ __('booking.admin.database.status_ran') }}</span>
                                    @else
                                        <span class="text-[#f0ab00]">⏳ {{ __('booking.admin.database.status_pending') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <div class="bg-white rounded-xl border border-[#e0ddd7] shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-[#f0ede6]">
            <h2 class="text-base font-semibold text-[#151515]" style="font-family: var(--font-display)">{{ __('booking.admin.database.tables_heading') }}</h2>
        </div>
        <div class="px-6 py-5">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-[#6a6e73] border-b border-[#f0ede6]">
                        <th class="py-2 pr-4">{{ __('booking.admin.database.table_name') }}</th>
                        <th class="py-2 pr-4">{{ __('booking.admin.database.table_exists') }}</th>
                        <th class="py-2">{{ __('booking.admin.database.table_missing_columns') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($tables as $table)
                        <tr class="border-b border-[#f5f3ef]">
                            <td class="py-2 pr-4 font-mono text-xs">{{ $table['table'] }}</td>
                            <td class="py-2 pr-4">
                                @if($table['exists'])
                                    <span class="text-[#3e8635]">✓ {{ __('booking.admin.database.exists_yes') }}</span>
                                @else
                                    <span class="text-[#c9190b]">✕ {{ __('booking.admin.database.exists_no') }}</span>
                                @endif
                            </td>
                            <td class="py-2 text-xs text-[#6a6e73]">
                                {{ $table['missing_columns'] !== [] ? implode(', ', $table['missing_columns']) : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
```

- [ ] **Step 7: Add the sidebar link**

In `resources/views/components/layout/admin-sidebar.blade.php`, insert a new block between the `admin.squares.*` link (ends with its `@endcan` around line 37) and the `admin.config.edit` ("Texts") link block (starts around line 39):

```blade
        @can('admin.config')
            <a href="{{ route('admin.database.index') }}" onmouseover="if (!this.dataset.active) { this.style.background='#26292e'; this.style.color='#ffffff'; }" onmouseout="if (!this.dataset.active) { this.style.background='transparent'; this.style.color='#b2b6bd'; }" data-active="{{ request()->routeIs('admin.database.*') ? '1' : '' }}" style="display:block; padding:12px 14px 12px {{ request()->routeIs('admin.database.*') ? '11px' : '17px' }}; font-family:var(--font-body); font-size:14px; text-decoration:none; transition:background 0.15s ease, color 0.15s ease; color:{{ request()->routeIs('admin.database.*') ? '#ffffff' : '#b2b6bd' }}; font-weight:{{ request()->routeIs('admin.database.*') ? '700' : '400' }}; background:{{ request()->routeIs('admin.database.*') ? '#34363b' : 'transparent' }}; border-left:{{ request()->routeIs('admin.database.*') ? '3px solid #bf4316' : '3px solid transparent' }};">
                {{ __('booking.admin.database.title') }}
            </a>
        @endcan

```

- [ ] **Step 8: Run the test to verify it passes**

```bash
wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=DatabaseControllerTest"
```

Expected: PASS (4 tests).

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Admin/DatabaseController.php resources/views/admin/database/index.blade.php routes/web.php lang/en/booking/admin.php lang/de/booking/admin.php resources/views/components/layout/admin-sidebar.blade.php tests/Feature/Admin/DatabaseControllerTest.php
git commit -m "Add admin database status page with migration runner"
```

---

### Task 4: Full regression run

- [ ] **Step 1: Run the full test suite**

```bash
wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test"
```

Expected: all `DatabaseSchemaCheckerTest` and `DatabaseControllerTest` tests pass. Note any pre-existing unrelated failures (there are already a few in this codebase from concurrent work, e.g. a `mitspieler` field rename) but do not fix those as part of this task — only confirm no *new* failures were introduced by this feature.

- [ ] **Step 2: Manually verify in the browser**

Start the app (`.claude/launch.json` already has a `laravel-serve` config running `php artisan serve` via WSL) and, logged in as an admin, visit `/admin/database`:
- Confirm the sidebar shows "Database" (or "Datenbank") between "Courts" and "Texts".
- Confirm the migrations table lists every file in `database/migrations/` with a Ran/Pending status.
- Confirm the tables section lists all 15 `bs_*` tables as existing with no missing columns (since the real `booking_local` DB should already have this schema).
- If a "Run pending migrations" button is visible, click it and confirm it redirects back with a success message and the pending list is now empty.
