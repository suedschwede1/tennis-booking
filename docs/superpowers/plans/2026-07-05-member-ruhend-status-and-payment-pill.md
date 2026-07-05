# Member Ruhend Status and Payment Pill Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a real tri-state `status` (aktiv/ruhend/ausgetreten) to `Member`, driven automatically by editing `left_at` (replacing the separate "Austragen" action), plus an editable Aktiv/Ruhend toggle and a read-only payment-status pill on the Mitglieder list.

**Architecture:** One migration adds `status` to `members`. Two model methods (`setLeftAt()`, `setStatus()`) centralize the `left_at`↔`status` coupling so both the profile form and the list's per-cell editor go through the same logic. The existing `MemberController::updateField()` per-cell endpoint is extended to whitelist `left_at` and `status`. The "Austragen" button/route/method are deleted outright — departure becomes "edit the date," matching how `joined_at` already works.

**Tech Stack:** Laravel (Eloquent, Blade), Alpine.js (existing per-cell pattern), PHPUnit.

---

## File structure

- Create: `app/Modules/Members/Database/Migrations/2026_07_06_090002_add_status_to_members_table.php`
- Modify: `app/Modules/Members/Models/Member.php` — `STATUSES` const, `setLeftAt()`, `setStatus()`, `scopeActive()`.
- Modify: `app/Modules/Members/Http/Controllers/MemberController.php` — `index()` filter + eager-load, `update()`, `updateField()`, `destroy()` removed.
- Modify: `app/Modules/Members/routes.php` — exclude `destroy` from the members resource route.
- Modify: `app/Modules/Members/resources/views/members/index.blade.php` — Status pill+toggle, "Austritt am" column, "Zahlung" column, filter option, Austragen button removed.
- Modify: `app/Modules/Members/resources/views/members/_form.blade.php` — `left_at` field.
- Modify: `app/Modules/Members/resources/lang/de/admin.php`, `.../en/admin.php` — new/removed keys.
- Modify: `tests/Feature/Modules/Members/MemberManagementTest.php`, `MemberFieldUpdateTest.php`, `MemberDueGenerationTest.php`
- Create: `tests/Unit/Modules/Members/MemberStatusTest.php`

---

### Task 1: `status` column and model coupling methods

**Files:**
- Create: `app/Modules/Members/Database/Migrations/2026_07_06_090002_add_status_to_members_table.php`
- Modify: `app/Modules/Members/Models/Member.php`
- Test: `tests/Unit/Modules/Members/MemberStatusTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members;

use App\Modules\Members\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberStatusTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function setting_left_at_marks_the_member_ausgetreten(): void
    {
        $member = Member::factory()->create(['status' => 'aktiv', 'left_at' => null]);

        $member->setLeftAt('2026-03-01');

        $this->assertSame('2026-03-01', $member->fresh()->left_at->toDateString());
        $this->assertSame('ausgetreten', $member->fresh()->status);
    }

    #[Test]
    public function clearing_left_at_marks_the_member_aktiv(): void
    {
        $member = Member::factory()->create(['status' => 'ausgetreten', 'left_at' => '2026-03-01']);

        $member->setLeftAt(null);

        $this->assertNull($member->fresh()->left_at);
        $this->assertSame('aktiv', $member->fresh()->status);
    }

    #[Test]
    public function setting_status_to_ruhend_leaves_left_at_untouched(): void
    {
        $member = Member::factory()->create(['status' => 'aktiv', 'left_at' => null]);

        $member->setStatus('ruhend');

        $this->assertNull($member->fresh()->left_at);
        $this->assertSame('ruhend', $member->fresh()->status);
    }

    #[Test]
    public function setting_status_to_aktiv_also_clears_left_at(): void
    {
        $member = Member::factory()->create(['status' => 'ausgetreten', 'left_at' => '2026-03-01']);

        $member->setStatus('aktiv');

        $this->assertNull($member->fresh()->left_at);
        $this->assertSame('aktiv', $member->fresh()->status);
    }

    #[Test]
    public function active_scope_only_matches_aktiv_status(): void
    {
        Member::factory()->create(['status' => 'aktiv']);
        Member::factory()->create(['status' => 'ruhend']);
        Member::factory()->create(['status' => 'ausgetreten']);

        $this->assertSame(1, Member::active()->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl php artisan test tests/Unit/Modules/Members/MemberStatusTest.php`
Expected: FAIL — `status` column doesn't exist, `setLeftAt()`/`setStatus()` are undefined.

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
        Schema::table('members', function (Blueprint $table) {
            $table->string('status')->default('aktiv')->after('left_at');
        });

        // Backfill: any existing departed member (left_at already set) is ausgetreten.
        \DB::table('members')->whereNotNull('left_at')->update(['status' => 'ausgetreten']);
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
```

- [ ] **Step 4: Update the model**

Current (`app/Modules/Members/Models/Member.php`):
```php
class Member extends Model
{
    use HasFactory;

    public const GENDERS = ['m', 'w', 'd'];

    protected $fillable = [
        'member_number', 'nuliga_id', 'firstname', 'lastname', 'birthdate', 'gender',
        'email', 'phone', 'street', 'zip', 'city', 'fee_category_id', 'joined_at', 'left_at',
    ];

    protected $casts = [
        'birthdate' => 'date',
        'joined_at' => 'date',
        'left_at' => 'date',
    ];

    protected static function newFactory(): MemberFactory
    {
        return MemberFactory::new();
    }

