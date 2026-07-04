# /admin/database: Fehlerausgabe & Tabellen-Reparatur — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die Seite /admin/database zeigt Artisan-Output und konkrete Fehlermeldungen an und bietet pro Problem-Tabelle einen „Reparieren"-Button, der fehlende Tabellen (via Create-Migration) bzw. fehlende Spalten (via `ALTER TABLE ADD`) ergänzt.

**Architecture:** `DatabaseSchemaChecker` bekommt ein Spalten-Definitions-Manifest (Blueprint-Closures), aus dem `EXPECTED_COLUMNS` abgeleitet wird, plus `repairTable()`. `DatabaseController::migrate()` flasht `Artisan::output()` und Exception-Meldungen; neue Action `repair()` + Route `POST admin/database/repair/{table}`. View zeigt Output-Block und Reparatur-Spalte.

**Tech Stack:** Laravel 12, PHPUnit (bestehende Tests in `tests/Unit/Services/DatabaseSchemaCheckerTest.php`, `tests/Feature/Admin/DatabaseControllerTest.php`), Tailwind-Klassen wie im Bestand. PHP nur via WSL ausführen.

**Spec:** `docs/superpowers/specs/2026-07-04-admin-database-repair-design.md`

**Hinweis zur Abweichung von der Spec:** Für „Tabelle fehlt, Migration als ran markiert" wird die Migration nicht aus der `migrations`-Tabelle gelöscht und neu ausgeführt, sondern direkt ihr `up()` aufgerufen und der Eintrag danach sichergestellt — gleiches Ergebnis, weniger Schritte.

**Files:**
- Modify: `app/Services/DatabaseSchemaChecker.php`
- Modify: `app/Http/Controllers/Admin/DatabaseController.php`
- Modify: `routes/web.php` (Zeile ~85, `can:admin.config`-Gruppe)
- Modify: `resources/views/admin/database/index.blade.php`
- Modify: `lang/de/booking/admin.php`, `lang/en/booking/admin.php` (`'database' => [...]`-Abschnitt)
- Test: `tests/Unit/Services/DatabaseSchemaCheckerTest.php`
- Test: `tests/Feature/Admin/DatabaseControllerTest.php`

**Alle Testkommandos via WSL:**
`wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test <pfad> 2>&1 | tail -20"`

---

### Task 1: Migrate zeigt Artisan-Output und konkrete Fehlermeldung

**Files:**
- Modify: `app/Http/Controllers/Admin/DatabaseController.php`
- Modify: `resources/views/admin/database/index.blade.php`
- Modify: `lang/de/booking/admin.php`, `lang/en/booking/admin.php`
- Test: `tests/Feature/Admin/DatabaseControllerTest.php`

- [ ] **Step 1: Failing Tests schreiben**

In `tests/Feature/Admin/DatabaseControllerTest.php` ergänzen (Imports `Illuminate\Support\Facades\Artisan` und `RuntimeException` hinzufügen):

```php
#[Test]
public function migrate_flashes_the_artisan_output_on_success(): void
{
    DB::table('migrations')->where('migration', '2026_06_28_155630_create_jobs_table')->delete();

    $this->actingAs($this->admin())
        ->post('/admin/database/migrate', ['confirmation' => 'MIGRATE'])
        ->assertRedirect('/admin/database')
        ->assertSessionHas('success')
        ->assertSessionHas('output', fn (string $output): bool => str_contains($output, '2026_06_28_155630_create_jobs_table'));
}

#[Test]
public function migrate_failure_shows_the_exception_message(): void
{
    Artisan::shouldReceive('call')->once()->andThrow(new RuntimeException('SQLSTATE boom'));
    Artisan::shouldReceive('output')->andReturn('');

    $this->actingAs($this->admin())
        ->post('/admin/database/migrate', ['confirmation' => 'MIGRATE'])
        ->assertRedirect('/admin/database')
        ->assertSessionHas('error', fn (string $error): bool => str_contains($error, 'SQLSTATE boom'));
}

#[Test]
public function database_page_renders_flashed_output(): void
{
    $this->actingAs($this->admin())
        ->withSession(['output' => 'OUTPUT-ZEILE-42'])
        ->get('/admin/database')
        ->assertOk()
        ->assertSee('OUTPUT-ZEILE-42');
}
```

- [ ] **Step 2: Tests laufen lassen — müssen fehlschlagen**

Run: `php artisan test tests/Feature/Admin/DatabaseControllerTest.php` (via WSL)
Expected: die drei neuen Tests FAIL (`output`/`error` nicht in Session bzw. Text nicht auf Seite), die sechs bestehenden PASS.

- [ ] **Step 3: Controller anpassen**

