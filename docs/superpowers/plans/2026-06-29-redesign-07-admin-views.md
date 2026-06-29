# Admin Views Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Alle 19 Admin-View-Dateien von Legacy-BEM-Klassen auf Tailwind v4 + Design-System migrieren.

**Architecture:** Jede View liegt in `@section('admin-content')`. Alle Legacy-Klassen (`admin-form__*`, `admin-table`, `abf-*`, `default-button`, `abmelden-button`, `admin-card`, `admin-filter-bar__*`) werden durch Tailwind-Klassen ersetzt. Controller, Routen, JS-Blöcke, `name`/`id`-Attribute bleiben unverändert.

**Tech Stack:** Laravel 13 Blade, Tailwind CSS v4, kein Alpine.js (Admin-Views sind server-side)

**Spec:** `docs/superpowers/specs/2026-06-29-redesign-07-admin-views-design.md`

---

## Design-Referenz (Kurzfassung)

**Card-Pattern:**
```html
<div class="bg-white rounded-xl border border-[#e0ddd7] shadow-sm overflow-hidden">
  <div class="px-6 py-4 border-b border-[#f0ede6]">
    <h2 class="text-base font-semibold text-[#151515]" style="font-family: var(--font-display)">Titel</h2>
  </div>
  <div class="px-6 py-5">...</div>
</div>
```

**Label:** `text-xs font-semibold uppercase tracking-wide text-[#6a6e73]`

**Input:** `w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent`

**Field-Wrapper:** `<div class="flex flex-col gap-1">`

**2-Spalten:** `grid grid-cols-1 sm:grid-cols-2 gap-4`

**Inline-Gruppe:** `flex gap-3 items-end`

**Primär-Button:** `bg-[#bf4316] hover:bg-[#9e3412] text-white text-sm font-medium px-5 py-2 rounded transition-colors`

**Sekundär-Button:** `border border-[#d1cbc0] text-[#6a6e73] text-sm px-4 py-2 rounded hover:bg-[#f9f8f6] transition-colors`

**Delete-Button (inline):** `text-xs text-red-600 hover:text-red-800 hover:underline transition-colors`

**Delete-Button (standalone):** `bg-red-600 hover:bg-red-700 text-white text-sm px-4 py-2 rounded transition-colors`

**Tabelle:**
```html
<div class="overflow-x-auto">
  <table class="w-full">
    <thead><tr>
      <th class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73] px-4 py-3 border-b border-[#e0ddd7] text-left whitespace-nowrap">Spalte</th>
    </tr></thead>
    <tbody>
      <tr class="hover:bg-[#fafaf9]">
        <td class="text-sm text-[#151515] px-4 py-3 border-b border-[#f0ede6]">Wert</td>
      </tr>
    </tbody>
  </table>
</div>
```

**Filter-Bar:** `flex flex-wrap gap-3 items-end` mit `<div class="flex flex-col gap-1">` pro Feld

**H1:** `<h1 class="text-2xl font-bold text-[#151515]" style="font-family: var(--font-display)">`

**HR:** `<hr class="border-[#f0ede6] my-2">`

**Paginierung:** `<div class="px-4 py-3 border-t border-[#f0ede6]">{{ $items->links() }}</div>`

**onsubmit confirm — IMMER Js::from():**
```blade
{{-- Falsch --}}
onsubmit="return confirm('{{ __('...') }}')"
{{-- Richtig --}}
onsubmit="return confirm({{ Js::from(__('...')) }})"
```

**Checkbox:**
```html
<label class="flex items-center gap-2 text-sm text-[#151515] cursor-pointer">
  <input type="checkbox" ...> Beschriftung
</label>
```

**Inline DELETE-Form in Tabelle:**
```html
<form method="POST" action="..." onsubmit="return confirm({{ Js::from(__('...')) }})" class="inline">
  @csrf @method('DELETE')
  <button type="submit" class="text-xs text-red-600 hover:text-red-800 hover:underline transition-colors">Löschen</button>
</form>
```

