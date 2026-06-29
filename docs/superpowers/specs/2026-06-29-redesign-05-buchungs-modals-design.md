# Design: Redesign Sub-Projekt 5 — Buchungs-Modals

**Datum:** 2026-06-29
**Serie:** UI-Redesign (7 Sub-Projekte)
**Ziel:** Booking-Modal, Cancel-Modal, Event-Modal und Feedback-Modal von vanilla JS (`booking.js`) + Legacy-CSS (`booking.css`) auf Alpine.js + Tailwind v4 migrieren. Admin-Iframe-Modal bleibt in `booking.js`.

---

## Kontext

`public/js/booking.js` (480 Zeilen) verwaltet aktuell 5 Modals via `display: block/none`. Die Modals sind in `resources/views/components/calendar/modals.blade.php` definiert. Trigger befinden sich in `resources/views/components/calendar/grid.blade.php`.

**Was migriert wird:** Feedback, Booking, Cancel, Event-Modal → Alpine.js + Tailwind  
**Was bleibt:** Admin-Iframe-Modal (`#admin-booking-modal`) in `booking.js`  
**Autocomplete:** Entfällt — Spieler-Felder werden zu plain `<input type="text">`

---

## Kommunikations-Pattern: Window Custom Events

Trigger (grid.blade.php) und Modals (modals.blade.php) liegen in getrennten DOM-Bäumen. Kommunikation via Alpine Custom Events:

```
Trigger in grid.blade.php:
  @click="$dispatch('open-booking', {sid, date, timeStart, timeEnd, squareName, dateLabel, timeLabel, createUrl})"

Modal in modals.blade.php:
  x-data="{open: false, sid: null, ...}"
  @open-booking.window="open=true; sid=$event.detail.sid; ..."
```

Escape-Key und Backdrop-Click schließen das Modal:
```html
<div @keydown.escape.window="open=false" @click.self="open=false" ...>
```

---

## Dateiänderungen

| Aktion | Datei |
|--------|-------|
| Rewrite | `resources/views/components/calendar/modals.blade.php` |
| Modify | `resources/views/components/calendar/grid.blade.php` |
| Modify | `public/js/booking.js` |
| Build | `public/build/` |

**Unverändert:** `public/css/booking.css`, `resources/views/calendar/index.blade.php`, alle Controller + Routen

---

## Modal-Struktur (gemeinsame Tailwind-Basis)

```html
<div x-show="open"
     x-transition.opacity
     @keydown.escape.window="open=false"
     @click.self="open=false"
     class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4">
  <div class="relative w-full max-w-md bg-white rounded-xl shadow-xl border border-[#e0ddd7]">

    <!-- Header -->
    <div class="px-6 pt-5 pb-3 border-b border-[#f0ede6]">
      <h2 class="text-base font-semibold text-[#151515]" style="font-family: var(--font-display)">Titel</h2>
      <p class="text-sm text-[#6a6e73] mt-0.5" x-text="squareName + ' · ' + dateLabel + ' · ' + timeLabel"></p>
    </div>

    <!-- Body -->
    <div class="px-6 py-4 flex flex-col gap-4">...</div>

    <!-- Actions -->
    <div class="px-6 pb-5 flex flex-col gap-2">
      <button type="submit" class="w-full bg-[#bf4316] hover:bg-[#9e3412] text-white font-medium py-2 rounded transition-colors">Primäre Aktion</button>
      <button type="button" @click="open=false" class="w-full border border-[#d1cbc0] text-[#6a6e73] py-2 rounded hover:bg-[#f9f8f6] transition-colors">Abbrechen</button>
    </div>

    <!-- Close ✕ -->
    <button type="button" @click="open=false" class="absolute top-3 right-4 text-[#9a9a9a] hover:text-[#151515] text-lg leading-none">✕</button>
  </div>
</div>
```

---

## 1. Feedback-Modal

**Trigger:** Automatisch beim Laden wenn `session('success')` oder `session('error')` gesetzt.

