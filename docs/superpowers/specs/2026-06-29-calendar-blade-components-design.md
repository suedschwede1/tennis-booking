# Design: Kalender Blade-Komponenten

**Datum:** 2026-06-29
**Ziel:** `calendar/index.blade.php` (492 Zeilen) in wiederverwendbare Blade-Komponenten aufteilen, ohne Layout oder JS-Funktionalität zu brechen.

---

## Ausgangslage

| Datei | Zeilen | Inhalt |
|---|---|---|
| `resources/views/calendar/index.blade.php` | 492 | Grid, Modals, Inline-JS, Sections |
| `app/Http/Controllers/CalendarController.php` | 229 | Unverändert |
| `public/js/booking.js` | 518 | Unverändert |

---

## Neue Struktur

```
resources/views/
  calendar/
    index.blade.php                  ← ~30 Zeilen (nur Gerüst)
  components/
    calendar/
      grid.blade.php                 ← Table + Zell-Logik + Inline-JS
      modals.blade.php               ← alle 4 Modals
```

---

## Komponenten

### `<x-calendar.grid>`

**Props:**
```php
$dates          // Collection der sichtbaren Tage
$squares        // Collection der Plätze
$dateLabels     // Array ['Y-m-d' => ['short', 'long', 'full']]
$reservationsBySlot  // Array [date][sid][hour] => Reservation
$eventBlocks    // Array [date][sid][hour] => Block-Array
$eventSkip      // Array [date][sid][hour] => bool
$today          // string 'Y-m-d'
$now            // Carbon
$isLoggedIn     // bool
$isAdmin        // bool
$authUserId     // int|null
$canAdminEvents // bool
$date           // Carbon (aktuelles Datum, für Links)
```

**Enthält:**
- `<colgroup>` mit responsiven CSS-Klassen
- `<thead>` (Datums- und Platz-Kopfzeile)
- `<tbody>` mit der gesamten Slot-Logik inkl. `continue`-Statement (bleibt inline)
- `<tfoot>` (Wiederholung der Kopfzeile)
- `@push('scripts')` Block mit dem Responsive-Visibility-JS

**Wichtig:** Das `continue`-Statement in der `@foreach`-Schleife (eventSkip-Check) bleibt zwingend inline — Blade-Komponenten unterbrechen den Loop-Kontext.

---

### `<x-calendar.modals>`

**Props:**
```php
$date           // Carbon (für redirect_to URLs)
$squares        // Collection (für Event-Platz-Auswahl)
$isAdmin        // bool (für @can-Guards)
```

**Enthält:**
- `#cancel-modal` — Stornierung eigener Buchungen
- `#booking-modal` — Neue Buchung erstellen (inkl. Spielernamen, Datalist)
- `#admin-booking-modal` — Admin-Iframe-Modal (`@auth` Guard)
- `#event-modal` — Event anlegen (`@can('admin.event')` Guard)

---

## Ergebnis `index.blade.php`

```blade
@extends('layouts.app')
@section('title', ...)

@push('header-nav')
  {{-- Datums-Navigation (bleibt inline, gehört zum Layout) --}}
@endpush

@section('calendar-system-info') ... @endsection
@section('calendar-help') ... @endsection

@section('content')
<div class="calendar-layout">
  <div class="calendar-wrap">
    <x-calendar.grid :dates="$dates" :squares="$squares" ... />
  </div>
</div>

<x-calendar.modals :date="$date" :squares="$squares" :isAdmin="$isAdmin" />
@endsection
```

---

## Was sich NICHT ändert

- `CalendarController.php` — keine Änderungen
- `public/js/booking.js` — keine Änderungen
- Alle CSS-Klassen — keine Änderungen
- JS-Selektoren (`#booking-modal`, `.booking-trigger`, etc.) — keine Änderungen
- Routing — keine Änderungen

---

## Risiken

| Risiko | Maßnahme |
|---|---|
| `continue` in Loop bricht | Zell-Logik bleibt vollständig inline in `grid.blade.php` |
| Props vergessen | Nach Umzug: Browser-Test aller Slot-Typen (frei, eigen, fremd, Event, vergangenheit) |
| `@can`/`@auth` Guards in Modals | Bleiben unverändert in `modals.blade.php` |

---

## Reihenfolge

1. `modals.blade.php` erstellen, aus `index.blade.php` ausschneiden, testen
2. `grid.blade.php` erstellen, aus `index.blade.php` ausschneiden, testen
3. `index.blade.php` auf ~30 Zeilen reduzieren
