# Schema-Drift-Erkennung (Stufe 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `/admin/database` erkennt und zeigt Schema-Drift an: unerwartete Extra-Spalten, Typ-/Nullable-/Default-Abweichungen bestehender Spalten, sowie fehlende und unerwartete Indizes (inkl. zusammengesetzter Unique-Constraints) — rein lesend, keine Reparatur.

**Architecture:** `DatabaseSchemaChecker::driftReport()` baut pro existierender Tabelle eine flüchtige „Probe"-Tabelle aus denselben Blueprint-Closures wie das bestehende `columnDefinitions()`-Manifest (plus einem neuen `tableLevelConstraints()`-Manifest für zusammengesetzte Unique-Indizes). Der Soll-Zustand wird über `Schema::getColumns()`/`Schema::getIndexes()` der Probe-Tabelle ermittelt und mit der echten Tabelle verglichen — beides über denselben Connection-Treiber (SQLite in Tests, MySQL in Produktion), dadurch automatisch treiberkorrekt ohne hartkodierte Typ-Strings. Ergebnisse sind strukturierte Funde (`kind` + Rohdaten), die Übersetzung ins Deutsche/Englische passiert wie beim Rest der Seite über `lang/*/booking/admin.php`.

**Tech Stack:** Laravel 13 (Schema::getColumns()/getIndexes() sind eingebaute Methoden, kein doctrine/dbal nötig), PHPUnit gegen SQLite in-memory (`phpunit.xml`). PHP nur via WSL ausführen.

**Spec:** `docs/superpowers/specs/2026-07-05-admin-database-drift-detection-design.md`

**Files:**
- Modify: `app/Services/DatabaseSchemaChecker.php`
- Modify: `app/Http/Controllers/Admin/DatabaseController.php`
- Modify: `resources/views/admin/database/index.blade.php`
- Modify: `lang/de/booking/admin.php`, `lang/en/booking/admin.php`
- Test: `tests/Unit/Services/DatabaseSchemaCheckerTest.php`
- Test: `tests/Feature/Admin/DatabaseControllerTest.php`

**Alle Testkommandos via WSL:**
`wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test <pfad> 2>&1 | tail -20"`

---

### Task 1: Probe-Mechanik + Extra-Spalten-Erkennung

**Files:**
- Modify: `app/Services/DatabaseSchemaChecker.php`
- Test: `tests/Unit/Services/DatabaseSchemaCheckerTest.php`

- [ ] **Step 1: Failing Tests schreiben**

In `tests/Unit/Services/DatabaseSchemaCheckerTest.php` ergänzen (nach dem letzten bestehenden Test, vor der schließenden `}` der Klasse):

```php
#[Test]
public function drift_report_is_empty_for_a_healthy_schema(): void
{
    $this->assertSame([], $this->checker->driftReport());
}

#[Test]
public function drift_report_flags_an_unexpected_extra_column(): void
{
    Schema::table('bs_users', function (Blueprint $table): void {
        $table->string('legacy_note', 32)->nullable();
    });

    $finding = collect($this->checker->driftReport())
        ->firstWhere('kind', 'extra_column');

    $this->assertNotNull($finding);
    $this->assertSame('bs_users', $finding['table']);
    $this->assertSame('legacy_note', $finding['column']);
}

#[Test]
public function drift_report_does_not_leak_probe_tables(): void
{
    $this->checker->driftReport();

    $this->assertFalse(Schema::hasTable('__drift_probe__bs_users'));
}

#[Test]
public function drift_report_does_not_double_report_a_missing_table(): void
{
    Schema::drop('bs_events_meta');

    $findings = collect($this->checker->driftReport())->where('table', 'bs_events_meta');

    $this->assertCount(0, $findings);
}
```

- [ ] **Step 2: Tests laufen lassen — müssen fehlschlagen**

Run: `php artisan test tests/Unit/Services/DatabaseSchemaCheckerTest.php --filter=drift_report` (via WSL)
Expected: alle vier FAIL mit `Call to undefined method App\Services\DatabaseSchemaChecker::driftReport()`.

