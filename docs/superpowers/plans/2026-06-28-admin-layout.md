# Admin-Layout im Alt-System-Stil — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die Admin-Seiten optisch an das Alt-System angleichen — zentriertes weißes Panel, umrandete Tabellen mit grauer Kopfzeile, Label/Eingabe-Formularzeilen mit kursiven Hinweisen — bei verbesserter Top-Navigation, ohne Logik-/Textänderungen.

**Architecture:** Rein additive CSS-Erweiterung in `public/css/booking.css` (Präfix `admin-`), umstrukturiertes `layouts/admin.blade.php` (Panel + Nav-Aktivzustand) und angepasstes Markup der Admin-Views. Keine Controller/Routen/Models.

**Tech Stack:** Blade, CSS (kein Framework), PHPUnit (`RefreshDatabase`, `#[Test]`), Tests laufen via `wsl ... php artisan test`.

**Spec:** `docs/superpowers/specs/2026-06-28-admin-layout-design.md`

---

## File Structure

- **Modify** `public/css/booking.css` — neuer Abschnitt „Admin layout" (Panel, Nav-Aktiv, Tabelle, Formularraster, Intro/Separator)
- **Modify** `resources/views/layouts/admin.blade.php` — Panel-Wrapper + Nav-Aktivzustand + Flash-Styling
- **Modify** Listen-Views: `admin/squares/index`, `admin/events/index`, `admin/users/index`, `admin/bookings/index` → `.admin-table` + Intro/Separator
- **Modify** Formular-Views: `admin/config/edit`, `admin/squares/_form`, `admin/events/_form`, `admin/users/_form` → `.admin-form`
- **Modify** `admin/dashboard`, `admin/bookings/show` — Panel-/Tabellen-Optik
- **Modify** `tests/Feature/Admin/SquareManagementTest.php` — eine Assertion auf `.admin-panel`/`.admin-table`-Struktur

Nicht angefasst: `admin/bookings/edit.blade.php` (eigenes `admin-booking-*`-Layout, nur Farben über Panel).

---

## Task 1: CSS — Admin-Abschnitt

**Files:**
- Modify: `public/css/booking.css` (am Dateiende anhängen)

- [ ] **Step 1: CSS-Block anhängen**

```css
/* ============================================================
   Admin layout (Alt-System-Optik: helles, zentriertes Panel)
   ============================================================ */
.admin-nav { display: flex; flex-wrap: wrap; gap: 6px; }
.admin-nav .default-button.is-active {
    color: #333;
    border-color: #888;
    border-bottom-color: #333;
    background: linear-gradient(to bottom, #fff 0%, #dcdcdc 100%);
    font-weight: 700;
}

.admin-panel {
    margin: 16px auto;
    max-width: 1024px;
    padding: 32px;
    color: #333;
    background: #fff;
    border-radius: 4px;
    box-shadow: 0 3px 6px rgba(0, 0, 0, 0.25);
}
.admin-panel h1 { margin: 0 0 4px; font-size: 22px; color: #333; }
.admin-intro { margin: 0 0 12px; color: #666; font-size: 13px; }
.admin-separator { margin: 12px 0 20px; border: 0; border-top: 1px solid #ccc; }

/* Tabellen */
.admin-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
.admin-table th,
.admin-table td { padding: 8px; border: 1px solid #ccc; text-align: left; vertical-align: top; }
.admin-table thead th { background: #eee; color: #547B97; font-weight: 700; }
.admin-table tbody tr:nth-child(even) td { background: #fafafa; }
.admin-table .admin-table__actions { white-space: nowrap; }
.admin-table .admin-table__actions a { margin-right: 10px; }
.admin-table form { display: inline; }

/* Formularraster: Label links, Eingabe rechts, Hinweis darunter */
.admin-form { width: 100%; }
.admin-form__row {
    display: grid;
    grid-template-columns: 220px 1fr;
    gap: 6px 16px;
    align-items: start;
    padding: 8px 0;
}
.admin-form__label { padding-top: 7px; text-align: right; color: #547B97; }
.admin-form__field input[type="text"],
.admin-form__field input[type="email"],
.admin-form__field input[type="number"],
.admin-form__field input[type="time"],
.admin-form__field input[type="date"],
.admin-form__field input[type="password"],
.admin-form__field select,
.admin-form__field textarea { width: 100%; max-width: 420px; }
.admin-form__note { margin: 3px 0 0; color: #666; font-size: 11px; font-style: italic; line-height: 1.4; }
.admin-form__actions { margin-top: 20px; padding-left: 236px; }

@media (max-width: 700px) {
    .admin-panel { padding: 16px; }
    .admin-form__row { grid-template-columns: 1fr; }
    .admin-form__label { text-align: left; padding-top: 0; }
    .admin-form__actions { padding-left: 0; }
}
```