In `app/Http/Controllers/Admin/DatabaseController.php` den `try/catch`-Block in `migrate()` ersetzen:

```php
try {
    Artisan::call('migrate', ['--force' => true]);
} catch (Throwable $e) {
    report($e);

    return redirect()->route('admin.database.index')
        ->withInput()
        ->with('error', __('booking.admin.database.migrate_failed_message', ['message' => $e->getMessage()]))
        ->with('output', trim(Artisan::output()));
}

return redirect()->route('admin.database.index')
    ->with('success', __('booking.admin.database.migrate_ran'))
    ->with('output', trim(Artisan::output()));
```

- [ ] **Step 4: Lang-Keys ergänzen**

In `lang/de/booking/admin.php` im `'database' => [...]`-Abschnitt den Key `'migrate_failed'` ersetzen durch:

```php
'migrate_failed_message' => 'Die Migration ist fehlgeschlagen: :message',
'output_heading' => 'Ausgabe',
```

In `lang/en/booking/admin.php` analog:

```php
'migrate_failed_message' => 'Running migrations failed: :message',
'output_heading' => 'Output',
```

- [ ] **Step 5: Output-Block in der View**

In `resources/views/admin/database/index.blade.php` direkt nach der `<h1>`-Zeile einfügen:

```blade
@if(session('output'))
    <details class="bg-[#151515] rounded-xl overflow-hidden" open>
        <summary class="px-4 py-2 text-sm font-medium text-white cursor-pointer select-none">{{ __('booking.admin.database.output_heading') }}</summary>
        <pre class="px-4 pb-4 text-xs leading-relaxed text-[#d2d2d2] overflow-x-auto">{{ session('output') }}</pre>
    </details>
@endif
```

- [ ] **Step 6: Tests laufen lassen — müssen grün sein**