```html
<div x-data="{open: @json(session()->has('success') || session()->has('error'))}"
     x-init="if (open) setTimeout(() => open=false, 4000)"
     x-show="open"
     ...backdrop-classes...>
  <div class="relative w-full max-w-sm bg-white rounded-xl shadow-xl border border-[#e0ddd7] px-6 py-5">
    @if(session('success'))
      <p class="text-green-700 font-medium">{{ session('success') }}</p>
    @elseif(session('error'))
      <p class="text-red-600 font-medium">{{ session('error') }}</p>
    @endif
    <button @click="open=false" class="mt-4 w-full border border-[#d1cbc0] text-[#6a6e73] py-2 rounded hover:bg-[#f9f8f6] transition-colors">Schließen</button>
  </div>
</div>
```

Auto-close nach 4 Sekunden via `setTimeout`.

---

## 2. Booking-Modal

**Trigger aus grid.blade.php** (nur `data-action="book"`):
```html
@click="$dispatch('open-booking', {
    sid: '{{ $slot['sid'] }}',
    date: '{{ $slot['date'] }}',
    timeStart: '{{ $slot['time_start'] }}',
    timeEnd: '{{ $slot['time_end'] }}',
    squareName: '{{ $slot['square_name'] }}',
    dateLabel: '{{ $slot['date_label'] }}',
    timeLabel: '{{ $slot['time_label'] }}',
    createUrl: '{{ $slot['create_url'] ?? '' }}'
})"
```

**Alpine-State:**
```javascript
{
  open: false,
  sid: null, date: null, timeStart: null, timeEnd: null,
  squareName: '', dateLabel: '', timeLabel: '',
  createUrl: '',
  quantity: '2',
  openBooking(detail) {
    Object.assign(this, detail);
    this.quantity = '2';
    this.open = true;
  }
}
```

**Listener:**
```html
@open-booking.window="openBooking($event.detail)"
```

**Player-Felder** (reaktiv, kein JS):
```html
<select x-model="quantity" name="quantity">
  <option value="2">Einzel (2 Spieler)</option>
  <option value="4">Doppel (4 Spieler)</option>
</select>

<!-- Spieler 2 — immer sichtbar, immer required -->
<input name="player2" type="text" required placeholder="Name Mitspieler">

<!-- Spieler 3+4 — nur bei Doppel -->
<div x-show="quantity == '4'">
  <input name="player3" type="text" placeholder="Name Mitspieler 3">
</div>
<div x-show="quantity == '4'">
  <input name="player4" type="text" placeholder="Name Mitspieler 4">
</div>
```

**Form-Action:** `{{ route('bookings.store') }}` (POST), Hidden-Inputs: `sid`, `date`, `time_start`, `time_end` via `x-bind:value`.

**"Veranstaltung anlegen"-Button** (Admin only):
```html
@can('admin.event')
  <a x-show="createUrl" x-bind:href="createUrl"
     class="w-full text-center text-sm text-[#bf4316] hover:underline py-1">
    Veranstaltung anlegen
  </a>
@endcan
```

**max-width:** `max-w-md` (420px).

---

## 3. Cancel-Modal

**Trigger aus grid.blade.php** (nur user-cancel, d.h. kein `data-delete-url`):
```html
@click="$dispatch('open-cancel', {
    bid: '{{ $booking['bid'] }}',
    squareName: '{{ $booking['square_name'] }}',
    dateLabel: '{{ $booking['date_label'] }}',
    timeLabel: '{{ $booking['time_label'] }}'
})"
```

Trigger mit `data-delete-url` (Admin-Pfad) behalten `booking-trigger` Klasse → wird von `booking.js` gehandelt.

**Alpine-State:**
```javascript
{
  open: false,
  bid: null, squareName: '', dateLabel: '', timeLabel: '',
  openCancel(detail) { Object.assign(this, detail); this.open = true; }
}
```

**Form:**
```html
<form x-bind:action="'/bookings/' + bid" method="POST">
  @csrf @method('DELETE')
  <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-medium py-2 rounded transition-colors">Buchung stornieren</button>
</form>
```

**max-width:** `max-w-md`.

---

## 4. Event-Modal (Admin only, `@can('admin.event')`)

**Trigger:** Wird vom Booking-Modal ausgelöst — der "Veranstaltung anlegen"-Link navigiert direkt zur Admin-URL (kein separates Modal-Trigger nötig). Die Event-Erstellung läuft über die bestehende Admin-URL.