- [ ] **Step 2: Sichtprüfung Syntax**

Run: `wsl bash -c "cd /mnt/c/development/bookingnew && php artisan view:clear"`
Expected: kein Fehler (CSS wird nicht kompiliert; dieser Schritt stellt nur sicher, dass nichts anderes kaputt ist).

- [ ] **Step 3: Commit**

```bash
git add public/css/booking.css
git commit -m "feat(admin-ui): add admin panel/table/form styles (old-system look)"
```

---

## Task 2: Layout-Chrome (Panel + Nav-Aktivzustand)

**Files:**
- Modify: `resources/views/layouts/admin.blade.php`

- [ ] **Step 1: Layout neu fassen**

Ersetze den Inhalt von `resources/views/layouts/admin.blade.php` durch:

```blade
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration – @yield('admin-title', 'Übersicht')</title>
    <link rel="stylesheet" href="{{ asset('css/booking.css') }}">
</head>
<body>
<div class="page-shell">
    <header class="top-header">
        <div class="brand-title">Administration</div>
        <nav class="admin-nav">
            <a href="{{ route('admin.dashboard') }}" class="default-button {{ request()->routeIs('admin.dashboard') ? 'is-active' : '' }}">Übersicht</a>
            @if(Route::has('admin.users.index'))@can('admin.user')<a href="{{ route('admin.users.index') }}" class="default-button {{ request()->routeIs('admin.users.*') ? 'is-active' : '' }}">Benutzer</a>@endcan @endif
            @if(Route::has('admin.events.index'))@can('admin.event')<a href="{{ route('admin.events.index') }}" class="default-button {{ request()->routeIs('admin.events.*') ? 'is-active' : '' }}">Veranstaltungen</a>@endcan @endif
            @if(Route::has('admin.bookings.index'))@can('admin.booking')<a href="{{ route('admin.bookings.index') }}" class="default-button {{ request()->routeIs('admin.bookings.*') ? 'is-active' : '' }}">Buchungen</a>@endcan @endif
            @if(Route::has('admin.squares.index'))@can('admin.config')<a href="{{ route('admin.squares.index') }}" class="default-button {{ request()->routeIs('admin.squares.*') ? 'is-active' : '' }}">Plätze</a>@endcan @endif
            @if(Route::has('admin.config.edit'))@can('admin.config')<a href="{{ route('admin.config.edit') }}" class="default-button {{ request()->routeIs('admin.config.*') ? 'is-active' : '' }}">Konfiguration</a>@endcan @endif
            <a href="{{ route('calendar.index') }}" class="default-button">Zum Kalender</a>
        </nav>
    </header>
    @if(session('success'))<div class="success-message">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="error-message">{{ $errors->first() }}</div>@endif
    <main class="admin-panel">@yield('admin-content')</main>
</div>
</body>
</html>
```

- [ ] **Step 2: Verifizieren, dass Admin-Seiten weiter laden**

Run: `wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test --filter='AccessControlTest|SquareManagementTest'"`
Expected: PASS (Routen/`assertSee('Administration')`/`assertSee('Plätze')` unverändert gültig).

- [ ] **Step 3: Commit**

```bash
git add resources/views/layouts/admin.blade.php
git commit -m "feat(admin-ui): centered panel + active-state top nav"
```

---

## Task 3: Listen-Views → .admin-table

**Files:**
- Modify: `resources/views/admin/squares/index.blade.php`
- Modify: `resources/views/admin/events/index.blade.php`
- Modify: `resources/views/admin/users/index.blade.php`
- Modify: `resources/views/admin/bookings/index.blade.php`
- Test: `tests/Feature/Admin/SquareManagementTest.php`

- [ ] **Step 1: Failing-Test (Struktur) ergänzen**