**Seiten-Wrapper:** `<div class="flex flex-col gap-6">`

---

## Task 1: admin/dashboard.blade.php

**Files:** Modify: `resources/views/admin/dashboard.blade.php`

**Aktuell:** `<h1>` ohne Klassen, `<hr class="admin-separator">`, `<ul>` mit Links

**Ziel:**
- Seiten-Wrapper `flex flex-col gap-6`
- H1 mit Tailwind-Klassen
- HR → `border-[#f0ede6] my-2`
- `<ul>` → Card mit Link-Liste oder einfache gestylte `<ul class="flex flex-col gap-2">`

- [ ] `dashboard.blade.php` auf Tailwind umstellen
- [ ] Keine Legacy-Klassen mehr vorhanden
- [ ] Commit: `style(admin): dashboard auf Tailwind v4 migrieren`

---

## Task 2: admin/users/ (index + _form + create + edit)

**Files:**
- Modify: `resources/views/admin/users/index.blade.php`
- Modify: `resources/views/admin/users/_form.blade.php`
- Modify: `resources/views/admin/users/create.blade.php`
- Modify: `resources/views/admin/users/edit.blade.php`

**Key-Hinweise:**
- `_form.blade.php`: `<script>`-Block mit `PRIVILEGE_PRESETS` — **KOMPLETT unverändert lassen**
- IDs `#uf-status`, `#uf-privileges` bleiben erhalten
- `edit.blade.php`: Drei Formulare (Update + Password-Reset + Delete), alle auf Tailwind
- Delete-Form: `onsubmit="return confirm({{ Js::from(__('booking.admin.users.confirm_delete')) }})"` (Js::from!)
- Multi-Select für Privileges: eigene `<select multiple>` — Tailwind-Border reicht, kein komplexes Styling
- Sektionen in `_form` → Cards oder `<div class="flex flex-col gap-4">` mit Überschrift

- [ ] `index.blade.php`: Filter-Bar + bedingte Tabelle auf Tailwind
- [ ] `_form.blade.php`: Alle `admin-form__*` Klassen ersetzen, Script unberührt
- [ ] `create.blade.php`: Wrapper + Submit-Button
- [ ] `edit.blade.php`: Drei Formulare, Delete mit Js::from, sekundäre Buttons
- [ ] Commit: `style(admin): users views auf Tailwind v4 migrieren`

---

## Task 3: admin/squares/ (index + _form + create + edit)

**Files:**
- Modify: `resources/views/admin/squares/index.blade.php`
- Modify: `resources/views/admin/squares/_form.blade.php`
- Modify: `resources/views/admin/squares/create.blade.php`
- Modify: `resources/views/admin/squares/edit.blade.php`

**Key-Hinweise:**
- `index.blade.php`: Tabelle immer sichtbar (kein `$searched`-Gate), DELETE per Zeile mit Js::from
- `_form.blade.php`: 4 Sektionen (General, Booking, Times, Display), sehr viele Felder
  - Checkbox-Felder: `capacity_heterogenic`, `allow_notes`, `pseudo_time_block_bookable`
  - Notizen (`admin-form__note`) → `<p class="text-xs text-[#6a6e73] mt-1">`
- `create.blade.php` + `edit.blade.php`: Simple Wrapper, nur Button stylen

- [ ] `index.blade.php` auf Tailwind (Tabelle + Delete mit Js::from)
- [ ] `_form.blade.php` alle Sektionen auf Tailwind (4 Cards oder gestackte Divs)
- [ ] `create.blade.php` + `edit.blade.php` Wrapper + Buttons
- [ ] Commit: `style(admin): squares views auf Tailwind v4 migrieren`

---

## Task 4: admin/events/ (index + _form + create + edit)

**Files:**
- Modify: `resources/views/admin/events/index.blade.php`
- Modify: `resources/views/admin/events/_form.blade.php`
- Modify: `resources/views/admin/events/create.blade.php`
- Modify: `resources/views/admin/events/edit.blade.php`