- [ ] **Step 3: `tableLevelConstraints()`, `probeSignature()` und `driftReport()` implementieren**

In `app/Services/DatabaseSchemaChecker.php` den Import-Block erweitern:

```php
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
```

Nach der Methode `knowsTable()` (vor `repairTable()`) einfügen:

```php
/**
 * Composite (multi-column) constraints that live outside the per-column
 * closures in columnDefinitions() — mirrored from the create migrations'
 * table-level $table->unique([...]) calls.
 *
 * @return array<string, Closure(Blueprint): mixed>
 */
private static function tableLevelConstraints(): array
{
    return [
        'fee_category_rates' => fn (Blueprint $t) => $t->unique(['fee_category_id', 'fiscal_year_id']),
        'member_dues' => fn (Blueprint $t) => $t->unique(['member_id', 'fiscal_year_id']),
        'payment_dues' => fn (Blueprint $t) => $t->unique(['payment_id', 'member_due_id']),
    ];
}

/**
 * Builds a throwaway table from the same closures used to validate a real
 * table, so the expected column/index signature is always computed by the
 * current DB driver (SQLite in tests, MySQL in production) instead of a
 * hardcoded type string that would only be correct for one driver.
 *
 * @param array<string, Closure(Blueprint): mixed> $definitions
 * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
 */
private function probeSignature(string $table, array $definitions): array
{
    $probeTable = "__drift_probe__{$table}";
    Schema::dropIfExists($probeTable);

    try {
        Schema::create($probeTable, function (Blueprint $blueprint) use ($definitions, $table): void {
            foreach ($definitions as $define) {
                $define($blueprint);
            }

            $constraint = self::tableLevelConstraints()[$table] ?? null;
            if ($constraint !== null) {
                $constraint($blueprint);
            }
        });

        return [Schema::getColumns($probeTable), Schema::getIndexes($probeTable)];
    } finally {
        Schema::dropIfExists($probeTable);
    }
}

/**
 * Compares every existing table against its expected signature (built via
 * probeSignature()) and reports what doesn't match. Missing tables are
 * intentionally not reported here — checkTables() already covers those.
 *
 * @return array<int, array<string, mixed>>
 */
public function driftReport(): array
{
    $findings = [];

    foreach (self::columnDefinitions() as $table => $definitions) {
        if (! Schema::hasTable($table)) {
            continue;
        }

        try {
            [$expectedColumns, $expectedIndexes] = $this->probeSignature($table, $definitions);
        } catch (Throwable $e) {
            $findings[] = ['table' => $table, 'kind' => 'drift_check_failed', 'message' => $e->getMessage()];
            continue;
        }

        $expectedByName = collect($expectedColumns)->keyBy('name');

        foreach (Schema::getColumns($table) as $column) {
            if (! $expectedByName->has($column['name'])) {
                $findings[] = ['table' => $table, 'kind' => 'extra_column', 'column' => $column['name']];
            }
        }
    }

    return $findings;
}
```

Hinweis zum `catch (Throwable $e)`-Zweig (`drift_check_failed`): Dieser Pfad greift, wenn der Aufbau der Probe-Tabelle selbst fehlschlägt (z.B. `constrained()` verweist auf eine fehlende Fremdtabelle). Ein treiberunabhängiger Test dafür ist unpraktisch, da SQLite Fremdschlüssel-Ziele beim Anlegen anders als MySQL nicht validiert — der Zweig bleibt bewusst ungetestet und wird stattdessen in Task 5 (Schritt 2) manuell gegen die echte MySQL-DB verifiziert.

- [ ] **Step 4: Tests laufen lassen — müssen grün sein**

Run: `php artisan test tests/Unit/Services/DatabaseSchemaCheckerTest.php --filter=drift_report` (via WSL)
Expected: alle vier PASS.

- [ ] **Step 5: Restliche Checker-Tests laufen lassen (Regressionscheck)**