In `SquareManagementTest` zu `admin_sees_square_list` ergänzen:

```php
        $this->actingAs($this->admin())->get('/admin/squares')
            ->assertOk()->assertSee('Plätze')->assertSee('Garagenplatz')
            ->assertSee('admin-table', false);
```

- [ ] **Step 2: Test laufen lassen (scheitert)**

Run: `wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test --filter=admin_sees_square_list"`
Expected: FAIL — `admin-table` noch nicht im Markup.

- [ ] **Step 3: squares/index umstellen**

`resources/views/admin/squares/index.blade.php`:

```blade
@extends('layouts.admin')
@section('admin-title', 'Plätze')
@section('admin-content')
    <h1>Plätze</h1>
    <p class="admin-intro">Welche Plätze haben Sie? Wie sollen diese heißen?</p>
    <hr class="admin-separator">
    <a href="{{ route('admin.squares.create') }}" class="default-button">Neuer Platz</a>
    <table class="admin-table">
        <thead><tr><th>Name</th><th>Anzeigename</th><th>Status</th><th>Zeit</th><th>Zeitblock</th><th></th></tr></thead>
        <tbody>
        @foreach($squares as $square)
            <tr>
                <td>{{ $square->name }}</td>
                <td>{{ $square->display_name }}</td>
                <td>{{ $square->status->value }}</td>
                <td>{{ substr((string) $square->time_start, 0, 5) }}–{{ substr((string) $square->time_end, 0, 5) }} Uhr</td>
                <td>{{ (int) round($square->time_block / 60) }} Min</td>
                <td class="admin-table__actions">
                    <a href="{{ route('admin.squares.edit', $square) }}">Bearbeiten</a>
                    <form method="POST" action="{{ route('admin.squares.destroy', $square) }}" onsubmit="return confirm('Platz löschen?')">
                        @method('DELETE') @csrf
                        <button type="submit" class="default-button">Löschen</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endsection
```

- [ ] **Step 4: events/index, users/index, bookings/index analog umstellen**

In allen drei den `<table class="booking-grid">` durch `<table class="admin-table">` ersetzen, `<thead>` mit Spaltenüberschriften umschließen (falls noch nicht vorhanden), die Aktionszelle mit `class="admin-table__actions"` versehen und am Seitenkopf `h1` + optionale `<p class="admin-intro">` + `<hr class="admin-separator">` setzen. Spalten/Inhalte/Routen bleiben unverändert. (Beim Editieren jeweils die Datei lesen und nur Tabellen-Klasse + Kopf anpassen.)

- [ ] **Step 5: Tests laufen lassen (grün)**

Run: `wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test --filter='SquareManagementTest|EventManagementTest|UserManagementTest|AdminBookingTest'"`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add resources/views/admin/squares/index.blade.php resources/views/admin/events/index.blade.php resources/views/admin/users/index.blade.php resources/views/admin/bookings/index.blade.php tests/Feature/Admin/SquareManagementTest.php
git commit -m "feat(admin-ui): admin list tables in old-system style"
```

---

## Task 4: Konfigurations-Formular → .admin-form

**Files:**
- Modify: `resources/views/admin/config/edit.blade.php`

- [ ] **Step 1: Formular umstellen**

`resources/views/admin/config/edit.blade.php` (Feldnamen/`name`-Attribute unverändert):

```blade
@extends('layouts.admin')
@section('admin-title', 'Konfiguration')
@section('admin-content')
<h1>Konfiguration</h1>
<hr class="admin-separator">
<form method="POST" action="{{ route('admin.config.update') }}" class="admin-form">
    @method('PUT')
    @csrf
    <div class="admin-form__row">
        <label class="admin-form__label" for="cf-system-name">Name des Systems</label>
        <div class="admin-form__field"><input id="cf-system-name" type="text" name="system_name" value="{{ $values['system_name'] }}"></div>
    </div>
    <div class="admin-form__row">
        <label class="admin-form__label" for="cf-client-name">Vollständiger Vereinsname</label>
        <div class="admin-form__field"><input id="cf-client-name" type="text" name="client_name_full" value="{{ $values['client_name_full'] }}"></div>
    </div>
    <div class="admin-form__row">
        <label class="admin-form__label" for="cf-email">Kontakt-E-Mail</label>
        <div class="admin-form__field"><input id="cf-email" type="email" name="contact_email" value="{{ $values['contact_email'] }}"></div>
    </div>
    <div class="admin-form__row">
        <label class="admin-form__label" for="cf-days">Kalendertage</label>
        <div class="admin-form__field"><input id="cf-days" type="number" name="calendar_days" min="1" max="31" value="{{ $values['calendar_days'] }}"></div>
    </div>
    <div class="admin-form__row">
        <label class="admin-form__label" for="cf-reg">Registrierung</label>
        <div class="admin-form__field">
            <select id="cf-reg" name="registration">
                <option value="0" @selected((string) $values['registration'] === '0')>Nein</option>
                <option value="1" @selected((string) $values['registration'] === '1')>Ja</option>
            </select>
        </div>
    </div>
    <div class="admin-form__row">
        <label class="admin-form__label" for="cf-maint">Wartungsmodus</label>
        <div class="admin-form__field">
            <select id="cf-maint" name="maintenance">
                <option value="0" @selected((string) $values['maintenance'] === '0')>Aus</option>
                <option value="1" @selected((string) $values['maintenance'] === '1')>An</option>
            </select>
        </div>
    </div>
    <div class="admin-form__actions"><button type="submit" class="default-button">Speichern</button></div>