**Key-Hinweise:**
- `index.blade.php`: Filter-Bar (Text + Court-Select + Date) + bedingte Tabelle
- `_form.blade.php`: `admin-form__field--flex` + `admin-form__inline-group` → `flex gap-3 items-end` + `flex flex-col gap-1`; inline-Paare für Datum/Zeit und Court/Capacity
- `create.blade.php`: Hat Tab-Switcher (`admin-type-switcher`) — dieser ist **link-basiert** (kein JS), frei gestaltbar. Aktiver Tab mit `bg-[#bf4316] text-white`, inaktiver Tab als Outline-Link.
- `create.blade.php`: `admin-form--compact` kann entfernt oder behalten werden (kein Legacy-CSS-Effekt nötig)
- `edit.blade.php`: `@unless(request('popup'))` für H1 bleibt

- [ ] `index.blade.php` Filter-Bar + Tabelle
- [ ] `_form.blade.php` Inline-Gruppen korrekt umsetzen
- [ ] `create.blade.php` Tab-Switcher + Form
- [ ] `edit.blade.php` Form + Buttons
- [ ] Commit: `style(admin): events views auf Tailwind v4 migrieren`

---

## Task 5: admin/bookings/index.blade.php + show.blade.php

**Files:**
- Modify: `resources/views/admin/bookings/index.blade.php`
- Modify: `resources/views/admin/bookings/show.blade.php`

**Key-Hinweise für index.blade.php:**
- Filter-Bar: Nur `<select name="sid">` + Submit-Button (kein Text-Input)
- `calendar-wrap` Div entfernen (Legacy-Wrapper)
- `style="display:inline"` auf Cancel/Delete-Forms → `class="inline"`
- Cancel-Form: `onsubmit="return confirm({{ Js::from(__('booking.admin.bookings.confirm_cancel')) }})"` (Js::from!)
- Delete-Form: `onsubmit="return confirm({{ Js::from(__('booking.admin.bookings.confirm_delete')) }})"` (Js::from!)
- `abmelden-button default-button` auf Delete-Button → `text-xs text-red-600 hover:text-red-800 hover:underline transition-colors`
- Paginierung: in `<div class="px-4 py-3 border-t border-[#f0ede6]">` wrappen

**Key-Hinweise für show.blade.php:**
- `<dl>` → Tailwind-gestylte Definition-Liste oder Card mit Key/Value-Rows
- `<hr class="admin-separator">` → `<hr class="border-[#f0ede6] my-2">`
- Reservierungen-Tabelle → Standard Tailwind-Tabelle
- Cancel/Delete-Forms: Js::from, Buttons auf Tailwind

- [ ] `index.blade.php` Filter-Bar + Tabelle + Actions (Js::from, class="inline")
- [ ] `show.blade.php` DL + Tabelle + Actions
- [ ] Commit: `style(admin): bookings index+show auf Tailwind v4 migrieren`

---

## Task 6: admin/bookings/edit.blade.php + _form.blade.php

**Files:**
- Modify: `resources/views/admin/bookings/edit.blade.php`
- Modify: `resources/views/admin/bookings/_form.blade.php`

**KRITISCH — edit.blade.php:**
- `<script>`-Block am Ende (80 Zeilen) — **KOMPLETT UNVERÄNDERT LASSEN**, kein Whitespace ändern
- JS-referenzierte IDs MÜSSEN erhalten bleiben: `#admin-booking-quantity`, `#admin-booking-repeat`, `#admin-booking-date-end`, `input[name="date"]`, `#admin-player3-field`, `#admin-player4-field`, `#admin-player3`, `#admin-player4`, `#type-switcher`, `#admin-booking-create`, `#panel-event`
- `<datalist id="admin-player-suggestions">` bleibt unverändert
- `admin-type-switcher__tab--active` Klasse bleibt auf Tab-Buttons (wird per JS getoggelt, Styling kommt von booking.css)
- `$isCreateMode`-Conditional-Struktur bleibt exakt erhalten
- Form-IDs `admin-booking-create` und `admin-booking-update` bleiben (Submit-Button referenziert via `form="{{ $formId }}"`)
- `abf-*` Klassen im Create-Mode ersetzen: `abf-card` → Card-Pattern, `abf-row` → Grid/Flex, `abf-label` → Label-Klassen, `abf-input` → Input-Klassen, `abf-field` → `flex flex-col gap-1`
- Edit-Mode: `admin-form__*` Klassen ersetzen
- Actions außerhalb des Forms (Edit-Mode): Cancel + Delete mit Js::from, `style` entfernen
- `admin-btn-primary` → Primary-Button-Klassen; `default-button` → Sekundär-Button-Klassen

