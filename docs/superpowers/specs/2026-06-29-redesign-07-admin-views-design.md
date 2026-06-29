# Design: Redesign Sub-Projekt 7 — Admin Views

**Datum:** 2026-06-29
**Serie:** UI-Redesign (7 Sub-Projekte)
**Ziel:** Alle 19 Admin-View-Dateien von Legacy-BEM-Klassen (`admin-form__*`, `admin-table`, `abf-*`, `default-button`) auf Tailwind v4 + Design-System migrieren.

---

## Kontext

Das Admin-Layout (`layouts/admin.blade.php`, `admin-sidebar.blade.php`) wurde bereits in Sub-Projekt 2 modernisiert. Dieses Sub-Projekt behandelt ausschließlich den Inhalt der Views innerhalb von `@section('admin-content')`.

**Alle 19 Dateien:**
- `admin/dashboard.blade.php`
- `admin/users/index.blade.php`, `create.blade.php`, `edit.blade.php`, `_form.blade.php`
- `admin/squares/index.blade.php`, `create.blade.php`, `edit.blade.php`, `_form.blade.php`
- `admin/events/index.blade.php`, `create.blade.php`, `edit.blade.php`, `_form.blade.php`
- `admin/bookings/index.blade.php`, `show.blade.php`, `edit.blade.php`, `_form.blade.php`
- `admin/config/edit.blade.php`
- `admin/testmail/index.blade.php`

**Was NICHT geändert wird:**
- Controller, Routen, Translation-Keys
- `name`-Attribute auf Form-Feldern
- `id`-Attribute die von JS verwendet werden
- `<script>`-Blöcke in `users/_form.blade.php` und `bookings/edit.blade.php`
- `<datalist>`-Element und `list="..."`-Attribute in `bookings/edit.blade.php`
- Hardcodierte deutsche Strings in `testmail/index.blade.php` (kein i18n-Eingriff)

---

## Task-Struktur

| Task | Dateien |
|------|---------|
| Task 1 | `admin/dashboard.blade.php` |
| Task 2 | `admin/users/` (index + _form + create + edit) |
| Task 3 | `admin/squares/` (index + _form + create + edit) |
| Task 4 | `admin/events/` (index + _form + create + edit) |
| Task 5 | `admin/bookings/index.blade.php` + `show.blade.php` |
| Task 6 | `admin/bookings/edit.blade.php` + `_form.blade.php` |
| Task 7 | `admin/config/edit.blade.php` + `testmail/index.blade.php` |
| Task 8 | `npm run build` + `public/build/` committen |

---

## Design-System

### Seitenstruktur

```html
{{-- Innerhalb @section('admin-content') --}}
<div class="flex flex-col gap-6">

    <h1 class="text-2xl font-bold text-[#151515]"
        style="font-family: var(--font-display)">{{ __('...') }}</h1>

    {{-- Filter-Bar (wenn vorhanden) --}}
    <form class="flex flex-wrap gap-3 items-end">...</form>

    {{-- Haupt-Card --}}
    <div class="bg-white rounded-xl border border-[#e0ddd7] shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-[#f0ede6]">
            <h2 class="text-base font-semibold text-[#151515]"
                style="font-family: var(--font-display)">Card-Titel</h2>
        </div>
        <div class="px-6 py-5">
            {{-- Inhalt --}}
        </div>
    </div>

</div>
```

### BEM → Tailwind Mapping

**Wrapper / Strukturelemente**

| Alt | Neu |
|-----|-----|
| `admin-form__section` | `<div class="flex flex-col gap-4">` innerhalb Card-Body |
| `admin-form__section` mit Titel | Card-Pattern mit Header-Div |
| `abf-card` / `admin-card` | `bg-white rounded-xl border border-[#e0ddd7] shadow-sm overflow-hidden` |
| `admin-separator` / `<hr class="admin-separator">` | `<hr class="border-[#f0ede6] my-2">` |
| `abf-row` | `grid grid-cols-1 sm:grid-cols-2 gap-4` |

**Form-Felder**

| Alt | Neu |
|-----|-----|
| `admin-form__label` | `text-xs font-semibold uppercase tracking-wide text-[#6a6e73]` |
| `admin-form__input` (text/number/email) | `w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent` |
| `admin-form__input` (select) | Gleiche Klassen wie Text-Input |
| `admin-form__note` | `text-xs text-[#6a6e73] mt-1` |
| `admin-form__field--flex` / `admin-form__inline-group` | `flex gap-3 items-end` |
| Field-Wrapper `admin-form__field` | `<div class="flex flex-col gap-1">` |
| Checkbox-Wrapper | `<label class="flex items-center gap-2 text-sm text-[#151515] cursor-pointer">` |

**Tabellen**