</form>
@endsection
```

- [ ] **Step 2: Test**

Run: `wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test --filter=ConfigTest"`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add resources/views/admin/config/edit.blade.php
git commit -m "feat(admin-ui): config form as label/field rows"
```

---

## Task 5: Platz-Formular → .admin-form mit Hinweisen

**Files:**
- Modify: `resources/views/admin/squares/_form.blade.php`

- [ ] **Step 1: _form auf das Raster umstellen**

Jede bisherige `<label>Feld <input ...></label>`-Zeile wird zu einem `.admin-form__row` mit getrenntem Label + Feld; die im Alt-System vorhandenen Erläuterungen kommen als `.admin-form__note`. Beispielzeilen (Rest analog, alle `name`-Attribute/`old()`-Aufrufe unverändert):

```blade
@csrf
<div class="admin-form__row">
    <label class="admin-form__label" for="sf-name">Name</label>
    <div class="admin-form__field"><input id="sf-name" type="text" name="name" value="{{ old('name', $form['name']) }}"></div>
</div>
<div class="admin-form__row">
    <label class="admin-form__label" for="sf-alias">Anzeigename</label>
    <div class="admin-form__field">
        <input id="sf-alias" type="text" name="alias" value="{{ old('alias', $form['alias']) }}">
        <p class="admin-form__note">Sichtbarer Name im Kalender (z. B. Garagenplatz).</p>
    </div>
</div>
<div class="admin-form__row">
    <label class="admin-form__label" for="sf-status">Status</label>
    <div class="admin-form__field">
        <select id="sf-status" name="status">
            @foreach(['enabled' => 'Aktiviert', 'readonly' => 'Nur Verwaltung', 'disabled' => 'Deaktiviert'] as $val => $lbl)
                <option value="{{ $val }}" @selected(old('status', $form['status']) === $val)>{{ $lbl }}</option>
            @endforeach
        </select>
    </div>
</div>
{{-- … übrige Felder identisch umgesetzt … --}}
<div class="admin-form__row">
    <label class="admin-form__label" for="sf-range-cancel">Stornierung (Stunden)</label>
    <div class="admin-form__field">
        <input id="sf-range-cancel" type="number" step="0.01" min="0" name="range_cancel" value="{{ old('range_cancel', $form['range_cancel']) }}">
        <p class="admin-form__note">Bis wann darf spätestens storniert werden? 0 = nie stornieren, 0,01 = praktisch immer.</p>
    </div>
