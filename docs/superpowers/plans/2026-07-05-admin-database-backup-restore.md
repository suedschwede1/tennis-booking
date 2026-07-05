# Backup/Export/Restore (Stufe 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `/admin/database` bekommt ein Backup-Panel: PHP-generierte, gzip-komprimierte SQL-Dumps erstellen, auflisten, herunterladen, löschen und aus der eigenen Liste wiederherstellen (mit automatischem Sicherheits-Backup vor jedem Restore) — ohne `mysqldump`/SSH, da one.com das nicht bietet.

**Architecture:** Neuer Service `DatabaseBackupService` baut den Dump treiberunabhängig über Laravels Schema-Introspektion (`Schema::getTableListing()`, `Schema::getColumnListing()`) und je Treiber die native DDL-Quelle (`SHOW CREATE TABLE` bei MySQL, `sqlite_master.sql` bei SQLite) — dadurch läuft dieselbe Logik unter SQLite (Tests) und MySQL (Produktion), voll automatisiert testbar per echtem Dump+Restore-Roundtrip. Restore läuft zweiphasig (erst alle DROP/CREATE, dann alle INSERT), damit die Reihenfolge von Fremdschlüssel-Abhängigkeiten keine Rolle spielt.

**Tech Stack:** Laravel 13 (`Schema::getTableListing()`, `Schema::disableForeignKeyConstraints()`), PHP `gzencode`/`gzdecode` (ext-zlib, auf jedem gängigen Hosting vorhanden), `Storage::fake('local')` für isolierte Tests. PHP nur via WSL ausführen.

**Spec:** `docs/superpowers/specs/2026-07-05-admin-database-backup-restore-design.md`

**Wichtiger Rechercheergebnis vor diesem Plan:** `Schema::getTableListing()` ohne Argumente scannt auf diesem lokalen MySQL-Server über Datenbank-Grenzen hinweg (lieferte auch `booking_test.migrations`!). Der Code MUSS explizit `Schema::getTableListing($schema, false)` mit der aktuellen Datenbank als `$schema` aufrufen (bei SQLite: `null`, da SQLite keine vergleichbare Mehr-Schema-Verwechslungsgefahr hat und `getDatabaseName()` dort `:memory:` zurückgibt, was NICHT der SQLite-internen Schema-Bezeichnung `main` entspricht).

**Files:**
- Create: `app/Services/DatabaseBackupService.php`
- Modify: `app/Http/Controllers/Admin/DatabaseController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/admin/database/index.blade.php`
- Modify: `lang/de/booking/admin.php`, `lang/en/booking/admin.php`
- Test: `tests/Unit/Services/DatabaseBackupServiceTest.php` (neu)
- Test: `tests/Feature/Admin/DatabaseControllerTest.php`

**Alle Testkommandos via WSL:**
`wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test <pfad> 2>&1 | tail -20"`

---

### Task 1: `DatabaseBackupService` — Dump erstellen, auflisten, löschen

**Files:**
- Create: `app/Services/DatabaseBackupService.php`
- Test: `tests/Unit/Services/DatabaseBackupServiceTest.php`

- [ ] **Step 1: Failing Tests schreiben**