Run: `php artisan test tests/Unit/Services/DatabaseSchemaCheckerTest.php` (via WSL)
Expected: alle PASS (17 Tests insgesamt).

- [ ] **Step 6: Commit**

```bash
git add app/Services/DatabaseSchemaChecker.php tests/Unit/Services/DatabaseSchemaCheckerTest.php
git commit -m "Add extra-column drift detection to DatabaseSchemaChecker via probe tables"
```

---

### Task 2: Typ-/Nullable-/Default-Abweichungen

**Files:**
- Modify: `app/Services/DatabaseSchemaChecker.php`
- Test: `tests/Unit/Services/DatabaseSchemaCheckerTest.php`

- [ ] **Step 1: Failing Test schreiben**

In `tests/Unit/Services/DatabaseSchemaCheckerTest.php`:

```php
#[Test]
public function drift_report_flags_a_column_with_the_wrong_type(): void
{
    Schema::table('bs_users', function (Blueprint $table): void {
        $table->dropColumn('alias');
    });
    Schema::table('bs_users', function (Blueprint $table): void {
        $table->string('alias', 32)->nullable();
    });

    $finding = collect($this->checker->driftReport())
        ->firstWhere('kind', 'type_mismatch');

    $this->assertNotNull($finding);
    $this->assertSame('bs_users', $finding['table']);
    $this->assertSame('alias', $finding['column']);
    $this->assertArrayHasKey('expected', $finding);
    $this->assertArrayHasKey('actual', $finding);
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `php artisan test tests/Unit/Services/DatabaseSchemaCheckerTest.php --filter=drift_report_flags_a_column_with_the_wrong_type` (via WSL)
Expected: FAIL, `firstWhere('kind', 'type_mismatch')` liefert `null` (Typabgleich existiert noch nicht).

- [ ] **Step 3: `driftReport()` um Typvergleich erweitern**

In `app/Services/DatabaseSchemaChecker.php` die `foreach (Schema::getColumns($table) as $column) { ... }`-Schleife in `driftReport()` ersetzen durch:

```php
foreach (Schema::getColumns($table) as $column) {
    $expected = $expectedByName->get($column['name']);

    if ($expected === null) {
        $findings[] = ['table' => $table, 'kind' => 'extra_column', 'column' => $column['name']];

        continue;
    }

    if ($column['type'] !== $expected['type']
        || $column['nullable'] !== $expected['nullable']
        || $column['default'] !== $expected['default']
    ) {
        $findings[] = [
            'table' => $table,
            'kind' => 'type_mismatch',
            'column' => $column['name'],
            'expected' => $this->describeColumn($expected),
            'actual' => $this->describeColumn($column),
        ];
    }
}
```

Direkt darunter (vor der schließenden `}` der Klasse, nach `driftReport()`) die Hilfsmethode ergänzen:

```php
/**
 * @param array<string, mixed> $column
 */
private function describeColumn(array $column): string
{
    $nullable = $column['nullable'] ? 'nullable' : 'not null';
    $default = $column['default'] !== null ? ", default {$column['default']}" : '';

    return "{$column['type']} {$nullable}{$default}";
}
```

- [ ] **Step 4: Tests laufen lassen — müssen grün sein**

Run: `php artisan test tests/Unit/Services/DatabaseSchemaCheckerTest.php` (via WSL)
Expected: alle PASS (18 Tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/DatabaseSchemaChecker.php tests/Unit/Services/DatabaseSchemaCheckerTest.php
git commit -m "Add type/nullable/default drift detection to DatabaseSchemaChecker"
```

---

### Task 3: Fehlende und unerwartete Indizes (inkl. zusammengesetzter Constraints)

**Files:**
- Modify: `app/Services/DatabaseSchemaChecker.php`
- Test: `tests/Unit/Services/DatabaseSchemaCheckerTest.php`

- [ ] **Step 1: Failing Tests schreiben**

