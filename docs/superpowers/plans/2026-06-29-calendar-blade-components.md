# Calendar Blade Components Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `calendar/index.blade.php` (492 Zeilen) in zwei anonyme Blade-Komponenten aufteilen — `modals.blade.php` und `grid.blade.php` — ohne Funktionalität zu verändern.

**Architecture:** Anonyme Blade-Komponenten (kein PHP-Class, nur Template). Props per `@props([])`. Die `index.blade.php` wird zum reinen Gerüst (~30 Zeilen). Die Zell-Loop-Logik mit `continue` bleibt zwingend inline in `grid.blade.php`.

**Tech Stack:** Laravel Blade, anonyme Komponenten (`resources/views/components/`)

---

## File Structure

| Aktion | Datei |
|---|---|
| Erstellen | `resources/views/components/calendar/modals.blade.php` |
| Erstellen | `resources/views/components/calendar/grid.blade.php` |
| Modifizieren | `resources/views/calendar/index.blade.php` |

---

### Task 1: `modals.blade.php` erstellen

**Files:**
- Create: `resources/views/components/calendar/modals.blade.php`
- Modify: `resources/views/calendar/index.blade.php` (Modals entfernen, Komponente einbinden)

- [ ] **Schritt 1: Komponenten-Verzeichnis anlegen**

```powershell
New-Item -ItemType Directory -Force "resources\views\components\calendar"
```

- [ ] **Schritt 2: `modals.blade.php` erstellen**

Datei `resources/views/components/calendar/modals.blade.php` anlegen mit exakt diesem Inhalt (die 4 Modals aus `index.blade.php` Zeilen 284–444 kopieren):

