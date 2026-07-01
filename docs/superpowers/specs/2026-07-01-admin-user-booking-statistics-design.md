# Admin: User Booking Statistics — Design

## Goal

Give admins a new page listing, per member, how many bookings they've made
and how those break down — so the club can spot heavy users, inactive
members, and usage patterns without querying the database directly.

## Scope (v1)

A single admin page, `admin/statistics`, with:

1. **Per-user table** (one row per active user), columns:
   - Name (alias)
   - Bookings total (active, i.e. `status` in `single`/`subscription`, not `cancelled`)
   - Einzel count (`quantity` = 2)
   - Doppel count (`quantity` = 4)
   - Bookings last calendar month (bookings whose reservation date falls in
     the previous full calendar month, e.g. if today is in July, this shows June)
   - Most-booked court (by booking count, same unit as the other columns;
     ties broken by court `sid` ascending). For a `subscription` booking
     this counts once (the booking), not once per occurrence — that keeps
     it consistent with the "Bookings total"/Einzel/Doppel columns, which
     are also booking counts, not reservation counts.
   - Cancellation rate (`cancelled` bookings / all bookings for that user, as a percentage)
   - Sortable by clicking any column header (client-side, since the dataset
     is currently ~100 rows — no pagination needed)

2. **Club-wide summary** above the table: total active bookings, total
   Einzel, total Doppel, total bookings last month, club-wide cancellation
   rate. Same numbers as the per-user table, just summed/aggregated.

Explicitly out of scope for v1 (can be follow-ups): subscription-vs-single
breakdown, month-over-month trend chart, teammate-frequency stats. These
were discussed and deferred.

## Data model notes

- `bs_bookings` (`Booking` model): `uid`, `sid`, `status` (`single` |
  `subscription` | `cancelled`), `quantity` (2 or 4).
- `bs_reservations` (`Reservation` model, via `Booking::reservations()`):
  has the `date` a booking occupies a court on — this is what "last month"
  should be computed against (a `subscription` booking has one reservation
  row per occurrence, so counting reservations rather than bookings gives
  the right usage picture for recurring bookings). `Reservation` has no
  `sid` of its own — court is always read via `reservation->booking->sid`
  (or, in practice, via the parent `Booking`'s eager-loaded `square`).
- Users: `User::whereIn('status', ['enabled', 'assist', 'admin'])` matches
  the existing "active members" definition used in `DashboardController`.

## Query approach

Reuse the same style as `Admin\DashboardController` (Eloquent, no raw SQL):

- Load active users.
- Load all non-cancelled bookings with their `reservations` and `square`
  eager-loaded, grouped by `uid` in PHP (dataset is small enough — under
  ~100 users, a few hundred bookings — that pushing grouping/aggregation
  into PHP collections is simpler and safe here, matching the existing
  dashboard's approach rather than introducing raw SQL aggregation).
- Cancellation rate needs cancelled bookings counted too, so a second
  query (or a single query over ALL bookings regardless of status,
  splitting active vs. cancelled in PHP) is needed.
- "Last month" = `Carbon::now()->subMonthNoOverflow()->startOfMonth()` to
  `->endOfMonth()`, filtered against `reservations.date`.

## UI

- New Blade view `resources/views/admin/statistics/index.blade.php`,
  following the existing admin page look (white rounded card, `ui-table`
  classes used elsewhere in e.g. `admin/users/index.blade.php` — reuse
  that table styling for consistency).
- Client-side sort: plain JS (no new dependency), same pattern as any
  existing sortable table in the codebase if one exists, otherwise a
  small inline `<script>` that reads `data-*` attributes per cell and
  re-orders `<tr>`s.
- New sidebar entry in `admin-sidebar.blade.php`, placed after "Buchungen",
  gated behind the same `admin.booking` permission (statistics are a view
  of booking data, so reuse that permission rather than adding a new one).

## Routing / Controller

- `Route::get('/admin/statistics', [StatisticsController::class, 'index'])->name('admin.statistics.index')`
  inside the existing `admin` + `can:admin.booking` middleware group.
- `App\Http\Controllers\Admin\StatisticsController::index()` — single
  action, no create/edit/delete (read-only page).

## Testing

- Feature test: seed a couple of users with mixed single/double/cancelled
  bookings across two months, hit the route as an admin, assert the
  expected totals appear in the response.
- No JS test for the client-side sort (out of scope, matches project's
  existing lack of JS test coverage elsewhere).
