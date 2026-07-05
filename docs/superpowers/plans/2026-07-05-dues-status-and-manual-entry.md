# Beiträge Status/Columns/Manual-Entry Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a manually-controlled `status` field (Ausstehend/Bezahlt/Überfällig), `teilbetrag`/`kommentar`/`faellig_am` columns, cascading "Bezahlt bis" advance-payment marking, and an inline manual-entry row to the Beiträge (dues) screen, replacing the separate create page — without touching the existing exact-match `Payment` flow.

**Architecture:** One migration adds five nullable columns to `member_dues`. `MemberDueController` gains `updateField` (per-cell autosave, mirroring `MemberController::updateField`) and `markPaidThrough` (cascading create/update across intervening fiscal years) methods; `create()`/`dues/create.blade.php` are deleted in favor of an inline compose row on the index page. `PaymentController::store()` gains one side effect: successfully-attached dues get `status='bezahlt'`. All new UI editing follows the existing per-cell Alpine autosave pattern already used in `members/index.blade.php` — no row-level edit mode.

**Tech Stack:** Laravel (Eloquent, Blade), Alpine.js, PHPUnit feature/unit tests.

---

## File structure

- Create: `app/Modules/Members/Database/Migrations/2026_07_06_090001_add_status_columns_to_member_dues_table.php`
- Modify: `app/Modules/Members/Models/MemberDue.php` — fillable, casts, `bezahltBisFiscalYear()` relation, `STATUSES` const, `displayStatus()` accessor.
- Modify: `app/Modules/Members/Http/Controllers/MemberDueController.php` — defaults on create, new `updateField`/`markPaidThrough`, `index()` gains `$members`/`$feeCategories`, `create()` removed.
- Modify: `app/Modules/Members/Http/Controllers/PaymentController.php` — mark covered dues `bezahlt` on payment.
- Modify: `app/Modules/Members/routes.php` — remove `dues/create` GET route, add two `PATCH` routes.
- Modify: `app/Modules/Members/resources/views/dues/index.blade.php` — new columns + inline manual-entry row.
- Delete: `app/Modules/Members/resources/views/dues/create.blade.php`.
- Modify: `app/Modules/Members/resources/lang/de/admin.php` and `.../en/admin.php` — new labels.
- Modify: `resources/css/app.css` — add `.ui-badge-warning`.
- Create: `tests/Feature/Modules/Members/MemberDueFieldUpdateTest.php`
- Create: `tests/Feature/Modules/Members/MemberDuePaidThroughTest.php`
- Create: `tests/Unit/Modules/Members/MemberDueDisplayStatusTest.php`
- Modify: `tests/Feature/Modules/Members/PaymentManagementTest.php`, `MemberDueGenerationTest.php`, `MemberDueManualCreationTest.php`

---

### Task 1: Migration and model support

**Files:**
- Create: `app/Modules/Members/Database/Migrations/2026_07_06_090001_add_status_columns_to_member_dues_table.php`
- Modify: `app/Modules/Members/Models/MemberDue.php`
- Test: `tests/Unit/Modules/Members/MemberDueDisplayStatusTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members;

use App\Modules\Members\Models\FeeCategory;
use App\Modules\Members\Models\FiscalYear;
use App\Modules\Members\Models\Member;
use App\Modules\Members\Models\MemberDue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberDueDisplayStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeDue(array $overrides = []): MemberDue
    {
        $member = Member::factory()->create();
        $category = FeeCategory::create(['name' => 'Vollmitglied']);
        $fiscalYear = FiscalYear::create(['name' => '2025/26', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31']);

        return MemberDue::create(array_merge([
            'member_id' => $member->id,
            'fiscal_year_id' => $fiscalYear->id,
            'fee_category_id' => $category->id,
            'amount' => 120,
            'status' => 'ausstehend',
        ], $overrides));
    }

    #[Test]
    public function ausstehend_with_a_past_due_date_displays_as_ueberfaellig(): void
    {
        $due = $this->makeDue(['status' => 'ausstehend', 'faellig_am' => '2020-01-01']);

        $this->assertSame('ueberfaellig', $due->displayStatus());
    }

    #[Test]
    public function ausstehend_with_a_future_due_date_displays_as_ausstehend(): void
    {
        $due = $this->makeDue(['status' => 'ausstehend', 'faellig_am' => '2099-01-01']);

        $this->assertSame('ausstehend', $due->displayStatus());
    }

    #[Test]
    public function ausstehend_with_no_due_date_displays_as_ausstehend(): void
    {
        $due = $this->makeDue(['status' => 'ausstehend', 'faellig_am' => null]);

        $this->assertSame('ausstehend', $due->displayStatus());
    }

    #[Test]
    public function bezahlt_with_a_past_due_date_still_displays_as_bezahlt(): void
    {
        $due = $this->makeDue(['status' => 'bezahlt', 'faellig_am' => '2020-01-01']);

        $this->assertSame('bezahlt', $due->displayStatus());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run (via WSL, per project convention): `wsl php artisan test tests/Unit/Modules/Members/MemberDueDisplayStatusTest.php`
Expected: FAIL — `status`/`faellig_am` columns don't exist yet, or `displayStatus()` is undefined.

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_dues', function (Blueprint $table) {
            $table->string('status')->default('ausstehend')->after('amount');
            $table->decimal('teilbetrag', 8, 2)->nullable()->after('status');
            $table->text('kommentar')->nullable()->after('teilbetrag');
            $table->foreignId('bezahlt_bis_fiscal_year_id')->nullable()->after('kommentar')
                ->constrained('fiscal_years')->nullOnDelete();
            $table->date('faellig_am')->nullable()->after('bezahlt_bis_fiscal_year_id');
        });
    }

    public function down(): void
    {
        Schema::table('member_dues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bezahlt_bis_fiscal_year_id');
            $table->dropColumn(['status', 'teilbetrag', 'kommentar', 'faellig_am']);
        });
    }
};
```

- [ ] **Step 4: Update the model**

Current (`app/Modules/Members/Models/MemberDue.php`):
```php
class MemberDue extends Model
{
    protected $fillable = ['member_id', 'fiscal_year_id', 'fee_category_id', 'amount'];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function feeCategory(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class);
    }

    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(Payment::class, 'payment_dues');
    }

    public function isPaid(): bool
    {
        return $this->payments()->exists();
    }
}
```

Replace with:
```php
class MemberDue extends Model
{
    public const STATUSES = ['ausstehend', 'bezahlt', 'ueberfaellig'];

    protected $fillable = [
        'member_id', 'fiscal_year_id', 'fee_category_id', 'amount',
        'status', 'teilbetrag', 'kommentar', 'bezahlt_bis_fiscal_year_id', 'faellig_am',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'teilbetrag' => 'decimal:2',
        'faellig_am' => 'date',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function feeCategory(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class);
    }

    public function bezahltBisFiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'bezahlt_bis_fiscal_year_id');
    }

    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(Payment::class, 'payment_dues');
    }

    public function isPaid(): bool
    {
        return $this->payments()->exists();
    }

    /** Der angezeigte Status: "ausstehend" mit verstrichenem Fälligkeitsdatum gilt automatisch als "überfällig". */
    public function displayStatus(): string
    {
        if ($this->status === 'ausstehend' && $this->faellig_am !== null && $this->faellig_am->isPast()) {
            return 'ueberfaellig';
        }

        return $this->status;
    }
}
```

- [ ] **Step 5: Run the migration and test**

Run: `wsl php artisan migrate` then `wsl php artisan test tests/Unit/Modules/Members/MemberDueDisplayStatusTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Members/Database/Migrations/2026_07_06_090001_add_status_columns_to_member_dues_table.php app/Modules/Members/Models/MemberDue.php tests/Unit/Modules/Members/MemberDueDisplayStatusTest.php
git commit -m "Add status/teilbetrag/kommentar/faellig_am columns to member_dues"
```

