# Platz-Verwaltung (Admin) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admin-CRUD zum Anlegen, Bearbeiten und Löschen von Plätzen (`bs_squares`) mit konfigurierbarem Name **und** Anzeigename (Alias) sowie allen Buchungs-/Zeit-/Kapazitätsfeldern.

**Architecture:** Resource-Controller `Admin\SquareController` analog zu `Admin\EventController`, Blade-Views unter `resources/views/admin/squares/`, gegated über `can:admin.config`. Spaltenwerte in `bs_squares`, Zusatzfelder als `bs_squares_meta` (locale=null). Einheiten-Umrechnung (Min/Tage/Std ↔ Sekunden) im Controller.

**Tech Stack:** Laravel 13, PHP 8.3, Blade, PHPUnit (`RefreshDatabase`, `#[Test]`-Attribute, SQLite :memory:).

**Spec:** `docs/superpowers/specs/2026-06-28-square-admin-design.md`

---

## File Structure

- **Modify** `app/Models/Square.php` — `setMeta()`-Helfer + `ASK_NAMES_OPTIONS`-Konstante
- **Create** `app/Http/Controllers/Admin/SquareController.php` — CRUD + Umrechnungs-/Meta-Helfer
- **Modify** `routes/web.php` — Resource-Route `squares` im `can:admin.config`-Block
- **Modify** `resources/views/layouts/admin.blade.php` — Nav-Link „Plätze"
- **Create** `resources/views/admin/squares/index.blade.php`
- **Create** `resources/views/admin/squares/_form.blade.php`
- **Create** `resources/views/admin/squares/create.blade.php`
- **Create** `resources/views/admin/squares/edit.blade.php`
- **Create** `tests/Feature/Admin/SquareManagementTest.php`
- **Modify** `tests/Unit/Models/SquareModelTest.php` — Test für `setMeta()`

---

## Task 1: Square::setMeta() Helfer

**Files:**
- Modify: `app/Models/Square.php`
- Test: `tests/Unit/Models/SquareModelTest.php`

- [ ] **Step 1: Failing-Test ergänzen**

In `tests/Unit/Models/SquareModelTest.php` diesen Test hinzufügen (Imports `App\Models\SquareMeta` ggf. ergänzen):

```php
    #[\PHPUnit\Framework\Attributes\Test]
    public function set_meta_creates_updates_and_deletes(): void
    {
        $square = Square::factory()->create();

        $square->setMeta('alias', 'Garagenplatz');
        $this->assertSame('Garagenplatz', $square->getMeta('alias'));

        $square->setMeta('alias', 'Starplatz');
        $this->assertSame('Starplatz', $square->getMeta('alias'));
        $this->assertSame(1, \App\Models\SquareMeta::where('sid', $square->sid)->where('key', 'alias')->count());

        $square->setMeta('alias', null);
        $this->assertNull($square->fresh()->getMeta('alias'));
    }
```

- [ ] **Step 2: Test laufen lassen (muss scheitern)**

Run: `php artisan test --filter=set_meta_creates_updates_and_deletes`
Expected: FAIL — `Call to undefined method App\Models\Square::setMeta()`

- [ ] **Step 3: setMeta + Konstante implementieren**

In `app/Models/Square.php` direkt nach `getMeta()` einfügen:

```php
    /** Upsert a single meta value (bs_squares_meta key/value, locale=null); null deletes the row. */
    public function setMeta(string $key, ?string $value): void
    {
        if ($value === null) {
            $this->meta()->where('key', $key)->delete();
            return;
        }

        $row = $this->meta()->where('key', $key)->first();
        if ($row) {
            $row->update(['value' => $value]);
        } else {
            $this->meta()->create(['key' => $key, 'value' => $value, 'locale' => null]);
        }
    }
```

Und oben in der Klasse (nach `LEGACY_DISPLAY_NAMES`) die zulässigen Dropdown-Werte als Konstante:

```php
    /** Allowed values for the bs_squares_meta 'capacity-ask-names' dropdown. */
    public const ASK_NAMES_OPTIONS = [
        '', 'optional-names', 'optional-names-email', 'optional-names-phone', 'optional-names-email-phone',
        'required-names', 'required-names-email', 'required-names-phone', 'required-names-email-phone',
    ];
```

- [ ] **Step 4: Test laufen lassen (muss grün sein)**

Run: `php artisan test --filter=set_meta_creates_updates_and_deletes`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Models/Square.php tests/Unit/Models/SquareModelTest.php
git commit -m "feat(squares): add Square::setMeta() + ask-names options"
```

---

## Task 2: Route, Nav, Controller (index)

**Files:**
- Create: `app/Http/Controllers/Admin/SquareController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/admin.blade.php`
- Create: `resources/views/admin/squares/index.blade.php`
- Test: `tests/Feature/Admin/SquareManagementTest.php`

- [ ] **Step 1: Failing-Test schreiben**

`tests/Feature/Admin/SquareManagementTest.php`:

```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Admin;

use App\Models\Booking;
use App\Models\Square;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SquareManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User { return User::factory()->create(['status' => 'admin']); }

    /** Vollständige, gültige Formular-Eingabe; Overrides per $overrides. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Center', 'alias' => 'Garagenplatz', 'status' => 'enabled',
            'readonly_message' => '', 'priority' => 1, 'capacity' => 2,
            'capacity_ask_names' => '', 'name_visibility' => 'private',
            'time_start' => '08:00', 'time_end' => '22:00',
            'time_block' => 60, 'time_block_bookable' => 30, 'time_block_bookable_max' => 180,
            'min_range_book' => 0, 'range_book' => 56, 'max_active_bookings' => 0,
            'range_cancel' => 24, 'label_free' => 'frei',
        ], $overrides);
    }

    #[Test]
    public function regular_member_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create(['status' => 'enabled']))
            ->get('/admin/squares')->assertForbidden();
    }

    #[Test]
    public function admin_sees_square_list(): void
    {
        $square = Square::factory()->create(['name' => 'Center']);
        $square->setMeta('alias', 'Garagenplatz');

        $this->actingAs($this->admin())->get('/admin/squares')
            ->assertOk()->assertSee('Plätze')->assertSee('Garagenplatz');
    }
}
```

- [ ] **Step 2: Test laufen lassen (muss scheitern)**

Run: `php artisan test --filter=SquareManagementTest`
Expected: FAIL — Route `/admin/squares` existiert nicht (404 statt 403/200).

- [ ] **Step 3: Route registrieren**

In `routes/web.php` im `Route::middleware('can:admin.config')`-Block (neben `config`) ergänzen:

```php
    Route::middleware('can:admin.config')->group(function (): void {
        Route::get('config', [\App\Http\Controllers\Admin\OptionController::class, 'edit'])->name('config.edit');
        Route::put('config', [\App\Http\Controllers\Admin\OptionController::class, 'update'])->name('config.update');
        Route::resource('squares', \App\Http\Controllers\Admin\SquareController::class)->except(['show']);
    });
```

- [ ] **Step 4: Controller mit index() anlegen**

`app/Http/Controllers/Admin/SquareController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Square;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class SquareController extends Controller
{
    public function index(): View
    {
        $squares = Square::with('meta')->orderBy('priority')->orderBy('sid')->get();

        return view('admin.squares.index', compact('squares'));
    }
}
```

- [ ] **Step 5: Nav-Link ergänzen**

In `resources/views/layouts/admin.blade.php` nach der Konfiguration-Zeile (Zeile 17) einfügen:

```blade
            @if(Route::has('admin.squares.index'))@can('admin.config')<a href="{{ route('admin.squares.index') }}" class="default-button">Plätze</a>@endcan @endif
```

- [ ] **Step 6: Index-View anlegen**

`resources/views/admin/squares/index.blade.php`:

```blade
@extends('layouts.admin')
@section('admin-title', 'Plätze')
@section('admin-content')
    <h1>Plätze</h1>
    <a href="{{ route('admin.squares.create') }}" class="default-button">Neuer Platz</a>
    <table class="booking-grid">
        <thead><tr><th>Name</th><th>Anzeigename</th><th>Status</th><th>Zeit</th><th>Zeitblock</th><th></th></tr></thead>
        <tbody>
        @foreach($squares as $square)
            <tr>
                <td>{{ $square->name }}</td>
                <td>{{ $square->display_name }}</td>
                <td>{{ $square->status->value }}</td>
                <td>{{ substr((string) $square->time_start, 0, 5) }}–{{ substr((string) $square->time_end, 0, 5) }} Uhr</td>
                <td>{{ (int) round($square->time_block / 60) }} Min</td>
                <td>
                    <a href="{{ route('admin.squares.edit', $square) }}">Bearbeiten</a>
                    <form method="POST" action="{{ route('admin.squares.destroy', $square) }}" onsubmit="return confirm('Platz löschen?')" style="display:inline">
                        @method('DELETE') @csrf
                        <button type="submit" class="abmelden-button default-button">Löschen</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endsection
```

- [ ] **Step 7: Tests laufen lassen (grün)**

Run: `php artisan test --filter=SquareManagementTest`
Expected: PASS (beide Tests)

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Admin/SquareController.php routes/web.php resources/views/layouts/admin.blade.php resources/views/admin/squares/index.blade.php tests/Feature/Admin/SquareManagementTest.php
git commit -m "feat(squares): admin index list + route + nav"
```

---

## Task 3: Anlegen (create/store) + Formular

**Files:**
- Modify: `app/Http/Controllers/Admin/SquareController.php`
- Create: `resources/views/admin/squares/_form.blade.php`
- Create: `resources/views/admin/squares/create.blade.php`
- Test: `tests/Feature/Admin/SquareManagementTest.php`

- [ ] **Step 1: Failing-Tests ergänzen**

In `SquareManagementTest` hinzufügen:

```php
    #[Test]
    public function create_page_renders(): void
    {
        $this->actingAs($this->admin())->get('/admin/squares/create')
            ->assertOk()->assertSee('Anzeigename');
    }

    #[Test]
    public function store_creates_square_with_unit_conversions(): void
    {
        $this->actingAs($this->admin())->post('/admin/squares', $this->payload())
            ->assertRedirect(route('admin.squares.index'));

        $square = Square::where('name', 'Center')->firstOrFail();
        $this->assertSame(3600, (int) $square->time_block);            // 60 Min × 60
        $this->assertSame(1800, (int) $square->time_block_bookable);   // 30 Min × 60
        $this->assertSame(10800, (int) $square->time_block_bookable_max); // 180 Min × 60
        $this->assertSame(56 * 86400, (int) $square->range_book);      // 56 Tage
        $this->assertSame(86400, (int) $square->range_cancel);         // 24 Std × 3600
        $this->assertSame('Garagenplatz', $square->getMeta('alias'));
        $this->assertSame('frei', $square->getMeta('label.free'));
    }

    #[Test]
    public function store_maps_name_visibility(): void
    {
        $this->actingAs($this->admin())->post('/admin/squares', $this->payload(['name' => 'Pub', 'name_visibility' => 'public']));
        $pub = Square::where('name', 'Pub')->firstOrFail();
        $this->assertSame('true', $pub->getMeta('private_names'));
        $this->assertSame('true', $pub->getMeta('public_names'));

        $this->actingAs($this->admin())->post('/admin/squares', $this->payload(['name' => 'None', 'name_visibility' => 'none']));
        $none = Square::where('name', 'None')->firstOrFail();
        $this->assertSame('false', $none->getMeta('private_names'));
        $this->assertSame('false', $none->getMeta('public_names'));
    }

    #[Test]
    public function store_requires_name(): void
    {
        $this->actingAs($this->admin())->post('/admin/squares', $this->payload(['name' => '']))
            ->assertSessionHasErrors('name');
    }
```

- [ ] **Step 2: Tests laufen lassen (scheitern)**

Run: `php artisan test --filter=SquareManagementTest`
Expected: FAIL — `create`/`store` nicht definiert (Methode fehlt → 403/500/Route-Fehler).

- [ ] **Step 3: create/store + Helfer implementieren**

In `SquareController` ergänzen (nach `index()`):

```php
    public function create(): View
    {
        return view('admin.squares.create', ['square' => null, 'form' => $this->defaults()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->buildPayload($request);
        $square = Square::create($payload['columns']);
        $this->applyMeta($square, $payload['meta']);

        return redirect()->route('admin.squares.index')->with('success', 'Platz angelegt.');
    }

    /** Validate the form and split it into bs_squares columns + bs_squares_meta values. */
    private function buildPayload(Request $request): array
    {
        $data = $request->validate([
            'name'                       => ['required', 'string', 'max:64'],
            'alias'                      => ['nullable', 'string', 'max:64'],
            'status'                     => ['required', 'in:enabled,readonly,disabled'],
            'readonly_message'           => ['nullable', 'string'],
            'priority'                   => ['required', 'numeric'],
            'capacity'                   => ['required', 'integer', 'min:0'],
            'capacity_ask_names'         => ['nullable', Rule::in(Square::ASK_NAMES_OPTIONS)],
            'name_visibility'            => ['required', 'in:none,private,public'],
            'time_start'                 => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'time_end'                   => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'time_block'                 => ['required', 'integer', 'min:0'],
            'time_block_bookable'        => ['required', 'integer', 'min:0'],
            'time_block_bookable_max'    => ['required', 'integer', 'min:0'],
            'min_range_book'             => ['required', 'integer', 'min:0'],
            'range_book'                 => ['required', 'integer', 'min:0'],
            'max_active_bookings'        => ['required', 'integer', 'min:0'],
            'range_cancel'               => ['required', 'numeric', 'min:0'],
            'label_free'                 => ['nullable', 'string', 'max:64'],
        ]);

        $columns = [
            'name'                    => $data['name'],
            'status'                  => $data['status'],
            'priority'                => (float) $data['priority'],
            'capacity'                => (int) $data['capacity'],
            'capacity_heterogenic'    => $request->boolean('capacity_heterogenic') ? 1 : 0,
            'allow_notes'             => $request->boolean('allow_notes') ? 1 : 0,
            'time_start'              => $data['time_start'] . ':00',
            'time_end'                => $data['time_end'] . ':00',
            'time_block'              => (int) $data['time_block'] * 60,
            'time_block_bookable'     => (int) $data['time_block_bookable'] * 60,
            'time_block_bookable_max' => (int) $data['time_block_bookable_max'] * 60,
            'min_range_book'          => (int) $data['min_range_book'] * 60,
            'range_book'              => (int) $data['range_book'] * 86400,
            'max_active_bookings'     => (int) $data['max_active_bookings'],
            'range_cancel'            => (int) round(((float) $data['range_cancel']) * 3600),
        ];

        [$privateNames, $publicNames] = match ($data['name_visibility']) {
            'public'  => ['true', 'true'],
            'private' => ['true', 'false'],
            default   => ['false', 'false'],
        };

        $meta = [
            'alias'                      => $this->nullIfBlank($data['alias'] ?? null),
            'readonly.message'           => $this->nullIfBlank($data['readonly_message'] ?? null),
            'capacity-ask-names'         => $this->nullIfBlank($data['capacity_ask_names'] ?? null),
            'private_names'              => $privateNames,
            'public_names'               => $publicNames,
            'pseudo-time-block-bookable' => $request->boolean('pseudo_time_block_bookable') ? 'true' : 'false',
            'label.free'                 => $this->nullIfBlank($data['label_free'] ?? null),
        ];

        return ['columns' => $columns, 'meta' => $meta];
    }

    /** @param array<string, string|null> $meta */
    private function applyMeta(Square $square, array $meta): void
    {
        foreach ($meta as $key => $value) {
            $square->setMeta($key, $value);
        }
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return ($value === null || $value === '') ? null : $value;
    }

    /** @return array<string, mixed> Default form values for a new square. */
    private function defaults(): array
    {
        return [
            'name' => '', 'alias' => '', 'status' => 'enabled', 'readonly_message' => '',
            'priority' => 1, 'capacity' => 1, 'capacity_ask_names' => '',
            'capacity_heterogenic' => false, 'allow_notes' => false, 'name_visibility' => 'private',
            'time_start' => '08:00', 'time_end' => '23:00', 'time_block' => 60,
            'time_block_bookable' => 30, 'pseudo_time_block_bookable' => false,
            'time_block_bookable_max' => 180, 'min_range_book' => 0, 'range_book' => 56,
            'max_active_bookings' => 0, 'range_cancel' => 24, 'label_free' => '',
        ];
    }