</div>
```

Checkboxen (`capacity_heterogenic`, `allow_notes`, `pseudo_time_block_bookable`) bleiben als `<label><input type="checkbox">…</label>` in der Feld-Spalte (`<div class="admin-form__field">`), Label-Spalte leer (`<span class="admin-form__label"></span>`).

Hinweistexte (als `.admin-form__note`) sinngemäß aus dem Alt-System: Kapazität „Wieviele Spieler passen auf einen Platz?", Buchung im Voraus „Wie viele Tage im Voraus kann max. gebucht werden?", Buchungen einschränken „Auf 0 setzen, um beliebig viele Buchungen zu erlauben.", Bezeichnung freier Plätze „Individuelle Bezeichnung freier Plätze; Standard ist Frei.".

Create/Edit-View (`create.blade.php`, `edit.blade.php`) bleiben unverändert — sie binden `_form` nur ein.

- [ ] **Step 2: Tests**

Run: `wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test --filter=SquareManagementTest"`
Expected: PASS (`create_page_renders` prüft `assertSee('Anzeigename')` — Label bleibt vorhanden).

- [ ] **Step 3: Commit**

```bash
git add resources/views/admin/squares/_form.blade.php
git commit -m "feat(admin-ui): square form as label/field rows with notes"
```

---

## Task 6: Event-/Benutzer-Formulare → .admin-form

**Files:**
- Modify: `resources/views/admin/events/_form.blade.php`
- Modify: `resources/views/admin/users/_form.blade.php`

- [ ] **Step 1: Beide Formulare auf `.admin-form__row` umstellen**

Datei jeweils lesen, jede `<label>…</label>`-Zeile in ein `.admin-form__row` (Label + `.admin-form__field`) überführen, `name`-Attribute/`old()`/`@selected`/`@checked` unverändert lassen. Die einbindenden create/edit-Views ergänzen den umschließenden `<form>` (vorhanden) und ggf. `class="admin-form"` am `<form>`-Tag.

- [ ] **Step 2: Tests**

Run: `wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test --filter='EventManagementTest|UserManagementTest'"`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add resources/views/admin/events/_form.blade.php resources/views/admin/users/_form.blade.php resources/views/admin/events/create.blade.php resources/views/admin/events/edit.blade.php resources/views/admin/users/create.blade.php resources/views/admin/users/edit.blade.php
git commit -m "feat(admin-ui): event and user forms as label/field rows"
```

---

## Task 7: Dashboard/Show-Politur + Gesamtverifikation

**Files:**
- Modify: `resources/views/admin/dashboard.blade.php`
- Modify: `resources/views/admin/bookings/show.blade.php`

- [ ] **Step 1: dashboard & show an Panel/Tabelle angleichen**

`h1` + optionale `.admin-intro` + `.admin-separator`; etwaige Tabellen auf `.admin-table`. Buchungs-Edit (`admin/bookings/edit.blade.php`) bleibt unverändert (eigenes Layout).

- [ ] **Step 2: Volle Suite**

Run: `wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test"`
Expected: PASS (167+ Tests)

- [ ] **Step 3: Visuelle Verifikation**

Server starten und prüfen: `/admin/squares` (Tabelle), `/admin/config` (Formular), `/admin/squares/create` (langes Formular mit Hinweisen). Vergleich mit den Alt-System-Screenshots.

- [ ] **Step 4: Commit**

```bash
git add resources/views/admin/dashboard.blade.php resources/views/admin/bookings/show.blade.php
git commit -m "feat(admin-ui): polish dashboard and booking detail panel"
```

---

## Self-Review-Ergebnis

- **Spec-Abdeckung:** Panel/Chrome (T2), CSS-Bausteine (T1), Listen-Tabellen (T3), Konfig-Formular (T4), Platz-Formular mit Hinweisen (T5), Event/User-Formulare (T6), Dashboard/Show + Verifikation (T7), Buchungs-Edit ausgenommen (T7 Hinweis), keine Logik/Textänderungen (durchgängig). ✓
- **Platzhalter:** Die Tasks 3/5/6 beschreiben ein wiederkehrendes Muster für mehrere Views; das konkrete Muster ist jeweils mit vollständigem Beispiel gezeigt (squares/index, config, square _form), die übrigen Views folgen exakt diesem Muster — bewusst so, da identische, mechanische Transformation.
- **Konsistenz:** Klassen `.admin-panel/.admin-table/.admin-form/.admin-form__row/__label/__field/__note/__actions/.admin-intro/.admin-separator/.is-active` werden in T1 definiert und ab T2 verwendet. ✓
- **Annahme:** `booking.css` enthält bereits `.page-shell/.top-header/.brand-title/.default-button/.success-message/.error-message` (bestätigt) — Admin-Stile sind rein additiv.