Neue Datei `tests/Unit/Services/DatabaseBackupServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\DatabaseBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DatabaseBackupServiceTest extends TestCase
{
    use RefreshDatabase;

    private DatabaseBackupService $backups;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->backups = new DatabaseBackupService();
    }

    #[Test]
    public function create_writes_a_gzip_compressed_dump_containing_known_tables(): void
    {
        $filename = $this->backups->create();

        Storage::disk('local')->assertExists('backups/'.$filename);

        $sql = gzdecode(Storage::disk('local')->get('backups/'.$filename));

        $this->assertStringContainsString('CREATE TABLE `bs_options`', $sql);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `members`', $sql);
    }

    #[Test]
    public function create_includes_row_data(): void
    {
        DB::table('bs_options')->insert(['key' => 'drift_test_key', 'value' => 'drift_test_value', 'locale' => null]);

        $filename = $this->backups->create();
        $sql = gzdecode(Storage::disk('local')->get('backups/'.$filename));

        $this->assertStringContainsString('drift_test_key', $sql);
        $this->assertStringContainsString('drift_test_value', $sql);
    }

    #[Test]
    public function create_returns_a_filename_matching_the_expected_pattern(): void
    {
        $filename = $this->backups->create();

        $this->assertMatchesRegularExpression('/^backup-\d{8}_\d{6}\.sql\.gz$/', $filename);
    }

    #[Test]
    public function create_with_suffix_appends_it_to_the_filename(): void
    {
        $filename = $this->backups->create('prerestore');

        $this->assertMatchesRegularExpression('/^backup-\d{8}_\d{6}-prerestore\.sql\.gz$/', $filename);
    }

    #[Test]
    public function list_returns_newest_first_with_correct_size(): void
    {
        $older = $this->backups->create();
        $this->travel(1)->seconds();
        $newer = $this->backups->create();

        $list = $this->backups->list();

        $this->assertSame($newer, $list[0]['filename']);
        $this->assertSame($older, $list[1]['filename']);
        $this->assertGreaterThan(0, $list[0]['sizeBytes']);
    }

    #[Test]
    public function delete_removes_the_file(): void
    {
        $filename = $this->backups->create();

        $this->backups->delete($filename);

        Storage::disk('local')->assertMissing('backups/'.$filename);
        $this->assertSame([], $this->backups->list());
    }

    #[Test]
    public function delete_of_a_missing_file_is_a_noop(): void
    {
        $this->backups->delete('backup-20200101_000000.sql.gz');

        $this->assertSame([], $this->backups->list());
    }

    #[Test]
    public function old_backups_beyond_the_retention_limit_are_pruned(): void
    {
        $filenames = [];

        for ($i = 0; $i < 11; $i++) {
            $filenames[] = $this->backups->create();
            $this->travel(1)->seconds();
        }

        $list = $this->backups->list();

        $this->assertCount(10, $list);
        $remaining = array_column($list, 'filename');
        $this->assertNotContains($filenames[0], $remaining);
        $this->assertContains($filenames[10], $remaining);
    }
}
```

- [ ] **Step 2: Tests laufen lassen — müssen fehlschlagen**

Run: `php artisan test tests/Unit/Services/DatabaseBackupServiceTest.php` (via WSL)
Expected: FAIL — `Class "App\Services\DatabaseBackupService" not found`.

- [ ] **Step 3: `DatabaseBackupService` mit `create()`, `list()`, `delete()` implementieren**

