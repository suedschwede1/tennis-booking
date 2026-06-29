# Design: Redesign Sub-Projekt 6 — Account Views

**Datum:** 2026-06-29
**Serie:** UI-Redesign (7 Sub-Projekte)
**Ziel:** `account/edit.blade.php` und `account/bookings.blade.php` von Legacy-CSS auf Tailwind v4 + Design-System umstellen.

---

## Kontext

Beide Views erweitern `@extends('layouts.app')` und erhalten damit automatisch den neuen Header (Sub-Projekt 2) und Google Fonts. Kein strukturelles Refactoring — nur Tailwind-Klassen statt Legacy-Inline-CSS.

**Routen:**
- `GET /mein-konto` → `account.edit`
- `PUT /mein-konto` → `account.update` (Profil)
- `PUT /mein-konto/passwort` → `account.password`
- `GET /meine-buchungen` → `account.bookings`

**Cancel-Button:** Inline `onsubmit="return confirm(...)"` + DELETE-Form — kein Modal.

---

## Gemeinsame Layout-Basis

```blade
@section('content')
<div class="max-w-2xl mx-auto px-4 py-8 flex flex-col gap-6">
    <h1 class="text-2xl font-bold text-[#151515]"
        style="font-family: var(--font-display)">Seitentitel</h1>
    {{-- Cards --}}
</div>
@endsection
```

**Card-Wrapper:**
```html
<div class="bg-white rounded-xl border border-[#e0ddd7] shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-[#f0ede6]">
        <h2 class="text-base font-semibold text-[#151515]"
            style="font-family: var(--font-display)">Card-Titel</h2>
    </div>
    <div class="px-6 py-5">
        {{-- Card-Inhalt --}}
    </div>
</div>
```

**Input-Styling:**
```html
<input class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm
              focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
```

**Label-Styling:**
```html
<label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">Feldname</label>
```

**Fehler-Inline:**
```html
@error('fieldname')
    <p class="text-xs text-red-600 mt-0.5">{{ $message }}</p>
@enderror
```

**Submit-Button:**
```html
<button type="submit"
        class="bg-[#bf4316] hover:bg-[#9e3412] text-white text-sm font-medium px-5 py-2
               rounded transition-colors">
    Speichern
</button>
```

---

## `account/edit.blade.php` — Mein Konto

### Seitenstruktur

```
@section('content')
  max-w-2xl mx-auto px-4 py-8 flex flex-col gap-6
    H1: "Mein Konto"
    Card 1: Profil
    Card 2: Passwort ändern
@endsection
```

### Card 1: Profil (PUT /mein-konto)

**Header:** "Profil"

**Felder:**

`alias` (Anzeigename) — full-width, required, über dem Grid:
```html
<div class="flex flex-col gap-1">
    <label class="...">Anzeigename</label>
    <input type="text" name="alias" value="{{ old('alias', $user->alias) }}" required maxlength="128" class="...">
    @error('alias') <p class="...">{{ $message }}</p> @enderror
</div>
```

2-Spalten-Grid (grid grid-cols-1 sm:grid-cols-2 gap-4) für:
- `firstname` / `lastname`
- `email` / `phone`
- `street` / (leer oder zip)
- `zip` / `city`
- `gender` (select: –/male/female) — single column

**Gender-Select:**
```html
<select name="gender" class="...">
    <option value="">–</option>
    <option value="male" {{ old('gender', $gender) === 'male' ? 'selected' : '' }}>Männlich</option>
    <option value="female" {{ old('gender', $gender) === 'female' ? 'selected' : '' }}>Weiblich</option>
</select>
```

**Aktionen (Card-Footer):**
```html
<div class="px-6 pb-5 flex justify-end">
    <button type="submit" class="bg-[#bf4316] ... ">Speichern</button>
</div>
```

### Card 2: Passwort ändern (PUT /mein-konto/passwort)

**Header:** "Passwort ändern"

Drei Felder vertikal:
- `current_password` — required
- `password` — required, min 6
- `password_confirmation` — required

**Aktionen:**
```html
<div class="px-6 pb-5 flex justify-end">
    <button type="submit" class="bg-[#bf4316] ...">Passwort ändern</button>
</div>
```

---

## `account/bookings.blade.php` — Meine Buchungen

### Seitenstruktur

```
@section('content')
  max-w-2xl mx-auto px-4 py-8 flex flex-col gap-6
    H1: "Meine Buchungen"
    Card: Buchungsliste (oder Leer-Zustand)
@endsection
```

### Card: Buchungsliste

**Tabellen-Wrapper:** `overflow-x-auto` (verhindert horizontales Scrolling der Seite)

**Tabellen-Header:**
```html
<thead>
    <tr>
        <th class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]
                   px-4 py-3 border-b border-[#e0ddd7] text-left whitespace-nowrap">Platz</th>
        <th class="...">Datum</th>
        <th class="...">Zeit</th>
        <th class="...">Status</th>
        <th class="...">Aktion</th>
    </tr>
</thead>
```

**Tabellen-Zeilen:**
```html
<tr class="hover:bg-[#fafaf9]">
    <td class="text-sm text-[#151515] px-4 py-3 border-b border-[#f0ede6]">
        {{ $booking->square->display_name }}
    </td>
    <td class="...">{{ $reservation->date }}</td>
    <td class="... whitespace-nowrap">{{ $timeStart }} – {{ $timeEnd }} Uhr</td>
    <td class="...">
        <span class="inline-block bg-green-50 text-green-700 text-xs rounded-full px-2 py-0.5">
            aktiv
        </span>
    </td>
    <td class="...">
        <form method="POST" action="{{ route('bookings.destroy', $booking) }}"
              onsubmit="return confirm('Buchung wirklich stornieren?')">
            @csrf @method('DELETE')
            <button type="submit"
                    class="text-xs text-red-600 hover:text-red-800 hover:underline transition-colors">
                Abmelden
            </button>
        </form>
    </td>
</tr>
```

**Leer-Zustand** (keine Buchungen):
```html
<div class="px-6 py-10 text-center flex flex-col items-center gap-3">
    <p class="text-sm text-[#6a6e73]">Keine aktiven Buchungen.</p>
    <a href="{{ route('calendar.index') }}"
       class="text-sm text-[#bf4316] hover:underline">→ Zum Kalender</a>
</div>
```

---

## Was sich NICHT ändert

- `AccountController.php` — kein Eingriff
- Routen — kein Eingriff
- `layouts/app.blade.php` — kein Eingriff
- Translation-Keys (`__('booking.*')`) — bleiben wo vorhanden
- Formular-Felder, Namen, Validierungslogik — unverändert

---

## Erfolgskriterien

1. `account/edit.blade.php` zeigt Profil- und Passwort-Card auf beigem Hintergrund
2. Fehler werden inline unter dem jeweiligen Feld angezeigt
3. `account/bookings.blade.php` zeigt Buchungstabelle mit Cancel-Button
4. Cancel-Button öffnet `confirm()` Dialog vor DELETE
5. Leer-Zustand zeigt Hinweistext + Kalender-Link
6. `npm run build` ohne Fehler