**Alternativ** (wenn direktes Modal gewünscht): Dispatch `open-event` vom Booking-Modal-Button.

Da das Event-Modal im Original bereits `route('admin.events.store')` POST verwendet und ein eigenes Formular mit Datum/Zeit-Feldern hat, bleibt es als Alpine-Modal in `modals.blade.php` erhalten:

**Trigger:** Button im Booking-Modal dispatcht `open-event`:
```html
@can('admin.event')
  <button type="button" x-show="createUrl"
          @click="open=false; $dispatch('open-event', {sid, date, timeStart, timeEnd, squareName, dateLabel, timeLabel})"
          class="w-full text-sm text-[#bf4316] hover:underline py-1">
    Veranstaltung anlegen
  </button>
@endcan
```

**Alpine-State:**
```javascript
{
  open: false,
  sid: null, date: null, timeStart: null, timeEnd: null,
  squareName: '', dateLabel: '', timeLabel: '',
  openEvent(detail) { Object.assign(this, detail); this.open = true; }
}
```

**Formular-Felder:**
- Event-Name (text, required)
- Datum Start + Zeit Start (2-Spalten-Grid)
- Datum Ende + Zeit Ende (2-Spalten-Grid)
- Beschreibung (textarea, optional)
- Platz-Select (`#event-sid`, aus `$squares` prop)

**Form-Action:** `{{ route('admin.events.store') }}` (POST).  
**max-width:** `max-w-lg` (520px).

---

## grid.blade.php — Änderungen

**Vorher (booking-trigger Klasse + data-action):**
```html
<a href="#" class="calendar-cell cc-free booking-trigger" data-action="book" data-sid="...">
```

**Nachher (Alpine dispatch + booking-trigger nur für Admin):**
```html
{{-- User: book --}}
<a href="#" class="calendar-cell cc-free"
   @click.prevent="$dispatch('open-booking', {sid: '{{$sid}}', ...})">

{{-- Admin: book (bleibt für booking.js) --}}
<a href="#" class="calendar-cell cc-free booking-trigger" data-action="admin-book" data-sid="...">

{{-- User: cancel (kein delete-url) --}}
<a href="#" class="calendar-cell cc-own"
   @click.prevent="$dispatch('open-cancel', {bid: '{{$bid}}', ...})">

{{-- Admin: cancel (mit delete-url, bleibt für booking.js) --}}
<a href="#" class="calendar-cell cc-own booking-trigger" data-action="cancel" data-delete-url="...">
```

---

## public/js/booking.js — Reduzierung

Folgende Funktionen/Code-Blöcke werden **entfernt:**
- `openBookingModal()`, `closeBookingModal()`, `syncBookingFields()`
- `openCancelModal()`, `closeCancelModal()`
- `openEventModal()`, `closeEventModal()`
- `closeFeedbackModal()`, Feedback-Modal-Logik
- Player-Autocomplete (fetch + datalist)
- Event-Delegation für `data-action="book"` und user-cancel
- Escape-Key-Handler (wird von Alpine übernommen)

**Verbleibend (~80 Zeilen):**
- `openAdminBookingModal()`, `openAdminUrlInModal()`
- Iframe-Navigations-Monitor (reload bei Navigation außerhalb Form)
- Event-Delegation für `data-action="admin-book"` und admin-cancel (mit `data-delete-url`)
- `showModal(modal)` / `hideModal(modal)` nur für Iframe-Modal

---

## Erfolgskriterien

1. Booking-Modal öffnet via `$dispatch('open-booking', {...})` mit korrekten Slot-Daten
2. Player-Felder 3+4 erscheinen/verschwinden reaktiv bei Quantity-Wechsel
3. Cancel-Modal öffnet für eigene Buchungen, DELETE-Form funktioniert
4. Event-Modal öffnet via Button im Booking-Modal, POST zu `admin.events.store`
5. Feedback-Modal erscheint bei Flash-Session, schließt nach 4s automatisch
6. Escape-Key + Backdrop-Click schließen alle 4 Modals
7. Admin-Iframe-Modal funktioniert weiterhin unverändert
8. `booking.js` enthält nur noch Iframe-Modal-Code
9. `npm run build` ohne Fehler