```blade
@props([
    'date',
    'squares',
])

<div id="cancel-modal" class="booking-modal" style="display:none;">
    <div class="booking-modal__viewport">
        <div class="booking-modal__card">
            <button id="cancel-modal-close" class="booking-modal__close" title="{{ __('booking.feedback.close') }}">&#x2715;</button>
            <div class="booking-modal__header">
                <h2 id="cancel-modal-title"></h2>
            </div>
            <div class="booking-modal__body">
                <div id="cancel-modal-date" class="booking-modal__meta"></div>
                <div id="cancel-modal-time" class="booking-modal__meta"></div>
                <p class="booking-modal__warning">{{ __('booking.modal.confirm_cancel') }}</p>
            </div>
            <div class="booking-modal__actions booking-modal__actions--manage">
                <a id="cancel-modal-edit" href="#" class="default-button" hidden>{{ __('booking.modal.edit') }}</a>
                <form id="cancel-form" method="POST" action="" class="booking-modal__action-form">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="modal-danger-button">{{ __('booking.modal.cancel_booking') }}</button>
                </form>
                @can('admin.booking')
                <form id="delete-form" method="POST" action="" class="booking-modal__action-form" onsubmit="return confirm('{{ __('booking.admin.bookings.confirm_delete') }}')" hidden>
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="redirect_to" value="{{ route('calendar.index', ['date' => $date->format('Y-m-d')]) }}">
                    <button type="submit" class="modal-danger-button modal-danger-button--delete">{{ __('booking.admin.bookings.delete_permanent') }}</button>
                </form>
                @endcan
                <button type="button" id="cancel-modal-abort" class="default-button">{{ __('booking.modal.cancel') }}</button>
            </div>
        </div>
    </div>
</div>

<div id="booking-modal" class="booking-modal" style="display:none;">
    <div class="booking-modal__viewport">
        <div class="booking-modal__card">
            <button id="modal-close" class="booking-modal__close" title="{{ __('booking.feedback.close') }}">&#x2715;</button>
            <div class="booking-modal__header">
                <h2 id="modal-title"></h2>
            </div>
            <div class="booking-modal__body">
                <div id="modal-date" class="booking-modal__meta"></div>
                <div id="modal-time" class="booking-modal__meta"></div>
                <p class="booking-modal__success">{{ __('booking.modal.slot_free') }}</p>
            </div>
            <form method="POST" action="{{ route('bookings.store') }}" class="booking-modal__actions booking-modal__actions--stacked">
                @csrf
                <input type="hidden" id="modal-sid" name="sid">
                <input type="hidden" id="modal-date-input" name="date">
                <input type="hidden" id="modal-ts" name="time_start">
                <input type="hidden" id="modal-te" name="time_end">

                <label class="booking-modal__field">
                    <span class="booking-modal__field-label">{{ __('booking.modal.play_type') }}</span>
                    <select id="modal-quantity" name="quantity" class="booking-modal__select">
                        <option value="2">{{ __('booking.modal.single') }}</option>
                        <option value="4">{{ __('booking.modal.double') }}</option>
                    </select>
                </label>

                <label class="booking-modal__field booking-modal__field--player" id="modal-player2-field" hidden>
                    <span class="booking-modal__field-label">{{ __('booking.modal.player_name_2') }}</span>
                    <input type="text" id="modal-player2" name="player_name_2" class="booking-modal__input" list="player-suggestions" maxlength="120" placeholder="{{ __('booking.modal.player_name_2_placeholder') }}" required>
                </label>

                <label class="booking-modal__field booking-modal__field--player" id="modal-player3-field" hidden>
                    <span class="booking-modal__field-label">{{ __('booking.modal.player_name_3') }}</span>
                    <input type="text" id="modal-player3" name="player_name_3" class="booking-modal__input" list="player-suggestions" maxlength="120" placeholder="{{ __('booking.modal.player_name_3_placeholder') }}">
                </label>

                <label class="booking-modal__field booking-modal__field--player" id="modal-player4-field" hidden>
                    <span class="booking-modal__field-label">{{ __('booking.modal.player_name_4') }}</span>
                    <input type="text" id="modal-player4" name="player_name_4" class="booking-modal__input" list="player-suggestions" maxlength="120" placeholder="{{ __('booking.modal.player_name_4_placeholder') }}">
                </label>

                <datalist id="player-suggestions"></datalist>

                @can('admin.event')
                    <button type="button" id="modal-create-event" class="default-button">{{ __('booking.modal.create_event') }}</button>
                @endcan
                <button type="submit" class="modal-primary-button">{{ __('booking.modal.book_now') }}</button>
                <button type="button" id="modal-cancel" class="default-button">{{ __('booking.modal.cancel') }}</button>
            </form>
        </div>
    </div>
</div>

@auth
<div id="admin-booking-modal" class="booking-modal booking-modal--iframe" style="display:none;">
    <div class="booking-modal__viewport booking-modal__viewport--iframe">
        <div class="booking-modal__card booking-modal__card--iframe">
            <button id="abm-close" class="booking-modal__close booking-modal__close--iframe" title="{{ __('booking.feedback.close') }}">&#x2715;</button>
            <iframe id="abm-iframe" src="" class="booking-modal__iframe" allowfullscreen></iframe>
        </div>
    </div>
</div>
@endauth

@can('admin.event')
<div id="event-modal" class="booking-modal" style="display:none;">
    <div class="booking-modal__viewport">
        <div class="booking-modal__card booking-modal__card--event">
            <button id="event-modal-close" class="booking-modal__close" title="{{ __('booking.feedback.close') }}">&#x2715;</button>
            <div class="booking-modal__header">
                <h2>{{ __('booking.modal.create_event') }}</h2>
            </div>
            <form id="event-form" method="POST" action="{{ route('admin.events.store') }}" class="booking-modal__body booking-modal__body--event">
                @csrf
                <input type="hidden" name="status" value="enabled">
                <input type="hidden" name="redirect_to" value="{{ route('calendar.index', ['date' => $date->format('Y-m-d')]) }}">
                <input type="hidden" name="datetime_start" id="event-datetime-start">
                <input type="hidden" name="datetime_end" id="event-datetime-end">

                <label class="booking-modal__field">
                    <span class="booking-modal__field-label">{{ __('booking.modal.event_name') }}</span>
                    <input type="text" name="name" id="event-name" class="booking-modal__input" maxlength="128" required>
                </label>

                <div class="booking-modal__event-grid">
                    <label class="booking-modal__field">
                        <span class="booking-modal__field-label">{{ __('booking.modal.date_start') }}</span>
                        <input type="date" id="event-date-start" class="booking-modal__input" required>
                    </label>
                    <label class="booking-modal__field">
                        <span class="booking-modal__field-label">{{ __('booking.modal.time_start') }}</span>
                        <input type="time" id="event-time-start" class="booking-modal__input" required>
                    </label>
                    <label class="booking-modal__field">
                        <span class="booking-modal__field-label">{{ __('booking.modal.date_end') }}</span>
                        <input type="date" id="event-date-end" class="booking-modal__input" required>
                    </label>
                    <label class="booking-modal__field">
                        <span class="booking-modal__field-label">{{ __('booking.modal.time_end') }}</span>
                        <input type="time" id="event-time-end" class="booking-modal__input" required>
                    </label>
                </div>

                <label class="booking-modal__field">
                    <span class="booking-modal__field-label">{{ __('booking.modal.event_description') }}</span>
                    <textarea name="description" id="event-description" class="booking-modal__input booking-modal__textarea" rows="3" maxlength="4096"></textarea>
                </label>

                <label class="booking-modal__field">
                    <span class="booking-modal__field-label">{{ __('booking.calendar.court') }}</span>
                    <select name="sid" id="event-sid" class="booking-modal__select">
                        <option value="">{{ __('booking.admin.events.all_courts') }}</option>
                        @foreach($squares as $square)
                            <option value="{{ $square->sid }}">{{ $square->display_name }}</option>
                        @endforeach
                    </select>
                </label>

                <div class="booking-modal__actions booking-modal__actions--stacked booking-modal__actions--event">
                    <button type="submit" class="modal-primary-button">{{ __('booking.modal.save_event') }}</button>
                    <button type="button" id="event-modal-cancel" class="default-button">{{ __('booking.modal.cancel') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endcan
```

