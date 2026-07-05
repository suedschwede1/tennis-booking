# Direkt editierbare Zellen in der Mitgliederliste — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die Mitgliederliste erlaubt direktes Editieren einzelner Zellen (Name, E-Mail,
Beitragskategorie, Eingetreten am) statt eines aufklappenden Komplett-Formulars; jede
Zelle speichert für sich per Hintergrund-Request.

**Architecture:** Ein neuer JSON-Endpunkt `PATCH /members/{member}/field` validiert und
speichert genau ein Feld. Die Tabellenzellen nutzen **Alpine.js** (bereits global geladen
über `resources/js/app.js`, bestehendes Muster z. B. in `admin/bookings/edit.blade.php`)
für den Klick-zu-Eingabefeld-Zustand und einen kleinen gemeinsamen `fetch()`-Helper,
inline im Blade-Template — kein Build-Schritt nötig, da alle genutzten CSS-Klassen
bereits im aktuellen Tailwind-Build enthalten sind (`ui-input`, `ui-select`, `ui-error`,
`text-[#6a6e73]`, `cursor-pointer` etc. werden schon anderswo im Projekt verwendet).
Dies **ersetzt** die Task-8-Lösung (Zeile klappt zum kompletten `_form`-Partial auf),
die zurückgebaut wird.

**Tech Stack:** Laravel (Members-Modul), Blade, Alpine.js (bereits vorhanden), PHPUnit.
**Alle php/composer-Befehle laufen via WSL** (`wsl php …`), nie direkt in PowerShell.
Arbeitsverzeichnis: `C:\development\bookingnew`.

---

### Task 1: Task-8-Lösung zurückbauen (aufklappendes Formular entfernen)

**Files:**
- Modify: `app/Modules/Members/Http/Controllers/MemberController.php`
- Modify: `app/Modules/Members/resources/views/members/index.blade.php`
- Modify: `tests/Feature/Modules/Members/MemberManagementTest.php`

Dies ist ein Rückbau (kein neues Verhalten), daher kein klassischer Rot-Grün-Zyklus —
stattdessen: erst die dazugehörigen Tests entfernen, dann Code vereinfachen, dann
verifizieren, dass nichts anderes kaputtgeht.

- [ ] **Step 1: Die beiden Task-8-Tests aus `MemberManagementTest` entfernen**

Lösche die Methoden `the_index_renders_an_inline_edit_form_for_the_requested_member`
und `updating_from_the_list_redirects_back_with_the_active_filters` vollständig
(inklusive ihrer `#[Test]`-Attribute).

- [ ] **Step 2: Test-Lauf zur Kontrolle**

Run: `wsl php artisan test --filter=MemberManagementTest`
Expected: PASS, 18 Tests (vorher 20 — die zwei gelöschten fehlen jetzt einfach).

- [ ] **Step 3: `index()` vereinfachen** — in `MemberController.php` die Zeile
`'editing' => $request->integer('edit') ?: null,` aus dem `return view(...)`-Array
von `index()` entfernen.

- [ ] **Step 4: `update()` zurückbauen** — ersetze die gesamte Methode durch:

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

(Identische Validierungsregeln wie zuvor, nur der Filter-Redirect-Mechanismus entfällt.)

- [ ] **Step 5: View zurückbauen** — in `members/index.blade.php` den kompletten
`@foreach($members as $member) ... @endforeach`-Block durch die folgende, ungebrandete
Version ersetzen (kein `@if`/`@else` mehr, jede Zeile ist wieder eine normale `<tr>` —
diese Zellen werden im Detail in Task 3 nochmal angepasst, hier zunächst nur der Rückbau):

```blade
                    @foreach($members as $member)
                        <tr>
                            <td class="text-[#6a6e73]">{{ $member->member_number }}</td>
                            <td class="font-medium">{{ $member->fullName() }}</td>
                            <td class="text-[#6a6e73]">{{ $member->age() ?? '—' }}</td>
                            <td class="text-[#6a6e73]">{{ $member->feeCategory->name ?? '—' }}</td>
                            <td class="text-[#6a6e73]">{{ $member->email ?: '—' }}</td>
                            <td class="text-[#6a6e73]">{{ $member->joined_at->format('d.m.Y') }}</td>
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
                        </tr>
                    @endforeach
```