Neue Datei `app/Services/DatabaseBackupService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * PHP-generated SQL dump/restore for one.com shared hosting, which has no
 * SSH and therefore no mysqldump. Builds dumps via Laravel's own schema
 * introspection (not hardcoded MySQL syntax) so the exact same code path
 * runs — and is tested — against SQLite in the test suite and MySQL in
 * production.
 */
final class DatabaseBackupService
{
    private const DISK = 'local';
    private const DIRECTORY = 'backups';
    private const MAX_BACKUPS = 10;
    private const INSERT_BATCH_SIZE = 500;

    /**
     * @return array<int, array{filename: string, sizeBytes: int, createdAt: Carbon}>
     */
    public function list(): array
    {
        $backups = [];

        foreach (Storage::disk(self::DISK)->files(self::DIRECTORY) as $path) {
            $filename = basename($path);
            $createdAt = $this->parseTimestamp($filename);

            if ($createdAt === null) {
                continue;
            }

            $backups[] = [
                'filename' => $filename,
                'sizeBytes' => Storage::disk(self::DISK)->size($path),
                'createdAt' => $createdAt,
            ];
        }

        usort($backups, static fn (array $a, array $b): int => $b['createdAt']->timestamp <=> $a['createdAt']->timestamp);

        return $backups;
    }

    public function create(?string $suffix = null): string
    {
        $filename = 'backup-'.Carbon::now()->format('Ymd_His').($suffix !== null ? "-{$suffix}" : '').'.sql.gz';
        $path = self::DIRECTORY.'/'.$filename;

        try {
            Storage::disk(self::DISK)->put($path, gzencode($this->buildDump(), 9));
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($path);

            throw $e;
        }

        $this->prune();

        return $filename;
    }

    public function delete(string $filename): void
    {
        Storage::disk(self::DISK)->delete(self::DIRECTORY.'/'.$filename);
    }

    private function buildDump(): string
    {
        $driver = DB::connection()->getDriverName();
        $schema = $driver === 'sqlite' ? null : DB::connection()->getDatabaseName();
        $tables = Schema::getTableListing($schema, false);

        $statements = [];

        foreach ($tables as $table) {
            $statements[] = "DROP TABLE IF EXISTS `{$table}`;";
            $statements[] = $this->createTableStatement($table, $driver);
            array_push($statements, ...$this->insertStatements($table));
        }

        return implode("\n", $statements)."\n";
    }

    private function createTableStatement(string $table, string $driver): string
    {
        $ddl = $driver === 'sqlite'
            ? DB::selectOne("select sql from sqlite_master where type = 'table' and name = ?", [$table])->sql
            : DB::select("SHOW CREATE TABLE `{$table}`")[0]->{'Create Table'};

        return str_replace(["\r\n", "\n"], ' ', $ddl).';';
    }

    /**
     * @return array<int, string>
     */
    private function insertStatements(string $table): array
    {
        $columns = Schema::getColumnListing($table);
        $rows = DB::table($table)->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $pdo = DB::connection()->getPdo();
        $quotedColumns = implode(',', array_map(static fn (string $c): string => "`{$c}`", $columns));

        $statements = [];

        foreach ($rows->chunk(self::INSERT_BATCH_SIZE) as $chunk) {
            $valueGroups = [];

            foreach ($chunk as $row) {
                $values = array_map(
                    static function (string $column) use ($row, $pdo): string {
                        $value = $row->$column;

                        return $value === null ? 'NULL' : $pdo->quote((string) $value);
                    },
                    $columns,
                );
                $valueGroups[] = '('.implode(',', $values).')';
            }

            $statements[] = "INSERT INTO `{$table}` ({$quotedColumns}) VALUES ".implode(',', $valueGroups).';';
        }

        return $statements;
    }

    private function prune(): void
    {
        foreach (array_slice($this->list(), self::MAX_BACKUPS) as $backup) {
            $this->delete($backup['filename']);
        }
    }

    private function parseTimestamp(string $filename): ?Carbon
    {
        if (! preg_match('/^backup-(\d{8})_(\d{6})(?:-[a-z]+)?\.sql\.gz$/', $filename, $matches)) {
            return null;
        }

        return Carbon::createFromFormat('Ymd His', "{$matches[1]} {$matches[2]}");
    }
}
```

- [ ] **Step 4: Tests laufen lassen — müssen grün sein**

Run: `php artisan test tests/Unit/Services/DatabaseBackupServiceTest.php` (via WSL)
Expected: alle 7 PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/DatabaseBackupService.php tests/Unit/Services/DatabaseBackupServiceTest.php
git commit -m "Add DatabaseBackupService: create/list/delete PHP-generated SQL dumps"
```

---

### Task 2: Restore (zweiphasig, mit automatischem Sicherheits-Backup)

**Files:**
- Modify: `app/Services/DatabaseBackupService.php`
- Test: `tests/Unit/Services/DatabaseBackupServiceTest.php`

- [ ] **Step 1: Failing Tests schreiben**

In `tests/Unit/Services/DatabaseBackupServiceTest.php` ergänzen (Import `use RuntimeException;` und `use Illuminate\Support\Facades\Schema;` hinzufügen):

```php
#[Test]
public function restore_round_trips_structure_and_data(): void
{
    DB::table('fee_categories')->insert(['id' => 1, 'name' => 'Vollmitglied']);
    DB::table('fiscal_years')->insert(['id' => 1, 'name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    DB::table('members')->insert([
        'id' => 1, 'member_number' => 42, 'firstname' => 'Erika', 'lastname' => 'Musterfrau', 'joined_at' => '2020-01-01',
    ]);
    DB::table('member_dues')->insert([
        'id' => 1, 'member_id' => 1, 'fiscal_year_id' => 1, 'fee_category_id' => 1, 'amount' => 50,
    ]);

    $filename = $this->backups->create();

    DB::table('member_dues')->delete();
    DB::table('members')->delete();
    DB::table('fiscal_years')->delete();
    DB::table('fee_categories')->delete();

    $this->backups->restore($filename);

    $this->assertSame('Musterfrau', DB::table('members')->where('id', 1)->value('lastname'));
    $this->assertSame(1, DB::table('member_dues')->count());
    $this->assertTrue(Schema::hasTable('members'));
}

