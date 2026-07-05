# Member Change Log Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Record who changed what on a `Member` record (create, edit, inline field edit, mark departed) in a new `member_changes` table, and show that history on the member's edit page.

**Architecture:** Logging happens explicitly inside `MemberController`'s four mutating actions (`store`, `update`, `updateField`, `destroy`) via a small private helper, not through a global Eloquent observer — this keeps `ImportMembersCommand` (CSV import) and `DatabaseBackupService::restore()` out of the log without any suppression logic, since neither goes through the controller. Each log row stores a full before/after snapshot of the member's attributes as JSON; the UI derives "what changed" by diffing the two snapshots at render time.

**Tech Stack:** Laravel 13, PHP 8.3, PHPUnit with `#[Test]` attributes, MySQL in production / SQLite in tests.

**Spec:** `docs/superpowers/specs/2026-07-05-member-change-log-design.md`

---

## File Structure

```
app/Modules/Members/Database/Migrations/2026_07_05_090001_create_member_changes_table.php
app/Modules/Members/Models/MemberChange.php
app/Modules/Members/Http/Controllers/MemberController.php   (modify: store/update/updateField/destroy/edit)
app/Modules/Members/resources/views/members/_history.blade.php
app/Modules/Members/resources/views/members/edit.blade.php   (modify: include _history)
app/Modules/Members/resources/lang/de/admin.php               (modify: add left_at + history keys)
app/Modules/Members/resources/lang/en/admin.php                (modify: add left_at + history keys)

tests/Unit/Modules/Members/MemberChangeTest.php
tests/Feature/Modules/Members/MemberChangeLogTest.php
```

---

### Task 1: `member_changes` table and `MemberChange` model

**Files:**
- Create: `app/Modules/Members/Database/Migrations/2026_07_05_090001_create_member_changes_table.php`
- Create: `app/Modules/Members/Models/MemberChange.php`
- Test: `tests/Unit/Modules/Members/MemberChangeTest.php`

**Context:** `bs_users.uid` is an `increments()` column (unsigned INT, not bigint) — see `database/migrations/2026_06_26_073602_create_bs_users_table.php:22`. `foreignId()` always creates an unsigned **big** integer, which would mismatch that column's type in MySQL, so the FK to `bs_users` must be declared with a plain `unsignedInteger()` + explicit `foreign()`, not `foreignId()`.

- [ ] **Step 1: Write the migration**

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
        Schema::create('member_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('admin_user_id');
            $table->foreign('admin_user_id')->references('uid')->on('bs_users')->cascadeOnDelete();
            $table->string('action', 16);
            $table->json('before')->nullable();
            $table->json('after');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_changes');
    }
};
```

- [ ] **Step 2: Write the model**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Members\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberChange extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['member_id', 'admin_user_id', 'action', 'before', 'after'];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id', 'uid');
    }

    /**
     * Diffs `after` against `before`, skipping unchanged and internal keys.
     *
     * @return array<int, array{field: string, old: mixed, new: mixed}>
     */
    public function changedFields(): array
    {
        if ($this->before === null) {
            return [];
        }

        $ignored = ['id', 'created_at', 'updated_at'];
        $changes = [];

        foreach ($this->after as $field => $newValue) {
            if (in_array($field, $ignored, true)) {
                continue;
            }

            $oldValue = $this->before[$field] ?? null;

            if ($oldValue !== $newValue) {
                $changes[] = ['field' => $field, 'old' => $oldValue, 'new' => $newValue];
            }
        }

        return $changes;
    }
}
```

- [ ] **Step 3: Write a failing test for `changedFields()`**

Create `tests/Unit/Modules/Members/MemberChangeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members;

use App\Models\User;
use App\Modules\Members\Models\Member;
use App\Modules\Members\Models\MemberChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberChangeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function changed_fields_returns_only_differing_keys_and_ignores_timestamps(): void
    {
        $member = Member::factory()->create();
        $admin = User::factory()->create(['status' => 'admin']);

        $change = MemberChange::create([
            'member_id' => $member->id,
            'admin_user_id' => $admin->uid,
            'action' => 'updated',
            'before' => ['id' => $member->id, 'firstname' => 'Alt', 'lastname' => 'Gleich', 'updated_at' => 'y'],
            'after' => ['id' => $member->id, 'firstname' => 'Neu', 'lastname' => 'Gleich', 'updated_at' => 'z'],
        ]);

        $this->assertSame(
            [['field' => 'firstname', 'old' => 'Alt', 'new' => 'Neu']],
            $change->changedFields(),
        );
    }

    #[Test]
    public function changed_fields_is_empty_when_before_is_null(): void
    {
        $member = Member::factory()->create();
        $admin = User::factory()->create(['status' => 'admin']);

        $change = MemberChange::create([
            'member_id' => $member->id,
            'admin_user_id' => $admin->uid,
            'action' => 'created',
            'before' => null,
            'after' => ['firstname' => 'Neu'],
        ]);

        $this->assertSame([], $change->changedFields());
    }
}
```