```

- [ ] **Step 4: Formular-Partial anlegen**

`resources/views/admin/squares/_form.blade.php`:

```blade
@csrf
<label>Name <input type="text" name="name" value="{{ old('name', $form['name']) }}"></label>
<label>Anzeigename <input type="text" name="alias" value="{{ old('alias', $form['alias']) }}"></label>
<label>Status
    <select name="status">
        @foreach(['enabled' => 'Aktiviert', 'readonly' => 'Nur Verwaltung', 'disabled' => 'Deaktiviert'] as $val => $lbl)
            <option value="{{ $val }}" @selected(old('status', $form['status']) === $val)>{{ $lbl }}</option>
        @endforeach
    </select>
</label>
<label>Nachricht (bei „Nur Verwaltung")
    <input type="text" name="readonly_message" value="{{ old('readonly_message', $form['readonly_message']) }}">
</label>
<label>Priorität <input type="number" step="any" name="priority" value="{{ old('priority', $form['priority']) }}"></label>
<label>Kapazität <input type="number" min="0" name="capacity" value="{{ old('capacity', $form['capacity']) }}"></label>
<label>Namen anderer Spieler
    <select name="capacity_ask_names">
        @php $askLabels = ['' => 'Nicht fragen', 'optional-names' => 'Namen (optional)', 'optional-names-email' => 'Namen + E-Mail (optional)', 'optional-names-phone' => 'Namen + Telefon (optional)', 'optional-names-email-phone' => 'Namen + E-Mail + Telefon (optional)', 'required-names' => 'Namen (Pflicht)', 'required-names-email' => 'Namen + E-Mail (Pflicht)', 'required-names-phone' => 'Namen + Telefon (Pflicht)', 'required-names-email-phone' => 'Namen + E-Mail + Telefon (Pflicht)']; @endphp
        @foreach($askLabels as $val => $lbl)
            <option value="{{ $val }}" @selected(old('capacity_ask_names', $form['capacity_ask_names']) === $val)>{{ $lbl }}</option>
        @endforeach
    </select>
</label>
<label><input type="checkbox" name="capacity_heterogenic" value="1" @checked(old('capacity_heterogenic', $form['capacity_heterogenic']))> Mehrfachbuchungen</label>
<label><input type="checkbox" name="allow_notes" value="1" @checked(old('allow_notes', $form['allow_notes']))> Anmerkungen bei der Buchung erlauben</label>
<label>Sichtbarkeit von Namen
    <select name="name_visibility">
        @foreach(['none' => 'Niemand', 'private' => 'Angemeldete Benutzer', 'public' => 'Alle'] as $val => $lbl)
            <option value="{{ $val }}" @selected(old('name_visibility', $form['name_visibility']) === $val)>{{ $lbl }}</option>
        @endforeach
    </select>
</label>
<label>Zeit (Beginn) <input type="time" name="time_start" value="{{ old('time_start', $form['time_start']) }}"></label>
<label>Zeit (Ende) <input type="time" name="time_end" value="{{ old('time_end', $form['time_end']) }}"></label>
<label>Zeitblock (Minuten) <input type="number" min="0" name="time_block" value="{{ old('time_block', $form['time_block']) }}"></label>
<label>Zeitblock min. buchbar (Minuten) <input type="number" min="0" name="time_block_bookable" value="{{ old('time_block_bookable', $form['time_block_bookable']) }}"></label>
<label><input type="checkbox" name="pseudo_time_block_bookable" value="1" @checked(old('pseudo_time_block_bookable', $form['pseudo_time_block_bookable']))> Min. buchbaren Zeitblock nur für die Verwaltung</label>
<label>Zeitblock max. buchbar (Minuten) <input type="number" min="0" name="time_block_bookable_max" value="{{ old('time_block_bookable_max', $form['time_block_bookable_max']) }}"></label>
<label>Buchungsvorlauf (Minuten) <input type="number" min="0" name="min_range_book" value="{{ old('min_range_book', $form['min_range_book']) }}"></label>
<label>Buchung im Voraus (Tage) <input type="number" min="0" name="range_book" value="{{ old('range_book', $form['range_book']) }}"></label>
<label>Buchungen einschränken (gleichzeitig pro Benutzer, 0 = unbegrenzt) <input type="number" min="0" name="max_active_bookings" value="{{ old('max_active_bookings', $form['max_active_bookings']) }}"></label>
<label>Stornierung (Stunden) <input type="number" step="0.01" min="0" name="range_cancel" value="{{ old('range_cancel', $form['range_cancel']) }}"></label>
<label>Bezeichnung freier Plätze <input type="text" name="label_free" value="{{ old('label_free', $form['label_free']) }}"></label>
```

- [ ] **Step 5: Create-View anlegen**

`resources/views/admin/squares/create.blade.php`:

```blade
@extends('layouts.admin')
@section('admin-title', 'Neuer Platz')
@section('admin-content')
<h1>Neuer Platz</h1>
<form method="POST" action="{{ route('admin.squares.store') }}">
    @include('admin.squares._form', ['form' => $form, 'square' => $square])
    <button type="submit" class="default-button">Anlegen</button>
</form>
@endsection
```

- [ ] **Step 6: Tests laufen lassen (grün)**

Run: `php artisan test --filter=SquareManagementTest`
Expected: PASS (alle bisherigen)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/SquareController.php resources/views/admin/squares/ tests/Feature/Admin/SquareManagementTest.php
git commit -m "feat(squares): create/store with unit conversion + form"
```

---

## Task 4: Bearbeiten (edit/update)

**Files:**
- Modify: `app/Http/Controllers/Admin/SquareController.php`
- Create: `resources/views/admin/squares/edit.blade.php`
- Test: `tests/Feature/Admin/SquareManagementTest.php`

- [ ] **Step 1: Failing-Tests ergänzen**

```php
    #[Test]
    public function edit_page_reverse_converts_values(): void
    {
        $square = Square::factory()->create([
            'time_block' => 3600, 'range_book' => 56 * 86400, 'range_cancel' => 86400,
        ]);

        $this->actingAs($this->admin())->get(route('admin.squares.edit', $square))
            ->assertOk()
            ->assertSee('value="56"', false);   // range_book 56*86400 Sek ÷ 86400 = 56 Tage
    }

    #[Test]
    public function update_changes_columns_and_meta(): void
    {
        $square = Square::factory()->create(['name' => 'Alt']);

        $this->actingAs($this->admin())
            ->put(route('admin.squares.update', $square), $this->payload(['name' => 'Neu', 'alias' => 'Starplatz']))
            ->assertRedirect(route('admin.squares.index'));

        $fresh = $square->fresh();
        $this->assertSame('Neu', $fresh->name);
        $this->assertSame('Starplatz', $fresh->getMeta('alias'));
    }

    #[Test]
    public function update_with_blank_alias_deletes_meta(): void
    {
        $square = Square::factory()->create();
        $square->setMeta('alias', 'Garagenplatz');

        $this->actingAs($this->admin())
            ->put(route('admin.squares.update', $square), $this->payload(['alias' => '']))
            ->assertRedirect();

        $this->assertNull($square->fresh()->getMeta('alias'));
    }
```

- [ ] **Step 2: Tests laufen lassen (scheitern)**

Run: `php artisan test --filter=SquareManagementTest`
Expected: FAIL — `edit`/`update` nicht definiert.

- [ ] **Step 3: edit/update + toForm implementieren**

In `SquareController` ergänzen (nach `store()`):

```php
    public function edit(Square $square): View
    {
        return view('admin.squares.edit', ['square' => $square, 'form' => $this->toForm($square)]);
    }

    public function update(Request $request, Square $square): RedirectResponse
    {
        $payload = $this->buildPayload($request);
        $square->update($payload['columns']);
        $this->applyMeta($square, $payload['meta']);

        return redirect()->route('admin.squares.index')->with('success', 'Platz aktualisiert.');
    }

    /** Build form values from a square, reversing the unit conversions of buildPayload(). */
    private function toForm(Square $square): array
    {
        $publicNames  = $square->getMeta('public_names') === 'true';
        $privateNames = $square->getMeta('private_names') === 'true';
        $visibility   = $publicNames ? 'public' : ($privateNames ? 'private' : 'none');

        return [
            'name'                    => $square->name,
            'alias'                   => (string) $square->getMeta('alias'),
            'status'                  => $square->status->value,
            'readonly_message'        => (string) $square->getMeta('readonly.message'),
            'priority'                => $square->priority,
            'capacity'                => $square->capacity,
            'capacity_ask_names'      => (string) $square->getMeta('capacity-ask-names', ''),
            'capacity_heterogenic'    => (bool) $square->capacity_heterogenic,
            'allow_notes'             => (bool) $square->allow_notes,
            'name_visibility'         => $visibility,
            'time_start'              => substr((string) $square->time_start, 0, 5),
            'time_end'                => substr((string) $square->time_end, 0, 5),
            'time_block'              => (int) round($square->time_block / 60),
            'time_block_bookable'     => (int) round($square->time_block_bookable / 60),
            'pseudo_time_block_bookable' => $square->getMeta('pseudo-time-block-bookable') === 'true',
            'time_block_bookable_max' => (int) round(((int) $square->time_block_bookable_max) / 60),
            'min_range_book'          => (int) round($square->min_range_book / 60),
            'range_book'              => (int) round(((int) $square->range_book) / 86400),
            'max_active_bookings'     => (int) $square->max_active_bookings,
            'range_cancel'            => round(((int) $square->range_cancel) / 3600, 2),
            'label_free'              => (string) $square->getMeta('label.free'),
        ];
    }
```

- [ ] **Step 4: Edit-View anlegen**

`resources/views/admin/squares/edit.blade.php`:

```blade
@extends('layouts.admin')
@section('admin-title', 'Platz bearbeiten')
@section('admin-content')
<h1>Platz bearbeiten</h1>
<form method="POST" action="{{ route('admin.squares.update', $square) }}">
    @method('PUT')
    @include('admin.squares._form', ['form' => $form, 'square' => $square])
    <button type="submit" class="default-button">Speichern</button>
</form>
@endsection
```

- [ ] **Step 5: Tests laufen lassen (grün)**

Run: `php artisan test --filter=SquareManagementTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/SquareController.php resources/views/admin/squares/edit.blade.php tests/Feature/Admin/SquareManagementTest.php
git commit -m "feat(squares): edit/update with reverse unit conversion"
```

---

## Task 5: Löschen mit Soft-Delete-Guard

**Files:**
- Modify: `app/Http/Controllers/Admin/SquareController.php`
- Test: `tests/Feature/Admin/SquareManagementTest.php`

- [ ] **Step 1: Failing-Tests ergänzen**

```php
    #[Test]
    public function destroy_deletes_square_without_bookings(): void
    {
        $square = Square::factory()->create();
        $square->setMeta('alias', 'Garagenplatz');

        $this->actingAs($this->admin())->delete(route('admin.squares.destroy', $square))
            ->assertRedirect(route('admin.squares.index'));

        $this->assertDatabaseMissing('bs_squares', ['sid' => $square->sid]);
        $this->assertDatabaseMissing('bs_squares_meta', ['sid' => $square->sid]);
    }

    #[Test]
    public function destroy_disables_square_with_bookings(): void
    {
        $square = Square::factory()->create(['status' => 'enabled']);
        Booking::factory()->create(['sid' => $square->sid]);

        $this->actingAs($this->admin())->delete(route('admin.squares.destroy', $square))
            ->assertRedirect(route('admin.squares.index'));

        $this->assertDatabaseHas('bs_squares', ['sid' => $square->sid, 'status' => 'disabled']);
    }
```

- [ ] **Step 2: Tests laufen lassen (scheitern)**

Run: `php artisan test --filter=SquareManagementTest`
Expected: FAIL — `destroy` nicht definiert.

- [ ] **Step 3: destroy implementieren**

In `SquareController` ergänzen (nach `update()`):

```php
    public function destroy(Square $square): RedirectResponse
    {
        if ($square->bookings()->exists()) {
            $square->update(['status' => 'disabled']);

            return redirect()->route('admin.squares.index')
                ->with('success', 'Platz hat Buchungen und wurde deaktiviert statt gelöscht.');
        }

        $square->meta()->delete();
        $square->delete();

        return redirect()->route('admin.squares.index')->with('success', 'Platz gelöscht.');
    }
```

- [ ] **Step 4: Tests laufen lassen (grün)**

Run: `php artisan test --filter=SquareManagementTest`
Expected: PASS

- [ ] **Step 5: Volle Suite + Pint**

Run: `php artisan test`
Expected: PASS (keine Regression)
Run: `vendor/bin/pint app/Http/Controllers/Admin/SquareController.php app/Models/Square.php`

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/SquareController.php tests/Feature/Admin/SquareManagementTest.php
git commit -m "feat(squares): delete with soft-delete guard for booked courts"
```

---

## Self-Review-Ergebnis

- **Spec-Abdeckung:** Name+Alias (T3/T4), Status/readonly.message (T3 Felder + Form T3), alle Zeit-/Kapazitätsfelder + Umrechnung (T3 store, T4 toForm), Sichtbarkeits-Mapping (T3), capacity-ask-names (T1 Konstante + T3 Validierung/Form), Anzahl Plätze via create/destroy (T3/T5), Soft-Delete-Guard (T5), Rechte `admin.config` (T2), Tests (alle Tasks). ✓
- **Platzhalter:** keine.
- **Typ-Konsistenz:** `buildPayload()` liefert `['columns','meta']`, von `store`/`update` genutzt; `toForm()`/`defaults()` liefern dieselben Form-Keys, die `_form.blade.php` liest. `setMeta()` aus T1 wird in `applyMeta()` (T3) und Tests verwendet. ✓
- **Annahme:** Test-DB-Schema (`bs_squares`-Migration) entspricht dem realen Schema — bestätigt durch vorhandene `SquareFactory` + grüne Model-Tests.