---

### Task 2: Default status and due date when a due is created

**Files:**
- Modify: `app/Modules/Members/Http/Controllers/MemberDueController.php:49-67` (`store`) and `:69-113` (`generate`)
- Test: `tests/Feature/Modules/Members/MemberDueGenerationTest.php`, `tests/Feature/Modules/Members/MemberDueManualCreationTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Modules/Members/MemberDueGenerationTest.php` (inside the class, after the existing `generating_dues_creates_one_per_active_member_using_last_years_category` test):

```php
    #[Test]
    public function generated_dues_default_to_ausstehend_with_the_fiscal_years_start_as_due_date(): void
    {
        $category = FeeCategory::create(['name' => 'Vollmitglied']);
        $lastYear = FiscalYear::create(['name' => '2025/26', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31']);
        $newYear = FiscalYear::create(['name' => '2026/27', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31']);
        FeeCategoryRate::create(['fee_category_id' => $category->id, 'fiscal_year_id' => $newYear->id, 'amount' => 130]);

        $member = Member::factory()->create();
        MemberDue::create(['member_id' => $member->id, 'fiscal_year_id' => $lastYear->id, 'fee_category_id' => $category->id, 'amount' => 120]);

        $this->actingAs($this->admin())->post('/members/dues/generate', ['fiscal_year_id' => $newYear->id]);

        $created = MemberDue::where('fiscal_year_id', $newYear->id)->first();
        $this->assertSame('ausstehend', $created->status);
        $this->assertSame('2026-09-01', $created->faellig_am->format('Y-m-d'));
    }
```

Add to `tests/Feature/Modules/Members/MemberDueManualCreationTest.php` (after `admin_can_create_a_due_manually`):

```php
    #[Test]
    public function manually_created_due_defaults_to_ausstehend_with_the_fiscal_years_start_as_due_date(): void
    {
        $member = Member::factory()->create();
        $category = FeeCategory::create(['name' => 'Vollmitglied']);
        $fy = FiscalYear::create(['name' => '2026/27', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31']);

        $this->actingAs($this->admin())->post(route('members.dues.store'), [
            'member_id' => $member->id,
            'fiscal_year_id' => $fy->id,
            'fee_category_id' => $category->id,
            'amount' => 120,
        ]);

        $due = MemberDue::where('member_id', $member->id)->first();
        $this->assertSame('ausstehend', $due->status);
        $this->assertSame('2026-09-01', $due->faellig_am->format('Y-m-d'));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `wsl php artisan test tests/Feature/Modules/Members/MemberDueGenerationTest.php tests/Feature/Modules/Members/MemberDueManualCreationTest.php`
Expected: the two new tests FAIL (`status`/`faellig_am` are `null`/default-from-migration only, not derived from the fiscal year's `starts_on`).

- [ ] **Step 3: Implement the defaults**

Current (`app/Modules/Members/Http/Controllers/MemberDueController.php:49-67`):
```php
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'member_id' => [
                'required',
                'exists:members,id',
                Rule::unique('member_dues', 'member_id')
                    ->where('fiscal_year_id', (int) $request->input('fiscal_year_id')),
            ],
            'fiscal_year_id' => ['required', 'exists:fiscal_years,id'],
            'fee_category_id' => ['required', 'exists:fee_categories,id'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
        ]);

        MemberDue::create($validated);

        return redirect()->route('members.dues.index', ['fiscal_year_id' => $validated['fiscal_year_id']])
            ->with('status', __('members::admin.dues.created'));
    }
```

Replace with:
```php
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'member_id' => [
                'required',
                'exists:members,id',
                Rule::unique('member_dues', 'member_id')
                    ->where('fiscal_year_id', (int) $request->input('fiscal_year_id')),
            ],
            'fiscal_year_id' => ['required', 'exists:fiscal_years,id'],
            'fee_category_id' => ['required', 'exists:fee_categories,id'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
        ]);

        $fiscalYear = FiscalYear::findOrFail($validated['fiscal_year_id']);

        MemberDue::create([
            ...$validated,
            'status' => 'ausstehend',
            'faellig_am' => $fiscalYear->starts_on,
        ]);

        return redirect()->route('members.dues.index', ['fiscal_year_id' => $validated['fiscal_year_id']])
            ->with('status', __('members::admin.dues.created'));
    }
```

Current (`app/Modules/Members/Http/Controllers/MemberDueController.php:100-105`, inside `generate()`'s `each` callback):
```php
                MemberDue::create([
                    'member_id' => $member->id,
                    'fiscal_year_id' => $fiscalYearId,
                    'fee_category_id' => $lastDue->fee_category_id,
                    'amount' => $rate->amount,
                ]);
```

Replace with (note `$newFiscalYear` is looked up once before the loop — add it right after `$fiscalYearId` is computed, at the top of `generate()`):
```php
                MemberDue::create([
                    'member_id' => $member->id,
                    'fiscal_year_id' => $fiscalYearId,
                    'fee_category_id' => $lastDue->fee_category_id,
                    'amount' => $rate->amount,
                    'status' => 'ausstehend',
                    'faellig_am' => $newFiscalYear->starts_on,
                ]);
```

And at the top of `generate()`, right after `$fiscalYearId = (int) $validated['fiscal_year_id'];`, add:
```php
        $newFiscalYear = FiscalYear::findOrFail($fiscalYearId);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `wsl php artisan test tests/Feature/Modules/Members/MemberDueGenerationTest.php tests/Feature/Modules/Members/MemberDueManualCreationTest.php`
Expected: PASS (all tests in both files).

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Members/Http/Controllers/MemberDueController.php tests/Feature/Modules/Members/MemberDueGenerationTest.php tests/Feature/Modules/Members/MemberDueManualCreationTest.php
git commit -m "Default new dues to ausstehend with the fiscal year's start as due date"
```

---

### Task 3: Per-cell field update endpoint (status, teilbetrag, kommentar, faellig_am)

**Files:**
- Modify: `app/Modules/Members/Http/Controllers/MemberDueController.php` (add `updateField`)
- Modify: `app/Modules/Members/routes.php`
- Test: `tests/Feature/Modules/Members/MemberDueFieldUpdateTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Members;

