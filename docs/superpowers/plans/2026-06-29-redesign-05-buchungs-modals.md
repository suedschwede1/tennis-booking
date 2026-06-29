# UI-Redesign Buchungs-Modals Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Booking-Modal, Cancel-Modal, Event-Modal und Feedback-Modal von vanilla JS (`booking.js`) auf Alpine.js + Tailwind v4 migrieren; Admin-Iframe-Modal bleibt in `booking.js`.

**Architecture:** Trigger in `grid.blade.php` dispatchen Window Custom Events (`open-booking`, `open-cancel`, `open-event`). `modals.blade.php` wird komplett neu geschrieben mit vier Alpine-Komponenten die diese Events abhören. `booking.js` wird auf ~90 Zeilen Iframe-Modal-Code reduziert. Kein Autocomplete — Spieler-Felder sind plain Text-Inputs.

**Tech Stack:** Laravel 13 Blade, Alpine.js v3, Tailwind CSS v4, vanilla JS (für Iframe-Modal)

---

## File Structure

| Aktion | Datei |
|--------|-------|
| Rewrite | `resources/views/components/calendar/modals.blade.php` |
| Modify | `resources/views/components/calendar/grid.blade.php` |
| Replace | `public/js/booking.js` |
| Build | `public/build/` |

---

### Task 1: `modals.blade.php` komplett neu schreiben

**Files:**
- Modify: `resources/views/components/calendar/modals.blade.php`

- [ ] **Schritt 1: Aktuelle Datei lesen**

Lies `C:\development\bookingnew\resources\views\components\calendar\modals.blade.php` um den Ausgangszustand zu kennen.

- [ ] **Schritt 2: Datei komplett ersetzen**

Ersetze den gesamten Inhalt von `resources/views/components/calendar/modals.blade.php` durch:

```blade
@props(['date', 'squares'])

{{-- ═══════════════════════════════════════════════════════
     FEEDBACK MODAL — auto-close nach 4s
     ═══════════════════════════════════════════════════════ --}}
@if(session()->has('success') || session()->has('error'))
<div x-data="{ open: true }"
     x-init="setTimeout(() => open = false, 4000)"
     x-show="open"
     x-transition.opacity
     @keydown.escape.window="open = false"
     @click.self="open = false"
     class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4"
     style="display: none;">
    <div class="relative w-full max-w-sm bg-white rounded-xl shadow-xl border border-[#e0ddd7] px-6 py-5">
        @if(session('success'))
            <p class="text-sm font-medium text-green-700">{{ session('success') }}</p>
        @else
            <p class="text-sm font-medium text-red-600">{{ session('error') }}</p>
        @endif
        <button type="button"
                @click="open = false"
                class="mt-4 w-full border border-[#d1cbc0] text-[#6a6e73] text-sm py-2 rounded hover:bg-[#f9f8f6] transition-colors">
            Schließen
        </button>
    </div>
</div>
@endif

{{-- ═══════════════════════════════════════════════════════
     CANCEL MODAL — Buchung stornieren (nur eigene)
     ═══════════════════════════════════════════════════════ --}}
@auth
<div x-data="{
        open: false,
        bid: null,
        editUrl: '',
        squareName: '',
        dateLabel: '',
        timeLabel: '',
        openCancel(detail) {
            this.bid        = detail.bid;
            this.editUrl    = detail.editUrl   || '';
            this.squareName = detail.squareName;
            this.dateLabel  = detail.dateLabel;
            this.timeLabel  = detail.timeLabel;
            this.open = true;
        }
     }"
     @open-cancel.window="openCancel($event.detail)"
     x-show="open"
     x-transition.opacity
     @keydown.escape.window="open = false"
     @click.self="open = false"
     class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4"
     style="display: none;">
    <div class="relative w-full max-w-md bg-white rounded-xl shadow-xl border border-[#e0ddd7]">

        <div class="px-6 pt-5 pb-3 border-b border-[#f0ede6]">
            <h2 class="text-base font-semibold text-[#151515]"
                style="font-family: var(--font-display)">Buchung stornieren</h2>
            <p class="text-sm text-[#6a6e73] mt-0.5"
               x-text="squareName + ' · ' + dateLabel + ' · ' + timeLabel"></p>
        </div>

        <div class="px-6 py-4">
            <p class="text-sm text-[#6a6e73]">
                Möchten Sie diese Buchung wirklich stornieren? Diese Aktion kann nicht rückgängig gemacht werden.
            </p>
        </div>

        <div class="px-6 pb-5 flex flex-col gap-2">
            <template x-if="editUrl">
                <a x-bind:href="editUrl"
                   class="w-full text-center text-sm border border-[#d1cbc0] text-[#6a6e73] py-2 rounded hover:bg-[#f9f8f6] transition-colors block">
                    Buchung bearbeiten
                </a>
            </template>
            <form method="POST" x-bind:action="'/bookings/' + bid">
                @csrf
                @method('DELETE')
                <button type="submit"
                        class="w-full bg-red-600 hover:bg-red-700 text-white text-sm font-medium py-2 rounded transition-colors">
                    Buchung stornieren
                </button>
            </form>
            <button type="button"
                    @click="open = false"
                    class="w-full border border-[#d1cbc0] text-[#6a6e73] text-sm py-2 rounded hover:bg-[#f9f8f6] transition-colors">
                Abbrechen
            </button>
        </div>

        <button type="button"
                @click="open = false"
                class="absolute top-3 right-4 text-[#9a9a9a] hover:text-[#151515] text-lg leading-none">✕</button>
    </div>
</div>
@endauth

{{-- ═══════════════════════════════════════════════════════
     BOOKING MODAL — Neue Buchung
     ═══════════════════════════════════════════════════════ --}}
@auth
<div x-data="{
        open: false,
        sid: null,
        date: null,
        timeStart: null,
        timeEnd: null,
        squareName: '',
        dateLabel: '',
        timeLabel: '',
        timeStartFormatted: '',
        timeEndFormatted: '',
        quantity: '2',
        openBooking(detail) {
            this.sid                = detail.sid;
            this.date               = detail.date;
            this.timeStart          = detail.timeStart;
            this.timeEnd            = detail.timeEnd;
            this.squareName         = detail.squareName;
            this.dateLabel          = detail.dateLabel;
            this.timeLabel          = detail.timeLabel;
            this.timeStartFormatted = detail.timeStartFormatted;
            this.timeEndFormatted   = detail.timeEndFormatted;
            this.quantity = '2';
            this.open = true;
        }
     }"
     @open-booking.window="openBooking($event.detail)"
     x-show="open"
     x-transition.opacity
     @keydown.escape.window="open = false"
     @click.self="open = false"
     class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4"
     style="display: none;">
    <div class="relative w-full max-w-md bg-white rounded-xl shadow-xl border border-[#e0ddd7]">

        <div class="px-6 pt-5 pb-3 border-b border-[#f0ede6]">
            <h2 class="text-base font-semibold text-[#151515]"
                style="font-family: var(--font-display)"
                x-text="squareName"></h2>
            <p class="text-sm text-[#6a6e73] mt-0.5"
               x-text="dateLabel + ' · ' + timeLabel"></p>
        </div>

        <form method="POST" action="{{ route('bookings.store') }}">
            @csrf
            <input type="hidden" name="sid"        x-bind:value="sid">
            <input type="hidden" name="date"       x-bind:value="date">
            <input type="hidden" name="time_start" x-bind:value="timeStart">
            <input type="hidden" name="time_end"   x-bind:value="timeEnd">

            <div class="px-6 py-4 flex flex-col gap-4">

                {{-- Anzahl Spieler --}}
                <div class="flex flex-col gap-1">
                    <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">Anzahl Spieler</label>
                    <select name="quantity"
                            x-model="quantity"
                            class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
                        <option value="2">Einzel (2 Spieler)</option>
                        <option value="4">Doppel (4 Spieler)</option>
                    </select>
                </div>

                {{-- Spieler 2 — immer sichtbar, required --}}
                <div class="flex flex-col gap-1">
                    <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">Mitspieler</label>
                    <input type="text"
                           name="player2"
                           required
                           placeholder="Name Mitspieler"
                           class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
                </div>

                {{-- Spieler 3 — nur Doppel --}}
                <div class="flex flex-col gap-1" x-show="quantity == '4'">
                    <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">Mitspieler 3</label>
                    <input type="text"
                           name="player3"
                           placeholder="Name Mitspieler 3"
                           class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
                </div>

                {{-- Spieler 4 — nur Doppel --}}
                <div class="flex flex-col gap-1" x-show="quantity == '4'">
                    <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">Mitspieler 4</label>
                    <input type="text"
                           name="player4"
                           placeholder="Name Mitspieler 4"
                           class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
                </div>

            </div>

            <div class="px-6 pb-5 flex flex-col gap-2">
                @can('admin.event')
                <button type="button"
                        @click="open = false; $dispatch('open-event', {sid, date, timeStart, timeEnd, timeStartFormatted, timeEndFormatted, squareName, dateLabel, timeLabel})"
                        class="w-full text-center text-sm text-[#bf4316] hover:underline py-1">
                    Veranstaltung anlegen
                </button>
                @endcan
                <button type="submit"
                        class="w-full bg-[#bf4316] hover:bg-[#9e3412] text-white text-sm font-medium py-2 rounded transition-colors">
                    Jetzt buchen
                </button>
                <button type="button"
                        @click="open = false"
                        class="w-full border border-[#d1cbc0] text-[#6a6e73] text-sm py-2 rounded hover:bg-[#f9f8f6] transition-colors">
                    Abbrechen
                </button>
            </div>
        </form>

        <button type="button"
                @click="open = false"
                class="absolute top-3 right-4 text-[#9a9a9a] hover:text-[#151515] text-lg leading-none">✕</button>
    </div>
</div>
@endauth

{{-- ═══════════════════════════════════════════════════════
     EVENT MODAL — Veranstaltung anlegen (nur Admin)
     ═══════════════════════════════════════════════════════ --}}
@can('admin.event')
<div x-data="{
        open: false,
        sid: null,
        date: null,
        timeStart: null,
        timeEnd: null,
        timeStartFormatted: '',
        timeEndFormatted: '',
        squareName: '',
        dateLabel: '',
        timeLabel: '',
        openEvent(detail) {
            this.sid                = detail.sid;
            this.date               = detail.date;
            this.timeStart          = detail.timeStart;
            this.timeEnd            = detail.timeEnd;
            this.timeStartFormatted = detail.timeStartFormatted;
            this.timeEndFormatted   = detail.timeEndFormatted;
            this.squareName         = detail.squareName;
            this.dateLabel          = detail.dateLabel;
            this.timeLabel          = detail.timeLabel;
            this.open = true;
        }
     }"
     @open-event.window="openEvent($event.detail)"
     x-show="open"
     x-transition.opacity
     @keydown.escape.window="open = false"
     @click.self="open = false"
     class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4"
     style="display: none;">
    <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl border border-[#e0ddd7]">

        <div class="px-6 pt-5 pb-3 border-b border-[#f0ede6]">
            <h2 class="text-base font-semibold text-[#151515]"
                style="font-family: var(--font-display)">Veranstaltung anlegen</h2>
            <p class="text-sm text-[#6a6e73] mt-0.5"
               x-text="squareName + ' · ' + dateLabel + ' · ' + timeLabel"></p>
        </div>

        <form method="POST" action="{{ route('admin.events.store') }}">
            @csrf
            <input type="hidden" name="status"      value="enabled">
            <input type="hidden" name="redirect_to" value="{{ url('/') }}">

            <div class="px-6 py-4 flex flex-col gap-4">

                {{-- Veranstaltungsname --}}
                <div class="flex flex-col gap-1">
                    <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">Veranstaltungsname</label>
                    <input type="text"
                           name="name"
                           required
                           placeholder="z.B. Vereinsturnier"
                           class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
                </div>

                {{-- Datum/Zeit Start + Ende (2-Spalten-Grid) --}}
                <div class="grid grid-cols-2 gap-3">
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">Datum Start</label>
                        <input type="date"
                               name="date_start"
                               x-bind:value="date"
                               required
                               class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">Zeit Start</label>
                        <input type="time"
                               name="time_start"
                               x-bind:value="timeStartFormatted"
                               required
                               class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">Datum Ende</label>
                        <input type="date"
                               name="date_end"
                               x-bind:value="date"
                               required
                               class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">Zeit Ende</label>
                        <input type="time"
                               name="time_end"
                               x-bind:value="timeEndFormatted"
                               required
                               class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
                    </div>
                </div>

                {{-- Platz --}}
                <div class="flex flex-col gap-1">
                    <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">Platz</label>
                    <select name="sid"
                            x-bind:value="sid"
                            class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
                        @foreach($squares as $square)
                            <option value="{{ $square->sid }}">{{ $square->display_name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Beschreibung (optional) --}}
                <div class="flex flex-col gap-1">
                    <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">Beschreibung (optional)</label>
                    <textarea name="description"
                              rows="2"
                              class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent resize-none"></textarea>
                </div>

            </div>

            <div class="px-6 pb-5 flex flex-col gap-2">
                <button type="submit"
                        class="w-full bg-[#bf4316] hover:bg-[#9e3412] text-white text-sm font-medium py-2 rounded transition-colors">
                    Veranstaltung speichern
                </button>
                <button type="button"
                        @click="open = false"
                        class="w-full border border-[#d1cbc0] text-[#6a6e73] text-sm py-2 rounded hover:bg-[#f9f8f6] transition-colors">
                    Abbrechen
                </button>
            </div>
        </form>

        <button type="button"
                @click="open = false"
                class="absolute top-3 right-4 text-[#9a9a9a] hover:text-[#151515] text-lg leading-none">✕</button>
    </div>
</div>
@endcan

{{-- ═══════════════════════════════════════════════════════
     ADMIN IFRAME MODAL — bleibt für booking.js
     ═══════════════════════════════════════════════════════ --}}
@auth
<div id="admin-booking-modal" class="booking-modal booking-modal--iframe" style="display:none;">
    <div class="booking-modal__viewport booking-modal--iframe">
        <div class="booking-modal__card booking-modal__card--iframe">
            <button id="abm-close" type="button" class="booking-modal__close booking-modal__close--iframe">✕</button>
            <iframe id="abm-iframe" src="" class="booking-modal__iframe"></iframe>
        </div>
    </div>
</div>
@endauth
```

