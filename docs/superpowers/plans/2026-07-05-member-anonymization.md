# Member anonymization (DSGVO) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an admin anonymize a departed member's personal data (name, email, phone, address, external federation ID) once a configurable retention period has passed since they left, while preserving `member_number`, `birthdate`, `gender`, dates, `fee_category_id`, and all `dues`/`payments` history.

**Architecture:** A new `anonymized_at` column and `Member::isAnonymizable()` gate the feature. The retention period is stored as an `Option` row (same mechanism as all other admin-configurable behavior settings) and edited on the existing "Verhalten" admin page. A new `MemberController::anonymize()` action, protected by the existing `admin.members` permission and a typed-keyword confirmation (same UX as the DB migrate/restore actions), performs the field wipe. The member edit page shows the action (or the anonymized state) and locks the PII inputs once anonymized.

**Tech Stack:** Laravel (PHP), Blade views, PHPUnit feature/unit tests, the existing `App\Models\Option` key/value settings store.

---

### Task 1: `anonymized_at` column and `Member::isAnonymizable()`

**Files:**
- Create: `app/Modules/Members/Database/Migrations/2026_07_05_090001_add_anonymized_at_to_members.php`
- Modify: `app/Modules/Members/Models/Member.php`
- Test: `tests/Unit/Modules/Members/MemberIsAnonymizableTest.php`

- [ ] **Step 1: Write the failing unit test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members;

use App\Models\Option;
use App\Modules\Members\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberIsAnonymizableTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_active_member_is_not_anonymizable(): void
    {
        $member = Member::factory()->create(['left_at' => null]);

        $this->assertFalse($member->isAnonymizable());
    }

    #[Test]
    public function an_already_anonymized_member_is_not_anonymizable_again(): void
    {
        $member = Member::factory()->create([
            'left_at' => now()->subYears(5)->toDateString(),
            'anonymized_at' => now(),
        ]);

        $this->assertFalse($member->isAnonymizable());
    }

    #[Test]
    public function a_member_departed_within_the_retention_period_is_not_anonymizable(): void
    {
        $member = Member::factory()->create(['left_at' => now()->subYears(2)->toDateString()]);

        $this->assertFalse($member->isAnonymizable());
    }

    #[Test]
    public function a_member_departed_past_the_default_three_year_retention_is_anonymizable(): void
    {
        $member = Member::factory()->create(['left_at' => now()->subYears(3)->subDay()->toDateString()]);

        $this->assertTrue($member->isAnonymizable());
    }

    #[Test]
    public function the_retention_period_respects_the_configured_option(): void
    {
        Option::create(['key' => 'members.anonymization.retention_years', 'value' => '1', 'locale' => null]);

        $member = Member::factory()->create(['left_at' => now()->subYears(2)->toDateString()]);

        $this->assertTrue($member->isAnonymizable());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `wsl.exe -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test tests/Unit/Modules/Members/MemberIsAnonymizableTest.php"`
Expected: FAIL — `Call to undefined method App\Modules\Members\Models\Member::isAnonymizable()` (and the migration for `anonymized_at` doesn't exist yet, so factory creation itself may already fail with an unknown column error once the method is added — that's expected at this point).

- [ ] **Step 3: Create the migration**

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
            $table->timestamp('anonymized_at')->nullable()->after('left_at');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('anonymized_at');
        });
    }
};
```

- [ ] **Step 4: Update the `Member` model**

In `app/Modules/Members/Models/Member.php`, add `anonymized_at` to `$fillable` and `$casts`, and add the `isAnonymizable()` method:

```php
    protected $fillable = [
        'member_number', 'nuliga_id', 'firstname', 'lastname', 'birthdate', 'gender',
        'email', 'phone', 'street', 'zip', 'city', 'fee_category_id', 'joined_at', 'left_at',
        'anonymized_at',
    ];

    protected $casts = [
        'birthdate' => 'date',
        'joined_at' => 'date',
        'left_at' => 'date',
        'anonymized_at' => 'datetime',
    ];
```

Add the method (near `scopeActive`, using the fully-qualified `Option` class):

```php
    public function isAnonymizable(): bool
    {
        if ($this->anonymized_at !== null || $this->left_at === null) {
            return false;
        }

        $retentionYears = (int) \App\Models\Option::getValue('members.anonymization.retention_years', 3);

        return $this->left_at->lt(now()->subYears($retentionYears));
    }