```html
<div class="overflow-x-auto">
    <table class="w-full">
        <thead>
            <tr>
                <th class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]
                           px-4 py-3 border-b border-[#e0ddd7] text-left whitespace-nowrap">
                    Spaltenname
                </th>
            </tr>
        </thead>
        <tbody>
            <tr class="hover:bg-[#fafaf9]">
                <td class="text-sm text-[#151515] px-4 py-3 border-b border-[#f0ede6]">
                    Inhalt
                </td>
            </tr>
        </tbody>
    </table>
</div>
```

**Filter-Bar**

```html
<form method="GET" action="{{ route(...) }}" class="flex flex-wrap gap-3 items-end">
    <div class="flex flex-col gap-1">
        <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">Suche</label>
        <input type="text" name="search" value="{{ request('search') }}"
               class="border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
    </div>
    <button type="submit"
            class="bg-[#bf4316] hover:bg-[#9e3412] text-white text-sm font-medium px-4 py-2 rounded transition-colors">
        Suchen
    </button>
</form>
```

**Buttons**

| Alt | Neu |
|-----|-----|
| `default-button` (Speichern/Submit) | `bg-[#bf4316] hover:bg-[#9e3412] text-white text-sm font-medium px-5 py-2 rounded transition-colors` |
| `abmelden-button` (Stornieren, Inline-Link) | `text-xs text-red-600 hover:text-red-800 hover:underline transition-colors` |
| Sekundär (Abbrechen/Zurück) | `border border-[#d1cbc0] text-[#6a6e73] text-sm px-4 py-2 rounded hover:bg-[#f9f8f6] transition-colors` |
| Admin DELETE (eigenständiger Button) | `bg-red-600 hover:bg-red-700 text-white text-sm px-4 py-2 rounded transition-colors` |

**Inline-Forms (DELETE / Aktions-Buttons in Tabellenzellen)**

```html
{{-- Inline DELETE in Tabellenzeile --}}
<form method="POST" action="{{ route(..., $item) }}"
      onsubmit="return confirm({{ Js::from(__('booking.messages.confirm_delete')) }})"
      class="inline">
    @csrf @method('DELETE')
    <button type="submit"
            class="text-xs text-red-600 hover:text-red-800 hover:underline transition-colors">
        Löschen
    </button>
</form>
```

**Paginierung**

```html
<div class="px-4 py-3 border-t border-[#f0ede6]">
    {{ $items->links() }}
</div>
```

---

## Sonderfälle

### `bookings/edit.blade.php` — Dual-Mode + JS

**Was geändert wird:**
- `abf-card` / `abf-row` Wrapper → Tailwind Card-Pattern
- Tab-Switcher Buttons (`#tab-booking`, `#tab-event`) — IDs bleiben, nur Klassen ersetzen
- `abmelden-button`, `default-button` → Tailwind Button-Klassen
- `style="display:inline"` auf Aktions-Forms → `class="inline"`
- `admin-form__section`, `admin-form__field`, etc. → Tailwind

**Was NICHT geändert wird:**
- Der `<script>`-Block (komplett unverändert, kein Whitespace)
- `id`-Attribute: `#tab-booking`, `#tab-event`, `#booking-panel`, `#event-panel`, `#quantity`, `#player2-row`, `#player3-row`, `#player4-row`, `#repeat-type`, `#repeat-end-date-row`, `#date` etc.
- `<datalist id="player-names">` und `list="player-names"`-Attribute
- `@include('admin.events._form', [...])`-Aufruf
- `$isCreate`-Conditional-Struktur

### `users/_form.blade.php` — Inline-JS für Privilege-Presets

- `<script>`-Block bleibt **komplett unverändert**
- IDs `#status`, `#privileges` bleiben erhalten

### `testmail/index.blade.php`

- Hardcodierte deutsche Strings bleiben (kein i18n-Eingriff)
- Nur `admin-card` / `admin-card__title` → Tailwind Card-Pattern

### `onsubmit`-Confirm-Dialoge

Überall: `{{ __('...') }}` in JS-String-Kontext → `Js::from(__('...'))`

```blade
{{-- Falsch --}}
onsubmit="return confirm('{{ __('booking.messages.confirm_delete') }}')"

{{-- Richtig --}}
onsubmit="return confirm({{ Js::from(__('booking.messages.confirm_delete')) }})"
```

---

## Erfolgskriterien

1. Keine `admin-form__*`, `admin-table`, `abf-*`, `default-button`, `abmelden-button`, `admin-card`, `admin-filter-bar__*` Klassen mehr in den View-Dateien
2. Alle inline `style="..."` Attribute entfernt
3. JS-Blöcke in `users/_form.blade.php` und `bookings/edit.blade.php` unverändert
4. `Js::from()` in allen `onsubmit`-Confirm-Dialogen
5. `npm run build` ohne Fehler
6. Admin-Bereiche (Benutzerverwaltung, Platzverwaltung, Buchungen, Config) im Browser optisch konsistent mit dem restlichen Design-System