- [ ] **Schritt 3: Modals in `index.blade.php` ersetzen**

In `resources/views/calendar/index.blade.php` die Zeilen 284–444 (alle 4 Modal-Divs) ersetzen durch:

```blade
<x-calendar.modals :date="$date" :squares="$squares" />
```

- [ ] **Schritt 4: Browser-Test Modals**

App starten und Kalender aufrufen. Prüfen:
- Als Gast: Slot klicken → Login-Redirect (kein Modal)
- Als eingeloggter User: freien Slot klicken → `#booking-modal` öffnet sich
- Eigene Buchung klicken → `#cancel-modal` öffnet sich
- Als Admin: `#admin-booking-modal` und `#event-modal` prüfen

- [ ] **Schritt 5: Commit**

```bash
git add resources/views/components/calendar/modals.blade.php resources/views/calendar/index.blade.php
git commit -m "refactor(calendar): modals in eigene Blade-Komponente ausgelagert"
```

---

### Task 2: `grid.blade.php` erstellen

**Files:**
- Create: `resources/views/components/calendar/grid.blade.php`
- Modify: `resources/views/calendar/index.blade.php` (Grid-Block entfernen, Komponente einbinden)

- [ ] **Schritt 1: `grid.blade.php` erstellen**

Datei `resources/views/components/calendar/grid.blade.php` anlegen mit diesem Inhalt (Zeilen 72–281 + Zeilen 447–491 aus `index.blade.php`):