#[Test]
public function restore_creates_a_safety_backup_before_touching_data(): void
{
    $filename = $this->backups->create();
    $this->travel(1)->seconds();

    $actions = $this->backups->restore($filename);

    $safetyBackups = array_filter($this->backups->list(), static fn (array $b): bool => str_contains($b['filename'], '-prerestore'));
    $this->assertNotEmpty($safetyBackups);
    $this->assertStringContainsString(array_values($safetyBackups)[0]['filename'], implode(' ', $actions));
}

#[Test]
public function restore_of_a_corrupt_dump_throws_and_mentions_the_safety_backup(): void
{
    Storage::disk('local')->put('backups/backup-20200101_000000.sql.gz', gzencode('THIS IS NOT VALID SQL;', 9));

    $this->expectException(RuntimeException::class);

    try {
        $this->backups->restore('backup-20200101_000000.sql.gz');
    } catch (RuntimeException $e) {
        $this->assertStringContainsString('-prerestore', $e->getMessage());

        throw $e;
    }
}
```

- [ ] **Step 2: Tests laufen lassen — müssen fehlschlagen**

Run: `php artisan test tests/Unit/Services/DatabaseBackupServiceTest.php --filter=restore` (via WSL)
Expected: alle drei FAIL mit `Call to undefined method App\Services\DatabaseBackupService::restore()`.

- [ ] **Step 3: `restore()` implementieren**

In `app/Services/DatabaseBackupService.php` den Import-Block erweitern:

```php
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
```

Nach `delete()` einfügen:

```php
/**
 * Restores every table in the dump. Runs in two passes — all DROP/CREATE
 * statements first, then all INSERT statements — so foreign-key
 * dependency order between tables never matters, regardless of which
 * order Schema::getTableListing() happened to return them in.
 *
 * @return array<int, string> human-readable list of actions performed
 */
public function restore(string $filename): array
{
    $safetyBackup = $this->create('prerestore');
    $actions = ["Sicherheits-Backup erstellt: {$safetyBackup}"];

    $sql = gzdecode(Storage::disk(self::DISK)->get(self::DIRECTORY.'/'.$filename));
    $statements = array_values(array_filter(
        array_map('trim', explode("\n", $sql)),
        static fn (string $s): bool => $s !== '',
    ));

    $structureStatements = array_values(array_filter(
        $statements,
        static fn (string $s): bool => ! str_starts_with($s, 'INSERT'),
    ));
    $dataStatements = array_values(array_filter(
        $statements,
        static fn (string $s): bool => str_starts_with($s, 'INSERT'),
    ));

    Schema::disableForeignKeyConstraints();

    try {
        foreach ([...$structureStatements, ...$dataStatements] as $statement) {
            DB::unprepared($statement);
        }
    } catch (Throwable $e) {
        throw new RuntimeException(
            "Restore aus {$filename} fehlgeschlagen: {$e->getMessage()} (Sicherheits-Backup: {$safetyBackup})",
            0,
            $e,
        );
    } finally {
        Schema::enableForeignKeyConstraints();
    }

    $actions[] = count($statements)." Statements aus {$filename} ausgeführt.";

    return $actions;
}
```

- [ ] **Step 4: Tests laufen lassen — müssen grün sein**

Run: `php artisan test tests/Unit/Services/DatabaseBackupServiceTest.php` (via WSL)
Expected: alle 10 PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/DatabaseBackupService.php tests/Unit/Services/DatabaseBackupServiceTest.php
git commit -m "Add two-phase restore with automatic pre-restore safety backup"
```

---

### Task 3: Controller-Aktionen und Routen

**Files:**
- Modify: `app/Http/Controllers/Admin/DatabaseController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/DatabaseControllerTest.php`

- [ ] **Step 1: Failing Tests schreiben**

In `tests/Feature/Admin/DatabaseControllerTest.php` den Import-Block um `use Illuminate\Support\Facades\Storage;` erweitern und in `setUp()`-Nähe (oder direkt in jedem neuen Test) `Storage::fake('local');` aufrufen. Ergänzen:

```php
#[Test]
public function admin_can_create_a_backup(): void
{
    Storage::fake('local');

    $this->actingAs($this->admin())
        ->post('/admin/database/backups')
        ->assertRedirect('/admin/database')
        ->assertSessionHas('success');

    $this->assertCount(1, Storage::disk('local')->files('backups'));
}

#[Test]
public function admin_can_download_a_backup(): void
{
    Storage::fake('local');
    $filename = (new DatabaseBackupService())->create();

    $this->actingAs($this->admin())
        ->get("/admin/database/backups/{$filename}/download")
        ->assertOk()
        ->assertHeader('Content-Disposition', "attachment; filename={$filename}");
}

#[Test]
public function admin_can_delete_a_backup(): void
{
    Storage::fake('local');
    $filename = (new DatabaseBackupService())->create();

    $this->actingAs($this->admin())
        ->post("/admin/database/backups/{$filename}/delete")
        ->assertRedirect('/admin/database')
        ->assertSessionHas('success');

    Storage::disk('local')->assertMissing("backups/{$filename}");
}

#[Test]
public function restore_requires_typed_confirmation(): void
{
    Storage::fake('local');
    $filename = (new DatabaseBackupService())->create();

    $this->from('/admin/database')
        ->actingAs($this->admin())
        ->post("/admin/database/backups/{$filename}/restore", ['confirmation' => 'wrong'])
        ->assertRedirect('/admin/database')
        ->assertSessionHasErrors('confirmation');
}

#[Test]
public function admin_can_restore_with_valid_confirmation(): void
{
    Storage::fake('local');
    $filename = (new DatabaseBackupService())->create();

    $this->actingAs($this->admin())
        ->post("/admin/database/backups/{$filename}/restore", ['confirmation' => 'RESTORE'])
        ->assertRedirect('/admin/database')
        ->assertSessionHas('success')
        ->assertSessionHas('output');
}

#[Test]
public function regular_member_cannot_manage_backups(): void
{
    Storage::fake('local');
    $filename = (new DatabaseBackupService())->create();
    $member = User::factory()->create(['status' => 'enabled']);

    $this->actingAs($member)->post('/admin/database/backups')->assertForbidden();
    $this->actingAs($member)->post("/admin/database/backups/{$filename}/delete")->assertForbidden();
    $this->actingAs($member)->post("/admin/database/backups/{$filename}/restore", ['confirmation' => 'RESTORE'])->assertForbidden();
}

#[Test]
public function an_invalid_backup_filename_in_the_url_is_rejected(): void
{
    $this->actingAs($this->admin())
        ->post('/admin/database/backups/../../etc/passwd/delete')
        ->assertNotFound();
}
```

Am Dateianfang von `tests/Feature/Admin/DatabaseControllerTest.php` den Import `use App\Services\DatabaseBackupService;` ergänzen.

- [ ] **Step 2: Tests laufen lassen — müssen fehlschlagen**

Run: `php artisan test tests/Feature/Admin/DatabaseControllerTest.php --filter=backup` (via WSL)
Expected: alle FAIL (Routen existieren nicht → 404 statt der erwarteten Responses).

- [ ] **Step 3: Routen ergänzen**

In `routes/web.php` nach der `database/repair/{table}`-Zeile:

```php
Route::post('database/backups', [DatabaseController::class, 'createBackup'])->name('database.backups.create');
Route::get('database/backups/{filename}/download', [DatabaseController::class, 'downloadBackup'])
    ->name('database.backups.download')
    ->where('filename', '^backup-\d{8}_\d{6}(-prerestore)?\.sql\.gz$');
Route::post('database/backups/{filename}/delete', [DatabaseController::class, 'deleteBackup'])
    ->name('database.backups.delete')
    ->where('filename', '^backup-\d{8}_\d{6}(-prerestore)?\.sql\.gz$');
Route::post('database/backups/{filename}/restore', [DatabaseController::class, 'restoreBackup'])
    ->name('database.backups.restore')
    ->where('filename', '^backup-\d{8}_\d{6}(-prerestore)?\.sql\.gz$');
```

- [ ] **Step 4: Controller-Actions ergänzen**

In `app/Http/Controllers/Admin/DatabaseController.php`:

Import-Block erweitern:

```php
use App\Services\DatabaseBackupService;
use App\Services\DatabaseSchemaChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
```

Konstruktor und Klassenkonstante erweitern:

```php
final class DatabaseController extends Controller
{
    private const MIGRATION_CONFIRMATION = 'MIGRATE';
    private const RESTORE_CONFIRMATION = 'RESTORE';

    public function __construct(
        private readonly DatabaseSchemaChecker $checker,
        private readonly DatabaseBackupService $backups,
    ) {}
```

`index()` erweitern:

```php
public function index(): View
{
    return view('admin.database.index', [
        'migrations' => $this->checker->migrationStatus(),
        'tables' => $this->checker->checkTables(),
        'drift' => $this->checker->driftReport(),
        'backups' => $this->backups->list(),
        'hasPending' => $this->checker->hasPendingMigrations(),
        'migrationConfirmationValue' => self::MIGRATION_CONFIRMATION,
        'restoreConfirmationValue' => self::RESTORE_CONFIRMATION,
    ]);
}
```

Am Ende der Klasse (vor der schließenden `}`) ergänzen:

```php
public function createBackup(): RedirectResponse
{
    try {
        $filename = $this->backups->create();
    } catch (Throwable $e) {
        report($e);

        return redirect()->route('admin.database.index')
            ->with('error', __('booking.admin.database.backup_create_failed', ['message' => $e->getMessage()]));
    }

    return redirect()->route('admin.database.index')
        ->with('success', __('booking.admin.database.backup_created', ['filename' => $filename]));
}

public function downloadBackup(string $filename): StreamedResponse
{
    return Storage::disk('local')->download('backups/'.$filename, $filename);
}

public function deleteBackup(string $filename): RedirectResponse
{
    $this->backups->delete($filename);

    return redirect()->route('admin.database.index')
        ->with('success', __('booking.admin.database.backup_deleted', ['filename' => $filename]));
}

public function restoreBackup(Request $request, string $filename): RedirectResponse
{
    $validator = Validator::make($request->all(), [
        'confirmation' => ['required', 'string'],
    ]);

    $validator->after(function ($validator) use ($request): void {
        if (strtoupper(trim((string) $request->input('confirmation'))) !== self::RESTORE_CONFIRMATION) {
            $validator->errors()->add(
                'confirmation',
                __('booking.admin.database.restore_confirmation_invalid', [
                    'value' => self::RESTORE_CONFIRMATION,
                ]),
            );
        }
    });

    if ($validator->fails()) {
        return redirect()->route('admin.database.index')
            ->withErrors($validator)
            ->withInput();
    }

    try {
        $actions = $this->backups->restore($filename);
    } catch (Throwable $e) {
        report($e);

        return redirect()->route('admin.database.index')
            ->with('error', __('booking.admin.database.restore_failed', ['message' => $e->getMessage()]));
    }

    return redirect()->route('admin.database.index')
        ->with('success', __('booking.admin.database.restore_done', ['filename' => $filename]))
        ->with('output', implode(PHP_EOL, $actions));
}
```

- [ ] **Step 5: Lang-Keys ergänzen**

`lang/de/booking/admin.php`, `'database'`-Abschnitt, nach `'drift_check_failed'`:

```php
'backups_heading' => 'Backups',
'backup_create_button' => 'Backup erstellen',
'backup_created' => 'Backup :filename wurde erstellt.',
'backup_create_failed' => 'Backup fehlgeschlagen: :message',
'backup_none' => 'Keine Backups vorhanden.',
'backup_filename' => 'Datei',
'backup_size' => 'Größe',
'backup_created_at' => 'Erstellt',
'backup_actions' => 'Aktionen',
'backup_download' => 'Herunterladen',
'backup_delete' => 'Löschen',
'backup_delete_confirm' => 'Dieses Backup wirklich löschen?',
'backup_deleted' => 'Backup :filename wurde gelöscht.',
'backup_restore' => 'Wiederherstellen',
'backup_restore_warning' => 'Dies überschreibt ALLE aktuellen Daten mit dem Inhalt dieses Backups. Vor dem Vorgang wird automatisch ein Sicherheits-Backup erstellt. Fortfahren?',
'backup_restore_confirm_placeholder' => 'RESTORE eingeben',
'restore_confirmation_invalid' => 'Bitte geben Sie zur Bestätigung exakt :value ein.',
'restore_done' => 'Backup :filename wurde wiederhergestellt.',
'restore_failed' => 'Wiederherstellung fehlgeschlagen: :message',
```

