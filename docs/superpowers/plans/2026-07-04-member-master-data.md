# Mitglieder-Stammdaten-Erweiterung — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mitglieder bekommen getrennte Adressfelder (Straße/PLZ/Ort), Geschlecht, Nuliga-Nr. und eine Standard-Beitragskategorie; die Mitgliederliste bekommt Namenssuche, Kategorie-Filter und Alter-Spalte; CSV-Import und Vorschreibungs-Formular werden entsprechend erweitert.

**Architecture:** Eine Alter-Migration erweitert die `members`-Tabelle und splittet bestehende `address`-Werte über eine unit-getestete Helper-Klasse `AddressSplitter`. Formular, Validierung, Import, Liste und Dues-Formular werden darauf aufgebaut. Spec: `docs/superpowers/specs/2026-07-04-member-master-data-design.md`.

**Tech Stack:** Laravel (Module unter `app/Modules/Members`), Blade, PHPUnit. **Alle php/composer-Befehle laufen via WSL** (`wsl php …`), nie direkt in PowerShell. Arbeitsverzeichnis: `C:\development\bookingnew`.

---

### Task 1: AddressSplitter-Helper

**Files:**
- Create: `app/Modules/Members/Support/AddressSplitter.php`
- Test: `tests/Unit/Modules/Members/AddressSplitterTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members;

use App\Modules\Members\Support\AddressSplitter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AddressSplitterTest extends TestCase
{
    #[Test]
    public function splits_the_import_format_street_comma_zip_city(): void
    {
        $this->assertSame(
            ['street' => 'Teststr. 1', 'zip' => '4400', 'city' => 'Steyr'],
            AddressSplitter::split('Teststr. 1, 4400 Steyr'),
        );
    }

    #[Test]
    public function splits_zip_and_city_without_a_street(): void
    {
        $this->assertSame(
            ['street' => null, 'zip' => '4400', 'city' => 'Steyr'],
            AddressSplitter::split('4400 Steyr'),
        );
    }

    #[Test]
    public function keeps_an_unparsable_value_entirely_in_street(): void
    {
        $this->assertSame(
            ['street' => 'Hauptplatz 5', 'zip' => null, 'city' => null],
            AddressSplitter::split('Hauptplatz 5'),
        );
    }

    #[Test]
    public function returns_nulls_for_empty_input(): void
    {
        $this->assertSame(
            ['street' => null, 'zip' => null, 'city' => null],
            AddressSplitter::split(null),
        );
        $this->assertSame(
            ['street' => null, 'zip' => null, 'city' => null],
            AddressSplitter::split('  '),
        );
    }

    #[Test]
    public function handles_a_city_with_spaces(): void
    {
        $this->assertSame(
            ['street' => 'Weg 2', 'zip' => '4400', 'city' => 'Steyr Gleink'],
            AddressSplitter::split('Weg 2, 4400 Steyr Gleink'),
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl php artisan test --filter=AddressSplitterTest`
Expected: FAIL — `Class "App\Modules\Members\Support\AddressSplitter" not found`

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Members\Support;

/**
 * Zerlegt den Alt-Adresswert "Straße, PLZ Ort" (Format des CSV-Imports)
 * in Straße, PLZ und Ort. Nicht parsebare Werte landen komplett in street.
 */
