# Calendar — responsive day count

**Date:** 2026-06-28
**Status:** Approved (design)

## Goal

The booking calendar shows a fixed 3 days (yesterday / today / tomorrow). On wide
screens there is unused space to the right. Show **as many days as fit**, reflowing
**live on window resize**, without a page reload or extra server round-trips.

## Approach: render generous, reveal what fits

The server always renders a fixed maximum of days; client JS reveals as many as the
viewport can fit and hides the rest. No network traffic on resize.

### Anchor
`yesterday | today | tomorrow` are the base 3 and always visible. Extra width adds
**future** days on the right (`today+2`, `today+3`, …). Below 3-day width (phones)
the grid scrolls horizontally, as today. Sub-3 mobile reduction is out of scope.

### Components

1. **Controller** — `App\Http\Controllers\CalendarController::index()`
   - Replace the 3 hardcoded `$dates` with a fixed `MAX_DAYS = 8` window:
     `yesterday, today, today+1 … today+6` (8 entries; day indices 0–7).
   - `rangeStart` = first date `startOfDay`, `rangeEnd` = last date `endOfDay`
     (replace the hardcoded `$dates[2]`).
   - Everything downstream already iterates `$dates`, so it generalises unchanged.
   - Expose `MAX_DAYS` as a class constant so it is easy to adjust.

2. **View** — `resources/views/calendar/index.blade.php`
   - Iterate with the day index: `@foreach($dates as $dayIndex => $d)`.
   - Tag every per-day element with `data-day="{{ $dayIndex }}"`:
     the `<col>` slot columns, the date-header cells, the square-header cells,
     and the slot `<td>`s.
   - Day indices `>= 3` additionally get the class `cal-extra-day`.

3. **CSS** — `public/css/booking.css`
   - `.cal-extra-day { display: none; }` → no-JS / default render = today's 3 days
     (current behaviour preserved exactly).
   - `.cal-extra-day.is-visible { display: revert; }` → revealed columns fall back to
     their natural `table-column` / `table-cell` display.

4. **JS** — inline in the calendar view (`@push('scripts')` or a `<script>` block)
   - Read computed `--calendar-time-col` and `--calendar-slot-col` from the table,
     and the square count (`$squares->count()`, emitted as a data attribute).
   - `N = clamp(floor((wrapWidth − timeCol) / (squares × slotCol)), 3, MAX_DAYS)`.
   - Toggle `.is-visible` on every `cal-extra-day` element whose `data-day < N`.
   - Run on `DOMContentLoaded` and on `window` `resize` (debounced ~100 ms).

## Data flow

```
controller builds MAX_DAYS dates → view renders all, days >=3 hidden via CSS
  → on load + resize: JS measures wrap width → N days fit
  → reveal day-blocks 3..N-1 (.is-visible), hide the rest
```

## Error handling / edge cases
- No JS → CSS leaves exactly the base 3 days visible (graceful degradation).
- Narrower than 3 days → `.calendar-wrap` scrolls horizontally (unchanged).
- Width fits more than `MAX_DAYS` → capped at `MAX_DAYS` (minor right-side slack).

## Out of scope
- Reducing below 3 days on small phones.
- A user-selectable day count / persistence.
- AJAX-loading days beyond `MAX_DAYS`.

## Testing — `tests/Feature/CalendarControllerTest.php`
- Controller renders `MAX_DAYS` days: the range spans `yesterday … today+6`;
  a reservation on `today+6` appears in the passed data, one on `today+7` does not.
- Extra days (index >= 3) render with the `cal-extra-day` class (hidden by default).
- Existing assertions (squares ordering, reservations keyed by sid, cancelled hidden,
  per-date filtering) continue to pass; update any that assumed exactly 3 days.