`lang/en/booking/admin.php` analog:

```php
'backups_heading' => 'Backups',
'backup_create_button' => 'Create backup',
'backup_created' => 'Backup :filename created.',
'backup_create_failed' => 'Backup failed: :message',
'backup_none' => 'No backups yet.',
'backup_filename' => 'File',
'backup_size' => 'Size',
'backup_created_at' => 'Created',
'backup_actions' => 'Actions',
'backup_download' => 'Download',
'backup_delete' => 'Delete',
'backup_delete_confirm' => 'Really delete this backup?',
'backup_deleted' => 'Backup :filename deleted.',
'backup_restore' => 'Restore',
'backup_restore_warning' => 'This overwrites ALL current data with this backup. A safety backup is created automatically first. Continue?',
'backup_restore_confirm_placeholder' => 'Type RESTORE',
'restore_confirmation_invalid' => 'Please type :value exactly to confirm.',
'restore_done' => 'Backup :filename restored.',
'restore_failed' => 'Restore failed: :message',
```

- [ ] **Step 6: Tests laufen lassen — müssen grün sein**

Run: `php artisan test tests/Feature/Admin/DatabaseControllerTest.php` (via WSL)
Expected: alle PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/DatabaseController.php routes/web.php lang/de/booking/admin.php lang/en/booking/admin.php tests/Feature/Admin/DatabaseControllerTest.php
git commit -m "Add backup create/download/delete/restore actions and routes"
```

---

### Task 4: UI-Panel „Backups"

**Files:**
- Modify: `resources/views/admin/database/index.blade.php`
- Test: `tests/Feature/Admin/DatabaseControllerTest.php`

- [ ] **Step 1: Failing Test schreiben**

In `tests/Feature/Admin/DatabaseControllerTest.php`:

```php
#[Test]
public function database_page_lists_an_existing_backup(): void
{
    Storage::fake('local');
    $filename = (new DatabaseBackupService())->create();

    $this->actingAs($this->admin())
        ->get('/admin/database')
        ->assertOk()
        ->assertSee($filename)
        ->assertSee(route('admin.database.backups.download', $filename));
}