In `tests/Unit/Services/DatabaseSchemaCheckerTest.php`:

```php
#[Test]
public function drift_report_flags_a_missing_single_column_index(): void
{
    Schema::table('bs_users_meta', function (Blueprint $table): void {
        $table->dropIndex(['key']);
    });

    $finding = collect($this->checker->driftReport())
        ->firstWhere('kind', 'missing_index');

    $this->assertNotNull($finding);
    $this->assertSame('bs_users_meta', $finding['table']);
    $this->assertSame('key', $finding['signature']);
}

#[Test]
public function drift_report_flags_an_unexpected_extra_index(): void
{
    Schema::table('bs_users_meta', function (Blueprint $table): void {
        $table->index('value', 'bs_users_meta_value_test_index');
    });

    $finding = collect($this->checker->driftReport())
        ->firstWhere('kind', 'extra_index');

    $this->assertNotNull($finding);
    $this->assertSame('bs_users_meta', $finding['table']);
    $this->assertSame('value', $finding['signature']);
}

#[Test]
public function drift_report_flags_a_missing_composite_unique_index(): void
{
    Schema::table('member_dues', function (Blueprint $table): void {
        $table->dropUnique(['member_id', 'fiscal_year_id']);
    });

    $finding = collect($this->checker->driftReport())
        ->firstWhere('kind', 'missing_index');

    $this->assertNotNull($finding);
    $this->assertSame('member_dues', $finding['table']);
    $this->assertSame('fiscal_year_id,member_id|unique', $finding['signature']);
}
```

- [ ] **Step 2: Tests laufen lassen — müssen fehlschlagen**

Run: `php artisan test tests/Unit/Services/DatabaseSchemaCheckerTest.php --filter=drift_report_flags_a_missing_single_column_index` (via WSL)
Expected: FAIL (`missing_index` Kind existiert noch nicht).

- [ ] **Step 3: `driftReport()` um Indexvergleich erweitern**

In `app/Services/DatabaseSchemaChecker.php` direkt nach der Spalten-`foreach`-Schleife in `driftReport()` (innerhalb derselben `foreach (self::columnDefinitions() as $table => $definitions)`-Schleife, vor deren schließender `}`) ergänzen:

```php
$actualIndexSignatures = $this->normalizeIndexes(Schema::getIndexes($table));
$expectedIndexSignatures = $this->normalizeIndexes($expectedIndexes);

foreach (array_diff($expectedIndexSignatures, $actualIndexSignatures) as $signature) {
    $findings[] = ['table' => $table, 'kind' => 'missing_index', 'signature' => $signature];
}

foreach (array_diff($actualIndexSignatures, $expectedIndexSignatures) as $signature) {
    $findings[] = ['table' => $table, 'kind' => 'extra_index', 'signature' => $signature];
}
```

Direkt nach `describeColumn()` die Hilfsmethode ergänzen:

```php
/**
 * Reduces an index list to a comparable signature (sorted columns + unique
 * flag) since the auto-generated index name differs between the probe
 * table and the real table (they have different table-name prefixes).
 * Primary keys are excluded — already covered by column comparison.
 *
 * @param array<int, array<string, mixed>> $indexes
 * @return array<int, string>
 */
private function normalizeIndexes(array $indexes): array
{
    $signatures = [];

    foreach ($indexes as $index) {
        if ($index['primary']) {
            continue;
        }

        $columns = $index['columns'];
        sort($columns);
        $signatures[] = implode(',', $columns) . '|' . ($index['unique'] ? 'unique' : 'index');
    }

    sort($signatures);

    return $signatures;
}
```

Hinweis: Bei Single-Column-Indizes ergibt `implode(',', $columns) . '|' . (...)` für einen nicht-unique Index auf `key` das Signal `key|index`. Der Test in Step 1 erwartet `$finding['signature'] === 'key'` — das ist falsch, siehe Korrektur unten.

- [ ] **Step 3b: Testerwartung an das tatsächliche Signaturformat anpassen**