    public function dues(): HasMany
    {
        return $this->hasMany(MemberDue::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function feeCategory(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class);
    }

    public function age(): ?int
    {
        return $this->birthdate?->age;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('left_at');
    }

    public function fullName(): string
    {
        return trim("{$this->firstname} {$this->lastname}");
    }

    /** Next free member_number, for pre-filling the create form (max existing + 1, or 1 if none exist). */
    public static function nextNumber(): int
    {
        return (int) (static::query()->max('member_number') ?? 0) + 1;
    }

    public function paidUntil(): ?\Carbon\Carbon
    {
        return $this->dues()
            ->get()
            ->filter(fn (MemberDue $due) => $due->isPaid())
            ->map(fn (MemberDue $due) => $due->fiscalYear->ends_on)
            ->sort()
            ->last();
    }
}
```

Replace with:
```php
class Member extends Model
{
    use HasFactory;

    public const GENDERS = ['m', 'w', 'd'];
    public const STATUSES = ['aktiv', 'ruhend', 'ausgetreten'];

    protected $fillable = [
        'member_number', 'nuliga_id', 'firstname', 'lastname', 'birthdate', 'gender',
        'email', 'phone', 'street', 'zip', 'city', 'fee_category_id', 'joined_at', 'left_at', 'status',
    ];

    protected $casts = [
        'birthdate' => 'date',
        'joined_at' => 'date',
        'left_at' => 'date',
    ];

    protected static function newFactory(): MemberFactory
    {
        return MemberFactory::new();
    }

    public function dues(): HasMany
    {
        return $this->hasMany(MemberDue::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function feeCategory(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class);
    }

    public function age(): ?int
    {
        return $this->birthdate?->age;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'aktiv');
    }

    public function fullName(): string
    {
        return trim("{$this->firstname} {$this->lastname}");
    }

    /** Next free member_number, for pre-filling the create form (max existing + 1, or 1 if none exist). */
    public static function nextNumber(): int
    {
        return (int) (static::query()->max('member_number') ?? 0) + 1;
    }

    public function paidUntil(): ?\Carbon\Carbon
    {
        return $this->dues()
            ->get()
            ->filter(fn (MemberDue $due) => $due->isPaid())
            ->map(fn (MemberDue $due) => $due->fiscalYear->ends_on)
            ->sort()
            ->last();
    }

    /** Setzt das Austrittsdatum und leitet daraus automatisch den Status ab: gesetzt = ausgetreten, geleert = aktiv. */
    public function setLeftAt(?string $date): void
    {
        $this->update([
            'left_at' => $date,
            'status' => $date === null ? 'aktiv' : 'ausgetreten',
        ]);
    }

