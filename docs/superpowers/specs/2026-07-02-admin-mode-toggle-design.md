# Admin mode toggle for booking forms

## Problem

Admins (`can('admin.booking')`) always see the admin booking forms in the calendar — creating, editing, or cancelling any booking — with no way to temporarily see the calendar the way a regular member does. There is no "act as normal member" mode for the booking forms specifically.

## Design

### 1. Persistence: `admin_mode` cookie

A cookie named `admin_mode`, values `'1'` (on) or `'0'`/absent (off), 1-year lifetime — the same pattern as the existing `locale` cookie (see `SetLocale` middleware / `LocaleController`). No cookie present means **off** (the new default): an admin who has never toggled it sees the normal member forms.

`bootstrap/app.php`'s existing `$middleware->encryptCookies(except: ['locale'])` gets `'admin_mode'` added so the cookie is readable as plain text, consistent with how `locale` is handled.

### 2. Single decision point: `CalendarController::index()`

The existing line at `app/Http/Controllers/CalendarController.php:94`:

```php
$isAdmin = $isLoggedIn && $authUser->can('admin.booking');
```

becomes:

```php
$isAdmin = $isLoggedIn && $authUser->can('admin.booking') && $request->cookie('admin_mode') === '1';
```

`$isAdmin` is already the sole flag `grid.blade.php` uses to pick between admin booking forms (`admin-book` action, `admin.bookings.*` routes) and normal member forms (`book` action, own-booking cancel dispatch) for every calendar cell. No other file needs to branch on admin-mode — this one boolean change propagates through the existing rendering logic:
- Create: admin-mode on → `admin.bookings.create` popup; off → normal `open-booking` dispatch (member's own single-booking form).
- Cancel/edit on an occupied slot: admin-mode on → full admin edit/cancel for any booking; off → if the viewer owns the booking, the existing member cancel dialog (`open-cancel`); otherwise the booking renders as a plain read-only label (no click), exactly like a non-admin member viewing someone else's booking today.

`can('admin.booking')` is still evaluated first — a non-admin can never flip this to `true` no matter what cookie value they send. Toggling admin mode off is a UI/UX choice for admins to preview the member experience; it doesn't touch the underlying `can()` authorization checks in `Admin\BookingController` or `BookingController`, both of which remain independently enforced.

Nothing changes on `/admin/bookings` (the admin booking management list/search page) — that page's own `can:admin.booking` route middleware is unaffected by this cookie.

### 3. Toggle route

```
GET /admin-mode/{state}
```

- `state` restricted to `on`/`off` via route constraint (`whereIn`); anything else 404s.
- `App\Http\Controllers\AdminModeController::set(Request $request, string $state)`.
- Route middleware: `auth`, `can:admin.booking` — only users who actually hold the permission can toggle it (a 403 for anyone else, matching the existing `Route::middleware('can:admin.booking')` groups already in `routes/web.php`).
- Queues an `admin_mode` cookie (`'1'` for `on`, `'0'` for `off`), 1-year lifetime.
- Redirects `back()`.
- Route name: `admin-mode.set`.

### 4. Header UI

In `resources/views/components/layout/header.blade.php`, inside the `app-header__actions` block, add one button alongside the existing pill-style action buttons (Info/Hinweise/Meine Buchungen/...), visible only `@can('admin.booking')`:

- Label: `__('booking.nav.admin_mode_on')` ("Admin-Modus einschalten") when currently off, `__('booking.nav.admin_mode_off')` ("Admin-Modus ausschalten") when currently on — read via `request()->cookie('admin_mode') === '1'`.
- Links to `route('admin-mode.set', ['state' => $adminModeCookie === '1' ? 'off' : 'on'])`.
- Same visual style (`inline-flex h-8 items-center rounded-[6px] border ...`) as the other action buttons in that row.

New translation keys added to `lang/de/booking/public.php` and `lang/en/booking/public.php` under the `nav` array: `admin_mode_on`, `admin_mode_off`.

## Tests

- `AdminModeControllerTest`: toggling to `on`/`off` sets the cookie and redirects back; a user without `admin.booking` gets a 403; an invalid `state` value 404s.
- `CalendarControllerTest`: an admin-capable user with no `admin_mode` cookie sees the normal (`book`/own-cancel) calendar actions, not admin ones; the same user with `admin_mode=1` sees the admin actions (`admin-book`, admin edit/cancel links) exactly as before this feature existed; with `admin_mode=1` a non-admin user's `$isAdmin` is still `false` (permission check isn't bypassable via cookie).
- Header test: the admin-mode button is absent for non-admins, present with the correct label for admins in both cookie states.