- [ ] **Schritt 3: Commit**

```bash
git add resources/views/components/calendar/modals.blade.php
git commit -m "feat(modals): Buchungs-Modals zu Alpine.js + Tailwind v4 migriert"
```

---

### Task 2: `grid.blade.php` — Trigger-Elemente auf Alpine Custom Events umstellen

**Files:**
- Modify: `resources/views/components/calendar/grid.blade.php`

Die Datei hat zwei relevante Trigger-Blöcke:
- **Zeilen 135–151:** Book-Trigger (`$action === 'book'` oder `'admin-book'`)
- **Zeilen 152–173:** Cancel-Trigger (`$action === 'cancel'`)

`data-action="book"` → Alpine dispatch (kein `booking-trigger`)  
`data-action="admin-book"` → besteht bleiben (`booking-trigger` für booking.js)  
`data-action="cancel"` ohne `$isAdmin` → Alpine dispatch (kein `booking-trigger`)  
`data-action="cancel"` mit `$isAdmin` → bleibt (`booking-trigger` für booking.js)

- [ ] **Schritt 1: Book/Admin-Book Block ersetzen (Zeilen 135–151)**

Suche den Block:
```blade
        @elseif($action === 'book' || $action === 'admin-book')
            <td @class(['cal-extra-day' => $extraDay]) data-day="{{ $dayIndex }}">
                <a href="#"
                   class="calendar-cell {{ $cellClass }}{{ $slotClass }} booking-trigger"
                   title="{{ $cellTitle }}"
                   data-action="{{ $action }}"
                   data-sid="{{ $sid }}"
                   data-date="{{ $dateKey }}"
                   data-time-start="{{ $h * 3600 }}"
                   data-time-end="{{ ($h + 1) * 3600 }}"
                   data-square-name="{{ $squareLabel }}"
                   data-date-label="{{ $dateLabels[$d->format('Y-m-d')]['full'] }}"
                   data-time-label="{{ $timeLabel }} – {{ $nextLabel }} Uhr"
                   @if($isAdmin)
                       data-create-url="{{ route('admin.bookings.create') }}?sid={{ $sid }}&date={{ $dateKey }}&time_start={{ $h * 3600 }}&time_end={{ ($h + 1) * 3600 }}"
                   @endif></a>
            </td>
```