```blade
@props([
    'dates',
    'squares',
    'dateLabels',
    'reservationsBySlot',
    'eventBlocks',
    'eventSkip',
    'today',
    'now',
    'isLoggedIn',
    'isAdmin',
    'authUserId',
    'canAdminEvents',
    'date',
])

<table class="calendar-square-table booking-grid" id="calendar-grid" data-squares="{{ $squares->count() }}" data-max-days="{{ count($dates) }}">
    <colgroup>
        <col class="calendar-time-col">
        @foreach($dates as $dayIndex => $d)
            @foreach($squares as $square)
                <col @class(['calendar-slot-col', 'cal-extra-day' => $dayIndex >= 3]) data-day="{{ $dayIndex }}">
            @endforeach
        @endforeach
    </colgroup>
    <thead>
        <tr class="calendar-date-row">
            <td class="time-spacer">&nbsp;</td>
            @foreach($dates as $dayIndex => $d)
                <td colspan="{{ $squares->count() }}" @class(['day-header-cell', 'cal-extra-day' => $dayIndex >= 3]) data-day="{{ $dayIndex }}">
                    <div class="day-header-inline"><span class="day-header-name">{{ $dateLabels[$d->format('Y-m-d')]['short'] }}</span><span class="day-header-date">{{ $dateLabels[$d->format('Y-m-d')]['long'] }}</span></div>
                </td>
            @endforeach
        </tr>
        <tr class="calendar-square-row">
            <td class="time-side-head">{{ __('booking.calendar.court') }}</td>
            @foreach($dates as $dayIndex => $d)
                @foreach($squares as $square)
                    @php
                        $squareAlias = $square->display_name !== $square->name ? $square->display_name : null;
                    @endphp
                    <td @class(['square-head-cell', 'cal-extra-day' => $dayIndex >= 3]) data-day="{{ $dayIndex }}">
                        <span class="square-head-title">{{ $square->name }}</span>
                        @if($squareAlias)
                            <span class="square-head-alias">{{ $squareAlias }}</span>
                        @endif
                    </td>
                @endforeach
            @endforeach
        </tr>
    </thead>
    <tbody>
        @for($h = 8; $h <= 19; $h++)
            @php
                $timeLabel = str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':00';
                $nextLabel = str_pad((string) ($h + 1), 2, '0', STR_PAD_LEFT) . ':00';
            @endphp
            <tr class="calendar-core-row">
                <td class="time-cell">
                    <span class="time-main">{{ $timeLabel }}</span>
                    <span class="time-sub">{{ __('booking.calendar.to_time', ['time' => $nextLabel]) }}</span>
                </td>

                @foreach($dates as $dayIndex => $d)
                    @php
                        $dateKey = $d->format('Y-m-d');
                        $isPastSlot = $dateKey < $today || ($dateKey === $today && ($h + 1) <= $now->hour);
                        $extraDay = $dayIndex >= 3;
                    @endphp
                    @foreach($squares as $square)
                        @php
                            $sid = $square->sid;
                            $squareLabel = $square->display_name;

                            if (!empty($eventSkip[$dateKey][$sid][$h])) { continue; }

                            $evBlock = $eventBlocks[$dateKey][$sid][$h] ?? null;
                            $reservation = $reservationsBySlot[$dateKey][$sid][$h] ?? null;

                            $isOwn = $reservation
                                && $isLoggedIn
                                && isset($reservation->booking->user)
                                && $reservation->booking->user->uid === $authUserId;

                            $canManageBooking = $reservation && $isAdmin;

                            $secondaryLabel = $isLoggedIn ? $reservation?->booking?->player_names_label : null;

                            $cellClass = 'cc-over';
                            $slotClass = $isPastSlot ? ' slot-cell--past' : '';
                            $primaryLabel = '';
                            $action = null;
                            $cellTitle = $squareLabel . ' – ' . __('booking.calendar.past');

                            if (!$evBlock) {
                                if ($isPastSlot && !$reservation) {
                                    $cellClass = 'cc-over';
                                    $cellTitle = $squareLabel . ' – ' . __('booking.calendar.past');
                                } elseif (!$reservation) {
                                    $cellClass = 'cc-free';
                                    $action = $isLoggedIn && !$isPastSlot ? ($isAdmin ? 'admin-book' : 'book') : 'login';
                                    $cellTitle = $isLoggedIn
                                        ? __('booking.calendar.book_title', ['court' => $squareLabel, 'time' => $timeLabel])
                                        : __('booking.calendar.login_to_book');
                                } elseif ($isOwn || ($canManageBooking && !$isPastSlot)) {
                                    $cellClass = $isOwn ? 'cc-own' : 'cc-single-future';
                                    $action = 'cancel';
                                    $primaryLabel = $reservation->booking->owner_label;
                                    $cellTitle = $isOwn
                                        ? __('booking.calendar.cancel_title', ['court' => $squareLabel])
                                        : __('booking.calendar.edit_title', ['court' => $squareLabel]);
                                } else {
                                    $cellClass = 'cc-single-future';
                                    $primaryLabel = $isLoggedIn
                                        ? ($reservation->booking?->owner_label ?? __('booking.calendar.occupied'))
                                        : '';
                                    $cellTitle = $isPastSlot
                                        ? ($squareLabel . ' – ' . __('booking.calendar.past'))
                                        : ($squareLabel . ' – ' . __('booking.calendar.occupied'));
                                }
                            }
                        @endphp

                        @if($evBlock)
                            @php
                                $eventEditUrl = $canAdminEvents ? route('admin.events.edit', $evBlock['event']) : null;
                            @endphp
                            <td rowspan="{{ $evBlock['rows'] }}" colspan="{{ $evBlock['cols'] }}" @class(['event-cell', 'event-cell--editable' => $canAdminEvents, 'cal-extra-day' => $extraDay]) data-day="{{ $dayIndex }}" title="{{ $evBlock['name'] }}">
                                @if($eventEditUrl)
                                    <a href="{{ $eventEditUrl }}" class="event-label event-edit-trigger" data-edit-url="{{ $eventEditUrl }}">{{ $evBlock['name'] }}</a>
                                @else
                                    <span class="event-label">{{ $evBlock['name'] }}</span>
                                @endif
                            </td>
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
                        @elseif($action === 'login')
                            <td @class(['cal-extra-day' => $extraDay]) data-day="{{ $dayIndex }}">
                                <a href="{{ route('login', ['redirect_to' => route('calendar.index', ['date' => $date->format('Y-m-d')])]) }}"
                                   class="calendar-cell {{ $cellClass }}{{ $slotClass }} guest-login-cell"
                                   title="{{ $cellTitle }}"></a>
                            </td>
                        @else
                            <td @class(['cal-extra-day' => $extraDay]) data-day="{{ $dayIndex }}">
                                <span class="calendar-cell {{ $cellClass }}{{ $slotClass }}" title="{{ $cellTitle }}">
                                    @if($primaryLabel)
                                        <span class="cc-label-primary">{{ $primaryLabel }}</span>
                                    @endif
                                    @if($secondaryLabel)
                                        <span class="cc-label-secondary">{{ $secondaryLabel }}</span>
                                    @endif
                                </span>
                            </td>
                        @endif
                    @endforeach
                @endforeach
            </tr>
        @endfor
    </tbody>
    <tfoot>
        <tr class="calendar-square-row">
            <td class="time-side-head">{{ __('booking.calendar.court') }}</td>
            @foreach($dates as $dayIndex => $d)
                @foreach($squares as $square)
                    @php
                        $squareAlias = $square->display_name !== $square->name ? $square->display_name : null;
                    @endphp
                    <td @class(['square-head-cell', 'cal-extra-day' => $dayIndex >= 3]) data-day="{{ $dayIndex }}">
                        <span class="square-head-title">{{ $square->name }}</span>
                        @if($squareAlias)
                            <span class="square-head-alias">{{ $squareAlias }}</span>
                        @endif
                    </td>
                @endforeach
            @endforeach
        </tr>
        <tr class="calendar-date-row footer-date-row">
            <td class="time-spacer">&nbsp;</td>
            @foreach($dates as $dayIndex => $d)
                <td colspan="{{ $squares->count() }}" @class(['day-header-cell', 'cal-extra-day' => $dayIndex >= 3]) data-day="{{ $dayIndex }}">
                    <div class="day-header-inline"><span class="day-header-name">{{ $dateLabels[$d->format('Y-m-d')]['short'] }}</span><span class="day-header-date">{{ $dateLabels[$d->format('Y-m-d')]['long'] }}</span></div>
                </td>
            @endforeach
        </tr>
    </tfoot>
</table>

@push('scripts')
<script>
(function () {
    var grid = document.getElementById('calendar-grid');
    if (!grid) { return; }

    var wrap = grid.closest('.calendar-wrap') || grid.parentElement;
    var squares = parseInt(grid.dataset.squares, 10) || 1;
    var maxDays = parseInt(grid.dataset.maxDays, 10) || 3;
    var BASE_DAYS = 3;
    var extras = grid.querySelectorAll('.cal-extra-day');

    function cssPx(name, fallback) {
        var value = parseFloat(getComputedStyle(grid).getPropertyValue(name));
        return isNaN(value) ? fallback : value;
    }

    function visibleDayCount() {
        var dayWidth = squares * cssPx('--calendar-slot-col', 136);
        if (dayWidth <= 0) { return BASE_DAYS; }

        var fits = Math.floor((wrap.clientWidth - cssPx('--calendar-time-col', 94)) / dayWidth);
        return Math.max(BASE_DAYS, Math.min(maxDays, fits));
    }

    function applyVisibleDays() {
        var count = visibleDayCount();
        extras.forEach(function (el) {
            el.classList.toggle('is-visible', parseInt(el.getAttribute('data-day'), 10) < count);
        });
    }

    var timer;
    window.addEventListener('resize', function () {
        clearTimeout(timer);
        timer = setTimeout(applyVisibleDays, 100);
    });

    applyVisibleDays();
})();
</script>
@endpush
```