```

- [ ] **Step 5: Run the migration and the test**

Run: `wsl.exe -e bash -lc "cd /mnt/c/development/bookingnew && php artisan migrate && php artisan test tests/Unit/Modules/Members/MemberIsAnonymizableTest.php"`
Expected: PASS (5 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Members/Database/Migrations/2026_07_05_090001_add_anonymized_at_to_members.php app/Modules/Members/Models/Member.php tests/Unit/Modules/Members/MemberIsAnonymizableTest.php
git commit -m "Add anonymized_at column and Member::isAnonymizable()"
```

---

### Task 2: Retention period as an admin-configurable option

**Files:**
- Modify: `app/Http/Controllers/Admin/OptionController.php`
- Modify: `resources/views/admin/config/behavior.blade.php`
- Modify: `lang/de/booking/admin.php`
- Modify: `lang/en/booking/admin.php`
- Test: `tests/Feature/Admin/MemberAnonymizationRetentionOptionTest.php`

- [ ] **Step 1: Write the failing feature test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Option;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberAnonymizationRetentionOptionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['status' => 'admin']);
    }

    #[Test]
    public function the_behavior_page_defaults_the_retention_field_to_three_years(): void
    {
        $this->actingAs($this->admin())->get(route('admin.config.behavior.edit'))
            ->assertOk()
            ->assertSee('name="members_anonymization_retention_years"', false)
            ->assertSee('value="3"', false);
    }

    #[Test]
    public function an_admin_can_update_the_retention_period(): void
    {
        $this->actingAs($this->admin())->put(route('admin.config.behavior.update'), [
            'members_anonymization_retention_years' => 5,
        ])->assertRedirect(route('admin.config.behavior.edit'));

        $this->assertSame('5', Option::getValue('members.anonymization.retention_years'));
    }

    #[Test]
    public function the_retention_period_must_be_a_non_negative_integer(): void
    {
        $this->actingAs($this->admin())->put(route('admin.config.behavior.update'), [
            'members_anonymization_retention_years' => -1,
        ])->assertSessionHasErrors('members_anonymization_retention_years');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `wsl.exe -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/Admin/MemberAnonymizationRetentionOptionTest.php"`
Expected: FAIL — the field is not rendered on the page yet, and the update is silently ignored (unmapped key).

- [ ] **Step 3: Add the option to `OptionController`**

In `app/Http/Controllers/Admin/OptionController.php`, add to `BEHAVIOR_MAP`:

```php
    private const BEHAVIOR_MAP = [
        'registration'        => 'service.user.registration',
        'activation'          => 'service.user.activation',
        'maintenance'         => 'service.maintenance',
        'quotes_enabled'      => 'service.quotes.enabled',
        'calendar_hide'       => 'service.calendar.day-exceptions',
        'peak_limit_enabled'  => 'peak_limit.enabled',
        'peak_limit_w1_start' => 'peak_limit.window_1_start',
        'peak_limit_w1_end'   => 'peak_limit.window_1_end',
        'peak_limit_w2_start' => 'peak_limit.window_2_start',
        'peak_limit_w2_end'   => 'peak_limit.window_2_end',
        'members_anonymization_retention_years' => 'members.anonymization.retention_years',
    ];
```

Give it a default in `editBehavior()`, alongside the existing `quotes_enabled` default:

```php
    public function editBehavior(): View
    {
        $values = $this->loadValues(self::BEHAVIOR_MAP, true);

        if ($values['quotes_enabled'] === '') {
            $values['quotes_enabled'] = '1';
        }

        if ($values['members_anonymization_retention_years'] === '') {
            $values['members_anonymization_retention_years'] = '3';
        }

        return view('admin.config.behavior', [
            'values' => $values,
        ]);
    }
```

Add validation in `behaviorRules()`:

```php
    private function behaviorRules(): array
    {
        return [
            'registration'        => ['nullable', 'in:0,1'],
            'activation'          => ['nullable', 'in:immediate,manual,manual-email,email'],
            'maintenance'         => ['nullable', 'in:0,1'],
            'quotes_enabled'      => ['nullable', 'in:0,1'],
            'calendar_hide'       => ['nullable', 'string', 'max:4096'],
            'peak_limit_enabled'  => ['nullable', 'in:0,1'],
            'peak_limit_w1_start' => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
            'peak_limit_w1_end'   => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
            'peak_limit_w2_start' => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
            'peak_limit_w2_end'   => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
            'members_anonymization_retention_years' => ['nullable', 'integer', 'min:0'],
        ];
    }
```

- [ ] **Step 4: Add the field to the behavior view**

In `resources/views/admin/config/behavior.blade.php`, add a new section before the closing `</form>` (after the "Peak times" section's closing `</div>`):

```blade
        {{-- Data protection --}}
        <div class="bg-white rounded-xl border border-[#e0ddd7] shadow-sm overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-[#f0ede6]">
                <h2 class="text-base font-semibold text-[#151515]" style="font-family: var(--font-display)">{{ __('booking.admin.cfg.section_privacy') }}</h2>
            </div>
            <div class="px-6 py-5 flex flex-col gap-4">

                <div class="flex flex-col gap-1 max-w-xs">
                    <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]" for="cf-anon-years">{{ __('booking.admin.members_anonymization_retention_years') }}</label>
                    <input type="number" min="0" id="cf-anon-years" name="members_anonymization_retention_years"
                        value="{{ old('members_anonymization_retention_years', $values['members_anonymization_retention_years']) }}"
                        class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
                    <p class="text-xs text-[#6a6e73] mt-1">{{ __('booking.admin.members_anonymization_retention_years_hint') }}</p>
                </div>

            </div>
        </div>
```

- [ ] **Step 5: Add the lang keys**

In `lang/de/booking/admin.php`, add to the `cfg` array:

```php
            'section_privacy'         => 'Datenschutz',
```

And at the top level (alongside `quotes_enabled_hint`):

```php
        'members_anonymization_retention_years' => 'Aufbewahrungsfrist für ausgetretene Mitglieder (Jahre)',
        'members_anonymization_retention_years_hint' => 'Nach dieser Anzahl Jahre seit Austritt kann ein Mitglied im Bereich Mitgliederverwaltung anonymisiert werden.',
```

In `lang/en/booking/admin.php`, add to the `cfg` array:

```php
            'section_privacy' => 'Data Protection',
```

And at the top level:

```php
        'members_anonymization_retention_years' => 'Retention period for departed members (years)',
        'members_anonymization_retention_years_hint' => 'After this many years since leaving, a member can be anonymized in the members management area.',
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `wsl.exe -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/Admin/MemberAnonymizationRetentionOptionTest.php"`
Expected: PASS (3 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/OptionController.php resources/views/admin/config/behavior.blade.php lang/de/booking/admin.php lang/en/booking/admin.php tests/Feature/Admin/MemberAnonymizationRetentionOptionTest.php
git commit -m "Add configurable retention period for member anonymization"
```

---

### Task 3: `MemberController::anonymize()` action and route

**Files:**
- Modify: `app/Modules/Members/Http/Controllers/MemberController.php`
- Modify: `app/Modules/Members/routes.php`
- Modify: `app/Modules/Members/resources/lang/de/admin.php`
- Modify: `app/Modules/Members/resources/lang/en/admin.php`
- Test: `tests/Feature/Modules/Members/MemberAnonymizationTest.php`

- [ ] **Step 1: Write the failing feature test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Members;

use App\Models\User;
use App\Modules\Members\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberAnonymizationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['status' => 'admin']);
    }

    #[Test]
    public function a_non_admin_cannot_anonymize_a_member(): void
    {
        $user = User::factory()->create(['status' => 'enabled']);
        $member = Member::factory()->create(['left_at' => now()->subYears(5)->toDateString()]);

        $this->actingAs($user)->post("/members/{$member->id}/anonymize", [
            'confirmation' => 'ANONYMISIEREN',
        ])->assertForbidden();
    }

    #[Test]
    public function anonymizing_requires_the_exact_confirmation_keyword(): void
    {
        $member = Member::factory()->create(['left_at' => now()->subYears(5)->toDateString()]);

        $this->actingAs($this->admin())->post("/members/{$member->id}/anonymize", [
            'confirmation' => 'wrong',
        ])->assertSessionHasErrors('confirmation');

        $this->assertNull($member->fresh()->anonymized_at);
    }

    #[Test]
    public function a_member_that_is_not_yet_eligible_cannot_be_anonymized(): void
    {
        $member = Member::factory()->create(['left_at' => now()->subYear()->toDateString()]);

        $this->actingAs($this->admin())->post("/members/{$member->id}/anonymize", [
            'confirmation' => 'ANONYMISIEREN',
        ])->assertSessionHasErrors('confirmation');

        $this->assertNull($member->fresh()->anonymized_at);
    }

    #[Test]
    public function anonymizing_an_eligible_member_clears_personal_data_and_keeps_the_rest(): void
    {
        $member = Member::factory()->create([
            'member_number' => 42,
            'firstname' => 'Anna',
            'lastname' => 'Muster',
            'birthdate' => '1980-01-01',
            'gender' => 'w',
            'email' => 'anna@example.com',
            'phone' => '0664 1234567',
            'street' => 'Teststr. 1',
            'zip' => '4400',
            'city' => 'Steyr',
            'nuliga_id' => 288563,
            'joined_at' => '2010-01-01',
            'left_at' => now()->subYears(5)->toDateString(),
        ]);

        $this->actingAs($this->admin())->post("/members/{$member->id}/anonymize", [
            'confirmation' => 'ANONYMISIEREN',
        ])->assertRedirect(route('members.edit', $member));

        $fresh = $member->fresh();
        $this->assertSame('Ehemaliges', $fresh->firstname);
        $this->assertSame('Mitglied #42', $fresh->lastname);
        $this->assertNull($fresh->email);
        $this->assertNull($fresh->phone);
        $this->assertNull($fresh->street);
        $this->assertNull($fresh->zip);
        $this->assertNull($fresh->city);
        $this->assertNull($fresh->nuliga_id);
        $this->assertNotNull($fresh->anonymized_at);
        $this->assertSame(42, $fresh->member_number);
        $this->assertSame('1980-01-01', $fresh->birthdate->toDateString());
        $this->assertSame('w', $fresh->gender);
        $this->assertSame('2010-01-01', $fresh->joined_at->toDateString());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `wsl.exe -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/Modules/Members/MemberAnonymizationTest.php"`
Expected: FAIL — 404 (route `members/{member}/anonymize` doesn't exist yet).

- [ ] **Step 3: Add the route**

In `app/Modules/Members/routes.php`, add inside the `admin.members`-protected group, near `updateField`:

```php
        Route::post('members/{member}/anonymize', [MemberController::class, 'anonymize'])->name('members.anonymize');
```

- [ ] **Step 4: Add the `anonymize()` action**

In `app/Modules/Members/Http/Controllers/MemberController.php`, add the constant and method, and add the two new `use` imports:

```php
use Illuminate\Support\Facades\Validator;
```

```php
class MemberController
{
    private const ANONYMIZE_CONFIRMATION = 'ANONYMISIEREN';

    // ...existing methods...

    public function anonymize(Request $request, Member $member): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'confirmation' => ['required', 'string'],
        ]);

        $validator->after(function ($validator) use ($request, $member): void {
            if (strtoupper(trim((string) $request->input('confirmation'))) !== self::ANONYMIZE_CONFIRMATION) {
                $validator->errors()->add(
                    'confirmation',
                    __('members::admin.members.anonymize_confirmation_invalid', [
                        'value' => self::ANONYMIZE_CONFIRMATION,
                    ]),
                );

                return;
            }

            if (! $member->isAnonymizable()) {
                $validator->errors()->add(
                    'confirmation',
                    __('members::admin.members.anonymize_not_eligible'),
                );
            }
        });

        if ($validator->fails()) {
            return redirect()->route('members.edit', $member)->withErrors($validator);
        }

        $member->update([
            'firstname' => 'Ehemaliges',
            'lastname' => "Mitglied #{$member->member_number}",
            'email' => null,
            'phone' => null,
            'street' => null,
            'zip' => null,
            'city' => null,
            'nuliga_id' => null,
            'anonymized_at' => now(),
        ]);

        return redirect()->route('members.edit', $member)
            ->with('success', __('members::admin.members.anonymize_success'));
    }
}
```

- [ ] **Step 5: Add the lang keys**

In `app/Modules/Members/resources/lang/de/admin.php`, add inside the `members` array:

```php
        'anonymize_confirmation_invalid' => 'Bitte geben Sie zur Bestätigung exakt :value ein.',
        'anonymize_not_eligible' => 'Dieses Mitglied kann noch nicht anonymisiert werden.',
        'anonymize_success' => 'Mitglied wurde anonymisiert.',
```

In `app/Modules/Members/resources/lang/en/admin.php`, add the equivalent keys directly after `'field_update_failed' => 'Saving failed. Please try again later.',` inside the `members` array:

```php
        'anonymize_confirmation_invalid' => 'Please type exactly :value to confirm.',
        'anonymize_not_eligible' => 'This member cannot be anonymized yet.',
        'anonymize_success' => 'Member has been anonymized.',
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `wsl.exe -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/Modules/Members/MemberAnonymizationTest.php"`
Expected: PASS (4 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Modules/Members/Http/Controllers/MemberController.php app/Modules/Members/routes.php app/Modules/Members/resources/lang/de/admin.php app/Modules/Members/resources/lang/en/admin.php tests/Feature/Modules/Members/MemberAnonymizationTest.php
git commit -m "Add member anonymization action and route"
```

---

### Task 4: Edit page UI — anonymize action and locked PII after anonymization

**Files:**
- Modify: `app/Modules/Members/resources/views/members/edit.blade.php`
- Modify: `app/Modules/Members/resources/views/members/_form.blade.php`
- Modify: `app/Modules/Members/resources/lang/de/admin.php`
- Modify: `app/Modules/Members/resources/lang/en/admin.php`
- Test: `tests/Feature/Modules/Members/MemberAnonymizationUiTest.php`

- [ ] **Step 1: Write the failing feature test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Members;

use App\Models\User;
use App\Modules\Members\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberAnonymizationUiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['status' => 'admin']);
    }

    #[Test]
    public function the_edit_page_does_not_show_the_anonymize_button_for_an_active_member(): void
    {
        $member = Member::factory()->create(['left_at' => null]);

        $this->actingAs($this->admin())->get(route('members.edit', $member))
            ->assertOk()
            ->assertDontSee(route('members.anonymize', $member), false);
    }

    #[Test]
    public function the_edit_page_shows_the_anonymize_button_for_an_eligible_member(): void
    {
        $member = Member::factory()->create(['left_at' => now()->subYears(5)->toDateString()]);

        $this->actingAs($this->admin())->get(route('members.edit', $member))
            ->assertOk()
            ->assertSee(route('members.anonymize', $member), false);
    }

    #[Test]
    public function the_edit_page_shows_the_anonymized_state_and_locks_pii_fields(): void
    {
        $member = Member::factory()->create([
            'left_at' => now()->subYears(5)->toDateString(),
            'anonymized_at' => now(),
            'firstname' => 'Ehemaliges',
            'lastname' => 'Mitglied #42',
        ]);

        $this->actingAs($this->admin())->get(route('members.edit', $member))
            ->assertOk()
            ->assertDontSee(route('members.anonymize', $member), false)
            ->assertSee('id="mf-firstname" type="text" name="firstname" value="Ehemaliges" class="ui-input" disabled', false);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `wsl.exe -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/Modules/Members/MemberAnonymizationUiTest.php"`
Expected: FAIL — the anonymize link/button and the disabled-field markup don't exist yet.

- [ ] **Step 3: Update `_form.blade.php` to lock PII fields when anonymized**

In `app/Modules/Members/resources/views/members/_form.blade.php`, change the `firstname`, `lastname`, `email`, `phone`, `street`, `zip`, `city`, and `nuliga_id` inputs to add `@disabled(($member->anonymized_at ?? null) !== null)`. Example for `firstname` (apply the identical pattern to the other seven fields listed):

```blade
                <input id="mf-firstname" type="text" name="firstname" value="{{ old('firstname', $member->firstname ?? '') }}" class="ui-input" @disabled(($member->anonymized_at ?? null) !== null)>
```

- [ ] **Step 4: Add the anonymize section to `edit.blade.php`**

Replace the contents of `app/Modules/Members/resources/views/members/edit.blade.php` with:

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

    @if($member->anonymized_at !== null)
        <div class="ui-card">
            <div class="ui-card-body">
                <p>{{ __('members::admin.members.anonymized_at', ['date' => $member->anonymized_at->format('d.m.Y')]) }}</p>
            </div>
        </div>
    @elseif($member->isAnonymizable())
        <div class="ui-card">
            <div class="ui-card-header"><h2>{{ __('members::admin.members.anonymize_heading') }}</h2></div>
            <div class="ui-card-body ui-stack">
                <p>{{ __('members::admin.members.anonymize_warning') }}</p>
                <form method="POST" action="{{ route('members.anonymize', $member) }}" onsubmit="return confirm({{ Js::from(__('members::admin.members.anonymize_confirm_dialog')) }})">
                    @csrf
                    <div class="ui-field">
                        <label class="ui-label" for="mf-anonymize-confirmation">{{ __('members::admin.members.anonymize_confirmation_label') }}</label>
                        <input id="mf-anonymize-confirmation" type="text" name="confirmation" class="ui-input" autocomplete="off" spellcheck="false">
                        @error('confirmation')
                            <p class="text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <button type="submit" class="ui-btn ui-btn-danger">{{ __('members::admin.members.anonymize_button') }}</button>
                </form>
            </div>
        </div>
    @endif
</div>
@endsection
```

- [ ] **Step 5: Add the remaining lang keys**

In `app/Modules/Members/resources/lang/de/admin.php`, add inside the `members` array:

```php
        'anonymize_heading' => 'Anonymisierung',
        'anonymize_warning' => 'Dieses Mitglied ist ausgetreten und die Aufbewahrungsfrist ist abgelaufen. Beim Anonymisieren werden Name, E-Mail, Telefon, Adresse und Verbands-Nr. dauerhaft entfernt. Dies kann nicht rückgängig gemacht werden.',
        'anonymize_confirm_dialog' => 'Mitglied wirklich unwiderruflich anonymisieren?',
        'anonymize_confirmation_label' => 'Zur Bestätigung ANONYMISIEREN eingeben',
        'anonymize_button' => 'Anonymisieren',
        'anonymized_at' => 'Anonymisiert am :date',
```

In `app/Modules/Members/resources/lang/en/admin.php`, add the equivalent keys directly after the `anonymize_success` key added in Task 3 (same `members` array):

```php
        'anonymize_heading' => 'Anonymization',
        'anonymize_warning' => 'This member has left and the retention period has passed. Anonymizing permanently removes name, email, phone, address, and the federation ID. This cannot be undone.',
        'anonymize_confirm_dialog' => 'Really anonymize this member irreversibly?',
        'anonymize_confirmation_label' => 'Type ANONYMISIEREN to confirm',
        'anonymize_button' => 'Anonymize',
        'anonymized_at' => 'Anonymized on :date',
```

- [ ] **Step 6: Check for a `ui-btn-danger` style**

Run: `wsl.exe -e bash -lc "cd /mnt/c/development/bookingnew && grep -rn 'ui-btn-danger' resources/css app/Modules/Members/resources 2>/dev/null"`

If nothing is found, replace `ui-btn ui-btn-danger` in the button above with `ui-btn ui-btn-ghost` (the class used for the existing "Austragen" button) to avoid referencing an undefined style.

- [ ] **Step 7: Run the test to verify it passes**

Run: `wsl.exe -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/Modules/Members/MemberAnonymizationUiTest.php"`
Expected: PASS (3 tests)

- [ ] **Step 8: Run the full Members test suite to check for regressions**

Run: `wsl.exe -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/Modules/Members tests/Unit/Modules/Members"`
Expected: PASS, all tests green (including the pre-existing `MemberManagementTest`, `MemberFieldUpdateTest`, etc.)

- [ ] **Step 9: Commit**

```bash
git add app/Modules/Members/resources/views/members/edit.blade.php app/Modules/Members/resources/views/members/_form.blade.php app/Modules/Members/resources/lang/de/admin.php app/Modules/Members/resources/lang/en/admin.php tests/Feature/Modules/Members/MemberAnonymizationUiTest.php
git commit -m "Show anonymize action and lock PII fields on the member edit page"
```

---

### Task 5: Full regression check

**Files:** none (verification only)

- [ ] **Step 1: Run the entire test suite**

Run: `wsl.exe -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test"`
Expected: PASS, no failures anywhere in the suite.

- [ ] **Step 2: Clear caches so the new option/lang keys and view take effect when manually tested**

Run: `wsl.exe -e bash -lc "cd /mnt/c/development/bookingnew && php artisan view:clear && php artisan config:clear"`

- [ ] **Step 3: No commit needed for this task** (verification only — nothing to add).