#[Test]
public function database_page_shows_no_backups_message_when_empty(): void
{
    Storage::fake('local');

    $this->actingAs($this->admin())
        ->get('/admin/database')
        ->assertOk()
        ->assertSee(__('booking.admin.database.backup_none'));
}
```

- [ ] **Step 2: Tests laufen lassen — müssen fehlschlagen**

Run: `php artisan test tests/Feature/Admin/DatabaseControllerTest.php --filter=database_page_lists_an_existing_backup` (via WSL)
Expected: FAIL — die View kennt `$backups` bereits (aus Task 3), aber es gibt noch kein Panel, das den Dateinamen/Link rendert, daher `assertSee` schlägt fehl.

- [ ] **Step 3: Panel in die View einfügen**

In `resources/views/admin/database/index.blade.php` nach dem „Schema-Abweichungen"-Panel, vor dem abschließenden `</div>` und `@endsection`, einfügen:

```blade
<div class="bg-white rounded-xl border border-[#e0ddd7] shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-[#f0ede6] flex items-center justify-between gap-4">
        <h2 class="text-base font-semibold text-[#151515]" style="font-family: var(--font-display)">{{ __('booking.admin.database.backups_heading') }}</h2>
        <form method="POST" action="{{ route('admin.database.backups.create') }}">
            @csrf
            <button type="submit" class="bg-[#151515] hover:bg-[#2a2a2a] text-white text-sm font-medium px-4 py-2 rounded transition-colors">{{ __('booking.admin.database.backup_create_button') }}</button>
        </form>
    </div>
    <div class="px-6 py-5">
        @if(empty($backups))
            <p class="text-sm text-[#6a6e73]">{{ __('booking.admin.database.backup_none') }}</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-[#6a6e73] border-b border-[#f0ede6]">
                        <th class="py-2 pr-4">{{ __('booking.admin.database.backup_filename') }}</th>
                        <th class="py-2 pr-4">{{ __('booking.admin.database.backup_size') }}</th>
                        <th class="py-2 pr-4">{{ __('booking.admin.database.backup_created_at') }}</th>
                        <th class="py-2">{{ __('booking.admin.database.backup_actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($backups as $backup)
                        <tr class="border-b border-[#f5f3ef] align-top">
                            <td class="py-2 pr-4 font-mono text-xs">{{ $backup['filename'] }}</td>
                            <td class="py-2 pr-4 text-xs text-[#6a6e73]">{{ number_format($backup['sizeBytes'] / 1024, 1) }} KB</td>
                            <td class="py-2 pr-4 text-xs text-[#6a6e73]">{{ $backup['createdAt']->format('d.m.Y H:i:s') }}</td>
                            <td class="py-2">
                                <div class="flex flex-col gap-2">
                                    <div class="flex gap-3">
                                        <a href="{{ route('admin.database.backups.download', $backup['filename']) }}" class="text-xs font-medium text-[#151515] underline">{{ __('booking.admin.database.backup_download') }}</a>
                                        <form method="POST" action="{{ route('admin.database.backups.delete', $backup['filename']) }}" onsubmit="return confirm({{ Js::from(__('booking.admin.database.backup_delete_confirm')) }})">
                                            @csrf
                                            <button type="submit" class="text-xs font-medium text-[#c9190b] underline">{{ __('booking.admin.database.backup_delete') }}</button>
                                        </form>
                                    </div>
                                    <form method="POST" action="{{ route('admin.database.backups.restore', $backup['filename']) }}" onsubmit="return confirm({{ Js::from(__('booking.admin.database.backup_restore_warning')) }})">
                                        @csrf
                                        <div class="flex gap-2">
                                            <input
                                                type="text"
                                                name="confirmation"
                                                placeholder="{{ __('booking.admin.database.backup_restore_confirm_placeholder') }}"
                                                class="ui-input text-xs"
                                                autocomplete="off"
                                                spellcheck="false">
                                            <button type="submit" class="bg-[#bf4316] hover:bg-[#9e3412] text-white text-xs font-medium px-3 py-1.5 rounded transition-colors whitespace-nowrap">{{ __('booking.admin.database.backup_restore') }}</button>
                                        </div>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
```

- [ ] **Step 4: Tests laufen lassen — müssen grün sein**

Run: `php artisan test tests/Feature/Admin/DatabaseControllerTest.php` (via WSL)
Expected: alle PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/admin/database/index.blade.php tests/Feature/Admin/DatabaseControllerTest.php
git commit -m "Add backups panel to admin/database"
```

---

### Task 5: Gesamte Suite & schreibgeschützte Verifikation gegen die echte DB

- [ ] **Step 1: Komplette Testsuite**

Run: `wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test 2>&1 | tail -10"`
Expected: alle Suiten PASS, keine Regressionen (Ausgangsbasis 404 passed vor dieser Arbeit).

- [ ] **Step 2: Backup gegen die echte `booking_local`-DB erstellen und inhaltlich prüfen (schreibend, aber additiv — erzeugt nur eine neue Datei, ändert keine bestehenden Daten)**

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan tinker --execute='echo (new App\Services\DatabaseBackupService())->create();'"
```

Erwartung: gibt einen Dateinamen `backup-<Zeitstempel>.sql.gz` zurück. Danach prüfen:

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && ls -la storage/app/backups/ && php -r 'echo substr(gzdecode(file_get_contents(glob(\"storage/app/backups/*.sql.gz\")[0])), 0, 500);'"
```

Erwartung: Datei existiert, Inhalt beginnt mit `DROP TABLE IF EXISTS` gefolgt von einem plausiblen `CREATE TABLE`-Statement für eine der echten Tabellen (z.B. `bs_bookings`).

**Restore NICHT gegen die echte Produktions-DB testen** — das ist der eine Punkt in diesem Plan, der bewusst nur bis „Dump erstellen und inhaltlich prüfen" geht. Ein echter Restore-Test würde reale Vereinsdaten überschreiben; die Restore-Logik ist bereits durch den SQLite-Roundtrip-Test in Task 2 automatisiert abgedeckt.

- [ ] **Step 3: Manuell erzeugtes Test-Backup wieder löschen**

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && rm storage/app/backups/*.sql.gz"
```

- [ ] **Step 4: Abschluss**

Superpowers-Skill `verification-before-completion` beachten: Testausgabe und den Inhalt des manuell erzeugten Dumps als Beleg festhalten, dann fertig melden.