Run: `php artisan test tests/Feature/Admin/DatabaseControllerTest.php` (via WSL)
Expected: alle 9 PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/DatabaseController.php resources/views/admin/database/index.blade.php lang/de/booking/admin.php lang/en/booking/admin.php tests/Feature/Admin/DatabaseControllerTest.php
git commit -m "Show artisan output and concrete error message on admin/database migrate"
```

---

### Task 2: Checker: Spalten-Manifest mit Blueprint-Closures

`EXPECTED_COLUMNS` (nur Namen) wird durch `columnDefinitions()` ersetzt (Name → Closure mit vollständiger Spaltendefinition, 1:1 aus den Migrationen übernommen). `checkTables()` leitet die erwarteten Spalten aus den Schlüsseln ab — reines Refactoring, bestehende Tests bleiben grün.

**Files:**
- Modify: `app/Services/DatabaseSchemaChecker.php`
- Test: `tests/Unit/Services/DatabaseSchemaCheckerTest.php` (bestehende Tests als Sicherheitsnetz, ein neuer Test für `knowsTable()`)

- [ ] **Step 1: Failing Test für `knowsTable()` schreiben**

In `tests/Unit/Services/DatabaseSchemaCheckerTest.php`:

```php
#[Test]
public function knows_table_only_accepts_manifest_tables(): void
{
    $this->assertTrue($this->checker->knowsTable('bs_users'));
    $this->assertTrue($this->checker->knowsTable('members'));
    $this->assertFalse($this->checker->knowsTable('migrations'));
    $this->assertFalse($this->checker->knowsTable('bs_users; DROP TABLE x'));
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `php artisan test tests/Unit/Services/DatabaseSchemaCheckerTest.php` (via WSL)
Expected: FAIL `Call to undefined method ... knowsTable()`.

- [ ] **Step 3: Checker refactoren**

In `app/Services/DatabaseSchemaChecker.php`: Import `use Closure;` und `use Illuminate\Database\Schema\Blueprint;` ergänzen. Die Konstante `EXPECTED_COLUMNS` komplett ersetzen durch:

```php
/**
 * Expected column definitions per table, mirrored from database/migrations/*
 * and the module migrations (app/Modules/<Name>/Database/Migrations). Kept as
 * a flat manifest (not derived from the migrations at runtime) so this works
 * without a second DB connection or extra PHP extensions on shared hosting.
 * The closures let repairTable() add a missing column with the right type.
 *
 * @return array<string, array<string, Closure(Blueprint): mixed>>
 */
private static function columnDefinitions(): array
{
    return [
        'bs_users' => [
            'uid' => fn (Blueprint $t) => $t->increments('uid'),
            'alias' => fn (Blueprint $t) => $t->string('alias', 128)->index(),
            'status' => fn (Blueprint $t) => $t->string('status', 64)->default('placeholder'),
            'email' => fn (Blueprint $t) => $t->string('email', 128)->nullable()->index(),
            'pw' => fn (Blueprint $t) => $t->string('pw', 256)->nullable(),
            'login_attempts' => fn (Blueprint $t) => $t->unsignedTinyInteger('login_attempts')->nullable(),
            'login_detent' => fn (Blueprint $t) => $t->dateTime('login_detent')->nullable(),
            'last_activity' => fn (Blueprint $t) => $t->dateTime('last_activity')->nullable(),
            'last_ip' => fn (Blueprint $t) => $t->string('last_ip', 64)->nullable(),
            'created' => fn (Blueprint $t) => $t->dateTime('created')->nullable(),
        ],
        'bs_users_meta' => [
            'umid' => fn (Blueprint $t) => $t->increments('umid'),
            'uid' => fn (Blueprint $t) => $t->unsignedInteger('uid')->index(),
            'key' => fn (Blueprint $t) => $t->string('key', 64)->index(),
            'value' => fn (Blueprint $t) => $t->text('value'),
        ],
        'bs_squares' => [
            'sid' => fn (Blueprint $t) => $t->increments('sid'),
            'name' => fn (Blueprint $t) => $t->string('name', 64),
            'status' => fn (Blueprint $t) => $t->string('status', 64)->default('enabled'),
            'priority' => fn (Blueprint $t) => $t->float('priority')->default(1),
            'capacity' => fn (Blueprint $t) => $t->unsignedInteger('capacity'),
            'capacity_heterogenic' => fn (Blueprint $t) => $t->boolean('capacity_heterogenic'),
            'allow_notes' => fn (Blueprint $t) => $t->boolean('allow_notes')->default(false),
            'time_start' => fn (Blueprint $t) => $t->time('time_start'),
            'time_end' => fn (Blueprint $t) => $t->time('time_end'),
            'time_block' => fn (Blueprint $t) => $t->unsignedInteger('time_block'),
            'time_block_bookable' => fn (Blueprint $t) => $t->unsignedInteger('time_block_bookable'),
            'time_block_bookable_max' => fn (Blueprint $t) => $t->unsignedInteger('time_block_bookable_max')->nullable(),
            'min_range_book' => fn (Blueprint $t) => $t->unsignedInteger('min_range_book')->default(0),
            'range_book' => fn (Blueprint $t) => $t->unsignedInteger('range_book')->nullable(),
            'max_active_bookings' => fn (Blueprint $t) => $t->unsignedInteger('max_active_bookings')->default(0),
            'range_cancel' => fn (Blueprint $t) => $t->unsignedInteger('range_cancel')->nullable(),
        ],
        'bs_squares_meta' => [
            'smid' => fn (Blueprint $t) => $t->increments('smid'),
            'sid' => fn (Blueprint $t) => $t->unsignedInteger('sid')->index(),
            'key' => fn (Blueprint $t) => $t->string('key', 64)->index(),
            'value' => fn (Blueprint $t) => $t->text('value'),
            'locale' => fn (Blueprint $t) => $t->string('locale', 8)->nullable(),
        ],
        'bs_squares_products' => [
            'spid' => fn (Blueprint $t) => $t->increments('spid'),
            'sid' => fn (Blueprint $t) => $t->unsignedInteger('sid')->nullable()->index(),
            'priority' => fn (Blueprint $t) => $t->unsignedInteger('priority'),
            'date_start' => fn (Blueprint $t) => $t->date('date_start')->nullable(),
            'date_end' => fn (Blueprint $t) => $t->date('date_end')->nullable(),
            'name' => fn (Blueprint $t) => $t->string('name', 128),
            'description' => fn (Blueprint $t) => $t->text('description')->nullable(),
            'options' => fn (Blueprint $t) => $t->string('options', 512),
            'price' => fn (Blueprint $t) => $t->unsignedInteger('price'),
            'rate' => fn (Blueprint $t) => $t->unsignedInteger('rate'),
            'gross' => fn (Blueprint $t) => $t->boolean('gross'),
            'locale' => fn (Blueprint $t) => $t->string('locale', 8)->nullable(),
        ],
        'bs_squares_pricing' => [
            'spid' => fn (Blueprint $t) => $t->increments('spid'),
            'sid' => fn (Blueprint $t) => $t->unsignedInteger('sid')->nullable()->index(),
            'priority' => fn (Blueprint $t) => $t->unsignedInteger('priority'),
            'date_start' => fn (Blueprint $t) => $t->date('date_start'),
            'date_end' => fn (Blueprint $t) => $t->date('date_end'),
            'day_start' => fn (Blueprint $t) => $t->unsignedTinyInteger('day_start')->nullable(),
            'day_end' => fn (Blueprint $t) => $t->unsignedTinyInteger('day_end')->nullable(),
            'time_start' => fn (Blueprint $t) => $t->time('time_start')->nullable(),
            'time_end' => fn (Blueprint $t) => $t->time('time_end')->nullable(),
            'price' => fn (Blueprint $t) => $t->unsignedInteger('price')->nullable(),
            'rate' => fn (Blueprint $t) => $t->unsignedInteger('rate')->nullable(),
            'gross' => fn (Blueprint $t) => $t->boolean('gross')->nullable(),
            'per_time_block' => fn (Blueprint $t) => $t->unsignedInteger('per_time_block')->nullable(),
            'per_quantity' => fn (Blueprint $t) => $t->boolean('per_quantity')->nullable(),
        ],
        'bs_squares_coupons' => [
            'scid' => fn (Blueprint $t) => $t->increments('scid'),
            'sid' => fn (Blueprint $t) => $t->unsignedInteger('sid')->nullable()->index(),
            'code' => fn (Blueprint $t) => $t->string('code', 64)->index(),
            'date_start' => fn (Blueprint $t) => $t->dateTime('date_start')->nullable(),
            'date_end' => fn (Blueprint $t) => $t->dateTime('date_end')->nullable(),
            'discount_for_booking' => fn (Blueprint $t) => $t->unsignedInteger('discount_for_booking'),
            'discount_for_products' => fn (Blueprint $t) => $t->unsignedInteger('discount_for_products'),
            'discount_in_percent' => fn (Blueprint $t) => $t->boolean('discount_in_percent'),
        ],
        'bs_bookings' => [
            'bid' => fn (Blueprint $t) => $t->increments('bid'),
            'uid' => fn (Blueprint $t) => $t->unsignedInteger('uid')->index(),
            'sid' => fn (Blueprint $t) => $t->unsignedInteger('sid')->index(),
            'status' => fn (Blueprint $t) => $t->string('status', 64),
            'status_billing' => fn (Blueprint $t) => $t->string('status_billing', 64),
            'visibility' => fn (Blueprint $t) => $t->string('visibility', 64),
            'quantity' => fn (Blueprint $t) => $t->unsignedInteger('quantity'),
            'created' => fn (Blueprint $t) => $t->dateTime('created'),
        ],
        'bs_bookings_bills' => [
            'bbid' => fn (Blueprint $t) => $t->increments('bbid'),
            'bid' => fn (Blueprint $t) => $t->unsignedInteger('bid')->index(),
            'description' => fn (Blueprint $t) => $t->string('description', 512),
            'quantity' => fn (Blueprint $t) => $t->unsignedInteger('quantity')->nullable(),
            'time' => fn (Blueprint $t) => $t->unsignedInteger('time')->nullable(),
            'price' => fn (Blueprint $t) => $t->integer('price'),
            'rate' => fn (Blueprint $t) => $t->unsignedInteger('rate'),
            'gross' => fn (Blueprint $t) => $t->boolean('gross'),
        ],
        'bs_bookings_meta' => [
            'bmid' => fn (Blueprint $t) => $t->increments('bmid'),
            'bid' => fn (Blueprint $t) => $t->unsignedInteger('bid')->index(),
            'key' => fn (Blueprint $t) => $t->string('key', 64)->index(),
            'value' => fn (Blueprint $t) => $t->text('value'),
        ],
        'bs_reservations' => [
            'rid' => fn (Blueprint $t) => $t->increments('rid'),
            'bid' => fn (Blueprint $t) => $t->unsignedInteger('bid')->index(),
            'date' => fn (Blueprint $t) => $t->date('date')->index(),
            'time_start' => fn (Blueprint $t) => $t->time('time_start'),
            'time_end' => fn (Blueprint $t) => $t->time('time_end'),
        ],
        'bs_events' => [
            'eid' => fn (Blueprint $t) => $t->increments('eid'),
            'sid' => fn (Blueprint $t) => $t->unsignedInteger('sid')->nullable()->index(),
            'status' => fn (Blueprint $t) => $t->string('status', 64)->default('enabled'),
            'datetime_start' => fn (Blueprint $t) => $t->dateTime('datetime_start')->index(),
            'datetime_end' => fn (Blueprint $t) => $t->dateTime('datetime_end')->index(),
            'capacity' => fn (Blueprint $t) => $t->unsignedInteger('capacity')->nullable(),
        ],
        'bs_events_meta' => [
            'emid' => fn (Blueprint $t) => $t->increments('emid'),
            'eid' => fn (Blueprint $t) => $t->unsignedInteger('eid')->index(),
            'key' => fn (Blueprint $t) => $t->string('key', 64)->index(),
            'value' => fn (Blueprint $t) => $t->text('value'),
            'locale' => fn (Blueprint $t) => $t->string('locale', 8)->nullable(),
        ],
        'bs_options' => [
            'oid' => fn (Blueprint $t) => $t->increments('oid'),
            'key' => fn (Blueprint $t) => $t->string('key', 64)->index(),
            'value' => fn (Blueprint $t) => $t->text('value'),
            'locale' => fn (Blueprint $t) => $t->string('locale', 8)->nullable(),
        ],
        'bs_reservations_meta' => [
            'rmid' => fn (Blueprint $t) => $t->increments('rmid'),
            'rid' => fn (Blueprint $t) => $t->unsignedInteger('rid')->index(),
            'key' => fn (Blueprint $t) => $t->string('key', 64)->index(),
            'value' => fn (Blueprint $t) => $t->text('value'),
        ],
        'fiscal_years' => [
            'id' => fn (Blueprint $t) => $t->id(),
            'name' => fn (Blueprint $t) => $t->string('name', 32),
            'starts_on' => fn (Blueprint $t) => $t->date('starts_on'),
            'ends_on' => fn (Blueprint $t) => $t->date('ends_on'),
            'created_at' => fn (Blueprint $t) => $t->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $t) => $t->timestamp('updated_at')->nullable(),
        ],
        'fee_categories' => [
            'id' => fn (Blueprint $t) => $t->id(),
            'name' => fn (Blueprint $t) => $t->string('name', 64),
            'created_at' => fn (Blueprint $t) => $t->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $t) => $t->timestamp('updated_at')->nullable(),
        ],
        'fee_category_rates' => [
            'id' => fn (Blueprint $t) => $t->id(),
            'fee_category_id' => fn (Blueprint $t) => $t->foreignId('fee_category_id')->constrained()->cascadeOnDelete(),
            'fiscal_year_id' => fn (Blueprint $t) => $t->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete(),
            'amount' => fn (Blueprint $t) => $t->decimal('amount', 8, 2),
            'created_at' => fn (Blueprint $t) => $t->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $t) => $t->timestamp('updated_at')->nullable(),
        ],
        'members' => [
            'id' => fn (Blueprint $t) => $t->id(),
            'member_number' => fn (Blueprint $t) => $t->unsignedInteger('member_number')->unique(),
            'firstname' => fn (Blueprint $t) => $t->string('firstname', 128),
            'lastname' => fn (Blueprint $t) => $t->string('lastname', 128),
            'birthdate' => fn (Blueprint $t) => $t->date('birthdate')->nullable(),
            'email' => fn (Blueprint $t) => $t->string('email', 128)->nullable(),
            'phone' => fn (Blueprint $t) => $t->string('phone', 64)->nullable(),
            'address' => fn (Blueprint $t) => $t->string('address', 255)->nullable(),
            'joined_at' => fn (Blueprint $t) => $t->date('joined_at'),
            'left_at' => fn (Blueprint $t) => $t->date('left_at')->nullable(),
            'created_at' => fn (Blueprint $t) => $t->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $t) => $t->timestamp('updated_at')->nullable(),
        ],
        'member_dues' => [
            'id' => fn (Blueprint $t) => $t->id(),
            'member_id' => fn (Blueprint $t) => $t->foreignId('member_id')->constrained()->cascadeOnDelete(),
            'fiscal_year_id' => fn (Blueprint $t) => $t->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete(),
            'fee_category_id' => fn (Blueprint $t) => $t->foreignId('fee_category_id')->constrained()->cascadeOnDelete(),
            'amount' => fn (Blueprint $t) => $t->decimal('amount', 8, 2),
            'created_at' => fn (Blueprint $t) => $t->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $t) => $t->timestamp('updated_at')->nullable(),
        ],
        'payments' => [
            'id' => fn (Blueprint $t) => $t->id(),
            'member_id' => fn (Blueprint $t) => $t->foreignId('member_id')->constrained()->cascadeOnDelete(),
            'amount' => fn (Blueprint $t) => $t->decimal('amount', 8, 2),
            'paid_at' => fn (Blueprint $t) => $t->date('paid_at'),
            'note' => fn (Blueprint $t) => $t->string('note', 255)->nullable(),
            'created_at' => fn (Blueprint $t) => $t->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $t) => $t->timestamp('updated_at')->nullable(),
        ],
        'payment_dues' => [
            'id' => fn (Blueprint $t) => $t->id(),
            'payment_id' => fn (Blueprint $t) => $t->foreignId('payment_id')->constrained()->cascadeOnDelete(),
            'member_due_id' => fn (Blueprint $t) => $t->foreignId('member_due_id')->constrained()->cascadeOnDelete(),
            'created_at' => fn (Blueprint $t) => $t->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $t) => $t->timestamp('updated_at')->nullable(),
        ],
    ];
}

public function knowsTable(string $table): bool
{
    return array_key_exists($table, self::columnDefinitions());
}
```

In `checkTables()` die Schleife anpassen (`self::EXPECTED_COLUMNS` gibt es nicht mehr):

```php
foreach (self::columnDefinitions() as $table => $definitions) {
    $expectedColumns = array_keys($definitions);
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
```

- [ ] **Step 4: Alle Checker-Tests laufen lassen — müssen grün sein**

Run: `php artisan test tests/Unit/Services/DatabaseSchemaCheckerTest.php` (via WSL)
Expected: alle PASS (Refactoring-Sicherheitsnetz: besonders `check_tables_reports_no_missing_columns_after_a_normal_migration` beweist, dass das Manifest exakt den Migrationen entspricht).

- [ ] **Step 5: Commit**

```bash
git add app/Services/DatabaseSchemaChecker.php tests/Unit/Services/DatabaseSchemaCheckerTest.php
git commit -m "Replace column-name manifest with blueprint closures in DatabaseSchemaChecker"
```

---

### Task 3: Checker: repairTable()

**Files:**
- Modify: `app/Services/DatabaseSchemaChecker.php`
- Test: `tests/Unit/Services/DatabaseSchemaCheckerTest.php`

- [ ] **Step 1: Failing Tests schreiben**

In `tests/Unit/Services/DatabaseSchemaCheckerTest.php` (Import `InvalidArgumentException` ergänzen):

```php
#[Test]
public function repair_table_adds_missing_columns(): void
{
    Schema::table('bs_users', function (Blueprint $table): void {
        $table->dropColumn('last_ip');
    });

    $actions = $this->checker->repairTable('bs_users');

    $this->assertCount(1, $actions);
    $this->assertStringContainsString('last_ip', $actions[0]);
    $this->assertTrue(Schema::hasColumn('bs_users', 'last_ip'));

    $result = collect($this->checker->checkTables())->firstWhere('table', 'bs_users');
    $this->assertSame([], $result['missing_columns']);
}

#[Test]
public function repair_table_recreates_a_missing_table_via_its_migration(): void
{
    Schema::drop('bs_events_meta');

    $actions = $this->checker->repairTable('bs_events_meta');

    $this->assertCount(1, $actions);
    $this->assertStringContainsString('2026_06_26_073904_create_bs_events_meta_table', $actions[0]);
    $this->assertTrue(Schema::hasTable('bs_events_meta'));
    $this->assertDatabaseHas('migrations', ['migration' => '2026_06_26_073904_create_bs_events_meta_table']);
}

#[Test]
public function repair_table_restores_a_lost_migration_record(): void
{
    Schema::drop('bs_events_meta');
    DB::table('migrations')->where('migration', '2026_06_26_073904_create_bs_events_meta_table')->delete();

    $this->checker->repairTable('bs_events_meta');

    $this->assertTrue(Schema::hasTable('bs_events_meta'));
    $this->assertDatabaseHas('migrations', ['migration' => '2026_06_26_073904_create_bs_events_meta_table']);
}

#[Test]
public function repair_table_is_a_noop_for_a_healthy_table(): void
{
    $this->assertSame([], $this->checker->repairTable('bs_users'));
}

#[Test]
public function repair_table_throws_for_an_unknown_table(): void
{
    $this->expectException(InvalidArgumentException::class);

    $this->checker->repairTable('migrations');
}
```

- [ ] **Step 2: Tests laufen lassen — müssen fehlschlagen**

Run: `php artisan test tests/Unit/Services/DatabaseSchemaCheckerTest.php` (via WSL)
Expected: die fünf neuen FAIL (`Call to undefined method ... repairTable()`).

- [ ] **Step 3: repairTable() implementieren**

In `app/Services/DatabaseSchemaChecker.php` (Imports `use InvalidArgumentException;` und `use RuntimeException;` ergänzen):

```php
/**
 * Creates the table (via its create migration) or adds missing columns.
 * Only ever adds what is missing — never drops or alters existing columns.
 *
 * @return array<int, string> human-readable list of actions performed
 */
public function repairTable(string $table): array
{
    $definitions = self::columnDefinitions()[$table] ?? null;

    if ($definitions === null) {
        throw new InvalidArgumentException("Unknown table [{$table}].");
    }

    if (! Schema::hasTable($table)) {
        return [$this->createTableFromMigration($table)];
    }

    $missing = array_values(array_diff(array_keys($definitions), Schema::getColumnListing($table)));

    if ($missing === []) {
        return [];
    }

    Schema::table($table, function (Blueprint $blueprint) use ($definitions, $missing): void {
        foreach ($missing as $column) {
            ($definitions[$column])($blueprint);
        }
    });

    return array_map(
        static fn (string $column): string => "Added column {$column} to {$table}.",
        $missing,
    );
}

private function createTableFromMigration(string $table): string
{
    $migrator = app('migrator');
    $files = $migrator->getMigrationFiles(array_merge(
        [database_path('migrations')],
        $migrator->paths(),
    ));

    $name = null;
    $path = null;
    foreach ($files as $migrationName => $migrationPath) {
        if (str_ends_with($migrationName, "create_{$table}_table")) {
            [$name, $path] = [$migrationName, $migrationPath];
            break;
        }
    }

    if ($name === null) {
        throw new RuntimeException("No create migration found for table [{$table}].");
    }

    $migration = require $path;
    $migration->up();

    $repository = $migrator->getRepository();
    if (! in_array($name, $repository->getRan(), true)) {
        $repository->log($name, $repository->getNextBatchNumber());
    }

    return "Created table {$table} by running migration {$name}.";
}
```

Hinweis: `array_map` mit `$table` braucht `use` — bei Arrow-fn nicht nötig; hier `static fn` innerhalb der Methode: `$table` ist Parameter der umgebenden Methode und wird von Arrow-Functions automatisch erfasst. Passt so.

- [ ] **Step 4: Tests laufen lassen — müssen grün sein**

Run: `php artisan test tests/Unit/Services/DatabaseSchemaCheckerTest.php` (via WSL)
Expected: alle PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/DatabaseSchemaChecker.php tests/Unit/Services/DatabaseSchemaCheckerTest.php
git commit -m "Add repairTable to DatabaseSchemaChecker (recreate table / add missing columns)"
```

---

### Task 4: Reparatur-Aktion: Route, Controller, View, Lang

**Files:**
- Modify: `app/Http/Controllers/Admin/DatabaseController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/admin/database/index.blade.php`
- Modify: `lang/de/booking/admin.php`, `lang/en/booking/admin.php`
- Test: `tests/Feature/Admin/DatabaseControllerTest.php`

- [ ] **Step 1: Failing Tests schreiben**

In `tests/Feature/Admin/DatabaseControllerTest.php`:

```php
#[Test]
public function admin_can_repair_a_table_with_a_missing_column(): void
{
    Schema::table('bs_users', function (Blueprint $table): void {
        $table->dropColumn('last_ip');
    });

    $this->actingAs($this->admin())
        ->post('/admin/database/repair/bs_users')
        ->assertRedirect('/admin/database')
        ->assertSessionHas('success')
        ->assertSessionHas('output', fn (string $output): bool => str_contains($output, 'last_ip'));

    $this->assertTrue(Schema::hasColumn('bs_users', 'last_ip'));
}

#[Test]
public function admin_can_repair_a_missing_table(): void
{
    Schema::drop('bs_events_meta');

    $this->actingAs($this->admin())
        ->post('/admin/database/repair/bs_events_meta')
        ->assertRedirect('/admin/database')
        ->assertSessionHas('success');

    $this->assertTrue(Schema::hasTable('bs_events_meta'));
}

#[Test]
public function repairing_a_healthy_table_reports_nothing_to_do(): void
{
    $this->actingAs($this->admin())
        ->post('/admin/database/repair/bs_users')
        ->assertRedirect('/admin/database')
        ->assertSessionHas('success', __('booking.admin.database.repair_nothing', ['table' => 'bs_users']));
}

#[Test]
public function repairing_an_unknown_table_returns_404(): void
{
    $this->actingAs($this->admin())
        ->post('/admin/database/repair/migrations')
        ->assertNotFound();
}

#[Test]
public function regular_member_cannot_repair_tables(): void
{
    $this->actingAs(User::factory()->create(['status' => 'enabled']))
        ->post('/admin/database/repair/bs_users')
        ->assertForbidden();
}

#[Test]
public function database_page_offers_repair_for_a_problem_table(): void
{
    Schema::table('bs_users', function (Blueprint $table): void {
        $table->dropColumn('last_ip');
    });

    $this->actingAs($this->admin())
        ->get('/admin/database')
        ->assertOk()
        ->assertSee(__('booking.admin.database.repair_button'))
        ->assertSee(route('admin.database.repair', 'bs_users'));
}
```

- [ ] **Step 2: Tests laufen lassen — müssen fehlschlagen**

Run: `php artisan test tests/Feature/Admin/DatabaseControllerTest.php` (via WSL)
Expected: neue Tests FAIL (Route existiert nicht → 404 statt Redirect; Lang-Key fehlt).

- [ ] **Step 3: Route ergänzen**

In `routes/web.php` in der `can:admin.config`-Gruppe nach der `database/migrate`-Zeile:

```php
Route::post('database/repair/{table}', [DatabaseController::class, 'repair'])->name('database.repair');
```

- [ ] **Step 4: Controller-Action ergänzen**

In `app/Http/Controllers/Admin/DatabaseController.php`:

```php
public function repair(string $table): RedirectResponse
{
    abort_unless($this->checker->knowsTable($table), 404);

    try {
        $actions = $this->checker->repairTable($table);
    } catch (Throwable $e) {
        report($e);

        return redirect()->route('admin.database.index')
            ->with('error', __('booking.admin.database.repair_failed', ['table' => $table, 'message' => $e->getMessage()]));
    }

    if ($actions === []) {
        return redirect()->route('admin.database.index')
            ->with('success', __('booking.admin.database.repair_nothing', ['table' => $table]));
    }

    return redirect()->route('admin.database.index')
        ->with('success', __('booking.admin.database.repair_done', ['table' => $table]))
        ->with('output', implode(PHP_EOL, $actions));
}
```

- [ ] **Step 5: Lang-Keys ergänzen**

`lang/de/booking/admin.php`, `'database'`-Abschnitt:

```php
'repair_heading' => 'Reparatur',
'repair_button' => 'Reparieren',
'repair_confirm' => 'Dies ergänzt fehlende Tabellen bzw. Spalten in der Live-Datenbank. Es wird nichts gelöscht oder geändert. Fortfahren?',
'repair_done' => 'Tabelle :table wurde repariert.',
'repair_nothing' => 'Tabelle :table ist in Ordnung — nichts zu tun.',
'repair_failed' => 'Reparatur von :table fehlgeschlagen: :message',
'repair_preview_create' => 'Legt die Tabelle neu an.',
'repair_preview_columns' => 'Ergänzt Spalten: :columns',
```

`lang/en/booking/admin.php`:

```php
'repair_heading' => 'Repair',
'repair_button' => 'Repair',
'repair_confirm' => 'This adds missing tables or columns to the live database. Nothing is deleted or altered. Continue?',
'repair_done' => 'Table :table repaired.',
'repair_nothing' => 'Table :table is fine — nothing to do.',
'repair_failed' => 'Repairing :table failed: :message',
'repair_preview_create' => 'Creates the table.',
'repair_preview_columns' => 'Adds columns: :columns',
```

- [ ] **Step 6: View: Reparatur-Spalte**

In `resources/views/admin/database/index.blade.php` in der Tabellen-Tabelle einen vierten Header ergänzen:

```blade
<th class="py-2">{{ __('booking.admin.database.repair_heading') }}</th>
```

und in der `@foreach($tables ...)`-Zeile eine vierte Zelle:

```blade
<td class="py-2">
    @if(!$table['exists'] || $table['missing_columns'] !== [])
        <div class="flex flex-col gap-1">
            <p class="text-xs text-[#6a6e73]">
                @if(!$table['exists'])
                    {{ __('booking.admin.database.repair_preview_create') }}
                @else
                    {{ __('booking.admin.database.repair_preview_columns', ['columns' => implode(', ', $table['missing_columns'])]) }}
                @endif
            </p>
            <form method="POST" action="{{ route('admin.database.repair', $table['table']) }}" onsubmit="return confirm({{ Js::from(__('booking.admin.database.repair_confirm')) }})">
                @csrf
                <button type="submit" class="bg-[#bf4316] hover:bg-[#9e3412] text-white text-xs font-medium px-3 py-1.5 rounded transition-colors">{{ __('booking.admin.database.repair_button') }}</button>
            </form>
        </div>
    @else
        <span class="text-xs text-[#6a6e73]">—</span>
    @endif
</td>
```

- [ ] **Step 7: Tests laufen lassen — müssen grün sein**

Run: `php artisan test tests/Feature/Admin/DatabaseControllerTest.php` (via WSL)
Expected: alle PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Admin/DatabaseController.php routes/web.php resources/views/admin/database/index.blade.php lang/de/booking/admin.php lang/en/booking/admin.php tests/Feature/Admin/DatabaseControllerTest.php
git commit -m "Add per-table repair action to admin/database"
```

---

### Task 5: Gesamte Suite & manuelle Verifikation

- [ ] **Step 1: Komplette Testsuite**

Run: `wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test 2>&1 | tail -10"`
Expected: alle Suiten PASS, keine Regressionen.

- [ ] **Step 2: Manuelle Verifikation gegen die echte DB (nur lesend)**

Seite `/admin/database` im Browser bzw. per Test-Request prüfen: Migrationsliste vollständig, alle Tabellen grün, keine Reparatur-Buttons sichtbar (gesunder Zustand), kein Output-Block ohne Flash.

- [ ] **Step 3: Abschluss**

Superpowers-Skill `verification-before-completion` beachten: Testausgabe als Beleg festhalten, dann fertig melden.
