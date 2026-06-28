# Admin booking "Gebucht für" — free-text owner field

**Date:** 2026-06-28
**Status:** Approved (design)
**Branch:** feat/square-admin

## Goal

In the admin booking dialog (`resources/views/admin/bookings/edit.blade.php`), turn the
**"Gebucht für"** field from a `<select name="uid">` dropdown into a **free-text input with
member autocomplete**, behaving like the player-name fields: the admin can type *any* name,
including someone who has no user account.

## The constraint that shapes the design

`bs_bookings.uid` is a **required FK to `bs_users`**. There is no column for a free-text owner.
`uid` is wired into:

- **Permissions** — `BookingService::canUserCancelSingle()` compares `booking.uid` to the user.
- **Capacity / conflict** checks.
- **Display** — the owner shown on the calendar (`booking.user->name`), the admin list and the
  admin show page (`booking.user->alias`).

Player names 2–4 avoid this because they live in `bs_bookings_meta` (serialized `player-names`
key). "Player 1" is simply the `uid`'s alias.

So a free-text owner needs (a) a place to store the typed name and (b) a fallback `uid` so the
FK, permissions, capacity, and existing displays keep working.

## Approach: resolve-or-store (Approach A)

The field becomes a text input that reuses the existing `admin-player-suggestions` datalist
(already rendered with every member alias). On submit the backend resolves the typed text:

- **Resolves to exactly one member** (alias exact match, case-insensitive, `status != deleted`)
  → `uid` = that member. Behaviour is identical to today: booking attaches to the member's
  account, they can cancel it, the info block + *Benutzer bearbeiten* link work. No owner-name
  meta is stored (any stale value is removed).
- **Anything else** (no match, *or* ambiguous match of 2+ members with the same alias)
  → store the typed text in `bs_bookings_meta` under key **`owner-name`**, and set
  `uid` = the acting admin (`auth()->id()`) as a non-displayed fallback owner.

Ambiguous (non-unique alias) matches are deliberately treated as free text rather than rejected,
so the admin is never blocked.

## Components & changes

### 1. View — `resources/views/admin/bookings/edit.blade.php`
- Replace the `<select name="uid">` (lines ~30–35) with:
  ```blade
  <input type="text" name="booked_for"
         value="{{ old('booked_for', $bookedFor) }}"
         list="admin-player-suggestions" maxlength="120"
         class="admin-booking-input" required>
  ```
  (The `admin-player-suggestions` datalist already exists lower in the form.)
- Info block: only render the member meta (`Status` / `E-Mail` / `Telefon`) and the
  *Benutzer bearbeiten* link when the booking resolves to a real member — i.e. when there is
  **no** `owner-name` meta. When a free-text owner is set, show just the typed name.
- The displayed name line uses `$booking->owner_label`.

### 2. Controller view-data — `Admin\BookingController`
- `create()` and `edit()` pass a new `bookedFor` variable:
  - `create()` → `''` (empty; admin types).
  - `edit()` → `$booking->owner_label`.
- Both already pass `users` (used by the datalist) — unchanged.

### 3. Validation — `Admin\BookingController::validateBookingData()`
- Remove the `uid` rules.
- Add `'booked_for' => ['required', 'string', 'max:120']`.

### 4. Resolution + persistence — `Admin\BookingController::store()` / `update()`
- New private helper `resolveOwner(string $bookedFor): array` returning
  `['uid' => int, 'ownerName' => ?string]`:
  - Trim input. Query members with `status != 'deleted'` whose `alias` equals the input
    (case-insensitive). If exactly one row → `['uid' => $user->uid, 'ownerName' => null]`.
    Otherwise → `['uid' => auth()->id(), 'ownerName' => $bookedFor]`.
- `store()`/`update()` use the resolved `uid` when creating/updating the booking.
- After save, sync the `owner-name` meta via the existing `syncBookingMeta()` helper
  (it already creates/updates/deletes a single meta row): set it to `ownerName`, which
  deletes the row when `ownerName` is null.

### 5. Model — `App\Models\Booking`
- Add `getOwnerLabelAttribute(): string` — returns the `owner-name` meta value if non-empty,
  otherwise `user?->alias ?? '—'`. Reads the already-loaded `meta` relation (same pattern as
  `getPlayerNamesAttribute()`), so no extra queries where `meta` is eager-loaded.

### 6. Display call-sites (switch to `owner_label`)
- `resources/views/calendar/index.blade.php` — the owner label (`booking->user?->name`,
  ~lines 162 & 169). `meta` is already loaded here (used by `player_names_label`).
- `resources/views/admin/bookings/index.blade.php:23` — `$b->owner_label`
  (`index()` already eager-loads `meta`).
- `resources/views/admin/bookings/show.blade.php:7` — `$booking->owner_label`
  (`show()` already loads `meta`).

## Data flow

```
admin types name → POST booked_for
  → validateBookingData() (required string)
  → resolveOwner(): exact unique alias?  yes → uid=member, ownerName=null
                                          no  → uid=admin,  ownerName=typed text
  → Booking create/update with uid
  → syncBookingMeta(booking, 'owner-name', ownerName)  // null = delete row
display anywhere → booking.owner_label  // owner-name meta ?? user.alias
```

## Error handling
- `booked_for` empty → standard Laravel `required` validation error (mirrors today's behaviour
  where `uid` was required).
- No new failure modes: a non-resolving name is a valid outcome, not an error.

## Out of scope
- `resources/views/admin/bookings/_form.blade.php` is **dead** (the live UI is `edit.blade.php`);
  not modified. (Optional follow-up: delete it.)
- No DB schema change — the real legacy `booking_local` schema is the source of truth and is not
  migrated; the owner name rides in existing `bs_bookings_meta`.
- The public booking flow (`bookings/create.blade.php`) is unchanged.

## Testing — `tests/Feature/Admin/AdminBookingTest.php`
- Existing tests that post `uid` are updated to post `booked_for` with a member alias and assert
  the booking's `uid` resolves to that member (and **no** `owner-name` meta).
- New test: posting a `booked_for` that matches no member stores `owner-name` meta and sets
  `uid` to the acting admin.
- New test: posting an alias shared by two members is treated as free text (owner-name meta set).
- `owner_label` accessor unit coverage: returns meta value when present, else `user.alias`.