**_form.blade.php:**
- Wird nur im Edit-Mode per `@include` eingebunden
- `admin-form__section`, `admin-form__row`, `admin-form__field`, `admin-form__label` → Tailwind
- `admin-booking-quantity` ID auf `<select>` bleibt (JS-referenziert)
- `admin-player2/3/4` IDs bleiben (JS-referenziert)
- `<datalist id="admin-player-suggestions">` bleibt

- [ ] `_form.blade.php` alle Sektionen auf Tailwind (IDs erhalten)
- [ ] `edit.blade.php` Create-Mode (abf-*) auf Tailwind
- [ ] `edit.blade.php` Edit-Mode (`admin-form__*`) auf Tailwind
- [ ] `edit.blade.php` Actions-Bereich (Js::from, Button-Klassen)
- [ ] Script-Block verifizieren: identisch mit Original (byte-for-byte)
- [ ] Commit: `style(admin): bookings edit+form auf Tailwind v4 migrieren`

---

## Task 7: admin/config/edit.blade.php + testmail/index.blade.php

**Files:**
- Modify: `resources/views/admin/config/edit.blade.php`
- Modify: `resources/views/admin/testmail/index.blade.php`

**Key-Hinweise config/edit.blade.php:**
- 5 Sektionen → 5 Cards
- `style="width:140px"` auf singular/plural Inputs → entfernen, stattdessen `w-36`
- `admin-form__checkbox` → `<label class="flex items-center gap-2 text-sm text-[#151515] cursor-pointer">`
- `admin-form__field--flex` + `admin-form__inline-group` → `flex gap-3 items-end` + `flex flex-col gap-1`
- Submit am Ende: Primary-Button in `<div class="flex justify-end pt-2">`

**Key-Hinweise testmail/index.blade.php:**
- `admin-card` / `admin-card__title` / `admin-card__desc` → Card-Pattern mit Header + Body
- `admin-form__row` → `flex flex-col gap-1`
- `admin-form__input` → Input-Klassen, `is-invalid`-Klasse entfernen
- `admin-form__error` → `<p class="text-xs text-red-600 mt-1">`
- Hardcodierte deutsche Strings bleiben unverändert (kein i18n-Eingriff)

- [ ] `config/edit.blade.php` 5 Sektionen auf Tailwind, style-Attribute entfernen
- [ ] `testmail/index.blade.php` Card-Pattern + Formular
- [ ] Commit: `style(admin): config+testmail auf Tailwind v4 migrieren`

---

## Task 8: Build + Commit

**Files:** `public/build/` (generiert)

- [ ] `npm run build` ausführen
- [ ] Build ohne Fehler bestätigen
- [ ] `git add public/build/`
- [ ] Commit: `build: public/build nach Admin-Views Redesign aktualisiert`

---

## Erfolgskriterien

1. Keine `admin-form__*`, `admin-table`, `abf-*`, `default-button`, `abmelden-button`, `admin-card`, `admin-filter-bar__*` Klassen mehr in den View-Dateien
2. Alle inline `style="..."` Attribute entfernt
3. JS-Block in `bookings/edit.blade.php` byte-identisch mit Original
4. `Js::from()` in allen `onsubmit`-Confirm-Dialogen
5. `npm run build` ohne Fehler