- [ ] **Step 6: Test-Lauf für das gesamte Modul**

Run: `wsl php artisan test --filter=Members`
Expected: PASS, keine Fehlschläge (ignoriere die bekannte Duplicate-Testsuite-WARN-Meldung).

- [ ] **Step 7: Commit**

```bash
git add app/Modules/Members/Http/Controllers/MemberController.php app/Modules/Members/resources/views/members/index.blade.php tests/Feature/Modules/Members/MemberManagementTest.php
git commit -m "Revert row-expanding inline edit form; per-cell editing follows in a later commit"
```

---

### Task 2: Neuer Endpunkt `PATCH /members/{member}/field`

**Files:**
- Create: `tests/Feature/Modules/Members/MemberFieldUpdateTest.php`
- Modify: `app/Modules/Members/Http/Controllers/MemberController.php`
- Modify: `app/Modules/Members/routes.php`

- [ ] **Step 1: Write the failing test file**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Members;

use App\Models\User;
use App\Modules\Members\Models\FeeCategory;
use App\Modules\Members\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberFieldUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['status' => 'admin']);
    }

    #[Test]
    public function admin_can_update_the_firstname_field(): void
    {
        $member = Member::factory()->create(['firstname' => 'Alt']);

        $this->actingAs($this->admin())
            ->patchJson("/members/{$member->id}/field", ['field' => 'firstname', 'value' => 'Neu'])
            ->assertOk()
            ->assertJson(['value' => 'Neu']);

        $this->assertSame('Neu', $member->fresh()->firstname);
    }

    #[Test]
    public function admin_can_update_the_lastname_field(): void
    {
        $member = Member::factory()->create(['lastname' => 'Alt']);

        $this->actingAs($this->admin())
            ->patchJson("/members/{$member->id}/field", ['field' => 'lastname', 'value' => 'Neu'])
            ->assertOk()
            ->assertJson(['value' => 'Neu']);

        $this->assertSame('Neu', $member->fresh()->lastname);
    }

    #[Test]
    public function firstname_cannot_be_cleared(): void
    {
        $member = Member::factory()->create(['firstname' => 'Anna']);

        $this->actingAs($this->admin())
            ->patchJson("/members/{$member->id}/field", ['field' => 'firstname', 'value' => ''])
            ->assertStatus(422)
            ->assertExactJson(['message' => $this->firstErrorMessage('firstname', ['field' => 'firstname', 'value' => ''])]);
    }

    #[Test]
    public function admin_can_clear_the_email_field(): void
    {
        $member = Member::factory()->create(['email' => 'alt@example.com']);

        $this->actingAs($this->admin())
            ->patchJson("/members/{$member->id}/field", ['field' => 'email', 'value' => null])
            ->assertOk()
            ->assertJson(['value' => '—']);

        $this->assertNull($member->fresh()->email);
    }

    #[Test]
    public function invalid_email_is_rejected(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($this->admin())
            ->patchJson("/members/{$member->id}/field", ['field' => 'email', 'value' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonStructure(['message']);
    }

    #[Test]
    public function admin_can_update_the_fee_category_and_response_contains_its_name(): void
    {
        $member = Member::factory()->create(['fee_category_id' => null]);
        $category = FeeCategory::create(['name' => 'Vollzahler']);

        $this->actingAs($this->admin())
            ->patchJson("/members/{$member->id}/field", ['field' => 'fee_category_id', 'value' => $category->id])
            ->assertOk()
            ->assertJson(['value' => 'Vollzahler']);

        $this->assertSame($category->id, $member->fresh()->fee_category_id);
    }

    #[Test]
    public function clearing_the_fee_category_returns_a_dash(): void
    {
        $category = FeeCategory::create(['name' => 'Vollzahler']);
        $member = Member::factory()->create(['fee_category_id' => $category->id]);

        $this->actingAs($this->admin())
            ->patchJson("/members/{$member->id}/field", ['field' => 'fee_category_id', 'value' => null])
            ->assertOk()
            ->assertJson(['value' => '—']);

        $this->assertNull($member->fresh()->fee_category_id);
    }

    #[Test]
    public function an_unknown_fee_category_is_rejected(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($this->admin())
            ->patchJson("/members/{$member->id}/field", ['field' => 'fee_category_id', 'value' => 999])
            ->assertStatus(422);

        $this->assertNull($member->fresh()->fee_category_id);
    }

    #[Test]
    public function admin_can_update_joined_at_and_response_is_formatted(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($this->admin())
            ->patchJson("/members/{$member->id}/field", ['field' => 'joined_at', 'value' => '2026-03-15'])
            ->assertOk()
            ->assertJson(['value' => '15.03.2026']);

        $this->assertSame('2026-03-15', $member->fresh()->joined_at->toDateString());
    }

    #[Test]
    public function a_non_whitelisted_field_is_rejected(): void
    {
        $member = Member::factory()->create(['member_number' => 5]);

        $this->actingAs($this->admin())
            ->patchJson("/members/{$member->id}/field", ['field' => 'member_number', 'value' => 999])
            ->assertStatus(422);

        $this->assertSame(5, $member->fresh()->member_number);
    }

    #[Test]
    public function a_regular_user_cannot_update_a_field(): void
    {
        $member = Member::factory()->create();
        $user = User::factory()->create(['status' => 'enabled']);

        $this->actingAs($user)
            ->patchJson("/members/{$member->id}/field", ['field' => 'firstname', 'value' => 'X'])
            ->assertForbidden();
    }

    /** Baut die erwartete Validierungsmeldung für ein Feld über einen echten Validator-Aufruf, um keinen Laravel-Text hart zu kodieren. */
    private function firstErrorMessage(string $field, array $input): string
    {
        $rules = ['firstname' => ['required', 'string', 'max:128']];

        return validator($input, [
            'field' => ['required', 'string'],
            'value' => $rules[$field],
        ])->errors()->first('value');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl php artisan test --filter=MemberFieldUpdateTest`
Expected: FAIL — alle Tests schlagen mit 404 fehl (Route existiert noch nicht).

- [ ] **Step 3: Route ergänzen** — in `app/Modules/Members/routes.php`, direkt vor
`Route::resource('members', MemberController::class)->except(['show']);` einfügen:

```php
        Route::patch('members/{member}/field', [MemberController::class, 'updateField'])->name('members.update-field');

```

- [ ] **Step 4: Controller-Methode ergänzen** — in `MemberController.php` die Imports
um `use Illuminate\Http\JsonResponse;` und `use Illuminate\Validation\ValidationException;`
erweitern, dann folgende Methode ergänzen (z. B. nach `update()`):

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

        $member->update([$validated['field'] => $validated['value']]);

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

- [ ] **Step 5: Run test to verify it passes**

Run: `wsl php artisan test --filter=MemberFieldUpdateTest`
Expected: PASS, 11 Tests.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Members/Http/Controllers/MemberController.php app/Modules/Members/routes.php tests/Feature/Modules/Members/MemberFieldUpdateTest.php
git commit -m "Add PATCH endpoint to update a single member field"
```

---

### Task 3: Editierbare Zellen mit Alpine.js in der Liste

**Files:**
- Modify: `app/Modules/Members/resources/views/members/index.blade.php`
- Modify: `tests/Feature/Modules/Members/MemberManagementTest.php`

**Wichtig:** Jede editierbare Zelle zeigt serverseitig bereits den aktuellen Wert als
Text im `<span>` (nicht nur über Alpines `x-text`) — sonst wäre die Zelle vor dem
Laden von Alpine.js leer, und PHPUnit-Feature-Tests (die kein JavaScript ausführen)
würden den Namen/die Kategorie nicht mehr im HTML finden. `x-text` überschreibt den
Inhalt beim Laden mit demselben Wert, es gibt also keinen sichtbaren Unterschied.

- [ ] **Step 1: Write the failing tests** — in `MemberManagementTest` ergänzen:

```php
#[Test]
public function the_list_marks_name_email_category_and_joined_at_as_editable_fields(): void
{
    $category = FeeCategory::create(['name' => 'Vollzahler']);
    Member::factory()->create([
        'firstname' => 'Anna', 'lastname' => 'Muster',
        'email' => 'anna@example.com', 'fee_category_id' => $category->id,
    ]);

    $this->actingAs($this->admin())->get('/members')
        ->assertOk()
        ->assertSee('data-field="firstname"', false)
        ->assertSee('data-field="fee_category_id"', false)
        ->assertSee('data-field="email"', false)
        ->assertSee('data-field="joined_at"', false)
        ->assertSee('window.saveMemberField', false);
}
```

(Die bestehenden Tests `index_can_search_members_by_first_or_last_name` und
`index_shows_age_and_fee_category_columns` bleiben unverändert bestehen — sie
verifizieren zusätzlich, dass der Rückfall-Text in den neuen Zellen weiterhin
korrekt gerendert wird.)

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl php artisan test --filter=MemberManagementTest`
Expected: FAIL — `data-field`-Attribute existieren noch nicht.

- [ ] **Step 3: View ersetzen** — den `@foreach($members as $member) ... @endforeach`-Block
aus Task 1 durch folgende Version ersetzen:

```blade
                    @foreach($members as $member)
                        <tr>
                            <td class="text-[#6a6e73]">{{ $member->member_number }}</td>
                            <td class="font-medium"
                                data-field="firstname"
                                x-data="{
                                    editing: false, error: null, justSaved: false,
                                    firstname: @js($member->firstname),
                                    lastname: @js($member->lastname),
                                    async save(field) {
                                        this.error = null;
                                        const value = field === 'firstname' ? this.firstname : this.lastname;
                                        const result = await window.saveMemberField({{ $member->id }}, field, value);
                                        if (result.ok) {
                                            this.editing = false;
                                            this.justSaved = true;
                                            setTimeout(() => this.justSaved = false, 1500);
                                        } else {
                                            this.error = result.message;
                                        }
                                    }
                                }"
                                @click.outside="if (!error) editing = false">
                                <span x-show="!editing" @click="editing = true" x-text="firstname + ' ' + lastname" class="cursor-pointer" :style="justSaved && 'outline: 2px solid #3e8635; outline-offset: 2px;'">{{ $member->fullName() }}</span>
                                <div x-show="editing" class="flex flex-col gap-1">
                                    <div class="flex gap-1">
                                        <input type="text" x-model="firstname" @blur="save('firstname')" @keydown.enter.prevent="$event.target.blur()" :style="error && 'border-color:#c9190b'" class="ui-input">
                                        <input type="text" x-model="lastname" @blur="save('lastname')" @keydown.enter.prevent="$event.target.blur()" :style="error && 'border-color:#c9190b'" class="ui-input">
                                    </div>
                                    <p x-show="error" x-text="error" class="ui-error"></p>
                                </div>
                            </td>
                            <td class="text-[#6a6e73]">{{ $member->age() ?? '—' }}</td>
                            <td class="text-[#6a6e73]"
                                data-field="fee_category_id"
                                x-data="{
                                    editing: false, error: null, justSaved: false,
                                    value: @js((string) ($member->fee_category_id ?? '')),
                                    label: @js($member->feeCategory->name ?? '—'),
                                    async save() {
                                        this.error = null;
                                        const result = await window.saveMemberField({{ $member->id }}, 'fee_category_id', this.value || null);
                                        if (result.ok) {
                                            this.editing = false;
                                            this.label = result.value;
                                            this.justSaved = true;
                                            setTimeout(() => this.justSaved = false, 1500);
                                        } else {
                                            this.error = result.message;
                                        }
                                    }
                                }"
                                @click.outside="if (!error) editing = false">
                                <span x-show="!editing" @click="editing = true" x-text="label" class="cursor-pointer" :style="justSaved && 'outline: 2px solid #3e8635; outline-offset: 2px;'">{{ $member->feeCategory->name ?? '—' }}</span>
                                <div x-show="editing">
                                    <select x-model="value" @change="save()" class="ui-select">
                                        <option value=""></option>
                                        @foreach($feeCategories as $category)
                                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                                        @endforeach
                                    </select>
                                    <p x-show="error" x-text="error" class="ui-error"></p>
                                </div>
                            </td>
                            <td class="text-[#6a6e73]"
                                data-field="email"
                                x-data="{
                                    editing: false, error: null, justSaved: false,
                                    value: @js($member->email ?? ''),
                                    async save() {
                                        this.error = null;
                                        const result = await window.saveMemberField({{ $member->id }}, 'email', this.value || null);
                                        if (result.ok) {
                                            this.editing = false;
                                            this.justSaved = true;
                                            setTimeout(() => this.justSaved = false, 1500);
                                        } else {
                                            this.error = result.message;
                                        }
                                    }
                                }"
                                @click.outside="if (!error) editing = false">
                                <span x-show="!editing" @click="editing = true" x-text="value || '—'" class="cursor-pointer" :style="justSaved && 'outline: 2px solid #3e8635; outline-offset: 2px;'">{{ $member->email ?: '—' }}</span>
                                <div x-show="editing">
                                    <input type="email" x-model="value" @blur="save()" @keydown.enter.prevent="$event.target.blur()" :style="error && 'border-color:#c9190b'" class="ui-input">
                                    <p x-show="error" x-text="error" class="ui-error"></p>
                                </div>
                            </td>
                            <td class="text-[#6a6e73]"
                                data-field="joined_at"
                                x-data="{
                                    editing: false, error: null, justSaved: false,
                                    value: @js($member->joined_at->format('Y-m-d')),
                                    display: @js($member->joined_at->format('d.m.Y')),
                                    async save() {
                                        this.error = null;
                                        const result = await window.saveMemberField({{ $member->id }}, 'joined_at', this.value);
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
                                <span x-show="!editing" @click="editing = true" x-text="display" class="cursor-pointer" :style="justSaved && 'outline: 2px solid #3e8635; outline-offset: 2px;'">{{ $member->joined_at->format('d.m.Y') }}</span>
                                <div x-show="editing">
                                    <input type="date" x-model="value" @blur="save()" @keydown.enter.prevent="$event.target.blur()" :style="error && 'border-color:#c9190b'" class="ui-input">
                                    <p x-show="error" x-text="error" class="ui-error"></p>
                                </div>
                            </td>
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
                        </tr>
                    @endforeach
```

- [ ] **Step 4: Gemeinsames Script ergänzen** — direkt vor `@endsection` am Ende der
Datei einfügen:

```blade
<script>
    window.saveMemberField = async (memberId, field, value) => {
        const token = document.querySelector('input[name="_token"]')?.value ?? '';

        try {
            const response = await fetch(`/members/${memberId}/field`, {
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
</script>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `wsl php artisan test --filter=MemberManagementTest`
Expected: PASS, alle Tests der Klasse (die neue Testmethode plus alle bisherigen,
inklusive der Namens-/Kategorie-Anzeige-Tests, die dank des Rückfall-Texts weiterhin
funktionieren).

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Members/resources/views/members/index.blade.php tests/Feature/Modules/Members/MemberManagementTest.php
git commit -m "Make name, email, fee category and joined-at directly editable in the member list"
```

---

### Task 4: Gesamtlauf und manuelle Verifikation im Browser

- [ ] **Step 1: Run the full Members suite**

Run: `wsl php artisan test --filter=Members`
Expected: PASS, keine Fehlschläge.

- [ ] **Step 2: Run the whole suite**

Run: `wsl php artisan test`
Expected: PASS.

- [ ] **Step 3: Manuelle Verifikation im Preview-Browser** (kein PHPUnit-Test, da kein
JS-Test-Setup im Projekt existiert): Dev-Server starten, `/members` öffnen, für je eine
Zelle pro Typ (Name, E-Mail, Kategorie, Eingetreten) verifizieren:
- Klick öffnet das Eingabefeld mit dem aktuellen Wert vorbefüllt.
- Ändern + Verlassen des Felds (bzw. Auswahl bei der Kategorie) speichert, Zelle
  kehrt zur Anzeige zurück, kurzer grüner Rahmen erscheint und verschwindet wieder.
- Eine ungültige E-Mail zeigt eine Fehlermeldung, das Feld bleibt offen.
- Klick auf "Bearbeiten" führt weiterhin zur vollständigen Bearbeiten-Seite.
- Netzwerk-Tab zeigt `PATCH /members/{id}/field`-Requests mit korrektem Body.