class AddressSplitter
{
    /** @return array{street: ?string, zip: ?string, city: ?string} */
    public static function split(?string $address): array
    {
        $address = trim((string) $address);

        if ($address === '') {
            return ['street' => null, 'zip' => null, 'city' => null];
        }

        if (preg_match('/^(?:(.*),\s*)?(\d{4,5})\s+(.+)$/s', $address, $matches)) {
            $street = trim($matches[1]);

            return [
                'street' => $street === '' ? null : $street,
                'zip' => $matches[2],
                'city' => trim($matches[3]),
            ];
        }

        return ['street' => $address, 'zip' => null, 'city' => null];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `wsl php artisan test --filter=AddressSplitterTest`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Members/Support/AddressSplitter.php tests/Unit/Modules/Members/AddressSplitterTest.php
git commit -m "Add AddressSplitter for legacy 'street, zip city' member addresses"
```

---

### Task 2: Migration, Model, Factory

**Files:**
- Create: `app/Modules/Members/Database/Migrations/2026_07_04_090001_extend_members_master_data.php`
- Modify: `app/Modules/Members/Models/Member.php`
- Modify: `database/factories/Modules/Members/Models/MemberFactory.php`
- Test: `tests/Feature/Modules/Members/MemberManagementTest.php` (Tests ergänzen)

- [ ] **Step 1: Write the failing tests** — in `MemberManagementTest` ergänzen (Imports `App\Modules\Members\Models\FeeCategory` und `App\Modules\Members\Models\Member` oben in der Datei ergänzen, die bestehenden FQCN-Aufrufe können bleiben):

```php
#[Test]
public function members_store_the_new_master_data_fields(): void
{
    $category = FeeCategory::create(['name' => 'Vollzahler']);

    $member = Member::factory()->create([
        'gender' => 'w',
        'street' => 'Teststr. 1',
        'zip' => '4400',
        'city' => 'Steyr',
        'nuliga_id' => 288563,
        'fee_category_id' => $category->id,
    ]);

    $this->assertSame('Vollzahler', $member->feeCategory->name);
    $this->assertSame('w', $member->fresh()->gender);
    $this->assertSame(288563, $member->fresh()->nuliga_id);
}

#[Test]
public function age_is_computed_from_birthdate(): void
{
    $member = Member::factory()->create(['birthdate' => now()->subYears(46)->subDay()->toDateString()]);

    $this->assertSame(46, $member->age());
    $this->assertNull(Member::factory()->create(['birthdate' => null])->age());
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `wsl php artisan test --filter=MemberManagementTest`
Expected: FAIL — unbekannte Spalten (`gender` …) bzw. `Call to undefined method age()`

- [ ] **Step 3: Create the migration**

```php
<?php

declare(strict_types=1);

use App\Modules\Members\Support\AddressSplitter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->unsignedInteger('nuliga_id')->nullable()->unique()->after('member_number');
            $table->string('gender', 1)->nullable()->after('birthdate');
            $table->string('street', 255)->nullable()->after('phone');
            $table->string('zip', 10)->nullable()->after('street');
            $table->string('city', 128)->nullable()->after('zip');
            $table->foreignId('fee_category_id')->nullable()->after('city')->constrained('fee_categories')->nullOnDelete();
        });

        foreach (DB::table('members')->whereNotNull('address')->get(['id', 'address']) as $member) {
            DB::table('members')->where('id', $member->id)->update(AddressSplitter::split($member->address));
        }

        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('address');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('address', 255)->nullable()->after('phone');
        });

        foreach (DB::table('members')->get(['id', 'street', 'zip', 'city']) as $member) {
            $place = trim("{$member->zip} {$member->city}");
            $address = trim(implode(', ', array_filter([$member->street, $place === '' ? null : $place])));
            DB::table('members')->where('id', $member->id)->update(['address' => $address === '' ? null : $address]);
        }

        Schema::table('members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fee_category_id');
            $table->dropColumn(['street', 'zip', 'city', 'gender', 'nuliga_id']);
        });
    }
};
```

- [ ] **Step 4: Update the Member model** — in `app/Modules/Members/Models/Member.php`:

`$fillable` ersetzen (Import `Illuminate\Database\Eloquent\Relations\BelongsTo` ergänzen):

```php
public const GENDERS = ['m', 'w', 'd'];

protected $fillable = [
    'member_number', 'nuliga_id', 'firstname', 'lastname', 'birthdate', 'gender',
    'email', 'phone', 'street', 'zip', 'city', 'fee_category_id', 'joined_at', 'left_at',
];
```

Neue Methoden (unter `payments()` einfügen):

```php
public function feeCategory(): BelongsTo
{
    return $this->belongsTo(FeeCategory::class);
}

public function age(): ?int
{
    return $this->birthdate?->age;
}
```

- [ ] **Step 5: Update the factory** — in `MemberFactory::definition()` die Zeile `'address' => …` ersetzen durch:

```php
'gender' => $this->faker->optional()->randomElement(['m', 'w', 'd']),
'street' => $this->faker->optional()->streetAddress(),
'zip' => $this->faker->optional()->postcode(),
'city' => $this->faker->optional()->city(),
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `wsl php artisan test --filter=MemberManagementTest`
Expected: PASS (alle Tests der Klasse). Hinweis: `MemberImportCommandTest` schlägt ab jetzt erwartbar fehl (der Import schreibt noch `address`) — das behebt Task 6; bis dahin nicht die volle Suite als grün erwarten.

- [ ] **Step 7: Commit**

```bash
git add app/Modules/Members/Database/Migrations/2026_07_04_090001_extend_members_master_data.php app/Modules/Members/Models/Member.php database/factories/Modules/Members/Models/MemberFactory.php tests/Feature/Modules/Members/MemberManagementTest.php
git commit -m "Extend members with split address, gender, nuliga id and default fee category"
```

---

### Task 3: Sprachdateien

**Files:**
- Modify: `app/Modules/Members/resources/lang/de/admin.php`
- Modify: `app/Modules/Members/resources/lang/en/admin.php`

- [ ] **Step 1: DE** — im Block `'members'`: Key `'address'` und `'section'` entfernen, ergänzen:

```php
'section_person' => 'Person',
'section_contact' => 'Kontakt',
'section_club' => 'Verein',
'gender' => 'Geschlecht',
'gender_m' => 'Männlich',
'gender_w' => 'Weiblich',
'gender_d' => 'Divers',
'street' => 'Straße',
'zip' => 'PLZ',
'city' => 'Ort',
'nuliga_id' => 'Nuliga-Nr.',
'fee_category' => 'Beitragskategorie',
'age' => 'Alter',
'search' => 'Name suchen',
```

- [ ] **Step 2: EN** — analog in der englischen Datei (gleiche Keys, gleiche Position):

```php
'section_person' => 'Person',
'section_contact' => 'Contact',
'section_club' => 'Club',
'gender' => 'Gender',
'gender_m' => 'Male',
'gender_w' => 'Female',
'gender_d' => 'Diverse',
'street' => 'Street',
'zip' => 'Postal code',
'city' => 'City',
'nuliga_id' => 'Nuliga no.',
'fee_category' => 'Fee category',
'age' => 'Age',
'search' => 'Search name',
```

- [ ] **Step 3: Commit**

```bash
git add app/Modules/Members/resources/lang
git commit -m "Add translations for new member master data fields"
```

---

### Task 4: Formular + Validierung

**Files:**
- Modify: `app/Modules/Members/Http/Controllers/MemberController.php`
- Modify: `app/Modules/Members/resources/views/members/_form.blade.php` (komplett ersetzen)
- Test: `tests/Feature/Modules/Members/MemberManagementTest.php`

- [ ] **Step 1: Write the failing tests** — in `MemberManagementTest` ergänzen:

```php
#[Test]
public function admin_can_create_a_member_with_all_master_data_fields(): void
{
    $category = FeeCategory::create(['name' => 'Vollzahler']);

    $this->actingAs($this->admin())->post('/members', [
        'member_number' => 1,
        'firstname' => 'Neu',
        'lastname' => 'Mitglied',
        'gender' => 'w',
        'street' => 'Teststr. 1',
        'zip' => '4400',
        'city' => 'Steyr',
        'nuliga_id' => 288563,
        'fee_category_id' => $category->id,
        'joined_at' => '2026-01-01',
    ])->assertRedirect(route('members.index'));

    $this->assertDatabaseHas('members', [
        'member_number' => 1, 'gender' => 'w', 'street' => 'Teststr. 1', 'zip' => '4400',
        'city' => 'Steyr', 'nuliga_id' => 288563, 'fee_category_id' => $category->id,
    ]);
}

#[Test]
public function an_invalid_gender_fails_validation(): void
{
    $this->actingAs($this->admin())->post('/members', [
        'member_number' => 1, 'firstname' => 'A', 'lastname' => 'B',
        'gender' => 'x', 'joined_at' => '2026-01-01',
    ])->assertSessionHasErrors('gender');
}

#[Test]
public function a_duplicate_nuliga_id_fails_validation(): void
{
    Member::factory()->create(['nuliga_id' => 111]);

    $this->actingAs($this->admin())->post('/members', [
        'member_number' => 99, 'firstname' => 'A', 'lastname' => 'B',
        'nuliga_id' => 111, 'joined_at' => '2026-01-01',
    ])->assertSessionHasErrors('nuliga_id');
}

#[Test]
public function updating_a_member_keeps_its_own_nuliga_id_valid(): void
{
    $member = Member::factory()->create(['nuliga_id' => 222]);

    $this->actingAs($this->admin())->put("/members/{$member->id}", [
        'member_number' => $member->member_number,
        'firstname' => $member->firstname,
        'lastname' => $member->lastname,
        'nuliga_id' => 222,
        'joined_at' => $member->joined_at->toDateString(),
    ])->assertSessionDoesntHaveErrors('nuliga_id')->assertRedirect(route('members.index'));
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `wsl php artisan test --filter=MemberManagementTest`
Expected: FAIL — neue Felder werden nicht persistiert (`assertDatabaseHas`), `gender=x` erzeugt keinen Fehler.

- [ ] **Step 3: Update MemberController** — `store()`-Regeln ersetzen durch:

```php
$validated = $request->validate([
    'member_number' => ['required', 'integer', 'min:1', 'unique:members,member_number'],
    'nuliga_id' => ['nullable', 'integer', 'min:1', 'unique:members,nuliga_id'],
    'firstname' => ['required', 'string', 'max:128'],
    'lastname' => ['required', 'string', 'max:128'],
    'birthdate' => ['nullable', 'date'],
    'gender' => ['nullable', 'in:m,w,d'],
    'email' => ['nullable', 'email', 'max:128'],
    'phone' => ['nullable', 'string', 'max:64'],
    'street' => ['nullable', 'string', 'max:255'],
    'zip' => ['nullable', 'string', 'max:10'],
    'city' => ['nullable', 'string', 'max:128'],
    'fee_category_id' => ['nullable', 'exists:fee_categories,id'],
    'joined_at' => ['required', 'date'],
]);
```

`update()` bekommt dieselben Regeln, nur mit Ausnahmen für den eigenen Datensatz:

```php
'member_number' => ['required', 'integer', 'min:1', 'unique:members,member_number,'.$member->id],
'nuliga_id' => ['nullable', 'integer', 'min:1', 'unique:members,nuliga_id,'.$member->id],
```

`create()` und `edit()` liefern zusätzlich die Kategorien (Import `App\Modules\Members\Models\FeeCategory` ergänzen):

```php
public function create(): View
{
    return view('members::members.create', [
        'nextNumber' => Member::nextNumber(),
        'feeCategories' => FeeCategory::query()->orderBy('name')->get(),
    ]);
}

public function edit(Member $member): View
{
    return view('members::members.edit', [
        'member' => $member,
        'feeCategories' => FeeCategory::query()->orderBy('name')->get(),
    ]);
}
```

- [ ] **Step 4: Replace `_form.blade.php`** — kompletter neuer Inhalt:

```blade
@csrf
<div class="ui-card">
    <div class="ui-card-header"><h2>{{ __('members::admin.members.section_person') }}</h2></div>
    <div class="ui-card-body ui-stack">
        <div class="ui-grid-3 ui-form-panel">
            <div class="ui-field">
                <label class="ui-label" for="mf-firstname">{{ __('members::admin.members.firstname') }}</label>
                <input id="mf-firstname" type="text" name="firstname" value="{{ old('firstname', $member->firstname ?? '') }}" class="ui-input">
            </div>
            <div class="ui-field">
                <label class="ui-label" for="mf-lastname">{{ __('members::admin.members.lastname') }}</label>
                <input id="mf-lastname" type="text" name="lastname" value="{{ old('lastname', $member->lastname ?? '') }}" class="ui-input">
            </div>
            <div class="ui-field">
                <label class="ui-label" for="mf-birthdate">{{ __('members::admin.members.birthdate') }}</label>
                <input id="mf-birthdate" type="date" name="birthdate" value="{{ old('birthdate', optional($member->birthdate ?? null)->format('Y-m-d')) }}" class="ui-input">
            </div>
            <div class="ui-field">
                <label class="ui-label" for="mf-gender">{{ __('members::admin.members.gender') }}</label>
                <select id="mf-gender" name="gender" class="ui-select">
                    <option value=""></option>
                    @foreach(\App\Modules\Members\Models\Member::GENDERS as $gender)
                        <option value="{{ $gender }}" @selected(old('gender', $member->gender ?? '') === $gender)>{{ __("members::admin.members.gender_{$gender}") }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>
</div>
<div class="ui-card">
    <div class="ui-card-header"><h2>{{ __('members::admin.members.section_contact') }}</h2></div>
    <div class="ui-card-body ui-stack">
        <div class="ui-grid-3 ui-form-panel">
            <div class="ui-field">
                <label class="ui-label" for="mf-email">{{ __('members::admin.members.email') }}</label>
                <input id="mf-email" type="email" name="email" value="{{ old('email', $member->email ?? '') }}" class="ui-input">
            </div>
            <div class="ui-field">
                <label class="ui-label" for="mf-phone">{{ __('members::admin.members.phone') }}</label>
                <input id="mf-phone" type="text" name="phone" value="{{ old('phone', $member->phone ?? '') }}" class="ui-input">
            </div>
            <div class="ui-field">
                <label class="ui-label" for="mf-street">{{ __('members::admin.members.street') }}</label>
                <input id="mf-street" type="text" name="street" value="{{ old('street', $member->street ?? '') }}" class="ui-input">
            </div>
            <div class="ui-field max-w-[8rem]">
                <label class="ui-label" for="mf-zip">{{ __('members::admin.members.zip') }}</label>
                <input id="mf-zip" type="text" name="zip" value="{{ old('zip', $member->zip ?? '') }}" class="ui-input">
            </div>
            <div class="ui-field">
                <label class="ui-label" for="mf-city">{{ __('members::admin.members.city') }}</label>
                <input id="mf-city" type="text" name="city" value="{{ old('city', $member->city ?? '') }}" class="ui-input">
            </div>
        </div>
    </div>
</div>
<div class="ui-card">
    <div class="ui-card-header"><h2>{{ __('members::admin.members.section_club') }}</h2></div>
    <div class="ui-card-body ui-stack">
        <div class="ui-grid-3 ui-form-panel">
            <div class="ui-field">
                <label class="ui-label" for="mf-member-number">{{ __('members::admin.members.member_number') }}</label>
                <input id="mf-member-number" type="number" min="1" name="member_number" value="{{ old('member_number', $member->member_number ?? $nextNumber ?? '') }}" class="ui-input">
            </div>
            <div class="ui-field">
                <label class="ui-label" for="mf-nuliga-id">{{ __('members::admin.members.nuliga_id') }}</label>
                <input id="mf-nuliga-id" type="number" min="1" name="nuliga_id" value="{{ old('nuliga_id', $member->nuliga_id ?? '') }}" class="ui-input">
            </div>
            <div class="ui-field">
                <label class="ui-label" for="mf-fee-category">{{ __('members::admin.members.fee_category') }}</label>
                <select id="mf-fee-category" name="fee_category_id" class="ui-select">
                    <option value=""></option>
                    @foreach($feeCategories as $category)
                        <option value="{{ $category->id }}" @selected((int) old('fee_category_id', $member->fee_category_id ?? 0) === $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ui-field">
                <label class="ui-label" for="mf-joined-at">{{ __('members::admin.members.joined_at') }}</label>
                <input id="mf-joined-at" type="date" name="joined_at" value="{{ old('joined_at', optional($member->joined_at ?? null)->format('Y-m-d') ?? now()->toDateString()) }}" class="ui-input">
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `wsl php artisan test --filter=MemberManagementTest`
Expected: PASS (alle, inkl. der Bestandstests wie `create_form_prefills_the_next_free_member_number`)

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Members/Http/Controllers/MemberController.php app/Modules/Members/resources/views/members/_form.blade.php tests/Feature/Modules/Members/MemberManagementTest.php
git commit -m "Group member form into person/contact/club and validate new fields"
```

---

### Task 5: Mitgliederliste — Suche, Kategorie-Filter, Alter

**Files:**
- Modify: `app/Modules/Members/Http/Controllers/MemberController.php` (`index()`)
- Modify: `app/Modules/Members/resources/views/members/index.blade.php`
- Test: `tests/Feature/Modules/Members/MemberManagementTest.php`

- [ ] **Step 1: Write the failing tests**

```php
#[Test]
public function index_can_search_members_by_first_or_last_name(): void
{
    Member::factory()->create(['firstname' => 'Anna', 'lastname' => 'Muster']);
    Member::factory()->create(['firstname' => 'Bernd', 'lastname' => 'Alt']);

    $this->actingAs($this->admin())->get('/members?search=must')
        ->assertOk()->assertSee('Anna Muster')->assertDontSee('Bernd Alt');

    $this->actingAs($this->admin())->get('/members?search=bernd')
        ->assertOk()->assertSee('Bernd Alt')->assertDontSee('Anna Muster');
}

#[Test]
public function index_can_filter_members_by_fee_category(): void
{
    $vollzahler = FeeCategory::create(['name' => 'Vollzahler']);
    $pensionist = FeeCategory::create(['name' => 'Pensionisten']);
    Member::factory()->create(['firstname' => 'Anna', 'lastname' => 'Muster', 'fee_category_id' => $vollzahler->id]);
    Member::factory()->create(['firstname' => 'Bernd', 'lastname' => 'Alt', 'fee_category_id' => $pensionist->id]);

    $this->actingAs($this->admin())->get("/members?fee_category={$vollzahler->id}")
        ->assertOk()->assertSee('Anna Muster')->assertDontSee('Bernd Alt');
}

#[Test]
public function index_shows_age_and_fee_category_columns(): void
{
    $category = FeeCategory::create(['name' => 'Vollzahler']);
    Member::factory()->create([
        'firstname' => 'Anna', 'lastname' => 'Muster',
        'birthdate' => now()->subYears(46)->subDay()->toDateString(),
        'fee_category_id' => $category->id,
    ]);

    $this->actingAs($this->admin())->get('/members')
        ->assertOk()->assertSee('Vollzahler')->assertSee('<td class="text-[#6a6e73]">46</td>', false);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `wsl php artisan test --filter=MemberManagementTest`
Expected: FAIL — Suche/Filter wirken nicht, Alter-Spalte fehlt.

- [ ] **Step 3: Update `index()`** — komplett ersetzen:

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
        'filters' => $request->only('status', 'search', 'fee_category'),
        'feeCategories' => FeeCategory::query()->orderBy('name')->get(),
    ]);
}
```

- [ ] **Step 4: Update the index view** — Filterformular ersetzen (Suche + Kategorie vor dem Status-Feld):

```blade
<form method="GET" action="{{ route('members.index') }}" class="ui-row">
    <div class="ui-field min-w-[14rem]">
        <label class="ui-label" for="mi-search">{{ __('members::admin.members.search') }}</label>
        <input id="mi-search" type="text" name="search" value="{{ $filters['search'] ?? '' }}" class="ui-input">
    </div>
    <div class="ui-field min-w-[12rem]">
        <label class="ui-label" for="mi-fee-category">{{ __('members::admin.members.fee_category') }}</label>
        <select id="mi-fee-category" name="fee_category" class="ui-select">
            <option value="">{{ __('members::admin.common.all') }}</option>
            @foreach($feeCategories as $category)
                <option value="{{ $category->id }}" @selected((int) ($filters['fee_category'] ?? 0) === $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="ui-field min-w-[12rem]">
        <label class="ui-label" for="mi-status">{{ __('members::admin.members.status') }}</label>
        <select id="mi-status" name="status" class="ui-select">
            <option value="">{{ __('members::admin.common.all') }}</option>
            <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ __('members::admin.members.status_active') }}</option>
            <option value="departed" @selected(($filters['status'] ?? '') === 'departed')>{{ __('members::admin.members.status_departed') }}</option>
        </select>
    </div>
    <button type="submit" class="ui-btn ui-btn-primary">{{ __('members::admin.common.filter') }}</button>
</form>
```

Tabellenkopf um zwei Spalten erweitern (nach „Name"):

```blade
<th>{{ __('members::admin.members.age') }}</th>
<th>{{ __('members::admin.members.fee_category') }}</th>
```

Tabellenzeile, nach der Name-Zelle:

```blade
<td class="text-[#6a6e73]">{{ $member->age() ?? '—' }}</td>
<td class="text-[#6a6e73]">{{ $member->feeCategory->name ?? '—' }}</td>
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `wsl php artisan test --filter=MemberManagementTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Members/Http/Controllers/MemberController.php app/Modules/Members/resources/views/members/index.blade.php tests/Feature/Modules/Members/MemberManagementTest.php
git commit -m "Add name search, fee category filter, age and category columns to member list"
```

---

### Task 6: CSV-Import erweitern

**Files:**
- Modify: `app/Modules/Members/Console/ImportMembersCommand.php`
- Test: `tests/Feature/Modules/Members/MemberImportCommandTest.php`

- [ ] **Step 1: Update the test** — in `writeCsv()` bekommt Anna eine ID-Nr (Spalte `ID-Nr`, Wert `288563`; Bernds bleibt leer). In `imports_members_categories_and_paid_status_from_csv` die Zeile `assertSame('Teststr. 1, 4400 Steyr', $anna->address)` ersetzen durch:

```php
$this->assertSame('Teststr. 1', $anna->street);
$this->assertSame('4400', $anna->zip);
$this->assertSame('Steyr', $anna->city);
$this->assertSame('w', $anna->gender);
$this->assertSame(288563, $anna->nuliga_id);
```

und bei Bernd ergänzen:

```php
$this->assertSame('m', $bernd->gender);
$this->assertNull($bernd->nuliga_id);
```

Neue Assertion für die Standard-Kategorie am Mitglied (nach den FeeCategory-Checks):

```php
$this->assertSame('Vollzahler', $anna->feeCategory->name);
$this->assertSame('Pensionisten', $bernd->feeCategory->name);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl php artisan test --filter=MemberImportCommandTest`
Expected: FAIL — `address`-Spalte existiert nicht mehr / neue Felder sind null.

- [ ] **Step 3: Update the command** — in `handle()` den `Member::create(...)`-Block ersetzen durch:

```php
$category = $this->resolveCategory($row);

$member = Member::create([
    'member_number' => $nextNumber++,
    'nuliga_id' => $this->parseNuligaId($row['ID-Nr'] ?? ''),
    'firstname' => $firstname,
    'lastname' => $lastname,
    'birthdate' => $birthdate,
    'gender' => $this->parseGender($row['Geschlecht'] ?? ''),
    'email' => $this->nullable($row['Email'] ?? ''),
    'phone' => $this->nullable($row['Mobil'] ?? '') ?? $this->nullable($row['Privat'] ?? ''),
    'street' => $this->nullable($row['Strasse'] ?? ''),
    'zip' => $this->nullable($row['PLZ'] ?? ''),
    'city' => $this->nullable($row['Ort'] ?? ''),
    'fee_category_id' => $category?->id,
    'joined_at' => $joinedAt,
]);

$duesCreated += $this->createDues($member, $row, $currentYear, $category);
```

`createDues()` umbauen: Signatur `private function createDues(Member $member, array $row, int $currentYear, ?FeeCategory $category): int`; der bisherige Block aus `$categoryName`/`FeeCategory::firstOrCreate` am Anfang wird ersetzt durch:

```php
if (! $category) {
    return 0;
}
```

Neue private Methoden (neben `nullable()`):

```php
/** Mitgliedsart → Beitragskategorie; legt unbekannte Kategorien an. */
private function resolveCategory(array $row): ?FeeCategory
{
    $name = trim($row['Mitgliedsart'] ?? '');

    return $name === '' ? null : FeeCategory::firstOrCreate(['name' => self::CATEGORY_ALIASES[$name] ?? $name]);
}

/** "weiblich"/"maennlich"/"divers" (auch abgekürzt) → w/m/d. */
private function parseGender(string $value): ?string
{
    $letter = mb_strtolower(mb_substr(trim($value), 0, 1));

    return in_array($letter, ['m', 'w', 'd'], true) ? $letter : null;
}

private function parseNuligaId(string $value): ?int
{
    $value = trim($value);

    return ctype_digit($value) ? (int) $value : null;
}
```

`buildAddress()` löschen. Die Abschluss-Zeile in `handle()` anpassen:

```php
$this->line('Nicht importiert (kein Feld im Datenmodell): Alter, Reservierungssystem, Bemerkung.');
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `wsl php artisan test --filter=MemberImportCommandTest`
Expected: PASS (alle 3 Tests)

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Members/Console/ImportMembersCommand.php tests/Feature/Modules/Members/MemberImportCommandTest.php
git commit -m "Import gender, nuliga id, split address and default category from CSV"
```

---

### Task 7: Vorschreibungs-Formular — Kategorie vorbefüllen

**Files:**
- Modify: `app/Modules/Members/resources/views/dues/create.blade.php`
- Test: `tests/Feature/Modules/Members/MemberDueManualCreationTest.php`

- [ ] **Step 1: Write the failing test** — in `MemberDueManualCreationTest` ergänzen (vorhandene Helper/Imports der Datei wiederverwenden; falls dort kein `FeeCategory`-Import existiert, ergänzen):

```php
#[Test]
public function the_create_form_exposes_each_members_default_category(): void
{
    $category = FeeCategory::create(['name' => 'Vollzahler']);
    Member::factory()->create(['fee_category_id' => $category->id]);

    $this->actingAs($this->admin())->get(route('members.dues.create'))
        ->assertOk()
        ->assertSee('data-fee-category="'.$category->id.'"', false);
}
```

Hinweis: Wenn die Testklasse keinen `admin()`-Helper hat, das dortige bestehende Muster zum Einloggen als Admin übernehmen.

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl php artisan test --filter=MemberDueManualCreationTest`
Expected: FAIL — `data-fee-category` nicht im HTML.

- [ ] **Step 3: Update the view** — die Member-Option ersetzen durch:

```blade
<option value="{{ $member->id }}" data-fee-category="{{ $member->fee_category_id }}" @selected((int) old('member_id') === $member->id)>{{ $member->fullName() }}</option>
```

Vor `@endsection` das Sync-Skript ergänzen:

```blade
<script>
    (() => {
        const memberSelect = document.getElementById('df-member');
        const categorySelect = document.getElementById('df-category');
        const sync = () => {
            const category = memberSelect.selectedOptions[0]?.dataset.feeCategory;
            if (category) categorySelect.value = category;
        };
        memberSelect.addEventListener('change', sync);
        @unless(old('fee_category_id'))
        sync();
        @endunless
    })();
</script>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `wsl php artisan test --filter=MemberDueManualCreationTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Members/resources/views/dues/create.blade.php tests/Feature/Modules/Members/MemberDueManualCreationTest.php
git commit -m "Prefill due form category from the member's default fee category"
```

---

### Task 8: Inline-Bearbeitung in der Mitgliederliste

**Files:**
- Modify: `app/Modules/Members/Http/Controllers/MemberController.php` (`index()`, `update()`)
- Modify: `app/Modules/Members/resources/views/members/index.blade.php`
- Test: `tests/Feature/Modules/Members/MemberManagementTest.php`

- [ ] **Step 1: Write the failing tests**

```php
#[Test]
public function the_index_renders_an_inline_edit_form_for_the_requested_member(): void
{
    FeeCategory::create(['name' => 'Vollzahler']);
    $member = Member::factory()->create(['firstname' => 'Anna', 'lastname' => 'Muster']);

    $this->actingAs($this->admin())->get("/members?edit={$member->id}")
        ->assertOk()
        ->assertSee('action="'.route('members.update', $member).'"', false)
        ->assertSee('value="Anna"', false);
}

#[Test]
public function updating_from_the_list_redirects_back_with_the_active_filters(): void
{
    $member = Member::factory()->create(['firstname' => 'Anna', 'lastname' => 'Muster', 'member_number' => 3]);

    $this->actingAs($this->admin())->put("/members/{$member->id}", [
        'member_number' => 3,
        'firstname' => 'Anna',
        'lastname' => 'Musterfrau',
        'joined_at' => '2026-01-01',
        'filters' => ['search' => 'must', 'status' => '', 'fee_category' => ''],
    ])->assertRedirect(route('members.index', ['search' => 'must']));

    $this->assertSame('Musterfrau', $member->fresh()->lastname);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `wsl php artisan test --filter=MemberManagementTest`
Expected: FAIL — kein Inline-Formular im HTML, Redirect geht auf die ungefilterte Liste.

- [ ] **Step 3: Update the controller**

In `index()` dem View-Array einen Eintrag hinzufügen:

```php
'editing' => $request->integer('edit') ?: null,
```

In `update()` die Redirect-Zeile ersetzen durch:

```php
$filters = array_filter(
    (array) $request->input('filters', []),
    fn ($value) => $value !== null && $value !== '',
);

return redirect()->route('members.index', $filters);
```

- [ ] **Step 4: Update the index view** — den `<tr>`-Block in der `@foreach`-Schleife ersetzen durch (colspan = Spaltenanzahl der Tabelle, nach Task 5 sind das 7):

```blade
@if(($editing ?? null) === $member->id)
    <tr>
        <td colspan="7">
            <form method="POST" action="{{ route('members.update', $member) }}" class="ui-stack">
                @method('PUT')
                @foreach(array_filter($filters, fn ($value) => $value !== null && $value !== '') as $key => $value)
                    <input type="hidden" name="filters[{{ $key }}]" value="{{ $value }}">
                @endforeach
                @include('members::members._form')
                <div class="ui-form-actions">
                    <a href="{{ route('members.index', array_filter($filters)) }}" class="ui-btn ui-btn-ghost">{{ __('members::admin.common.cancel') }}</a>
                    <button type="submit" class="ui-btn ui-btn-primary">{{ __('members::admin.common.save') }}</button>
                </div>
            </form>
        </td>
    </tr>
@else
    <tr>
        <td class="text-[#6a6e73]">{{ $member->member_number }}</td>
        <td class="font-medium">{{ $member->fullName() }}</td>
        <td class="text-[#6a6e73]">{{ $member->age() ?? '—' }}</td>
        <td class="text-[#6a6e73]">{{ $member->feeCategory->name ?? '—' }}</td>
        <td class="text-[#6a6e73]">{{ $member->email ?: '—' }}</td>
        <td class="text-[#6a6e73]">{{ $member->joined_at->format('d.m.Y') }}</td>
        <td>
            <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                <a href="{{ route('members.index', array_merge(array_filter($filters), ['edit' => $member->id])) }}" class="ui-btn ui-btn-ghost">{{ __('members::admin.common.edit') }}</a>
                <form method="POST" action="{{ route('members.destroy', $member) }}" onsubmit="return confirm({{ Js::from(__('members::admin.members.confirm_leave')) }})">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="ui-btn ui-btn-ghost">{{ __('members::admin.members.mark_departed') }}</button>
                </form>
            </div>
        </td>
    </tr>
@endif
```

Hinweis: `_form.blade.php` beginnt mit `@csrf` und nutzt `$member` (Loop-Variable) sowie `$feeCategories` (kommt seit Task 5 aus `index()`) — es sind keine weiteren Variablen nötig. Der „Bearbeiten"-Link zeigt jetzt auf die Inline-Ansicht; die Route `members.edit` bleibt bestehen, wird aber nicht mehr verlinkt.

- [ ] **Step 5: Run tests to verify they pass**

Run: `wsl php artisan test --filter=MemberManagementTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Members/Http/Controllers/MemberController.php app/Modules/Members/resources/views/members/index.blade.php tests/Feature/Modules/Members/MemberManagementTest.php
git commit -m "Allow editing members inline in the list view"
```

---

### Task 9: Gesamtlauf + lokale Migration

- [ ] **Step 1: Run the full members test suite**

Run: `wsl php artisan test tests/Feature/Modules/Members tests/Unit/Modules/Members`
Expected: PASS (alle Tests, 0 Failures)

- [ ] **Step 2: Run the whole suite** (Regressionen außerhalb des Moduls, z. B. Dashboard/Export)

Run: `wsl php artisan test`
Expected: PASS. Bei Fehlschlägen, die `members.address` referenzieren: betroffene Stelle auf `street`/`zip`/`city` umstellen.

- [ ] **Step 3: Migrate the local database**

Run: `wsl php artisan migrate`
Expected: `2026_07_04_090001_extend_members_master_data` läuft durch; danach Stichprobe:
`wsl php artisan tinker --execute="var_dump(\App\Modules\Members\Models\Member::query()->whereNotNull('street')->first()?->only(['street','zip','city']));"`

- [ ] **Step 4: Final commit (falls noch offene Änderungen)**

```bash
git status --short
git add <offene Dateien> && git commit -m "Finish member master data extension"
```