- [ ] **Step 4: Run test to verify it fails, then passes**

Run: `wsl php artisan test --filter=MemberChangeTest`
Expected before Steps 1-2 are saved: FAIL (table/class not found). After: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Members/Database/Migrations/2026_07_05_090001_create_member_changes_table.php app/Modules/Members/Models/MemberChange.php tests/Unit/Modules/Members/MemberChangeTest.php
git commit -m "Add member_changes table and MemberChange model"
```

---

### Task 2: Log changes from `MemberController`

**Files:**
- Modify: `app/Modules/Members/Http/Controllers/MemberController.php`
- Test: `tests/Feature/Modules/Members/MemberChangeLogTest.php` (new)

**Current state of the four methods being modified** (read `app/Modules/Members/Http/Controllers/MemberController.php` yourself first to confirm this hasn't drifted further):

```php
public function store(Request $request): RedirectResponse
{
    $validated = $request->validate([
        'member_number' => ['required', 'integer', 'min:1', 'unique:members,member_number'],
        'nuliga_id' => ['nullable', 'integer', 'min:1', 'unique:members,nuliga_id'],
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

    Member::create($validated);

    return redirect()->route('members.index');
}
```
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

    $member->update($validated);

    return redirect()->route('members.index');
}
```
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

    try {
        $member->update([$validated['field'] => $validated['value']]);
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
```php
public function destroy(Member $member): RedirectResponse
{
    $member->update(['left_at' => $member->left_at ?? now()->toDateString()]);

    return redirect()->route('members.index');
}
```

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Modules/Members/MemberChangeLogTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Members;

use App\Models\User;
use App\Modules\Members\Models\Member;
use App\Modules\Members\Models\MemberChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberChangeLogTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['status' => 'admin']);
    }

    #[Test]
    public function creating_a_member_logs_a_created_change(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/members', [
            'member_number' => 1,
            'firstname' => 'Neu',
            'lastname' => 'Mitglied',
            'joined_at' => '2026-01-01',
        ]);

        $member = Member::where('member_number', 1)->firstOrFail();
        $change = MemberChange::where('member_id', $member->id)->firstOrFail();

        $this->assertSame('created', $change->action);
        $this->assertSame($admin->uid, $change->admin_user_id);
        $this->assertNull($change->before);
        $this->assertSame('Neu', $change->after['firstname']);
    }

    #[Test]
    public function updating_a_member_logs_the_before_and_after_snapshot(): void
    {
        $member = Member::factory()->create(['firstname' => 'Alt']);

        $this->actingAs($this->admin())->put("/members/{$member->id}", [
            'member_number' => $member->member_number,
            'firstname' => 'Neu',
            'lastname' => $member->lastname,
            'joined_at' => $member->joined_at->toDateString(),
        ]);

        $change = MemberChange::where('member_id', $member->id)->where('action', 'updated')->firstOrFail();

        $this->assertSame('Alt', $change->before['firstname']);
        $this->assertSame('Neu', $change->after['firstname']);
    }

    #[Test]
    public function updating_a_single_field_inline_logs_an_updated_change(): void
    {
        $member = Member::factory()->create(['firstname' => 'Alt']);

        $this->actingAs($this->admin())->patch("/members/{$member->id}/field", [
            'field' => 'firstname',
            'value' => 'Neu',
        ]);

        $change = MemberChange::where('member_id', $member->id)->where('action', 'updated')->firstOrFail();

        $this->assertSame('Alt', $change->before['firstname']);
        $this->assertSame('Neu', $change->after['firstname']);
    }

    #[Test]
    public function marking_a_member_departed_logs_a_departed_change(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($this->admin())->delete("/members/{$member->id}");

        $change = MemberChange::where('member_id', $member->id)->where('action', 'departed')->firstOrFail();

        $this->assertNull($change->before['left_at']);
        $this->assertNotNull($change->after['left_at']);
    }

    #[Test]
    public function csv_import_does_not_write_change_log_rows(): void
    {
        $csv = <<<CSV
        Name,Vorname,Mitgliedsart,MB ,Bemerkung,Email,Strasse,PLZ,Ort,Privat,Mobil,Geschlecht,GebDatum,ID-Nr,Alter,Reservierungssystem
        Muster,Anna,Vollmitglied,,,,,,,,,,,,,
        CSV;
        $file = sys_get_temp_dir().'/member-change-log-import-test.csv';
        file_put_contents($file, $csv);

        $this->artisan('members:import-csv', ['file' => $file])->assertSuccessful();

        $this->assertSame(0, MemberChange::count());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `wsl php artisan test --filter=MemberChangeLogTest`
Expected: The first 4 tests FAIL (`firstOrFail()` throws — no `member_changes` rows are written yet). The CSV import test PASSES already (nothing logs today), which is fine — it stays green through this task and is what proves the exclusion still holds after Step 3.

- [ ] **Step 3: Add the logging helper and call it from all four actions**

Edit `app/Modules/Members/Http/Controllers/MemberController.php`. Add the import:

```php
use App\Modules\Members\Models\MemberChange;
```

Add this private method to the class (e.g. right after `destroy()`):

```php
    private function logChange(Member $member, string $action, ?array $before): void
    {
        MemberChange::create([
            'member_id' => $member->id,
            'admin_user_id' => auth()->id(),
            'action' => $action,
            'before' => $before,
            'after' => $member->fresh()->toArray(),
        ]);
    }
```

Change `store()`'s body from `Member::create($validated);` to:

```php
        $member = Member::create($validated);
        $this->logChange($member, 'created', null);
```

Change `update()`'s body from `$member->update($validated);` to:

```php
        $before = $member->toArray();
        $member->update($validated);
        $this->logChange($member, 'updated', $before);
```

In `updateField()`, insert a snapshot before the mutating `try` block and log after it succeeds — change:

```php
    try {
        $member->update([$validated['field'] => $validated['value']]);
    } catch (\Throwable $e) {
        report($e);

        return response()->json(['message' => __('members::admin.members.field_update_failed')], 500);
    }

    return response()->json([
```

to:

```php
    $before = $member->toArray();

    try {
        $member->update([$validated['field'] => $validated['value']]);
    } catch (\Throwable $e) {
        report($e);

        return response()->json(['message' => __('members::admin.members.field_update_failed')], 500);
    }

    $this->logChange($member, 'updated', $before);

    return response()->json([
```

Change `destroy()` from:

```php
    public function destroy(Member $member): RedirectResponse
    {
        $member->update(['left_at' => $member->left_at ?? now()->toDateString()]);

        return redirect()->route('members.index');
    }
```

to:

```php
    public function destroy(Member $member): RedirectResponse
    {
        $before = $member->toArray();
        $member->update(['left_at' => $member->left_at ?? now()->toDateString()]);
        $this->logChange($member, 'departed', $before);

        return redirect()->route('members.index');
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `wsl php artisan test --filter=MemberChangeLogTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Run the full Members regression suite**

Run: `wsl php artisan test --testsuite=Members`
Expected: PASS, no regressions in the pre-existing `MemberManagementTest` (the `updateField`/`update`/`destroy` behavior itself is unchanged — only a side-effect log row is added).

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Members/Http/Controllers/MemberController.php tests/Feature/Modules/Members/MemberChangeLogTest.php
git commit -m "Log member create/update/depart actions to member_changes"
```

---

### Task 3: Show change history on the member edit page

**Files:**
- Modify: `app/Modules/Members/Http/Controllers/MemberController.php` (`edit()` method)
- Create: `app/Modules/Members/resources/views/members/_history.blade.php`
- Modify: `app/Modules/Members/resources/views/members/edit.blade.php`
- Modify: `app/Modules/Members/resources/lang/de/admin.php`, `app/Modules/Members/resources/lang/en/admin.php`
- Test: `tests/Feature/Modules/Members/MemberChangeLogTest.php` (extend)

**Current `edit()` method** (confirm this still matches before editing):

```php
    public function edit(Member $member): View
    {
        return view('members::members.edit', [
            'member' => $member,
            'feeCategories' => FeeCategory::query()->orderBy('name')->get(),
        ]);
    }
```

**Current `edit.blade.php`:**

```blade
@extends('members::layout')
@section('admin-title', __('members::admin.members.title'))
@section('admin-content')
<div class="ui-page">
    <div class="ui-page-header">
        <h1>{{ $member->fullName() }}</h1>
    </div>
    <form method="POST" action="{{ route('members.update', $member) }}" class="ui-form-shell">
        @method('PUT')
        @include('members::members._form', ['member' => $member])
        <div class="ui-form-actions">
            <a href="{{ route('members.payments.create', $member) }}" class="ui-btn ui-btn-ghost">{{ __('members::admin.payments.new_payment') }}</a>
            <a href="{{ route('members.index') }}" class="ui-btn ui-btn-ghost">{{ __('members::admin.common.cancel') }}</a>
            <button type="submit" class="ui-btn ui-btn-primary">{{ __('members::admin.common.save') }}</button>
        </div>
    </form>
</div>
@endsection
```

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Modules/Members/MemberChangeLogTest.php`:

```php
    #[Test]
    public function edit_page_shows_change_history(): void
    {
        $admin = $this->admin();
        $member = Member::factory()->create(['firstname' => 'Alt']);

        MemberChange::create([
            'member_id' => $member->id,
            'admin_user_id' => $admin->uid,
            'action' => 'updated',
            'before' => ['firstname' => 'Alt'],
            'after' => ['firstname' => 'Neu'],
        ]);

        $this->actingAs($admin)->get("/members/{$member->id}/edit")
            ->assertOk()
            ->assertSee($admin->alias)
            ->assertSee('Alt')
            ->assertSee('Neu');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl php artisan test --filter=MemberChangeLogTest`
Expected: FAIL (`assertSee($admin->alias)` etc. — the edit page doesn't render any history yet).

- [ ] **Step 3: Add the lang keys**

Edit `app/Modules/Members/resources/lang/de/admin.php`. Add `'left_at' => 'Ausgetreten am',` to the `'members'` array (next to `'joined_at'`), and add a new top-level `'history'` array after `'dashboard'`:

```php
    'history' => [
        'title' => 'Änderungshistorie',
        'created' => 'Angelegt',
        'no_changes' => 'Keine Feldänderungen.',
        'empty' => 'Keine Änderungen protokolliert.',
    ],
```

Edit `app/Modules/Members/resources/lang/en/admin.php` the same way: add `'left_at' => 'Departed on',` to `'members'`, and:

```php
    'history' => [
        'title' => 'Change history',
        'created' => 'Created',
        'no_changes' => 'No field changes.',
        'empty' => 'No changes recorded.',
    ],
```

- [ ] **Step 4: Add `$changes` to the `edit()` view data**

Edit `app/Modules/Members/Http/Controllers/MemberController.php`, change `edit()` to:

```php
    public function edit(Member $member): View
    {
        return view('members::members.edit', [
            'member' => $member,
            'feeCategories' => FeeCategory::query()->orderBy('name')->get(),
            'changes' => MemberChange::where('member_id', $member->id)->with('adminUser')->latest()->get(),
        ]);
    }
```

- [ ] **Step 5: Write the history partial**

Create `app/Modules/Members/resources/views/members/_history.blade.php`:

```blade
@php
    $dateFields = ['birthdate', 'joined_at', 'left_at'];
    $fieldLabels = [
        'member_number' => __('members::admin.members.member_number'),
        'nuliga_id' => __('members::admin.members.nuliga_id'),
        'firstname' => __('members::admin.members.firstname'),
        'lastname' => __('members::admin.members.lastname'),
        'birthdate' => __('members::admin.members.birthdate'),
        'gender' => __('members::admin.members.gender'),
        'email' => __('members::admin.members.email'),
        'phone' => __('members::admin.members.phone'),
        'street' => __('members::admin.members.street'),
        'zip' => __('members::admin.members.zip'),
        'city' => __('members::admin.members.city'),
        'fee_category_id' => __('members::admin.members.fee_category'),
        'joined_at' => __('members::admin.members.joined_at'),
        'left_at' => __('members::admin.members.left_at'),
    ];
    $formatHistoryValue = function ($field, $value) use ($dateFields) {
        if ($value === null || $value === '') {
            return '—';
        }
        if (in_array($field, $dateFields, true)) {
            return \Illuminate\Support\Carbon::parse($value)->format('d.m.Y');
        }
        if ($field === 'gender') {
            return match ($value) {
                'm' => __('members::admin.members.gender_m'),
                'w' => __('members::admin.members.gender_w'),
                'd' => __('members::admin.members.gender_d'),
                default => $value,
            };
        }
        return $value;
    };
@endphp
<div class="ui-card">
    <div class="ui-card-header"><h2>{{ __('members::admin.history.title') }}</h2></div>
    <div class="ui-card-body ui-stack">
        @forelse($changes as $change)
            <div class="ui-stack" style="border-bottom:1px solid #e7e3da; padding-bottom:12px; margin-bottom:12px;">
                <p class="ui-kpi-meta">
                    {{ $change->created_at->format('d.m.Y H:i') }} — {{ $change->adminUser->alias ?? '—' }}
                </p>
                @if($change->action === 'created')
                    <p>{{ __('members::admin.history.created') }}</p>
                @else
                    @php $fields = $change->changedFields(); @endphp
                    @if($fields === [])
                        <p class="ui-kpi-meta">{{ __('members::admin.history.no_changes') }}</p>
                    @else
                        <ul>
                            @foreach($fields as $fieldChange)
                                <li>
                                    {{ $fieldLabels[$fieldChange['field']] ?? $fieldChange['field'] }}:
                                    {{ $formatHistoryValue($fieldChange['field'], $fieldChange['old']) }}
                                    →
                                    {{ $formatHistoryValue($fieldChange['field'], $fieldChange['new']) }}
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </div>
        @empty
            <p class="ui-kpi-meta">{{ __('members::admin.history.empty') }}</p>
        @endforelse
    </div>
</div>
```

- [ ] **Step 6: Include the partial from the edit page**

Edit `app/Modules/Members/resources/views/members/edit.blade.php`, adding the include right after the closing `</form>`:

```blade
@extends('members::layout')
@section('admin-title', __('members::admin.members.title'))
@section('admin-content')
<div class="ui-page">
    <div class="ui-page-header">
        <h1>{{ $member->fullName() }}</h1>
    </div>
    <form method="POST" action="{{ route('members.update', $member) }}" class="ui-form-shell">
        @method('PUT')
        @include('members::members._form', ['member' => $member])
        <div class="ui-form-actions">
            <a href="{{ route('members.payments.create', $member) }}" class="ui-btn ui-btn-ghost">{{ __('members::admin.payments.new_payment') }}</a>
            <a href="{{ route('members.index') }}" class="ui-btn ui-btn-ghost">{{ __('members::admin.common.cancel') }}</a>
            <button type="submit" class="ui-btn ui-btn-primary">{{ __('members::admin.common.save') }}</button>
        </div>
    </form>

    @include('members::members._history', ['changes' => $changes])
</div>
@endsection
```

- [ ] **Step 7: Run test to verify it passes**

Run: `wsl php artisan test --filter=MemberChangeLogTest`
Expected: PASS (6 tests).

- [ ] **Step 8: Run the full Members regression suite**

Run: `wsl php artisan test --testsuite=Members`
Expected: PASS, no regressions.

- [ ] **Step 9: Commit**

```bash
git add app/Modules/Members/Http/Controllers/MemberController.php app/Modules/Members/resources/views/members/_history.blade.php app/Modules/Members/resources/views/members/edit.blade.php app/Modules/Members/resources/lang/de/admin.php app/Modules/Members/resources/lang/en/admin.php tests/Feature/Modules/Members/MemberChangeLogTest.php
git commit -m "Show member change history on the edit page"
```

---

## Final Verification

- [ ] Run `wsl php artisan test --testsuite=Members` — all tests pass (baseline + 8 new: 2 unit + 6 feature).
- [ ] Run `wsl php artisan test` (full suite) — no new failures beyond the pre-existing, unrelated date-drift flakes documented in earlier sessions.
- [ ] Manually verify in the browser: edit a member's name, refresh the edit page, confirm the change history shows "Vorname: <old> → <new>" with your admin name and a timestamp.