Die Signatur enthält immer das `|index`- oder `|unique`-Suffix. In `tests/Unit/Services/DatabaseSchemaCheckerTest.php` die beiden betroffenen Assertions korrigieren:

```php
$this->assertSame('key|index', $finding['signature']);
```

(in `drift_report_flags_a_missing_single_column_index`) und

```php
$this->assertSame('value|index', $finding['signature']);
```

(in `drift_report_flags_an_unexpected_extra_index`). Der Composite-Test (`fiscal_year_id,member_id|unique`) ist bereits korrekt.

- [ ] **Step 4: Tests laufen lassen — müssen grün sein**

Run: `php artisan test tests/Unit/Services/DatabaseSchemaCheckerTest.php` (via WSL)
Expected: alle PASS (21 Tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/DatabaseSchemaChecker.php tests/Unit/Services/DatabaseSchemaCheckerTest.php
git commit -m "Add missing/extra index drift detection to DatabaseSchemaChecker"
```

---

### Task 4: Anzeige auf /admin/database

**Files:**
- Modify: `app/Http/Controllers/Admin/DatabaseController.php`
- Modify: `resources/views/admin/database/index.blade.php`
- Modify: `lang/de/booking/admin.php`, `lang/en/booking/admin.php`
- Test: `tests/Feature/Admin/DatabaseControllerTest.php`

- [ ] **Step 1: Failing Tests schreiben**

In `tests/Feature/Admin/DatabaseControllerTest.php` ergänzen:

```php
#[Test]
public function database_page_shows_no_drift_message_for_a_healthy_schema(): void
{
    $this->actingAs($this->admin())
        ->get('/admin/database')
        ->assertOk()
        ->assertSee(__('booking.admin.database.drift_none'));
}

#[Test]
public function database_page_shows_an_extra_column_drift_finding(): void
{
    $admin = $this->admin();

    Schema::table('bs_users', function (Blueprint $table): void {
        $table->string('legacy_note', 32)->nullable();
    });

    $this->actingAs($admin)
        ->get('/admin/database')
        ->assertOk()
        ->assertSee('bs_users')
        ->assertSee('legacy_note');
}
```

- [ ] **Step 2: Tests laufen lassen — müssen fehlschlagen**

Run: `php artisan test tests/Feature/Admin/DatabaseControllerTest.php --filter=drift` (via WSL)
Expected: erster Test FAIL (Lang-Key `drift_none` existiert nicht, `__()` gibt den Key selbst zurück statt echtem Text — `assertSee` schlägt fehl, da der View die Variable `$drift` noch nicht kennt und einen Fehler wirft).

- [ ] **Step 3: Controller erweitert `driftReport()` an die View**

In `app/Http/Controllers/Admin/DatabaseController.php` die `index()`-Methode anpassen:

```php
public function index(): View
{
    return view('admin.database.index', [
        'migrations' => $this->checker->migrationStatus(),
        'tables' => $this->checker->checkTables(),
        'drift' => $this->checker->driftReport(),
        'hasPending' => $this->checker->hasPendingMigrations(),
        'migrationConfirmationValue' => self::MIGRATION_CONFIRMATION,
    ]);
}
```

- [ ] **Step 4: Lang-Keys ergänzen**

In `lang/de/booking/admin.php`, `'database'`-Abschnitt, nach `'repair_preview_columns'`:

```php
'drift_heading' => 'Schema-Abweichungen',
'drift_none' => 'Keine Abweichungen gefunden.',
'drift_extra_column' => 'Unerwartete Spalte :column',
'drift_type_mismatch' => 'Spalte :column: erwartet :expected, ist :actual',
'drift_missing_index' => 'Fehlender Index (:signature)',
'drift_extra_index' => 'Unerwarteter Index (:signature)',
'drift_check_failed' => 'Schema-Abgleich fehlgeschlagen: :message',
```

In `lang/en/booking/admin.php` analog:

```php
'drift_heading' => 'Schema Drift',
'drift_none' => 'No drift found.',
'drift_extra_column' => 'Unexpected column :column',
'drift_type_mismatch' => 'Column :column: expected :expected, got :actual',
'drift_missing_index' => 'Missing index (:signature)',
'drift_extra_index' => 'Unexpected index (:signature)',
'drift_check_failed' => 'Schema check failed: :message',
```

- [ ] **Step 5: View: neuer Abschnitt „Schema-Abweichungen"**

In `resources/views/admin/database/index.blade.php` nach dem schließenden `</div>` des „Tabellen"-Panels (unmittelbar vor dem abschließenden `</div>` des äußeren `flex flex-col gap-6`-Containers) einfügen:

```blade
<div class="bg-white rounded-xl border border-[#e0ddd7] shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-[#f0ede6]">
        <h2 class="text-base font-semibold text-[#151515]" style="font-family: var(--font-display)">{{ __('booking.admin.database.drift_heading') }}</h2>
    </div>
    <div class="px-6 py-5">
        @if(empty($drift))
            <p class="text-sm text-[#6a6e73]">{{ __('booking.admin.database.drift_none') }}</p>
        @else
            <ul class="flex flex-col gap-2 text-sm">
                @foreach($drift as $finding)
                    <li>
                        <span class="font-mono text-xs text-[#6a6e73]">{{ $finding['table'] }}</span>
                        —
                        @switch($finding['kind'])
                            @case('extra_column')
                                {{ __('booking.admin.database.drift_extra_column', ['column' => $finding['column']]) }}
                                @break
                            @case('type_mismatch')
                                {{ __('booking.admin.database.drift_type_mismatch', ['column' => $finding['column'], 'expected' => $finding['expected'], 'actual' => $finding['actual']]) }}
                                @break
                            @case('missing_index')
                                {{ __('booking.admin.database.drift_missing_index', ['signature' => $finding['signature']]) }}
                                @break
                            @case('extra_index')
                                {{ __('booking.admin.database.drift_extra_index', ['signature' => $finding['signature']]) }}
                                @break
                            @case('drift_check_failed')
                                {{ __('booking.admin.database.drift_check_failed', ['message' => $finding['message']]) }}
                                @break
                        @endswitch
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
```

- [ ] **Step 6: Tests laufen lassen — müssen grün sein**

Run: `php artisan test tests/Feature/Admin/DatabaseControllerTest.php` (via WSL)
Expected: alle PASS (17 Tests insgesamt in dieser Datei).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/DatabaseController.php resources/views/admin/database/index.blade.php lang/de/booking/admin.php lang/en/booking/admin.php tests/Feature/Admin/DatabaseControllerTest.php
git commit -m "Show schema drift findings on admin/database"
```

---

### Task 5: Gesamte Suite & manuelle Verifikation

- [ ] **Step 1: Komplette Testsuite**

Run: `wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test 2>&1 | tail -10"`
Expected: alle Suiten PASS, keine Regressionen (Ausgangsbasis war 384 passed vor dieser Arbeit; erwartet ca. 384 + 11 neue Tests).

- [ ] **Step 2: Read-only Verifikation gegen die echte `booking_local`-DB**

Per Tinker (nur lesend, keine Schreiboperation außer den selbstlöschenden Probe-Tabellen):

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan tinker --execute='dump(app(App\Services\DatabaseSchemaChecker::class)->driftReport());'"
```

Erwartung: Ergebnis durchsehen. Findings sind KEIN Fehler dieser Implementierung — sie zeigen echten, vorher unsichtbaren Zustand der Legacy-DB. Vor dem Abschluss dem Nutzer das Ergebnis mitteilen (insbesondere falls `drift_check_failed`-Einträge auftreten, z.B. weil eine FK-Zieltabelle fehlt).

- [ ] **Step 3: Abschluss**

Superpowers-Skill `verification-before-completion` beachten: Testausgabe und Tinker-Ergebnis als Beleg festhalten, dann fertig melden.