use App\Models\User;
use App\Modules\Members\Models\FeeCategory;
use App\Modules\Members\Models\FiscalYear;
use App\Modules\Members\Models\Member;
use App\Modules\Members\Models\MemberDue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberDueFieldUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['status' => 'admin']);
    }

    private function makeDue(): MemberDue
    {
        $member = Member::factory()->create();
        $category = FeeCategory::create(['name' => 'Vollmitglied']);
        $fiscalYear = FiscalYear::create(['name' => '2025/26', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31']);

        return MemberDue::create([
            'member_id' => $member->id,
            'fiscal_year_id' => $fiscalYear->id,
            'fee_category_id' => $category->id,
            'amount' => 120,
        ]);
    }

    #[Test]
    public function admin_can_update_the_status_field(): void
    {
        $due = $this->makeDue();

        $this->actingAs($this->admin())
            ->patchJson("/members/dues/{$due->id}/field", ['field' => 'status', 'value' => 'ueberfaellig'])
            ->assertOk()
            ->assertJson(['value' => 'ueberfaellig']);

        $this->assertSame('ueberfaellig', $due->fresh()->status);
    }

    #[Test]
    public function an_unknown_status_value_is_rejected(): void
    {
        $due = $this->makeDue();

        $this->actingAs($this->admin())
            ->patchJson("/members/dues/{$due->id}/field", ['field' => 'status', 'value' => 'irgendwas'])
            ->assertStatus(422);

        $this->assertSame('ausstehend', $due->fresh()->status);
    }

    #[Test]
    public function admin_can_set_teilbetrag(): void
    {
        $due = $this->makeDue();

        $this->actingAs($this->admin())
            ->patchJson("/members/dues/{$due->id}/field", ['field' => 'teilbetrag', 'value' => 42.50])
            ->assertOk()
            ->assertJson(['value' => '42,50 €']);

        $this->assertSame('42.50', $due->fresh()->teilbetrag);
    }

    #[Test]
    public function clearing_teilbetrag_returns_a_dash(): void
    {
        $due = $this->makeDue();
        $due->update(['teilbetrag' => 42.50]);

        $this->actingAs($this->admin())
            ->patchJson("/members/dues/{$due->id}/field", ['field' => 'teilbetrag', 'value' => null])
            ->assertOk()
            ->assertJson(['value' => '–']);

        $this->assertNull($due->fresh()->teilbetrag);
    }

    #[Test]
    public function admin_can_set_kommentar(): void
    {
        $due = $this->makeDue();

        $this->actingAs($this->admin())
            ->patchJson("/members/dues/{$due->id}/field", ['field' => 'kommentar', 'value' => 'Halber Beitrag erlassen'])
            ->assertOk()
            ->assertJson(['value' => 'Halber Beitrag erlassen']);

        $this->assertSame('Halber Beitrag erlassen', $due->fresh()->kommentar);
    }

    #[Test]
    public function admin_can_update_faellig_am(): void
    {
        $due = $this->makeDue();

        $this->actingAs($this->admin())
            ->patchJson("/members/dues/{$due->id}/field", ['field' => 'faellig_am', 'value' => '2026-01-15'])
            ->assertOk()
            ->assertJson(['value' => '15.01.2026']);

        $this->assertSame('2026-01-15', $due->fresh()->faellig_am->toDateString());
    }

    #[Test]
    public function a_non_whitelisted_field_is_rejected(): void
    {
        $due = $this->makeDue();

        $this->actingAs($this->admin())
            ->patchJson("/members/dues/{$due->id}/field", ['field' => 'amount', 'value' => 999])
            ->assertStatus(422);

        $this->assertSame('120.00', $due->fresh()->amount);
    }

    #[Test]
    public function a_regular_user_cannot_update_a_field(): void
    {
        $due = $this->makeDue();
        $user = User::factory()->create(['status' => 'enabled']);

        $this->actingAs($user)
            ->patchJson("/members/dues/{$due->id}/field", ['field' => 'status', 'value' => 'bezahlt'])
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl php artisan test tests/Feature/Modules/Members/MemberDueFieldUpdateTest.php`
Expected: FAIL — route `members/dues/{due}/field` doesn't exist (404).

- [ ] **Step 3: Add the route**

Current (`app/Modules/Members/routes.php:29-32`):
```php
        Route::get('members/dues', [MemberDueController::class, 'index'])->name('members.dues.index');
        Route::get('members/dues/create', [MemberDueController::class, 'create'])->name('members.dues.create');
        Route::post('members/dues', [MemberDueController::class, 'store'])->name('members.dues.store');
        Route::post('members/dues/generate', [MemberDueController::class, 'generate'])->name('members.dues.generate');
```

Replace with:
```php
        Route::get('members/dues', [MemberDueController::class, 'index'])->name('members.dues.index');
        Route::post('members/dues', [MemberDueController::class, 'store'])->name('members.dues.store');
        Route::post('members/dues/generate', [MemberDueController::class, 'generate'])->name('members.dues.generate');
        Route::patch('members/dues/{due}/field', [MemberDueController::class, 'updateField'])->name('members.dues.update-field');
```

(The `members/dues/create` GET route is removed here since Task 9 deletes the page it served; leaving it a step early is harmless — nothing links to it until Task 9's cleanup runs in the same overall plan, and no test references it after Task 9. If a task-runner executes tasks out of order, note this route removal now so `create()` isn't dead-linked in the meantime.)

- [ ] **Step 4: Implement `updateField`**

Add to `app/Modules/Members/Http/Controllers/MemberDueController.php`, add these imports at the top if not already present:
```php
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
```

Add this method to the class:
```php
    /** Speichert genau ein Feld eines Beitrags — für die direkt editierbaren Zellen der Liste. */
    public function updateField(Request $request, MemberDue $due): JsonResponse
    {
        $fieldRules = [
            'status' => ['required', 'string', Rule::in(MemberDue::STATUSES)],
            'teilbetrag' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'kommentar' => ['nullable', 'string', 'max:1000'],
            'faellig_am' => ['nullable', 'date'],
        ];

        $field = $request->string('field')->value();

        try {
            $validated = $request->validate([
                'field' => ['required', 'string', Rule::in(array_keys($fieldRules))],
                'value' => $fieldRules[$field] ?? ['prohibited'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->validator->errors()->first()], 422);
        }

        try {
            $due->update([$validated['field'] => $validated['value']]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('members::admin.dues.field_update_failed')], 500);
        }

        return response()->json([
            'value' => match ($validated['field']) {
                'faellig_am' => $due->faellig_am?->format('d.m.Y'),
                'teilbetrag' => $due->teilbetrag !== null ? number_format((float) $due->teilbetrag, 2, ',', '.').' €' : '–',
                'kommentar' => $due->kommentar ?: '–',
                default => (string) $due->{$validated['field']},
            },
        ]);
    }
```

- [ ] **Step 5: Add the missing lang key**

Add to `app/Modules/Members/resources/lang/de/admin.php`, inside the `'dues'` array, after `'status_open' => 'Offen',`:
```php
        'field_update_failed' => 'Speichern fehlgeschlagen. Bitte später erneut versuchen.',
```

Add to `app/Modules/Members/resources/lang/en/admin.php`, inside the `'dues'` array, after `'status_open' => 'Open',`:
```php
        'field_update_failed' => 'Saving failed. Please try again later.',
```

- [ ] **Step 6: Run test to verify it passes**

Run: `wsl php artisan test tests/Feature/Modules/Members/MemberDueFieldUpdateTest.php`
Expected: PASS (8 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Modules/Members/Http/Controllers/MemberDueController.php app/Modules/Members/routes.php app/Modules/Members/resources/lang/de/admin.php app/Modules/Members/resources/lang/en/admin.php tests/Feature/Modules/Members/MemberDueFieldUpdateTest.php
git commit -m "Add per-cell field update endpoint for dues (status, teilbetrag, kommentar, faellig_am)"
```

---

### Task 4: Mark covered dues as bezahlt when a payment is recorded

**Files:**
- Modify: `app/Modules/Members/Http/Controllers/PaymentController.php:69-96`
- Test: `tests/Feature/Modules/Members/PaymentManagementTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Modules/Members/PaymentManagementTest.php`, after `admin_can_record_a_payment_covering_multiple_fiscal_years`:

```php
    #[Test]
    public function recording_a_payment_sets_status_to_bezahlt_on_covered_dues(): void
    {
        $member = Member::factory()->create();
        $category = FeeCategory::create(['name' => 'Vollmitglied']);
        $fy = FiscalYear::create(['name' => '2025/26', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31']);
        $due = MemberDue::create(['member_id' => $member->id, 'fiscal_year_id' => $fy->id, 'fee_category_id' => $category->id, 'amount' => 120, 'status' => 'ausstehend']);

        $this->actingAs($this->admin())->post("/members/{$member->id}/payments", [
            'amount' => 120,
            'paid_at' => '2025-09-10',
            'member_due_ids' => [$due->id],
        ]);

        $this->assertSame('bezahlt', $due->fresh()->status);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl php artisan test tests/Feature/Modules/Members/PaymentManagementTest.php`
Expected: the new test FAILS — `status` stays `'ausstehend'`.

- [ ] **Step 3: Implement the side effect**

Current (`app/Modules/Members/Http/Controllers/PaymentController.php:69-92`):
```php
        DB::transaction(function () use ($validated, $member, $dueIds): void {
            // Re-check inside the transaction (with the due rows locked) so two
            // concurrent submissions cannot allocate the same due twice.
            $alreadyPaid = MemberDue::query()
                ->whereKey($dueIds)
                ->whereHas('payments')
                ->lockForUpdate()
                ->exists();

            if ($alreadyPaid) {
                throw ValidationException::withMessages([
                    'member_due_ids' => __('members::admin.payments.due_already_paid'),
                ]);
            }

            $payment = Payment::create([
                'member_id' => $member->id,
                'amount' => $validated['amount'],
                'paid_at' => $validated['paid_at'],
                'note' => $validated['note'] ?? null,
            ]);

            $payment->dues()->attach($dueIds);
        });
```

Replace with:
```php
        DB::transaction(function () use ($validated, $member, $dueIds): void {
            // Re-check inside the transaction (with the due rows locked) so two
            // concurrent submissions cannot allocate the same due twice.
            $alreadyPaid = MemberDue::query()
                ->whereKey($dueIds)
                ->whereHas('payments')
                ->lockForUpdate()
                ->exists();

            if ($alreadyPaid) {
                throw ValidationException::withMessages([
                    'member_due_ids' => __('members::admin.payments.due_already_paid'),
                ]);
            }

            $payment = Payment::create([
                'member_id' => $member->id,
                'amount' => $validated['amount'],
                'paid_at' => $validated['paid_at'],
                'note' => $validated['note'] ?? null,
            ]);

            $payment->dues()->attach($dueIds);

            MemberDue::whereKey($dueIds)->update(['status' => 'bezahlt']);
        });
```

- [ ] **Step 4: Run test to verify it passes**

Run: `wsl php artisan test tests/Feature/Modules/Members/PaymentManagementTest.php`
Expected: PASS (all tests, including the new one).

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Members/Http/Controllers/PaymentController.php tests/Feature/Modules/Members/PaymentManagementTest.php
git commit -m "Mark covered dues as bezahlt when a payment is recorded"
```

---

### Task 5: "Bezahlt bis" cascading endpoint

**Files:**
- Modify: `app/Modules/Members/Http/Controllers/MemberDueController.php` (add `markPaidThrough`)
- Modify: `app/Modules/Members/routes.php`
- Test: `tests/Feature/Modules/Members/MemberDuePaidThroughTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Members;

use App\Models\User;
use App\Modules\Members\Models\FeeCategory;
use App\Modules\Members\Models\FiscalYear;
use App\Modules\Members\Models\Member;
use App\Modules\Members\Models\MemberDue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberDuePaidThroughTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['status' => 'admin']);
    }

    #[Test]
    public function marking_paid_through_a_later_year_creates_intervening_dues_as_bezahlt(): void
    {
        $member = Member::factory()->create();
        $category = FeeCategory::create(['name' => 'Vollmitglied']);
        $fy2026 = FiscalYear::create(['name' => '2026/27', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31']);
        $fy2027 = FiscalYear::create(['name' => '2027/28', 'starts_on' => '2027-09-01', 'ends_on' => '2028-08-31']);
        $fy2028 = FiscalYear::create(['name' => '2028/29', 'starts_on' => '2028-09-01', 'ends_on' => '2029-08-31']);
        $due = MemberDue::create(['member_id' => $member->id, 'fiscal_year_id' => $fy2026->id, 'fee_category_id' => $category->id, 'amount' => 120]);

        $this->actingAs($this->admin())
            ->patchJson("/members/dues/{$due->id}/paid-through", ['fiscal_year_id' => $fy2028->id])
            ->assertOk();

        $due->refresh();
        $this->assertSame('bezahlt', $due->status);
        $this->assertSame($fy2028->id, $due->bezahlt_bis_fiscal_year_id);

        $fy2027Due = MemberDue::where('member_id', $member->id)->where('fiscal_year_id', $fy2027->id)->first();
        $this->assertNotNull($fy2027Due);
        $this->assertSame('bezahlt', $fy2027Due->status);
        $this->assertSame($category->id, $fy2027Due->fee_category_id);
        $this->assertSame('120.00', $fy2027Due->amount);
        $this->assertSame($fy2028->id, $fy2027Due->bezahlt_bis_fiscal_year_id);

        $fy2028Due = MemberDue::where('member_id', $member->id)->where('fiscal_year_id', $fy2028->id)->first();
        $this->assertNotNull($fy2028Due);
        $this->assertSame('bezahlt', $fy2028Due->status);
    }

    #[Test]
    public function an_already_existing_intervening_due_is_updated_not_duplicated(): void
    {
        $member = Member::factory()->create();
        $category = FeeCategory::create(['name' => 'Vollmitglied']);
        $fy2026 = FiscalYear::create(['name' => '2026/27', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31']);
        $fy2027 = FiscalYear::create(['name' => '2027/28', 'starts_on' => '2027-09-01', 'ends_on' => '2028-08-31']);
        $due = MemberDue::create(['member_id' => $member->id, 'fiscal_year_id' => $fy2026->id, 'fee_category_id' => $category->id, 'amount' => 120]);
        MemberDue::create(['member_id' => $member->id, 'fiscal_year_id' => $fy2027->id, 'fee_category_id' => $category->id, 'amount' => 130, 'status' => 'ausstehend']);

        $this->actingAs($this->admin())
            ->patchJson("/members/dues/{$due->id}/paid-through", ['fiscal_year_id' => $fy2027->id])
            ->assertOk();

        $this->assertSame(2, MemberDue::where('member_id', $member->id)->count());
        $existing = MemberDue::where('member_id', $member->id)->where('fiscal_year_id', $fy2027->id)->first();
        $this->assertSame('bezahlt', $existing->status);
        $this->assertSame('130.00', $existing->amount);
    }

    #[Test]
    public function a_fiscal_year_before_the_dues_own_year_is_rejected(): void
    {
        $member = Member::factory()->create();
        $category = FeeCategory::create(['name' => 'Vollmitglied']);
        $earlier = FiscalYear::create(['name' => '2024/25', 'starts_on' => '2024-09-01', 'ends_on' => '2025-08-31']);
        $fy = FiscalYear::create(['name' => '2026/27', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31']);
        $due = MemberDue::create(['member_id' => $member->id, 'fiscal_year_id' => $fy->id, 'fee_category_id' => $category->id, 'amount' => 120]);

        $this->actingAs($this->admin())
            ->patchJson("/members/dues/{$due->id}/paid-through", ['fiscal_year_id' => $earlier->id])
            ->assertStatus(422);

        $this->assertNull($due->fresh()->bezahlt_bis_fiscal_year_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl php artisan test tests/Feature/Modules/Members/MemberDuePaidThroughTest.php`
Expected: FAIL — route doesn't exist yet.

- [ ] **Step 3: Add the route**

Current (`app/Modules/Members/routes.php`, after Task 3's edit):
```php
        Route::patch('members/dues/{due}/field', [MemberDueController::class, 'updateField'])->name('members.dues.update-field');
```

Replace with:
```php
        Route::patch('members/dues/{due}/field', [MemberDueController::class, 'updateField'])->name('members.dues.update-field');
        Route::patch('members/dues/{due}/paid-through', [MemberDueController::class, 'markPaidThrough'])->name('members.dues.mark-paid-through');
```

- [ ] **Step 4: Implement `markPaidThrough`**

Add to `app/Modules/Members/Http/Controllers/MemberDueController.php`:
```php
    /** Markiert eine Beitragszeile als im Voraus bezahlt bis zu einem späteren Fiskaljahr und legt/aktualisiert dazwischenliegende Zeilen entsprechend. */
    public function markPaidThrough(Request $request, MemberDue $due): JsonResponse
    {
        $validated = $request->validate([
            'fiscal_year_id' => [
                'required',
                'exists:fiscal_years,id',
                function (string $attribute, mixed $value, callable $fail) use ($due): void {
                    $target = FiscalYear::find($value);
                    if ($target !== null && $target->starts_on->lt($due->fiscalYear->starts_on)) {
                        $fail(__('members::admin.dues.paid_through_before_own_year'));
                    }
                },
            ],
        ]);

        $targetFiscalYearId = (int) $validated['fiscal_year_id'];

        DB::transaction(function () use ($due, $targetFiscalYearId): void {
            $target = FiscalYear::findOrFail($targetFiscalYearId);

            $intervening = FiscalYear::query()
                ->where('starts_on', '>', $due->fiscalYear->starts_on)
                ->where('starts_on', '<=', $target->starts_on)
                ->orderBy('starts_on')
                ->get();

            foreach ($intervening as $fiscalYear) {
                $existing = MemberDue::where('member_id', $due->member_id)
                    ->where('fiscal_year_id', $fiscalYear->id)
                    ->first();

                if ($existing !== null) {
                    // Only status/bezahlt_bis_fiscal_year_id change on an existing row — its own
                    // amount/fee_category_id are left alone (per spec).
                    $existing->update([
                        'status' => 'bezahlt',
                        'bezahlt_bis_fiscal_year_id' => $targetFiscalYearId,
                    ]);

                    continue;
                }

                MemberDue::create([
                    'member_id' => $due->member_id,
                    'fiscal_year_id' => $fiscalYear->id,
                    'fee_category_id' => $due->fee_category_id,
                    'amount' => $due->amount,
                    'status' => 'bezahlt',
                    'bezahlt_bis_fiscal_year_id' => $targetFiscalYearId,
                    'faellig_am' => $fiscalYear->starts_on,
                ]);
            }

            $due->update([
                'status' => 'bezahlt',
                'bezahlt_bis_fiscal_year_id' => $targetFiscalYearId,
            ]);
        });

        return response()->json(['status' => 'bezahlt']);
    }
```

Add the missing lang key. In `app/Modules/Members/resources/lang/de/admin.php`, inside `'dues'`, after `'field_update_failed'`:
```php
        'paid_through_before_own_year' => 'Das gewählte Fiskaljahr liegt vor dem Fiskaljahr dieser Beitragszeile.',
```
In `app/Modules/Members/resources/lang/en/admin.php`, inside `'dues'`, after `'field_update_failed'`:
```php
        'paid_through_before_own_year' => 'The selected fiscal year is before this due\'s own fiscal year.',
```

Add the missing import to `MemberDueController.php` if not already present from Task 3:
```php
use Illuminate\Support\Facades\DB;
```

- [ ] **Step 5: Run test to verify it passes**

Run: `wsl php artisan test tests/Feature/Modules/Members/MemberDuePaidThroughTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Run the full Members suite to catch regressions**

Run: `wsl php artisan test --filter=Members`
Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add app/Modules/Members/Http/Controllers/MemberDueController.php app/Modules/Members/routes.php app/Modules/Members/resources/lang/de/admin.php app/Modules/Members/resources/lang/en/admin.php tests/Feature/Modules/Members/MemberDuePaidThroughTest.php
git commit -m "Add cascading Bezahlt-bis endpoint for advance payments"
```

---

### Task 6: Warning badge CSS

**Files:**
- Modify: `resources/css/app.css:425-433`

- [ ] **Step 1: Add `.ui-badge-warning`**

Current (`resources/css/app.css:425-433`):
```css
.ui-badge-danger {
    background: #f9f0f0;
    color: #c9190b;
}

.ui-badge-info {
    background: #eff6ff;
    color: #1a3a6b;
}
```

Replace with:
```css
.ui-badge-danger {
    background: #f9f0f0;
    color: #c9190b;
}

.ui-badge-warning {
    background: #fdf7e7;
    color: #f0ab00;
}

.ui-badge-info {
    background: #eff6ff;
    color: #1a3a6b;
}
```

- [ ] **Step 2: Rebuild assets**

Run: `wsl npm run build`
Expected: builds with no errors.

- [ ] **Step 3: Commit**

```bash
git add resources/css/app.css public/build
git commit -m "Add warning badge style for the Überfällig dues status"
```

---

### Task 7: Dues table — new columns

**Files:**
- Modify: `app/Modules/Members/resources/lang/de/admin.php`, `.../en/admin.php`
- Modify: `app/Modules/Members/Http/Controllers/MemberDueController.php:19-38` (`index`)
- Modify: `app/Modules/Members/resources/views/dues/index.blade.php`

- [ ] **Step 1: Add lang keys**

In `app/Modules/Members/resources/lang/de/admin.php`, replace the `'dues'` array's status lines:
```php
        'status' => 'Status',
        'status_paid' => 'Bezahlt',
        'status_open' => 'Offen',
```
with:
```php
        'status' => 'Status',
        'status_ausstehend' => 'Ausstehend',
        'status_bezahlt' => 'Bezahlt',
        'status_ueberfaellig' => 'Überfällig',
        'teilbetrag' => 'Teilbetrag',
        'kommentar' => 'Kommentar',
        'paid_through' => 'Bezahlt bis',
        'due_date' => 'Fällig am',
```

In `app/Modules/Members/resources/lang/en/admin.php`, replace:
```php
        'status' => 'Status',
        'status_paid' => 'Paid',
        'status_open' => 'Open',
```
with:
```php
        'status' => 'Status',
        'status_ausstehend' => 'Pending',
        'status_bezahlt' => 'Paid',
        'status_ueberfaellig' => 'Overdue',
        'teilbetrag' => 'Partial amount',
        'kommentar' => 'Comment',
        'paid_through' => 'Paid through',
        'due_date' => 'Due date',
```

- [ ] **Step 2: Pass fiscal years and dues eager-loaded for the new columns**

Current (`app/Modules/Members/Http/Controllers/MemberDueController.php:19-38`):
```php
    public function index(Request $request): View
    {
        $fiscalYears = FiscalYear::query()->orderBy('starts_on')->get();
        $currentFiscalYear = $fiscalYears->first(
            fn (FiscalYear $fiscalYear) => now()->between($fiscalYear->starts_on, $fiscalYear->ends_on),
        );
        $defaultFiscalYearId = $currentFiscalYear->id ?? $fiscalYears->last()->id ?? 0;
        $selectedFiscalYearId = (int) $request->query('fiscal_year_id', $defaultFiscalYearId);

        $dues = MemberDue::query()
            ->with(['member', 'feeCategory', 'payments'])
            ->where('fiscal_year_id', $selectedFiscalYearId)
            ->get();

        return view('members::dues.index', [
            'fiscalYears' => $fiscalYears,
            'selectedFiscalYearId' => $selectedFiscalYearId,
            'dues' => $dues,
        ]);
    }
```

Replace with:
```php
    public function index(Request $request): View
    {
        $fiscalYears = FiscalYear::query()->orderBy('starts_on')->get();
        $currentFiscalYear = $fiscalYears->first(
            fn (FiscalYear $fiscalYear) => now()->between($fiscalYear->starts_on, $fiscalYear->ends_on),
        );
        $defaultFiscalYearId = $currentFiscalYear->id ?? $fiscalYears->last()->id ?? 0;
        $selectedFiscalYearId = (int) $request->query('fiscal_year_id', $defaultFiscalYearId);

        $dues = MemberDue::query()
            ->with(['member', 'feeCategory', 'payments', 'fiscalYear', 'bezahltBisFiscalYear'])
            ->where('fiscal_year_id', $selectedFiscalYearId)
            ->get();

        return view('members::dues.index', [
            'fiscalYears' => $fiscalYears,
            'selectedFiscalYearId' => $selectedFiscalYearId,
            'dues' => $dues,
            'members' => Member::query()->active()->orderBy('lastname')->orderBy('firstname')->get(),
            'feeCategories' => FeeCategory::query()->orderBy('name')->get(),
        ]);
    }
```

(`Member` and `FeeCategory` are already imported in this file — they're used by `create()`, which Task 9 removes; leave the `use` statements as-is since `index()` now needs them too.)

- [ ] **Step 3: Rewrite the table body**

Current (`app/Modules/Members/resources/views/dues/index.blade.php`, full file):
```blade
@extends('members::layout')
@section('admin-title', __('members::admin.dues.title'))
@section('admin-content')
<div class="ui-page">
    <div class="ui-page-header"><h1>{{ __('members::admin.dues.title') }}</h1></div>

    @if(session('status'))
        <div class="ui-card"><div class="ui-card-body">{{ session('status') }}</div></div>
    @endif

    <div class="ui-card">
        <div class="ui-card-body ui-stack">
            <form method="GET" action="{{ route('members.dues.index') }}" class="ui-row">
                <div class="ui-field min-w-[12rem]">
                    <select name="fiscal_year_id" class="ui-select" onchange="this.form.submit()">
                        @foreach($fiscalYears as $fiscalYear)
                            <option value="{{ $fiscalYear->id }}" @selected($selectedFiscalYearId === $fiscalYear->id)>{{ $fiscalYear->name }}</option>
                        @endforeach
                    </select>
                </div>
            </form>
            <form method="POST" action="{{ route('members.dues.generate') }}">
                @csrf
                <input type="hidden" name="fiscal_year_id" value="{{ $selectedFiscalYearId }}">
                <button type="submit" class="ui-btn ui-btn-primary">{{ __('members::admin.dues.generate') }}</button>
            </form>
            <a href="{{ route('members.dues.create') }}" class="ui-btn ui-btn-ghost">{{ __('members::admin.dues.new') }}</a>
        </div>
    </div>

    <div class="ui-card">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ __('members::admin.members.name') }}</th>
                        <th>{{ __('members::admin.fee_categories.title') }}</th>
                        <th>{{ __('members::admin.fee_categories.amount') }}</th>
                        <th>{{ __('members::admin.dues.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($dues as $due)
                        <tr>
                            <td>{{ $due->member->fullName() }}</td>
                            <td>{{ $due->feeCategory->name }}</td>
                            <td>{{ number_format((float) $due->amount, 2) }} €</td>
                            <td>
                                <span class="ui-badge {{ $due->payments->isNotEmpty() ? 'ui-badge-success' : 'ui-badge-info' }}">
                                    {{ $due->payments->isNotEmpty() ? __('members::admin.dues.status_paid') : __('members::admin.dues.status_open') }}
                                </span>
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

Replace with:
```blade
@extends('members::layout')
@section('admin-title', __('members::admin.dues.title'))
@section('admin-content')
<div class="ui-page">
    <div class="ui-page-header"><h1>{{ __('members::admin.dues.title') }}</h1></div>

    @if(session('status'))
        <div class="ui-card"><div class="ui-card-body">{{ session('status') }}</div></div>
    @endif

    <div class="ui-card">
        <div class="ui-card-body ui-stack">
            <form method="GET" action="{{ route('members.dues.index') }}" class="ui-row">
                <div class="ui-field min-w-[12rem]">
                    <select name="fiscal_year_id" class="ui-select" onchange="this.form.submit()">
                        @foreach($fiscalYears as $fiscalYear)
                            <option value="{{ $fiscalYear->id }}" @selected($selectedFiscalYearId === $fiscalYear->id)>{{ $fiscalYear->name }}</option>
                        @endforeach
                    </select>
                </div>
            </form>
            <form method="POST" action="{{ route('members.dues.generate') }}">
                @csrf
                <input type="hidden" name="fiscal_year_id" value="{{ $selectedFiscalYearId }}">
                <button type="submit" class="ui-btn ui-btn-primary">{{ __('members::admin.dues.generate') }}</button>
            </form>
        </div>
    </div>

    <div class="ui-card">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ __('members::admin.members.name') }}</th>
                        <th>{{ __('members::admin.fee_categories.title') }}</th>
                        <th>{{ __('members::admin.fee_categories.amount') }}</th>
                        <th>{{ __('members::admin.dues.status') }}</th>
                        <th>{{ __('members::admin.dues.due_date') }}</th>
                        <th>{{ __('members::admin.dues.paid_through') }}</th>
                        <th>{{ __('members::admin.dues.teilbetrag') }}</th>
                        <th>{{ __('members::admin.dues.kommentar') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($dues as $due)
                        <tr>
                            <td>{{ $due->member->fullName() }}</td>
                            <td>{{ $due->feeCategory->name }}</td>
                            <td>{{ number_format((float) $due->amount, 2) }} €</td>
                            <td data-field="status"
                                x-data="{
                                    editing: false, error: null, justSaved: false,
                                    value: @js($due->status),
                                    displayValue: @js($due->displayStatus()),
                                    labels: { ausstehend: @js(__('members::admin.dues.status_ausstehend')), bezahlt: @js(__('members::admin.dues.status_bezahlt')), ueberfaellig: @js(__('members::admin.dues.status_ueberfaellig')) },
                                    classes: { ausstehend: 'ui-badge-warning', bezahlt: 'ui-badge-success', ueberfaellig: 'ui-badge-danger' },
                                    async save() {
                                        this.error = null;
                                        const result = await window.saveDueField({{ $due->id }}, 'status', this.value);
                                        if (result.ok) {
                                            this.editing = false;
                                            this.value = result.value;
                                            // The server-computed overdue override (displayStatus()) only re-evaluates
                                            // on the next page load; right after a manual edit we show the raw value
                                            // the admin just chose, matching what was actually stored.
                                            this.displayValue = result.value;
                                            this.justSaved = true;
                                            setTimeout(() => this.justSaved = false, 1500);
                                        } else {
                                            this.error = result.message;
                                        }
                                    }
                                }"
                                @click.outside="if (!error) editing = false">
                                <span x-show="!editing" @click="editing = true" x-text="labels[displayValue]" :class="'ui-badge ' + classes[displayValue]" class="cursor-pointer" :style="justSaved && 'outline: 2px solid #3e8635; outline-offset: 2px;'"></span>
                                <div x-show="editing">
                                    <select x-model="value" @change="save()" class="ui-select">
                                        <option value="ausstehend" x-text="labels.ausstehend"></option>
                                        <option value="bezahlt" x-text="labels.bezahlt"></option>
                                        <option value="ueberfaellig" x-text="labels.ueberfaellig"></option>
                                    </select>
                                    <p x-show="error" x-text="error" class="ui-error"></p>
                                </div>
                            </td>
                            <td data-field="faellig_am"
                                x-data="{
                                    editing: false, error: null, justSaved: false,
                                    value: @js($due->faellig_am?->format('Y-m-d') ?? ''),
                                    display: @js($due->faellig_am?->format('d.m.Y') ?? '–'),
                                    async save() {
                                        this.error = null;
                                        const result = await window.saveDueField({{ $due->id }}, 'faellig_am', this.value);
                                        if (result.ok) {
                                            this.editing = false;
                                            this.display = result.value;
                                            this.justSaved = true;
                                            setTimeout(() => this.justSaved = false, 1500);
                                        } else {
                                            this.error = result.message;
                                        }
                                    }
                                }"
                                @click.outside="if (!error) editing = false">
                                <span x-show="!editing" @click="editing = true" x-text="display" class="cursor-pointer" :style="justSaved && 'outline: 2px solid #3e8635; outline-offset: 2px;'"></span>
                                <div x-show="editing">
                                    <input type="date" x-model="value" @blur="save()" @keydown.enter.prevent="$event.target.blur()" :style="error && 'border-color:#c9190b'" class="ui-input">
                                    <p x-show="error" x-text="error" class="ui-error"></p>
                                </div>
                            </td>
                            <td>
                                <select class="ui-select" onchange="window.saveDuePaidThrough({{ $due->id }}, this.value)">
                                    <option value="">–</option>
                                    @foreach($fiscalYears->filter(fn ($fy) => $fy->starts_on->gte($due->fiscalYear->starts_on)) as $fiscalYear)
                                        <option value="{{ $fiscalYear->id }}" @selected($due->bezahlt_bis_fiscal_year_id === $fiscalYear->id)>{{ $fiscalYear->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td data-field="teilbetrag"
                                x-data="{
                                    editing: false, error: null, justSaved: false,
                                    value: @js($due->teilbetrag ?? ''),
                                    display: @js($due->teilbetrag !== null ? number_format((float) $due->teilbetrag, 2, ',', '.').' €' : '–'),
                                    async save() {
                                        this.error = null;
                                        const result = await window.saveDueField({{ $due->id }}, 'teilbetrag', this.value || null);
                                        if (result.ok) {
                                            this.editing = false;
                                            this.display = result.value;
                                            this.justSaved = true;
                                            setTimeout(() => this.justSaved = false, 1500);
                                        } else {
                                            this.error = result.message;
                                        }
                                    }
                                }"
                                @click.outside="if (!error) editing = false">
                                <span x-show="!editing" @click="editing = true" x-text="display" class="cursor-pointer" :style="(display !== '–' ? 'color:#f0ab00;font-weight:500;' : '') + (justSaved ? 'outline: 2px solid #3e8635; outline-offset: 2px;' : '')"></span>
                                <div x-show="editing">
                                    <input type="number" step="0.01" min="0" x-model="value" @blur="save()" @keydown.enter.prevent="$event.target.blur()" :style="error && 'border-color:#c9190b'" class="ui-input">
                                    <p x-show="error" x-text="error" class="ui-error"></p>
                                </div>
                            </td>
                            <td data-field="kommentar"
                                x-data="{
                                    editing: false, error: null, justSaved: false,
                                    value: @js($due->kommentar ?? ''),
                                    display: @js($due->kommentar ?: '–'),
                                    async save() {
                                        this.error = null;
                                        const result = await window.saveDueField({{ $due->id }}, 'kommentar', this.value || null);
                                        if (result.ok) {
                                            this.editing = false;
                                            this.display = result.value;
                                            this.justSaved = true;
                                            setTimeout(() => this.justSaved = false, 1500);
                                        } else {
                                            this.error = result.message;
                                        }
                                    }
                                }"
                                @click.outside="if (!error) editing = false">
                                <span x-show="!editing" @click="editing = true" x-text="display" class="cursor-pointer" :style="justSaved && 'outline: 2px solid #3e8635; outline-offset: 2px;'"></span>
                                <div x-show="editing">
                                    <input type="text" x-model="value" @blur="save()" @keydown.enter.prevent="$event.target.blur()" :style="error && 'border-color:#c9190b'" class="ui-input">
                                    <p x-show="error" x-text="error" class="ui-error"></p>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
    window.saveDueField = async (dueId, field, value) => {
        const token = document.querySelector('input[name="_token"]')?.value ?? '';

        try {
            const response = await fetch(`/members/dues/${dueId}/field`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                body: JSON.stringify({ field, value }),
            });
            const data = await response.json();

            if (!response.ok) {
                return { ok: false, message: data.message || 'Fehler beim Speichern.' };
            }

            return { ok: true, value: data.value };
        } catch (e) {
            return { ok: false, message: 'Fehler beim Speichern.' };
        }
    };

    window.saveDuePaidThrough = async (dueId, fiscalYearId) => {
        if (!fiscalYearId) return;
        const token = document.querySelector('input[name="_token"]')?.value ?? '';

        const response = await fetch(`/members/dues/${dueId}/paid-through`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token,
            },
            body: JSON.stringify({ fiscal_year_id: fiscalYearId }),
        });

        if (response.ok) {
            window.location.reload();
        }
    };
</script>
@endsection
```

There is no CSRF `<input name="_token">` elsewhere on this page (the `saveDueField`/`saveDuePaidThrough` helpers look for one via `document.querySelector('input[name="_token"]')`, same as `members/index.blade.php` relies on one existing somewhere in the page — verify the layout (`members::layout`) already renders a `@csrf` token input somewhere globally; if not, add a hidden one). Check `app/Modules/Members/resources/views/layout.blade.php` for an existing `@csrf` (the logout form there has one) — that satisfies the selector, matching how `members/index.blade.php` already relies on the same global lookup.

- [ ] **Step 4: Visual/manual verification**

Run: start the dev server (`wsl php artisan serve` or the existing `laravel-serve` preview config), log in as admin, open `/members/dues`, and confirm: Status pill is clickable and saves via dropdown with the three labels and colors (warning/success/danger); Fällig-am cell edits like a date field; Bezahlt-bis select updates and reloads the page, showing the row (and, once Task 9 is not yet done, no visible regression on the removed "Beitrag manuell anlegen" link — that's handled in Task 9); Teilbetrag/Kommentar edit and show "–" when empty.

- [ ] **Step 5: Run the Members test suite**

Run: `wsl php artisan test --filter=Members`
Expected: all pass (the old `status_open`/`status_paid`-based assertions, if any existed, no longer exist to break — confirm by grepping test files for `status_paid`/`status_open` and updating any hits to the new keys/behavior before running).

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Members/resources/lang/de/admin.php app/Modules/Members/resources/lang/en/admin.php app/Modules/Members/Http/Controllers/MemberDueController.php app/Modules/Members/resources/views/dues/index.blade.php
git commit -m "Add editable Status/Fällig-am/Bezahlt-bis/Teilbetrag/Kommentar columns to Beiträge"
```

---

### Task 8: Inline manual-entry row, remove the separate create page

**Files:**
- Modify: `app/Modules/Members/resources/views/dues/index.blade.php`
- Delete: `app/Modules/Members/resources/views/dues/create.blade.php`
- Modify: `app/Modules/Members/Http/Controllers/MemberDueController.php` (remove `create()`)
- Modify: `tests/Feature/Modules/Members/MemberDueManualCreationTest.php`

- [ ] **Step 1: Update the test file first**

Current (`tests/Feature/Modules/Members/MemberDueManualCreationTest.php`), remove these two tests entirely (they test the deleted page):
```php
    #[Test]
    public function create_page_renders_with_members_years_and_categories(): void
    {
        // ... (delete this whole test method)
    }

    #[Test]
    public function the_create_form_exposes_each_members_default_category(): void
    {
        // ... (delete this whole test method)
    }
```

Add this replacement test instead (verifying the same "auto-fill category" data is now on the index page):
```php
    #[Test]
    public function the_index_page_exposes_each_members_default_category_for_the_inline_form(): void
    {
        $category = FeeCategory::create(['name' => 'Vollzahler']);
        Member::factory()->create(['fee_category_id' => $category->id]);
        FiscalYear::create(['name' => '2026/27', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31']);

        $this->actingAs($this->admin())->get(route('members.dues.index'))
            ->assertOk()
            ->assertSee('data-fee-category="'.$category->id.'"', false);
    }
```

Leave every other test in the file unchanged (`admin_can_create_a_due_manually`, `duplicate_due_for_same_member_and_fiscal_year_is_rejected`, `due_amount_must_be_within_column_range`, `regular_member_cannot_create_dues`, and the one added in Task 2) — they post to `members.dues.store` directly and don't depend on the page.

- [ ] **Step 2: Run to verify the new test fails, old ones are gone**

Run: `wsl php artisan test tests/Feature/Modules/Members/MemberDueManualCreationTest.php`
Expected: FAIL on the new test — `data-fee-category` isn't on the index page yet.

- [ ] **Step 3: Remove `create()` and its route**

Current (`app/Modules/Members/Http/Controllers/MemberDueController.php`), delete this whole method:
```php
    public function create(): View
    {
        return view('members::dues.create', [
            'members' => Member::query()->active()->orderBy('lastname')->orderBy('firstname')->get(),
            'fiscalYears' => FiscalYear::query()->orderBy('starts_on')->get(),
            'feeCategories' => FeeCategory::query()->orderBy('name')->get(),
        ]);
    }
```

(The GET `members/dues/create` route was already removed in Task 3's routes.php edit.)

- [ ] **Step 4: Delete the create view**

```bash
rm app/Modules/Members/resources/views/dues/create.blade.php
```

- [ ] **Step 5: Add the inline compose row**

In `app/Modules/Members/resources/views/dues/index.blade.php`, restore a manual-entry trigger (removed in Task 7's rewrite of the header card) and add the compose row. Current header card (from Task 7):
```blade
    <div class="ui-card">
        <div class="ui-card-body ui-stack">
            <form method="GET" action="{{ route('members.dues.index') }}" class="ui-row">
                <div class="ui-field min-w-[12rem]">
                    <select name="fiscal_year_id" class="ui-select" onchange="this.form.submit()">
                        @foreach($fiscalYears as $fiscalYear)
                            <option value="{{ $fiscalYear->id }}" @selected($selectedFiscalYearId === $fiscalYear->id)>{{ $fiscalYear->name }}</option>
                        @endforeach
                    </select>
                </div>
            </form>
            <form method="POST" action="{{ route('members.dues.generate') }}">
                @csrf
                <input type="hidden" name="fiscal_year_id" value="{{ $selectedFiscalYearId }}">
                <button type="submit" class="ui-btn ui-btn-primary">{{ __('members::admin.dues.generate') }}</button>
            </form>
        </div>
    </div>
```

Replace with:
```blade
    <div class="ui-card" x-data="{ composing: false }">
        <div class="ui-card-body ui-stack">
            <form method="GET" action="{{ route('members.dues.index') }}" class="ui-row">
                <div class="ui-field min-w-[12rem]">
                    <select name="fiscal_year_id" class="ui-select" onchange="this.form.submit()">
                        @foreach($fiscalYears as $fiscalYear)
                            <option value="{{ $fiscalYear->id }}" @selected($selectedFiscalYearId === $fiscalYear->id)>{{ $fiscalYear->name }}</option>
                        @endforeach
                    </select>
                </div>
            </form>
            <form method="POST" action="{{ route('members.dues.generate') }}">
                @csrf
                <input type="hidden" name="fiscal_year_id" value="{{ $selectedFiscalYearId }}">
                <button type="submit" class="ui-btn ui-btn-primary">{{ __('members::admin.dues.generate') }}</button>
            </form>
            <button type="button" class="ui-btn ui-btn-ghost" @click="composing = !composing">{{ __('members::admin.dues.new') }}</button>
        </div>
        <form x-show="composing" method="POST" action="{{ route('members.dues.store') }}" class="ui-card-body ui-row" style="border-top:1px solid var(--ui-border)">
            @csrf
            <input type="hidden" name="fiscal_year_id" value="{{ $selectedFiscalYearId }}">
            <div class="ui-field min-w-[12rem]">
                <select id="due-inline-member" name="member_id" class="ui-select">
                    @foreach($members as $member)
                        <option value="{{ $member->id }}" data-fee-category="{{ $member->fee_category_id }}">{{ $member->fullName() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-field min-w-[10rem]">
                <select id="due-inline-category" name="fee_category_id" class="ui-select">
                    @foreach($feeCategories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-field min-w-[8rem]">
                <input type="number" step="0.01" min="0" name="amount" placeholder="{{ __('members::admin.fee_categories.amount') }}" class="ui-input">
            </div>
            <button type="submit" class="ui-btn ui-btn-primary">{{ __('members::admin.common.save') }}</button>
        </form>
    </div>
```

- [ ] **Step 6: Add the member→category auto-fill script**

At the end of the `<script>` block already added in Task 7 (right before `</script>`), add:
```javascript
    document.addEventListener('DOMContentLoaded', () => {
        const memberSelect = document.getElementById('due-inline-member');
        const categorySelect = document.getElementById('due-inline-category');
        if (!memberSelect || !categorySelect) return;
        memberSelect.addEventListener('change', () => {
            const category = memberSelect.selectedOptions[0]?.dataset.feeCategory;
            if (category) categorySelect.value = category;
        });
    });
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `wsl php artisan test tests/Feature/Modules/Members/MemberDueManualCreationTest.php`
Expected: PASS (all tests, including the new one).

- [ ] **Step 8: Run the full Members suite**

Run: `wsl php artisan test --filter=Members`
Expected: all pass.

- [ ] **Step 9: Manual verification**

Start the dev server, open `/members/dues`, click "Beitrag manuell anlegen", confirm the inline row appears, selecting a member auto-fills the category, submitting creates the due and the row disappears (page reload from the redirect).

- [ ] **Step 10: Commit**

```bash
git add -A app/Modules/Members/resources/views/dues/create.blade.php app/Modules/Members/resources/views/dues/index.blade.php app/Modules/Members/Http/Controllers/MemberDueController.php tests/Feature/Modules/Members/MemberDueManualCreationTest.php
git commit -m "Replace the dues create page with an inline manual-entry row"
```

---

### Task 9: Full regression pass

**Files:** none (verification only)

- [ ] **Step 1: Run the entire Members test suite**

Run: `wsl php artisan test --filter=Members`
Expected: all tests pass, zero failures.

- [ ] **Step 2: Run the full project test suite**

Run: `wsl php artisan test`
Expected: all tests pass (confirms nothing outside the Members module broke — e.g. no route-name collisions from the removed `members.dues.create` route).

- [ ] **Step 3: Manual browser walkthrough**

Using the `laravel-serve` preview config: visit `/members/dues`, exercise every new interaction listed in Task 7 Step 4 and Task 8 Step 9 end-to-end in one pass, then visit `/members` and `/members/dashboard` to confirm neither was affected.

No commit for this task — verification only.

---

## Self-review notes

- **Spec coverage:** data model (Task 1), creation defaults (Task 2), per-cell status/teilbetrag/kommentar/faellig_am editing (Task 3), Payment→status side effect (Task 4), Bezahlt-bis cascading (Task 5), warning badge token (Task 6), new table columns (Task 7), inline manual-entry replacing the create page (Task 8). Every requirement in the spec's "Data model changes," "Status logic," "'Bezahlt bis'," "Per-cell inline editing," "Inline manual-entry row," and "Testing" sections maps to a task.
- **Placeholder scan:** none — every step has literal code, exact file paths, and exact commands.
- **Type/name consistency:** `MemberDue::STATUSES` (Task 1) is the same array `updateField`'s `Rule::in()` (Task 3) validates against. `displayStatus()` (Task 1) is used for the Status cell's read-only pill in Task 7 (`displayValue`), matching the spec's "wherever the status is rendered ... use displayStatus()"; the edit dropdown itself still shows/writes the raw `status` (`value`), since editing must always operate on the real stored field. One accepted limitation, called out inline in Task 7's code comment: right after a manual status edit, the pill shows the raw value just saved rather than re-running the overdue check client-side — the overdue recompute only happens on next page load, consistent with how Task 5's Bezahlt-bis flow already reloads the page for cross-row consistency rather than trying to sync derived state in the browser.