- [ ] **Schritt 2: Grid + JS in `index.blade.php` ersetzen**

In `resources/views/calendar/index.blade.php` den gesamten `@section('content')` Block ersetzen:

```blade
@section('content')
<div class="calendar-layout">
    <div class="calendar-wrap">
        <x-calendar.grid
            :dates="$dates"
            :squares="$squares"
            :date-labels="$dateLabels"
            :reservations-by-slot="$reservationsBySlot"
            :event-blocks="$eventBlocks"
            :event-skip="$eventSkip"
            :today="$today"
            :now="$now"
            :is-logged-in="$isLoggedIn"
            :is-admin="$isAdmin"
            :auth-user-id="$authUserId"
            :can-admin-events="$canAdminEvents"
            :date="$date"
        />
    </div>
</div>

<x-calendar.modals :date="$date" :squares="$squares" />
@endsection
```

Den alten `@push('scripts')` Block (Zeilen 447–491) ebenfalls aus `index.blade.php` entfernen — er ist jetzt in `grid.blade.php`.

- [ ] **Schritt 3: Browser-Test Grid**

Kalender aufrufen und prüfen:
- Grid wird korrekt dargestellt (Zeitzeilen 08:00–19:00, alle Plätze)
- Vergangene Slots: grau (`cc-over`)
- Freie Slots: klickbar, Modal öffnet sich
- Eigene Buchungen: farbig (`cc-own`), Cancel-Modal öffnet sich
- Fremde Buchungen: `cc-single-future`, kein Modal
- Events: `event-cell` mit korrektem Rowspan/Colspan
- Responsive: Fenster schmaler ziehen → Extra-Tage verschwinden