Ersetze durch:
```blade
        @elseif($action === 'book' || $action === 'admin-book')
            <td @class(['cal-extra-day' => $extraDay]) data-day="{{ $dayIndex }}">
                @if($action === 'book')
                    <a href="#"
                       class="calendar-cell {{ $cellClass }}{{ $slotClass }}"
                       title="{{ $cellTitle }}"
                       @click.prevent="$dispatch('open-booking', {
                           sid: {{ $sid }},
                           date: @js($dateKey),
                           timeStart: {{ $h * 3600 }},
                           timeEnd: {{ ($h + 1) * 3600 }},
                           timeStartFormatted: @js(str_pad($h, 2, '0', STR_PAD_LEFT) . ':00'),
                           timeEndFormatted: @js(str_pad($h + 1, 2, '0', STR_PAD_LEFT) . ':00'),
                           squareName: @js($squareLabel),
                           dateLabel: @js($dateLabels[$d->format('Y-m-d')]['full']),
                           timeLabel: @js($timeLabel . ' – ' . $nextLabel . ' Uhr')
                       })"></a>
                @else
                    {{-- admin-book: bleibt für booking.js --}}
                    <a href="#"
                       class="calendar-cell {{ $cellClass }}{{ $slotClass }} booking-trigger"
                       title="{{ $cellTitle }}"
                       data-action="admin-book"
                       data-sid="{{ $sid }}"
                       data-date="{{ $dateKey }}"
                       data-time-start="{{ $h * 3600 }}"
                       data-time-end="{{ ($h + 1) * 3600 }}"
                       data-square-name="{{ $squareLabel }}"
                       data-date-label="{{ $dateLabels[$d->format('Y-m-d')]['full'] }}"
                       data-time-label="{{ $timeLabel }} – {{ $nextLabel }} Uhr"
                       data-create-url="{{ route('admin.bookings.create') }}?sid={{ $sid }}&date={{ $dateKey }}&time_start={{ $h * 3600 }}&time_end={{ ($h + 1) * 3600 }}"></a>
                @endif
            </td>
```