    /** Setzt den Status manuell (nur 'aktiv' oder 'ruhend' — 'ausgetreten' läuft ausschließlich über setLeftAt()). Zurück auf 'aktiv' löscht ein gesetztes Austrittsdatum. */
    public function setStatus(string $status): void
    {
        $this->update([
            'status' => $status,
            'left_at' => $status === 'aktiv' ? null : $this->left_at,
        ]);
    }
}
```

- [ ] **Step 5: Run the migration and test**

Run: `wsl php artisan migrate` then `wsl php artisan test tests/Unit/Modules/Members/MemberStatusTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Members/Database/Migrations/2026_07_06_090002_add_status_to_members_table.php app/Modules/Members/Models/Member.php tests/Unit/Modules/Members/MemberStatusTest.php
git commit -m "Add status column to members, coupled to left_at via setLeftAt/setStatus"
```

---

### Task 2: Remove the Austragen action

**Files:**
- Modify: `app/Modules/Members/Http/Controllers/MemberController.php` (remove `destroy()`)
- Modify: `app/Modules/Members/routes.php`
- Modify: `app/Modules/Members/resources/views/members/index.blade.php` (remove the Austragen form/button)
- Modify: `app/Modules/Members/resources/lang/de/admin.php`, `.../en/admin.php` (remove now-dead keys)
- Modify: `tests/Feature/Modules/Members/MemberManagementTest.php`

- [ ] **Step 1: Remove the dead test**

In `tests/Feature/Modules/Members/MemberManagementTest.php`, delete this test entirely (it posts to the route this task removes):
```php
    #[Test]
    public function admin_can_mark_a_member_as_departed(): void
    {
        $member = \App\Modules\Members\Models\Member::factory()->create();

        $this->actingAs($this->admin())->delete("/members/{$member->id}")
            ->assertRedirect(route('members.index'));

        $this->assertNotNull($member->fresh()->left_at);
    }
```
(Task 3's tests cover the equivalent behavior — setting `left_at` — through the new endpoints instead.)

- [ ] **Step 2: Remove the route**

Current (`app/Modules/Members/routes.php`):
```php
        Route::resource('members', MemberController::class)->except(['show']);
```

Replace with:
```php
        Route::resource('members', MemberController::class)->except(['show', 'destroy']);
```

- [ ] **Step 3: Remove the controller method**

Delete this whole method from `app/Modules/Members/Http/Controllers/MemberController.php`:
```php
    public function destroy(Member $member): RedirectResponse
    {
        $before = $member->toArray();

        DB::transaction(function () use ($member, $before) {
            $member->update(['left_at' => $member->left_at ?? now()->toDateString()]);
            $this->logChange($member, 'departed', $before);
        });

        return redirect()->route('members.index');
    }
```

Check whether `RedirectResponse` is still used elsewhere in the file (it is — `store()` and `update()` both return it) — keep the import.

- [ ] **Step 4: Remove the button from the list**

Current (`app/Modules/Members/resources/views/members/index.blade.php:170-179`):
```blade
                            <td>
                                <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                                    <a href="{{ route('members.edit', $member) }}" class="ui-btn ui-btn-ghost">{{ __('members::admin.common.edit') }}</a>
                                    <form method="POST" action="{{ route('members.destroy', $member) }}" onsubmit="return confirm({{ Js::from(__('members::admin.members.confirm_leave')) }})">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="ui-btn ui-btn-ghost">{{ __('members::admin.members.mark_departed') }}</button>
                                    </form>
                                </div>
                            </td>
```

Replace with:
```blade
                            <td>
                                <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                                    <a href="{{ route('members.edit', $member) }}" class="ui-btn ui-btn-ghost">{{ __('members::admin.common.edit') }}</a>
                                </div>
                            </td>
```

- [ ] **Step 5: Remove the dead lang keys**

In `app/Modules/Members/resources/lang/de/admin.php`, delete these two lines from the `'members'` array:
```php
        'mark_departed' => 'Austragen',
        'confirm_leave' => 'Mitglied wirklich austragen?',
```

In `app/Modules/Members/resources/lang/en/admin.php`, delete:
```php
        'mark_departed' => 'Mark as departed',
        'confirm_leave' => 'Really mark this member as departed?',
```

- [ ] **Step 6: Run the Members test suite**

Run: `wsl php artisan test --filter=Members`
Expected: no failures related to the removed route/method (some may still fail — this task doesn't yet add the replacement UI, that's Tasks 4-5 — but nothing should newly fail beyond what's expected from removing dead code; specifically confirm `MemberManagementTest.php` and `MemberFieldUpdateTest.php` still pass in full).

- [ ] **Step 7: Commit**

```bash
git add app/Modules/Members/Http/Controllers/MemberController.php app/Modules/Members/routes.php app/Modules/Members/resources/views/members/index.blade.php app/Modules/Members/resources/lang/de/admin.php app/Modules/Members/resources/lang/en/admin.php tests/Feature/Modules/Members/MemberManagementTest.php
git commit -m "Remove the Austragen action — departure is now recorded via left_at directly"
```

---

### Task 3: Whitelist `left_at` and `status` on the field-update endpoint

**Files:**
- Modify: `app/Modules/Members/Http/Controllers/MemberController.php` (`updateField()`, `update()`)
- Test: `tests/Feature/Modules/Members/MemberFieldUpdateTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Modules/Members/MemberFieldUpdateTest.php`, after the existing `admin_can_update_joined_at_and_response_is_formatted` test:

```php
    #[Test]
    public function setting_left_at_via_field_update_marks_the_member_ausgetreten(): void
    {
        $member = Member::factory()->create(['status' => 'aktiv']);

        $this->actingAs($this->admin())
            ->patchJson("/members/{$member->id}/field", ['field' => 'left_at', 'value' => '2026-03-01'])
            ->assertOk()
            ->assertJson(['value' => '01.03.2026']);

        $this->assertSame('2026-03-01', $member->fresh()->left_at->toDateString());
        $this->assertSame('ausgetreten', $member->fresh()->status);
    }

    #[Test]
    public function clearing_left_at_via_field_update_marks_the_member_aktiv(): void
    {
        $member = Member::factory()->create(['status' => 'ausgetreten', 'left_at' => '2026-03-01']);

        $this->actingAs($this->admin())
            ->patchJson("/members/{$member->id}/field", ['field' => 'left_at', 'value' => null])
            ->assertOk()
            ->assertJson(['value' => '—']);

        $this->assertNull($member->fresh()->left_at);
        $this->assertSame('aktiv', $member->fresh()->status);
    }

    #[Test]
    public function admin_can_set_status_to_ruhend(): void
    {
        $member = Member::factory()->create(['status' => 'aktiv']);

        $this->actingAs($this->admin())
            ->patchJson("/members/{$member->id}/field", ['field' => 'status', 'value' => 'ruhend'])
            ->assertOk()
            ->assertJson(['value' => 'ruhend']);

        $this->assertSame('ruhend', $member->fresh()->status);
        $this->assertNull($member->fresh()->left_at);
    }

    #[Test]
    public function setting_status_to_aktiv_via_field_update_clears_left_at(): void
    {
        $member = Member::factory()->create(['status' => 'ausgetreten', 'left_at' => '2026-03-01']);

        $this->actingAs($this->admin())
            ->patchJson("/members/{$member->id}/field", ['field' => 'status', 'value' => 'aktiv'])
            ->assertOk();

        $this->assertSame('aktiv', $member->fresh()->status);
        $this->assertNull($member->fresh()->left_at);
    }