- [ ] **Schritt 4: Commit**

```bash
git add resources/views/components/calendar/grid.blade.php resources/views/calendar/index.blade.php
git commit -m "refactor(calendar): grid in eigene Blade-Komponente ausgelagert"
```

---

### Task 3: Finale Überprüfung

- [ ] **Schritt 1: Zeilenzahl prüfen**

```powershell
(Get-Content "resources\views\calendar\index.blade.php").Count
```

Erwartung: unter 50 Zeilen.

- [ ] **Schritt 2: Blade-Cache leeren**

```bash
php artisan view:clear
```

- [ ] **Schritt 3: Vollständiger Regressionstest**

Alle Slot-Typen im Browser durchklicken:
1. Gast → freier Slot → Login-Redirect ✓
2. User → freier Slot → Booking-Modal ✓
3. User → eigene Buchung → Cancel-Modal ✓
4. User → fremde Buchung → kein Klick-Handler ✓
5. Admin → freier Slot → Admin-Booking-Modal (Iframe) ✓
6. Admin → Event-Zelle → Edit-Link ✓
7. Responsiv: Browserfenster auf ~800px → Extra-Tage ausgeblendet ✓

- [ ] **Schritt 4: Finaler Commit**

```bash
git add .
git commit -m "refactor(calendar): Extraktion in Blade-Komponenten abgeschlossen"
```