- [ ] **Schritt 2: Cancel-Block ersetzen (Zeilen 152–173)**

Suche den Block:
```blade
        @elseif($action === 'cancel')
            <td @class(['cal-extra-day' => $extraDay]) data-day="{{ $dayIndex }}">
                <a href="#"
                   class="calendar-cell {{ $cellClass }}{{ $slotClass }} booking-trigger"
                   title="{{ $cellTitle }}"
                   data-action="cancel"
                   data-bid="{{ $reservation->booking->bid }}"
                   data-square-name="{{ $squareLabel }}"
                   data-date-label="{{ $dateLabels[$d->format('Y-m-d')]['full'] }}"
                   data-time-label="{{ $timeLabel }} – {{ $nextLabel }} Uhr"
                   @if($isAdmin)
                       data-edit-url="{{ route('admin.bookings.edit', $reservation->booking) }}"
                       data-delete-url="{{ route('admin.bookings.destroy', $reservation->booking) }}"
                   @elseif($isOwn)
                       data-edit-url="{{ route('bookings.edit', $reservation->booking) }}?popup=1"
                   @endif>
                    <span class="cc-label-primary">{{ $primaryLabel }}</span>
                    @if($secondaryLabel)
                        <span class="cc-label-secondary">{{ $secondaryLabel }}</span>
                    @endif
                </a>
            </td>
```