    #[Test]
    public function setting_status_to_ausgetreten_via_field_update_is_rejected(): void
    {
        $member = Member::factory()->create(['status' => 'aktiv']);

        $this->actingAs($this->admin())
            ->patchJson("/members/{$member->id}/field", ['field' => 'status', 'value' => 'ausgetreten'])
            ->assertStatus(422);

        $this->assertSame('aktiv', $member->fresh()->status);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `wsl php artisan test tests/Feature/Modules/Members/MemberFieldUpdateTest.php`
Expected: the 5 new tests FAIL — `left_at`/`status` aren't in `updateField()`'s `$fieldRules` yet, so they 422 with "a non-whitelisted field" behavior instead of the expected outcomes.

- [ ] **Step 3: Extend `updateField()`**

Current (`app/Modules/Members/Http/Controllers/MemberController.php:124-166`):
```php
    /** Speichert genau ein Feld eines Mitglieds — für die direkt editierbaren Zellen der Liste. */
    public function updateField(Request $request, Member $member): JsonResponse
    {
        $fieldRules = [
            'firstname' => ['required', 'string', 'max:128'],
            'lastname' => ['required', 'string', 'max:128'],
            'email' => ['nullable', 'email', 'max:128'],
            'fee_category_id' => ['nullable', 'exists:fee_categories,id'],
            'joined_at' => ['required', 'date'],
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

        $before = $member->toArray();

        try {
            DB::transaction(function () use ($member, $validated, $before) {
                $member->update([$validated['field'] => $validated['value']]);
                $this->logChange($member, 'updated', $before);
            });
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('members::admin.members.field_update_failed')], 500);
        }

        return response()->json([
            'value' => match ($validated['field']) {
                'joined_at' => $member->joined_at->format('d.m.Y'),
                'fee_category_id' => $member->feeCategory?->name ?? '—',
                'email' => $member->email ?? '—',
                default => (string) $member->{$validated['field']},
            },
        ]);
    }
```

Replace with:
```php
    /** Speichert genau ein Feld eines Mitglieds — für die direkt editierbaren Zellen der Liste. */
    public function updateField(Request $request, Member $member): JsonResponse
    {
        $fieldRules = [
            'firstname' => ['required', 'string', 'max:128'],
            'lastname' => ['required', 'string', 'max:128'],
            'email' => ['nullable', 'email', 'max:128'],
            'fee_category_id' => ['nullable', 'exists:fee_categories,id'],
            'joined_at' => ['required', 'date'],
            'left_at' => ['nullable', 'date'],
            'status' => ['required', 'string', Rule::in(['aktiv', 'ruhend'])],
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

        $before = $member->toArray();

        try {
            DB::transaction(function () use ($member, $validated, $before) {
                match ($validated['field']) {
                    'left_at' => $member->setLeftAt($validated['value']),
                    'status' => $member->setStatus($validated['value']),
                    default => $member->update([$validated['field'] => $validated['value']]),
                };
                $this->logChange($member, 'updated', $before);
            });
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('members::admin.members.field_update_failed')], 500);
        }

        return response()->json([
            'value' => match ($validated['field']) {
                'joined_at' => $member->joined_at->format('d.m.Y'),
                'left_at' => $member->left_at?->format('d.m.Y') ?? '—',
                'fee_category_id' => $member->feeCategory?->name ?? '—',
                'email' => $member->email ?? '—',
                default => (string) $member->{$validated['field']},
            },
        ]);
    }
```

`Rule::in(['aktiv', 'ruhend'])` is the mechanism that rejects `ausgetreten` (test 5 above) — it's simply not one of the two allowed values for a *manual* status write, since `ausgetreten` only ever comes from `setLeftAt()`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `wsl php artisan test tests/Feature/Modules/Members/MemberFieldUpdateTest.php`
Expected: PASS (all tests, including the 5 new ones).

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Members/Http/Controllers/MemberController.php tests/Feature/Modules/Members/MemberFieldUpdateTest.php
git commit -m "Whitelist left_at and status on the member field-update endpoint"
```

---

### Task 4: `left_at` in the profile form

**Files:**
- Modify: `app/Modules/Members/resources/views/members/_form.blade.php`
- Modify: `app/Modules/Members/Http/Controllers/MemberController.php` (`update()`)
- Test: `tests/Feature/Modules/Members/MemberManagementTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Modules/Members/MemberManagementTest.php`, after `members_store_the_new_master_data_fields` (or any convenient existing test):

```php
    #[Test]
    public function updating_a_member_with_a_left_at_date_marks_them_ausgetreten(): void
    {
        $member = \App\Modules\Members\Models\Member::factory()->create(['status' => 'aktiv']);

        $this->actingAs($this->admin())->put("/members/{$member->id}", [
            'member_number' => $member->member_number,
            'firstname' => $member->firstname,
            'lastname' => $member->lastname,
            'joined_at' => $member->joined_at->format('Y-m-d'),
            'left_at' => '2026-05-01',
        ])->assertRedirect(route('members.index'));

        $this->assertSame('2026-05-01', $member->fresh()->left_at->toDateString());
        $this->assertSame('ausgetreten', $member->fresh()->status);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl php artisan test tests/Feature/Modules/Members/MemberManagementTest.php`
Expected: the new test FAILS — `update()` doesn't accept/process `left_at` yet (either a validation error is silently ignored since `left_at` isn't in the validated array, or the assertion on `status` fails because nothing set it).

- [ ] **Step 3: Add the field to the form**

Current (`app/Modules/Members/resources/views/members/_form.blade.php:78-81`, the last field in the "Verein" section):
```blade
            <div class="ui-field">
                <label class="ui-label" for="mf-joined-at">{{ __('members::admin.members.joined_at') }}</label>
                <input id="mf-joined-at" type="date" name="joined_at" value="{{ old('joined_at', optional($member->joined_at ?? null)->format('Y-m-d') ?? now()->toDateString()) }}" class="ui-input">
            </div>
        </div>
    </div>
</div>
```

Replace with:
```blade
            <div class="ui-field">
                <label class="ui-label" for="mf-joined-at">{{ __('members::admin.members.joined_at') }}</label>
                <input id="mf-joined-at" type="date" name="joined_at" value="{{ old('joined_at', optional($member->joined_at ?? null)->format('Y-m-d') ?? now()->toDateString()) }}" class="ui-input">
            </div>
            <div class="ui-field">
                <label class="ui-label" for="mf-left-at">{{ __('members::admin.members.left_at') }}</label>
                <input id="mf-left-at" type="date" name="left_at" value="{{ old('left_at', optional($member->left_at ?? null)->format('Y-m-d')) }}" class="ui-input">
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 4: Wire it through `update()`**

Current (`app/Modules/Members/Http/Controllers/MemberController.php:95-121`):
```php
    public function update(Request $request, Member $member): RedirectResponse
    {
        $validated = $request->validate([
            'member_number' => ['required', 'integer', 'min:1', 'unique:members,member_number,'.$member->id],
            'nuliga_id' => ['nullable', 'integer', 'min:1', 'unique:members,nuliga_id,'.$member->id],
            'firstname' => ['required', 'string', 'max:128'],
            'lastname' => ['required', 'string', 'max:128'],
            'birthdate' => ['nullable', 'date'],
            'gender' => ['nullable', Rule::in(Member::GENDERS)],
            'email' => ['nullable', 'email', 'max:128'],
            'phone' => ['nullable', 'string', 'max:64'],
            'street' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:128'],
            'fee_category_id' => ['nullable', 'exists:fee_categories,id'],
            'joined_at' => ['required', 'date'],
        ]);

        $before = $member->toArray();

        DB::transaction(function () use ($member, $validated, $before) {
            $member->update($validated);
            $this->logChange($member, 'updated', $before);
        });

        return redirect()->route('members.index');
    }
```

Replace with:
```php
    public function update(Request $request, Member $member): RedirectResponse
    {
        $validated = $request->validate([
            'member_number' => ['required', 'integer', 'min:1', 'unique:members,member_number,'.$member->id],
            'nuliga_id' => ['nullable', 'integer', 'min:1', 'unique:members,nuliga_id,'.$member->id],
            'firstname' => ['required', 'string', 'max:128'],
            'lastname' => ['required', 'string', 'max:128'],
            'birthdate' => ['nullable', 'date'],
            'gender' => ['nullable', Rule::in(Member::GENDERS)],
            'email' => ['nullable', 'email', 'max:128'],
            'phone' => ['nullable', 'string', 'max:64'],
            'street' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:128'],
            'fee_category_id' => ['nullable', 'exists:fee_categories,id'],
            'joined_at' => ['required', 'date'],
            'left_at' => ['nullable', 'date'],
        ]);

        $leftAt = $validated['left_at'] ?? null;
        unset($validated['left_at']);

        $before = $member->toArray();

        DB::transaction(function () use ($member, $validated, $before, $leftAt) {
            $member->update($validated);
            $member->setLeftAt($leftAt);
            $this->logChange($member, 'updated', $before);
        });

        return redirect()->route('members.index');
    }
```

(`setLeftAt()` runs as a second `update()` call inside the same transaction — it's the simplest way to reuse the exact coupling logic from Task 1 without duplicating it here. `left_at` is stripped from `$validated` first so the plain `$member->update($validated)` call doesn't race with `setLeftAt()`'s own write to the same column.)

- [ ] **Step 5: Run tests to verify they pass**

Run: `wsl php artisan test tests/Feature/Modules/Members/MemberManagementTest.php`
Expected: PASS (all tests, including the new one).

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Members/resources/views/members/_form.blade.php app/Modules/Members/Http/Controllers/MemberController.php tests/Feature/Modules/Members/MemberManagementTest.php
git commit -m "Add left_at field to the member profile form"
```

---

### Task 5: Mitglieder list — Status pill/toggle, Austritt-am column, filter option

**Files:**
- Modify: `app/Modules/Members/Http/Controllers/MemberController.php` (`index()`)
- Modify: `app/Modules/Members/resources/views/members/index.blade.php`
- Modify: `app/Modules/Members/resources/lang/de/admin.php`, `.../en/admin.php`
- Test: `tests/Feature/Modules/Members/MemberManagementTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Modules/Members/MemberManagementTest.php`:

```php
    #[Test]
    public function the_status_filter_can_show_only_ruhend_members(): void
    {
        \App\Modules\Members\Models\Member::factory()->create(['firstname' => 'Aktiv', 'status' => 'aktiv']);
        \App\Modules\Members\Models\Member::factory()->create(['firstname' => 'Ruhend', 'status' => 'ruhend']);
        \App\Modules\Members\Models\Member::factory()->create(['firstname' => 'Weg', 'status' => 'ausgetreten', 'left_at' => '2026-01-01']);

        $response = $this->actingAs($this->admin())->get('/members?status=ruhend');

        $response->assertOk()->assertSee('Ruhend')->assertDontSee('Weg');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl php artisan test tests/Feature/Modules/Members/MemberManagementTest.php`
Expected: the new test FAILS — the controller's status filter doesn't recognize `ruhend` yet, so `index()` falls through to the `active()` scope, excluding the ruhend member entirely (page won't contain "Ruhend").

- [ ] **Step 3: Update the controller filter and eager-load**

Current (`app/Modules/Members/Http/Controllers/MemberController.php:20-50`):
```php
    public function index(Request $request): View
    {
        $query = Member::query()->with('feeCategory');

        if ($request->filled('status') && $request->string('status')->value() === 'departed') {
            $query->whereNotNull('left_at');
        } else {
            $query->active();
        }

        $search = trim($request->string('search')->value());
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('firstname', 'like', "%{$search}%")
                    ->orWhere('lastname', 'like', "%{$search}%");
            });
        }

        if ($request->filled('fee_category')) {
            $query->where('fee_category_id', $request->integer('fee_category'));
        }

        return view('members::members.index', [
            'members' => $query->orderBy('lastname')->orderBy('firstname')->get(),
            'filters' => array_filter(
                $request->only('status', 'search', 'fee_category'),
                fn ($value) => $value !== null && $value !== '',
            ),
            'feeCategories' => FeeCategory::query()->orderBy('name')->get(),
        ]);
    }
```

Replace with:
```php
    public function index(Request $request): View
    {
        $query = Member::query()->with(['feeCategory', 'dues.fiscalYear']);

        $statusFilter = $request->string('status')->value();
        if (in_array($statusFilter, Member::STATUSES, true)) {
            $query->where('status', $statusFilter);
        } elseif ($statusFilter === '') {
            $query->active();
        }

        $search = trim($request->string('search')->value());
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('firstname', 'like', "%{$search}%")
                    ->orWhere('lastname', 'like', "%{$search}%");
            });
        }

        if ($request->filled('fee_category')) {
            $query->where('fee_category_id', $request->integer('fee_category'));
        }

        return view('members::members.index', [
            'members' => $query->orderBy('lastname')->orderBy('firstname')->get(),
            'filters' => array_filter(
                $request->only('status', 'search', 'fee_category'),
                fn ($value) => $value !== null && $value !== '',
            ),
            'feeCategories' => FeeCategory::query()->orderBy('name')->get(),
        ]);
    }
```

Note the filter value changed from `'active'`/`'departed'` to the raw status values `'aktiv'`/`'ruhend'`/`'ausgetreten'` directly — this matches Step 4's updated filter `<select>` options below. `dues.fiscalYear` is eager-loaded here for Task 6's payment-status column (added now to avoid a second pass over this file; it's unused until Task 6 wires up the column, same sequencing already used in the Beiträge plan for `members`/`feeCategories`).

- [ ] **Step 4: Update the filter select and Status cell**

Current (`app/Modules/Members/resources/views/members/index.blade.php:28-35`):
```blade
                <div class="ui-field min-w-[12rem]">
                    <label class="ui-label" for="mi-status">{{ __('members::admin.members.status') }}</label>
                    <select id="mi-status" name="status" class="ui-select">
                        <option value="">{{ __('members::admin.common.all') }}</option>
                        <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ __('members::admin.members.status_active') }}</option>
                        <option value="departed" @selected(($filters['status'] ?? '') === 'departed')>{{ __('members::admin.members.status_departed') }}</option>
                    </select>
                </div>
```

Replace with:
```blade
                <div class="ui-field min-w-[12rem]">
                    <label class="ui-label" for="mi-status">{{ __('members::admin.members.status') }}</label>
                    <select id="mi-status" name="status" class="ui-select">
                        <option value="aktiv" @selected(($filters['status'] ?? 'aktiv') === 'aktiv')>{{ __('members::admin.members.status_active') }}</option>
                        <option value="ruhend" @selected(($filters['status'] ?? '') === 'ruhend')>{{ __('members::admin.members.status_ruhend') }}</option>
                        <option value="ausgetreten" @selected(($filters['status'] ?? '') === 'ausgetreten')>{{ __('members::admin.members.status_departed') }}</option>
                        <option value="all" @selected(($filters['status'] ?? '') === 'all')>{{ __('members::admin.common.all') }}</option>
                    </select>
                </div>