Ersetze durch:
```blade
        @elseif($action === 'cancel')
            <td @class(['cal-extra-day' => $extraDay]) data-day="{{ $dayIndex }}">
                @if($isAdmin)
                    {{-- Admin: Iframe-Modal via booking.js --}}
                    <a href="#"
                       class="calendar-cell {{ $cellClass }}{{ $slotClass }} booking-trigger"
                       title="{{ $cellTitle }}"
                       data-action="cancel"
                       data-bid="{{ $reservation->booking->bid }}"
                       data-square-name="{{ $squareLabel }}"
                       data-date-label="{{ $dateLabels[$d->format('Y-m-d')]['full'] }}"
                       data-time-label="{{ $timeLabel }} – {{ $nextLabel }} Uhr"
                       data-edit-url="{{ route('admin.bookings.edit', $reservation->booking) }}"
                       data-delete-url="{{ route('admin.bookings.destroy', $reservation->booking) }}">
                        <span class="cc-label-primary">{{ $primaryLabel }}</span>
                        @if($secondaryLabel)
                            <span class="cc-label-secondary">{{ $secondaryLabel }}</span>
                        @endif
                    </a>
                @elseif($isOwn)
                    {{-- User: Alpine Cancel Modal --}}
                    <a href="#"
                       class="calendar-cell {{ $cellClass }}{{ $slotClass }}"
                       title="{{ $cellTitle }}"
                       @click.prevent="$dispatch('open-cancel', {
                           bid: @js((string) $reservation->booking->bid),
                           editUrl: @js(route('bookings.edit', $reservation->booking) . '?popup=1'),
                           squareName: @js($squareLabel),
                           dateLabel: @js($dateLabels[$d->format('Y-m-d')]['full']),
                           timeLabel: @js($timeLabel . ' – ' . $nextLabel . ' Uhr')
                       })">
                        <span class="cc-label-primary">{{ $primaryLabel }}</span>
                        @if($secondaryLabel)
                            <span class="cc-label-secondary">{{ $secondaryLabel }}</span>
                        @endif
                    </a>
                @else
                    {{-- Andere Buchung sichtbar aber nicht interaktiv --}}
                    <span class="calendar-cell {{ $cellClass }}{{ $slotClass }}" title="{{ $cellTitle }}">
                        <span class="cc-label-primary">{{ $primaryLabel }}</span>
                        @if($secondaryLabel)
                            <span class="cc-label-secondary">{{ $secondaryLabel }}</span>
                        @endif
                    </span>
                @endif
            </td>
```

- [ ] **Schritt 3: Commit**

```bash
git add resources/views/components/calendar/grid.blade.php
git commit -m "feat(calendar): Grid-Trigger auf Alpine Custom Events umgestellt"
```

---

### Task 3: `public/js/booking.js` auf Iframe-Modal reduzieren

**Files:**
- Replace: `public/js/booking.js`

- [ ] **Schritt 1: Aktuelle Datei lesen**

Lies `C:\development\bookingnew\public\js\booking.js` um den Ausgangszustand zu kennen (480 Zeilen).

- [ ] **Schritt 2: Datei komplett ersetzen**

Ersetze den gesamten Inhalt von `public/js/booking.js` durch folgenden reduzierten Code (nur Iframe-Modal + Admin-Event-Delegation):