```

`"all"` is not one of `Member::STATUSES`, so Step 3's `in_array($statusFilter, Member::STATUSES, true)` check is false for it, and it also isn't the empty string — falling through to neither branch, meaning no status filter is applied at all (all three states show). This matches the handoff's explicit "Alle anzeigen" option.

Now find the Status column's `<td>` in the same file (currently a plain `<td class="text-[#6a6e73]">{{ $member->age() ?? '—' }}</td>` sits between Name and Beitragskategorie — Status isn't a column in the current table at all yet; check `<thead>` for the current column list). Current `<thead>` (`app/Modules/Members/resources/views/members/index.blade.php:44-53`):
```blade
                <thead>
                    <tr>
                        <th>{{ __('members::admin.members.member_number') }}</th>
                        <th>{{ __('members::admin.members.name') }}</th>
                        <th>{{ __('members::admin.members.age') }}</th>
                        <th>{{ __('members::admin.members.fee_category') }}</th>
                        <th>{{ __('members::admin.members.email') }}</th>
                        <th>{{ __('members::admin.members.joined_at') }}</th>
                        <th class="text-right">{{ __('members::admin.common.actions') }}</th>
                    </tr>
                </thead>
```

Replace with (adding Status, Austritt am, and Zahlung columns):
```blade
                <thead>
                    <tr>
                        <th>{{ __('members::admin.members.member_number') }}</th>
                        <th>{{ __('members::admin.members.name') }}</th>
                        <th>{{ __('members::admin.members.age') }}</th>
                        <th>{{ __('members::admin.members.status') }}</th>
                        <th>{{ __('members::admin.members.left_at') }}</th>
                        <th>{{ __('members::admin.members.fee_category') }}</th>
                        <th>{{ __('members::admin.members.email') }}</th>
                        <th>{{ __('members::admin.members.joined_at') }}</th>
                        <th>{{ __('members::admin.dues.title') }}</th>
                        <th class="text-right">{{ __('members::admin.common.actions') }}</th>
                    </tr>
                </thead>