```javascript
(function () {
    'use strict';

    // ─── Helpers ───────────────────────────────────────────────────────────────

    function showModal(modal) {
        if (modal) { modal.style.display = 'block'; }
    }

    function hideModal(modal) {
        if (modal) { modal.style.display = 'none'; }
    }

    function closeIframeModal() {
        var modal  = document.getElementById('admin-booking-modal');
        var iframe = document.getElementById('abm-iframe');
        if (iframe) { iframe.src = ''; }
        hideModal(modal);
    }

    // ─── Admin Iframe Modal ─────────────────────────────────────────────────────

    function openAdminBookingModal(element) {
        var modal  = document.getElementById('admin-booking-modal');
        var iframe = document.getElementById('abm-iframe');
        if (!modal || !iframe) { return; }

        var createUrl = element.dataset.createUrl;
        if (!createUrl) { return; }

        iframe.src = createUrl + (createUrl.includes('?') ? '&' : '?') + 'popup=1';
        showModal(modal);
    }

    function openAdminUrlInModal(url) {
        var modal  = document.getElementById('admin-booking-modal');
        var iframe = document.getElementById('abm-iframe');
        if (!modal || !iframe) { return; }

        iframe.src = url + (url.includes('?') ? '&' : '?') + 'popup=1';
        showModal(modal);
    }

    // Schließen via Close-Button
    var abmClose = document.getElementById('abm-close');
    if (abmClose) {
        abmClose.addEventListener('click', closeIframeModal);
    }

    // Schließen via Backdrop-Klick
    var abmModal = document.getElementById('admin-booking-modal');
    if (abmModal) {
        abmModal.addEventListener('click', function (e) {
            if (e.target === abmModal ||
                e.target.classList.contains('booking-modal__viewport')) {
                closeIframeModal();
            }
        });
    }

    // Escape-Key (nur Iframe-Modal — Alpine handelt die anderen)
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeIframeModal(); }
    });

    // Iframe-Navigation-Monitor: Seite neuladen wenn Form erfolgreich submitted
    var abmIframe = document.getElementById('abm-iframe');
    if (abmIframe) {
        abmIframe.addEventListener('load', function () {
            try {
                var loc = abmIframe.contentWindow.location.href;
                if (loc && loc !== 'about:blank' && !loc.includes('popup=1')) {
                    closeIframeModal();
                    window.location.reload();
                }
            } catch (_) {
                // Cross-origin: ignorieren
            }
        });
    }

    // ─── Event-Delegation für Admin-Trigger (.booking-trigger) ─────────────────

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('.booking-trigger');
        if (!trigger) { return; }

        e.preventDefault();

        var action    = trigger.dataset.action;
        var deleteUrl = trigger.dataset.deleteUrl;
        var editUrl   = trigger.dataset.editUrl;

        if (action === 'admin-book') {
            openAdminBookingModal(trigger);
        } else if (action === 'cancel' && deleteUrl) {
            // Admin-Cancel: Edit-Formular im Iframe
            if (editUrl) {
                openAdminUrlInModal(editUrl);
            }
        }
    });

})();
```

- [ ] **Schritt 3: Commit**

```bash
git add public/js/booking.js
git commit -m "refactor(booking): booking.js auf Iframe-Modal reduziert, Modals zu Alpine migriert"
```

---

### Task 4: Build aktualisieren und committen

**Files:** `public/build/`

- [ ] **Schritt 1: Build ausführen**

```bash
cd C:\development\bookingnew
npm run build
```

Erwartung: `✓ built in Xms` ohne Fehler. Die neue CSS enthält alle Tailwind-Klassen aus den Modal-Komponenten (`fixed`, `inset-0`, `z-50`, `bg-black/40`, `rounded-xl`, etc.).

- [ ] **Schritt 2: Build committen**

```bash
git add public/build/
git commit -m "chore: Vite-Build nach Buchungs-Modals Migration aktualisiert"
```

---

## Manuelle Verifikation

Nach Implementierung im Browser testen (`npm run dev` oder Produktions-Build):

1. **Booking-Modal:** Freie Zelle klicken → Modal öffnet mit Platznamen + Datum/Zeit. Quantity auf "Doppel" wechseln → Spieler 3 + 4 erscheinen. ESC-Taste und Backdrop-Klick schließen Modal.
2. **Cancel-Modal:** Eigene Buchung klicken → Cancel-Modal mit Bestätigung öffnet. DELETE-Form sendet korrekt.
3. **Event-Modal:** Als Admin: Booking-Modal öffnen → "Veranstaltung anlegen" klicken → Event-Modal öffnet mit vorausgefülltem Datum/Zeit.
4. **Feedback-Modal:** Nach erfolgreicher Buchung → Grüne Flash-Meldung erscheint und schließt automatisch nach 4s.
5. **Admin-Iframe-Modal:** Als Admin: Admin-Buchung-Slot klicken → Iframe-Modal öffnet mit Admin-Formular darin.
6. **Admin-Cancel:** Als Admin eigene/fremde Buchung klicken → Iframe mit Admin-Edit-Formular.