```

Now, inside the `@foreach($members as $member)` row, right after the Alter `<td>` (`<td class="text-[#6a6e73]">{{ $member->age() ?? '—' }}</td>`) and before the fee-category `<td>`, add the new Status and Austritt-am cells:
```blade
                            <td data-field="status"
                                x-data="{
                                    editing: false, error: null, justSaved: false,
                                    value: @js($member->status === 'ausgetreten' ? 'aktiv' : $member->status),
                                    displayValue: @js($member->status),
                                    labels: { aktiv: @js(__('members::admin.members.status_active')), ruhend: @js(__('members::admin.members.status_ruhend')), ausgetreten: @js(__('members::admin.members.status_departed')) },
                                    classes: { aktiv: 'ui-badge-success', ruhend: 'ui-badge-warning', ausgetreten: 'ui-badge-info' },
                                    async save() {
                                        this.error = null;
                                        const result = await window.saveMemberField({{ $member->id }}, 'status', this.value);
                                        if (result.ok) {
                                            this.editing = false;
                                            this.value = result.value;
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
                                        <option value="aktiv" x-text="labels.aktiv"></option>
                                        <option value="ruhend" x-text="labels.ruhend"></option>
                                    </select>
                                    <p x-show="error" x-text="error" class="ui-error"></p>
                                </div>
                            </td>
                            <td data-field="left_at"
                                x-data="{
                                    editing: false, error: null, justSaved: false,
                                    value: @js($member->left_at?->format('Y-m-d') ?? ''),
                                    display: @js($member->left_at?->format('d.m.Y') ?? '–'),
                                    async save() {
                                        this.error = null;
                                        const result = await window.saveMemberField({{ $member->id }}, 'left_at', this.value || null);
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
```

The Status cell's edit `<select>` only lists `aktiv`/`ruhend` (matching Task 3's server-side whitelist) — if a member is currently `ausgetreten`, opening the editor defaults `value` to `'aktiv'` (since `ausgetreten` isn't one of the two selectable options), so picking either option from a departed member's dropdown reactivates them; the read-only closed-state span still correctly shows "Ausgetreten" via `displayValue` beforehand. Reactivating this way only changes `status`/`left_at` via `setStatus('aktiv')` — consistent with the spec.

- [ ] **Step 5: Add the missing lang key**

Add to `app/Modules/Members/resources/lang/de/admin.php`, inside `'members'`, after `'status_active' => 'Aktiv',`:
```php
        'status_ruhend' => 'Ruhend',
```

Add to `app/Modules/Members/resources/lang/en/admin.php`, inside `'members'`, after `'status_active' => 'Active',`:
```php
        'status_ruhend' => 'Dormant',
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `wsl php artisan test tests/Feature/Modules/Members/MemberManagementTest.php`
Expected: PASS (all tests, including the new one).

- [ ] **Step 7: Run the full Members suite**

Run: `wsl php artisan test --filter=Members`
Expected: all pass (this task's `dues.fiscalYear` eager-load and reordered columns shouldn't break anything else; confirm).

- [ ] **Step 8: Manual verification**

Start the dev server, open `/members`, confirm: filter dropdown shows Aktiv/Ruhend/Ausgetreten/Alle anzeigen in that order and defaults to Aktiv; the Status pill is clickable, shows the right color, and the dropdown only offers Aktiv/Ruhend; the Austritt-am cell is a clickable date field that persists on blur and switches the Status pill to Ausgetreten after a reload... (note: unlike the dues screen, this Status cell's `displayValue` won't auto-update from an `left_at` edit in the *same* cell interaction without a reload, since they're two separate Alpine components — confirm this is acceptable by reloading the page after setting a date and checking the pill updated correctly server-side).

- [ ] **Step 9: Commit**

```bash
git add app/Modules/Members/Http/Controllers/MemberController.php app/Modules/Members/resources/views/members/index.blade.php app/Modules/Members/resources/lang/de/admin.php app/Modules/Members/resources/lang/en/admin.php tests/Feature/Modules/Members/MemberManagementTest.php
git commit -m "Add editable Status pill and Austritt-am column, extend status filter to Ruhend"
```

---

### Task 6: Payment-status ("Zahlung") column and Ruhend exclusion from due generation

**Files:**
- Modify: `app/Modules/Members/resources/views/members/index.blade.php`
- Test: `tests/Feature/Modules/Members/MemberManagementTest.php`, `tests/Feature/Modules/Members/MemberDueGenerationTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Modules/Members/MemberManagementTest.php`:

```php
    #[Test]
    public function the_list_shows_a_dash_for_a_member_with_no_dues(): void
    {
        \App\Modules\Members\Models\Member::factory()->create(['firstname' => 'Ohne', 'lastname' => 'Beitrag']);

        $this->actingAs($this->admin())->get('/members')
            ->assertOk()
            ->assertSee('Ohne Beitrag');
    }

    #[Test]
    public function the_list_shows_the_latest_dues_status(): void
    {
        $member = \App\Modules\Members\Models\Member::factory()->create(['firstname' => 'Bezahler']);
        $category = \App\Modules\Members\Models\FeeCategory::create(['name' => 'Vollmitglied']);
        $fy = \App\Modules\Members\Models\FiscalYear::create(['name' => '2026/27', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31']);
        \App\Modules\Members\Models\MemberDue::create([
            'member_id' => $member->id, 'fiscal_year_id' => $fy->id, 'fee_category_id' => $category->id,
            'amount' => 120, 'status' => 'bezahlt',
        ]);

        $this->actingAs($this->admin())->get('/members')
            ->assertOk()
            ->assertSeeInOrder(['Bezahler', 'Bezahlt']);
    }
```

Add to `tests/Feature/Modules/Members/MemberDueGenerationTest.php`, after `generating_dues_creates_one_per_active_member_using_last_years_category`:

```php
    #[Test]
    public function generating_dues_skips_ruhend_members(): void
    {
        $category = FeeCategory::create(['name' => 'Vollmitglied']);
        $lastYear = FiscalYear::create(['name' => '2025/26', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31']);
        $newYear = FiscalYear::create(['name' => '2026/27', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31']);
        FeeCategoryRate::create(['fee_category_id' => $category->id, 'fiscal_year_id' => $newYear->id, 'amount' => 130]);

        $ruhendMember = Member::factory()->create(['status' => 'ruhend']);
        MemberDue::create(['member_id' => $ruhendMember->id, 'fiscal_year_id' => $lastYear->id, 'fee_category_id' => $category->id, 'amount' => 120]);

        $this->actingAs($this->admin())->post('/members/dues/generate', ['fiscal_year_id' => $newYear->id]);

        $this->assertDatabaseMissing('member_dues', [
            'member_id' => $ruhendMember->id,
            'fiscal_year_id' => $newYear->id,
        ]);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `wsl php artisan test tests/Feature/Modules/Members/MemberManagementTest.php tests/Feature/Modules/Members/MemberDueGenerationTest.php`
Expected: `the_list_shows_the_latest_dues_status` FAILS (no "Zahlung" column exists yet to show "Bezahlt"); `generating_dues_skips_ruhend_members` should already PASS (Task 1's `scopeActive()` change already covers this — this test just confirms it explicitly for this spec's own record; if it fails, something in Task 1 is wrong and must be fixed before continuing). `the_list_shows_a_dash_for_a_member_with_no_dues` should already pass (it's just checking the member's name still renders).

- [ ] **Step 3: Add the Zahlung column**

In `app/Modules/Members/resources/views/members/index.blade.php`, add a new `<th>` to the `<thead>` row built in Task 5 (`{{ __('members::admin.dues.title') }}` was already added there as a placeholder header — leave it in place, it's this column's header) and add the corresponding `<td>` to each row, right after the `joined_at` cell and before the Aktionen `<td>`:

```blade
                            <td>
                                @php $latestDue = $member->dues->sortByDesc(fn ($due) => $due->fiscalYear->starts_on)->first(); @endphp
                                @if($latestDue)
                                    <span class="ui-badge {{ match($latestDue->displayStatus()) { 'bezahlt' => 'ui-badge-success', 'ausstehend' => 'ui-badge-warning', 'ueberfaellig' => 'ui-badge-danger' } }}">
                                        {{ __('members::admin.dues.status_'.$latestDue->displayStatus()) }}
                                    </span>
                                @else
                                    <span class="text-[#6a6e73]">–</span>
                                @endif
                            </td>
```

This relies on `$member->dues` already being eager-loaded with `fiscalYear` from Task 5's `index()` change (`->with(['feeCategory', 'dues.fiscalYear'])`), so `sortByDesc` runs in memory, not as an extra query per row.

- [ ] **Step 4: Run tests to verify they pass**

Run: `wsl php artisan test tests/Feature/Modules/Members/MemberManagementTest.php tests/Feature/Modules/Members/MemberDueGenerationTest.php`
Expected: PASS (all tests in both files).

- [ ] **Step 5: Run the full Members suite**

Run: `wsl php artisan test --filter=Members`
Expected: all pass.

- [ ] **Step 6: Manual verification**

Reload `/members`, confirm the Zahlung column shows the correct colored label for members with dues and "–" for members without.

- [ ] **Step 7: Commit**

```bash
git add app/Modules/Members/resources/views/members/index.blade.php tests/Feature/Modules/Members/MemberManagementTest.php tests/Feature/Modules/Members/MemberDueGenerationTest.php
git commit -m "Add payment-status column to the Mitglieder list, confirm Ruhend exclusion from due generation"
```

---

### Task 7: Full regression pass

**Files:** none (verification only)

- [ ] **Step 1: Run the entire Members test suite**

Run: `wsl php artisan test --filter=Members`
Expected: all tests pass, zero failures.

- [ ] **Step 2: Manual browser walkthrough**

Using the `laravel-serve` preview config: visit `/members`, exercise every new interaction from Tasks 5-6 (status filter including Ruhend, Status pill edit, Austritt-am date edit, Zahlung column) end-to-end; visit `/members/{id}/edit` and confirm the new `left_at` field works and reflects in the list after saving; visit `/members/dues` and confirm nothing there regressed.

No commit for this task — verification only.

---

## Self-review notes

- **Spec coverage:** data model + coupling (Task 1), Austragen removal (Task 2), field-update whitelist (Task 3), form field (Task 4), list Status/Austritt-am/filter (Task 5), payment pill + Ruhend exclusion confirmation (Task 6). Every requirement in the spec maps to a task, including the explicit test-list items (route removal, filter results, rejection of manual `ausgetreten`).
- **Placeholder scan:** none — every step has literal code, exact file paths, and exact commands.
- **Type/name consistency:** `Member::STATUSES` (Task 1) matches `Rule::in(Member::STATUSES)`-style checks used in `index()` (Task 5, via `in_array(..., Member::STATUSES, true)`) and the two-value whitelist in `updateField()` (Task 3, `Rule::in(['aktiv', 'ruhend'])` — deliberately a subset, not the full constant, since `ausgetreten` is excluded from manual entry). `setLeftAt()`/`setStatus()` signatures defined in Task 1 are used identically in Tasks 3 and 4. `MemberDue::displayStatus()`/`STATUSES` (from the prior Beiträge plan) are reused as-is in Task 6, not redefined.
