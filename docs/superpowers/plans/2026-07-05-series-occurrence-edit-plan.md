# Serientermin: Einzeltermin vs. ganze Serie bearbeiten — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let admins choose, when editing/stornieren/löschen a booking that is part of a series (`Booking::isSubscription()`), whether the action applies only to the clicked occurrence or to that occurrence and all following ones — without introducing a new "series" data model.

**Architecture:** A series is one `Booking` row (status `subscription`) owning many `Reservation` rows (one per date). There is no per-occurrence status/notes column. "Scoping" an action is implemented as a **split**: the targeted reservation(s) are reassigned (`bid` update) to a freshly created `Booking` that copies the original's user/court/quantity/meta, and the existing update/cancel/delete logic then runs against that (possibly new) booking. All PHP commands run via WSL per project convention.

**Tech Stack:** Laravel 11, PHPUnit (`#[Test]` attributes, `RefreshDatabase`), Blade + vanilla JS (no build step for `public/js/booking.js`), MySQL (`booking_local` schema — no migrations needed, no new columns).

---

## Task 1: `BookingService::splitForScope()` — core split logic

**Files:**
- Modify: `app/Services/BookingService.php`
- Test: `tests/Unit/Services/BookingServiceSplitTest.php` (new file)

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Services/BookingServiceSplitTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Booking;
use App\Models\Reservation;
use App\Models\Square;
use App\Models\User;
use App\Services\BookingService;
use App\Services\SquareValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookingServiceSplitTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BookingService(new SquareValidator);
    }

    /** @return array{0: Booking, 1: list<Reservation>} */
    private function makeSubscription(array $dates): array
    {
        $user = User::factory()->create();
        $square = Square::factory()->create();
        $booking = Booking::factory()->create([
            'uid' => $user->uid,
            'sid' => $square->sid,
            'status' => 'subscription',
            'quantity' => 4,
        ]);
        $booking->meta()->create(['key' => 'owner-name', 'value' => 'Gast Serie']);
        $booking->meta()->create(['key' => 'admin-note', 'value' => 'Interne Notiz']);

        $reservations = [];
        foreach ($dates as $date) {
            $reservations[] = Reservation::factory()->create([
                'bid' => $booking->bid, 'date' => $date, 'time_start' => '10:00:00', 'time_end' => '11:00:00',
            ]);
        }

        return [$booking->fresh(['reservations', 'meta']), $reservations];
    }

    #[Test]
    public function returns_same_booking_when_occurrence_is_null(): void
    {
        [$booking] = $this->makeSubscription(['2026-07-06', '2026-07-13', '2026-07-20']);

        $result = $this->service->splitForScope($booking, null, 'single_occurrence');

        $this->assertSame($booking->bid, $result->bid);
    }

    #[Test]
    public function returns_same_booking_when_not_a_subscription(): void
    {
        $booking = Booking::factory()->create(['status' => 'single']);
        Reservation::factory()->create(['bid' => $booking->bid, 'date' => '2026-07-06']);

        $result = $this->service->splitForScope($booking, '2026-07-06', 'single_occurrence');

        $this->assertSame($booking->bid, $result->bid);
    }

    #[Test]
    public function returns_same_booking_when_occurrence_date_matches_no_reservation(): void
    {
        [$booking] = $this->makeSubscription(['2026-07-06', '2026-07-13']);

        $result = $this->service->splitForScope($booking, '2026-08-01', 'single_occurrence');

        $this->assertSame($booking->bid, $result->bid);
    }

    #[Test]
    public function single_occurrence_moves_only_that_reservation_into_a_new_single_booking(): void
    {
        [$booking, $reservations] = $this->makeSubscription(['2026-07-06', '2026-07-13', '2026-07-20']);

        $newBooking = $this->service->splitForScope($booking, '2026-07-13', 'single_occurrence');

        $this->assertNotSame($booking->bid, $newBooking->bid);
        $this->assertSame('single', $newBooking->fresh()->status);
        $this->assertSame($booking->uid, $newBooking->uid);
        $this->assertSame($booking->sid, $newBooking->sid);
        $this->assertSame(4, $newBooking->quantity);
        $this->assertEquals(1, $newBooking->reservations()->count());
        $this->assertSame('2026-07-13', $newBooking->reservations()->first()->date);

        $booking->refresh();
        $this->assertSame('subscription', $booking->status);
        $this->assertEquals(2, $booking->reservations()->count());
        $this->assertDatabaseHas('bs_bookings_meta', ['bid' => $newBooking->bid, 'key' => 'owner-name', 'value' => 'Gast Serie']);
        $this->assertDatabaseHas('bs_bookings_meta', ['bid' => $newBooking->bid, 'key' => 'admin-note', 'value' => 'Interne Notiz']);
    }

    #[Test]
    public function single_occurrence_leaves_original_as_single_when_only_two_reservations_existed(): void
    {
        [$booking] = $this->makeSubscription(['2026-07-06', '2026-07-13']);

        $this->service->splitForScope($booking, '2026-07-13', 'single_occurrence');

        $this->assertSame('single', $booking->fresh()->status);
        $this->assertEquals(1, $booking->reservations()->count());
    }

    #[Test]
    public function single_occurrence_on_the_only_remaining_reservation_does_not_split(): void
    {
        [$booking] = $this->makeSubscription(['2026-07-06']);
        $booking->update(['status' => 'subscription']); // force subscription flag even with 1 row

        $result = $this->service->splitForScope($booking, '2026-07-06', 'single_occurrence');

        $this->assertSame($booking->bid, $result->bid);
    }

    #[Test]
    public function this_and_following_moves_occurrence_and_later_dates_into_new_booking(): void
    {
        [$booking] = $this->makeSubscription(['2026-07-06', '2026-07-13', '2026-07-20', '2026-07-27']);

        $newBooking = $this->service->splitForScope($booking, '2026-07-20', 'this_and_following');

        $this->assertNotSame($booking->bid, $newBooking->bid);
        $this->assertSame('subscription', $newBooking->fresh()->status);
        $this->assertEquals(2, $newBooking->reservations()->count());
        $this->assertEqualsCanonicalizing(
            ['2026-07-20', '2026-07-27'],
            $newBooking->reservations()->pluck('date')->all(),
        );

        $booking->refresh();
        $this->assertSame('subscription', $booking->status);
        $this->assertEqualsCanonicalizing(
            ['2026-07-06', '2026-07-13'],
            $booking->reservations()->pluck('date')->all(),
        );
    }

    #[Test]
    public function this_and_following_on_the_last_occurrence_creates_a_single_booking(): void
    {
        [$booking] = $this->makeSubscription(['2026-07-06', '2026-07-13', '2026-07-20']);

        $newBooking = $this->service->splitForScope($booking, '2026-07-20', 'this_and_following');

        $this->assertSame('single', $newBooking->fresh()->status);
        $this->assertEquals(1, $newBooking->reservations()->count());
    }

    #[Test]
    public function this_and_following_on_the_first_occurrence_does_not_split(): void
    {
        [$booking] = $this->makeSubscription(['2026-07-06', '2026-07-13', '2026-07-20']);

        $result = $this->service->splitForScope($booking, '2026-07-06', 'this_and_following');

        $this->assertSame($booking->bid, $result->bid);
        $this->assertEquals(3, $booking->reservations()->count());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run (via WSL): `wsl php artisan test --filter=BookingServiceSplitTest`
Expected: FAIL — `Call to undefined method App\Services\BookingService::splitForScope()`

- [ ] **Step 3: Implement `splitForScope()` and its helpers**

In `app/Services/BookingService.php`, add `use Illuminate\Support\Collection;` to the imports at the top, then add these public/private methods to the `BookingService` class (after `deleteSingle()`):

```php
    public function splitForScope(Booking $booking, ?string $occurrenceDate, string $scope): Booking
    {
        if ($occurrenceDate === null || ! $booking->isSubscription()) {
            return $booking;
        }

        $booking->loadMissing(['reservations', 'meta']);
        $reservations = $booking->reservations
            ->sortBy([['date', 'asc'], ['time_start', 'asc']])
            ->values();

        if (! $reservations->contains(fn (Reservation $r): bool => $r->date === $occurrenceDate)) {
            return $booking;
        }

        return $scope === 'this_and_following'
            ? $this->splitFromDate($booking, $reservations, $occurrenceDate)
            : $this->splitSingleOccurrence($booking, $reservations, $occurrenceDate);
    }

    /** @param Collection<int, Reservation> $reservations */
    private function splitSingleOccurrence(Booking $booking, Collection $reservations, string $occurrenceDate): Booking
    {
        if ($reservations->count() <= 1) {
            return $booking;
        }

        $moving = $reservations->filter(fn (Reservation $r): bool => $r->date === $occurrenceDate)->values();

        return DB::transaction(function () use ($booking, $moving): Booking {
            $newBooking = $this->cloneBookingShell($booking, $moving->count());
            Reservation::whereIn('rid', $moving->pluck('rid'))->update(['bid' => $newBooking->bid]);
            $this->recalculateStatus($booking);

            return $newBooking->fresh(['reservations', 'meta']);
        });
    }

    /** @param Collection<int, Reservation> $reservations */
    private function splitFromDate(Booking $booking, Collection $reservations, string $occurrenceDate): Booking
    {
        $head = $reservations->filter(fn (Reservation $r): bool => $r->date < $occurrenceDate)->values();
        $tail = $reservations->filter(fn (Reservation $r): bool => $r->date >= $occurrenceDate)->values();

        if ($head->isEmpty()) {
            return $booking;
        }

        return DB::transaction(function () use ($booking, $tail): Booking {
            $newBooking = $this->cloneBookingShell($booking, $tail->count());
            Reservation::whereIn('rid', $tail->pluck('rid'))->update(['bid' => $newBooking->bid]);
            $this->recalculateStatus($booking);

            return $newBooking->fresh(['reservations', 'meta']);
        });
    }

    private function cloneBookingShell(Booking $booking, int $movingReservationCount): Booking
    {
        $newBooking = Booking::create([
            'uid' => $booking->uid,
            'sid' => $booking->sid,
            'status' => $movingReservationCount > 1 ? 'subscription' : 'single',
            'status_billing' => $booking->status_billing,
            'visibility' => $booking->visibility,
            'quantity' => $booking->quantity,
            'created' => now()->format('Y-m-d H:i:s'),
        ]);

        foreach (['owner-name', 'admin-note', 'player-names'] as $key) {
            $value = $booking->meta->firstWhere('key', $key)?->value;
            if ($value !== null) {
                $newBooking->meta()->create(['key' => $key, 'value' => $value]);
            }
        }

        return $newBooking;
    }

    private function recalculateStatus(Booking $booking): void
    {
        $remaining = $booking->reservations()->count();
        $booking->update(['status' => $remaining > 1 ? 'subscription' : 'single']);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `wsl php artisan test --filter=BookingServiceSplitTest`
Expected: PASS (9 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/BookingService.php tests/Unit/Services/BookingServiceSplitTest.php
git commit -m "Add BookingService::splitForScope to split a subscription for single/following occurrence edits"
```

---

## Task 2: Wire `occurrence`/`scope` into `cancelSingle()` and `deleteSingle()`

**Files:**
- Modify: `app/Services/BookingService.php`
- Test: `tests/Unit/Services/BookingServiceSplitTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Services/BookingServiceSplitTest.php` (inside the class, before the final `}`):

```php
    #[Test]
    public function cancel_single_with_scope_cancels_only_the_split_off_booking(): void
    {
        [$booking] = $this->makeSubscription(['2026-07-06', '2026-07-13', '2026-07-20']);

        $this->service->cancelSingle($booking, '2026-07-13', 'single_occurrence');

        $this->assertSame('subscription', $booking->fresh()->status);
        $this->assertEquals(2, $booking->reservations()->count());

        $cancelled = Booking::where('status', 'cancelled')->firstOrFail();
        $this->assertEquals(1, $cancelled->reservations()->count());
        $this->assertSame('2026-07-13', $cancelled->reservations()->first()->date);
    }

    #[Test]
    public function cancel_single_without_occurrence_cancels_whole_booking_as_before(): void
    {
        [$booking] = $this->makeSubscription(['2026-07-06', '2026-07-13']);

        $this->service->cancelSingle($booking);

        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertEquals(2, $booking->reservations()->count());
    }

    #[Test]
    public function delete_single_with_this_and_following_scope_only_deletes_tail_reservations(): void
    {
        [$booking] = $this->makeSubscription(['2026-07-06', '2026-07-13', '2026-07-20']);

        $this->service->deleteSingle($booking, '2026-07-13', 'this_and_following');

        $this->assertDatabaseHas('bs_bookings', ['bid' => $booking->bid]);
        $this->assertEquals(1, $booking->fresh()->reservations()->count());
        $this->assertSame('single', $booking->fresh()->status);
        $this->assertDatabaseMissing('bs_reservations', ['date' => '2026-07-13']);
        $this->assertDatabaseMissing('bs_reservations', ['date' => '2026-07-20']);
        $this->assertDatabaseHas('bs_reservations', ['date' => '2026-07-06']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `wsl php artisan test --filter=BookingServiceSplitTest`
Expected: FAIL — `Too few arguments to function cancelSingle()` / `deleteSingle()`

- [ ] **Step 3: Update method signatures**

In `app/Services/BookingService.php`, change `cancelSingle()`:

```php
    public function cancelSingle(Booking $booking, ?string $occurrenceDate = null, string $scope = 'single_occurrence'): void
    {
        $target = DB::transaction(function () use ($booking, $occurrenceDate, $scope): Booking {
            $target = $this->splitForScope($booking, $occurrenceDate, $scope);
            $target->update([
                'status' => 'cancelled',
                'status_billing' => 'cancelled',
            ]);

            return $target;
        });

        $target->loadMissing('user');

        $email = $target->user?->email ?? null;
        if (!empty($email) && $this->isMailConfigured()) {
            dispatch(function () use ($email, $target): void {
                try {
                    Mail::to($email)->send(new BookingCancelled($target));
                } catch (\Throwable) {
                    // Mail errors must not affect the response already sent
                }
            })->afterResponse();
        }
    }
```

and `deleteSingle()`:

```php
    public function deleteSingle(Booking $booking, ?string $occurrenceDate = null, string $scope = 'single_occurrence'): void
    {
        DB::transaction(function () use ($booking, $occurrenceDate, $scope): void {
            $target = $this->splitForScope($booking, $occurrenceDate, $scope);
            $target->loadMissing(['meta', 'bills', 'reservations.meta']);

            foreach ($target->reservations as $reservation) {
                $reservation->meta()->delete();
            }

            $target->reservations()->delete();
            $target->meta()->delete();
            $target->bills()->delete();
            $target->delete();
        });
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `wsl php artisan test --filter=BookingServiceSplitTest`
Expected: PASS (12 tests)

- [ ] **Step 5: Run the full existing service/admin test suites to check for regressions**

Run: `wsl php artisan test --filter=BookingServiceTest`
Run: `wsl php artisan test --filter=AdminBookingTest`
Expected: PASS (no regressions — new params are optional with backward-compatible defaults)

- [ ] **Step 6: Commit**

```bash
git add app/Services/BookingService.php tests/Unit/Services/BookingServiceSplitTest.php
git commit -m "Support scoped cancel/delete of a single occurrence or a booking's tail"
```

---

## Task 3: Wire `occurrence`/`edit_scope` into `BookingController` write actions

**Files:**
- Modify: `app/Http/Controllers/Admin/BookingController.php`
- Test: `tests/Feature/Admin/AdminBookingTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Admin/AdminBookingTest.php` (inside the class, before the final `}`):

```php
    /** @return array{0: Booking, list<Reservation>} */
    private function subscriptionBooking(User $owner, Square $square, array $dates): Booking
    {
        $booking = Booking::factory()->create([
            'uid' => $owner->uid, 'sid' => $square->sid, 'status' => 'subscription', 'quantity' => 2,
        ]);
        foreach ($dates as $date) {
            Reservation::factory()->create([
                'bid' => $booking->bid, 'date' => $date, 'time_start' => '10:00:00', 'time_end' => '11:00:00',
            ]);
        }

        return $booking;
    }

    #[Test]
    public function update_with_single_occurrence_scope_only_changes_that_occurrence(): void
    {
        $admin = $this->admin();
        $square = Square::factory()->create();
        $booking = $this->subscriptionBooking($admin, $square, ['2026-07-06', '2026-07-13', '2026-07-20']);

        $this->actingAs($admin)->put("/admin/bookings/{$booking->bid}", $this->bookingPayload([
            'booked_for' => $admin->alias,
            'sid' => $square->sid,
            'date' => '2026-07-13',
            'date_end' => '2026-07-13',
            'time_start' => '14:00',
            'time_end' => '15:00',
            'occurrence' => '2026-07-13',
            'edit_scope' => 'single_occurrence',
        ]))->assertRedirect(route('admin.bookings.index'));

        $booking->refresh();
        $this->assertSame('subscription', $booking->status);
        $this->assertEqualsCanonicalizing(['2026-07-06', '2026-07-20'], $booking->reservations()->pluck('date')->all());

        $newBooking = Booking::where('bid', '!=', $booking->bid)->where('sid', $square->sid)->firstOrFail();
        $this->assertSame('single', $newBooking->status);
        $reservation = $newBooking->reservations()->firstOrFail();
        $this->assertSame('2026-07-13', $reservation->date);
        $this->assertSame('14:00:00', $reservation->time_start);
    }

    #[Test]
    public function update_with_this_and_following_scope_splits_off_the_tail(): void
    {
        $admin = $this->admin();
        $square = Square::factory()->create();
        $booking = $this->subscriptionBooking($admin, $square, ['2026-07-06', '2026-07-13', '2026-07-20']);

        $this->actingAs($admin)->put("/admin/bookings/{$booking->bid}", $this->bookingPayload([
            'booked_for' => $admin->alias,
            'sid' => $square->sid,
            'date' => '2026-07-13',
            'date_end' => '2026-07-20',
            'repeat_type' => 'weekly',
            'occurrence' => '2026-07-13',
            'edit_scope' => 'this_and_following',
        ]))->assertRedirect(route('admin.bookings.index'));

        $booking->refresh();
        $this->assertEqualsCanonicalizing(['2026-07-06'], $booking->reservations()->pluck('date')->all());

        $newBooking = Booking::where('bid', '!=', $booking->bid)->where('sid', $square->sid)->firstOrFail();
        $this->assertEqualsCanonicalizing(['2026-07-13', '2026-07-20'], $newBooking->reservations()->pluck('date')->all());
    }

    #[Test]
    public function cancel_with_single_occurrence_scope_only_cancels_that_occurrence(): void
    {
        $admin = $this->admin();
        $square = Square::factory()->create();
        $booking = $this->subscriptionBooking($admin, $square, ['2026-07-06', '2026-07-13']);

        $this->actingAs($admin)->post("/admin/bookings/{$booking->bid}/cancel", [
            'occurrence' => '2026-07-13',
            'edit_scope' => 'single_occurrence',
        ])->assertRedirect(route('admin.bookings.index'));

        $this->assertSame('single', $booking->fresh()->status);
        $cancelled = Booking::where('status', 'cancelled')->firstOrFail();
        $this->assertSame('2026-07-13', $cancelled->reservations()->firstOrFail()->date);
    }

    #[Test]
    public function destroy_with_this_and_following_scope_only_removes_tail_occurrences(): void
    {
        $admin = $this->admin();
        $square = Square::factory()->create();
        $booking = $this->subscriptionBooking($admin, $square, ['2026-07-06', '2026-07-13', '2026-07-20']);

        $this->actingAs($admin)->delete("/admin/bookings/{$booking->bid}", [
            'occurrence' => '2026-07-13',
            'edit_scope' => 'this_and_following',
        ])->assertRedirect(route('admin.bookings.index'));

        $this->assertDatabaseHas('bs_bookings', ['bid' => $booking->bid]);
        $this->assertEqualsCanonicalizing(['2026-07-06'], $booking->fresh()->reservations()->pluck('date')->all());
        $this->assertDatabaseMissing('bs_reservations', ['date' => '2026-07-20']);
    }

    #[Test]
    public function update_without_occurrence_still_edits_the_whole_series_as_before(): void
    {
        $admin = $this->admin();
        $square = Square::factory()->create();
        $booking = $this->subscriptionBooking($admin, $square, ['2026-07-06', '2026-07-13']);

        $this->actingAs($admin)->put("/admin/bookings/{$booking->bid}", $this->bookingPayload([
            'booked_for' => $admin->alias,
            'sid' => $square->sid,
            'date' => '2026-07-06',
            'date_end' => '2026-07-13',
            'repeat_type' => 'weekly',
        ]))->assertRedirect(route('admin.bookings.index'));

        $booking->refresh();
        $this->assertSame('subscription', $booking->status);
        $this->assertEqualsCanonicalizing(['2026-07-06', '2026-07-13'], $booking->reservations()->pluck('date')->all());
        $this->assertEquals(1, Booking::count());
    }
```

Add the missing imports at the top of the test file if not already present (`Square` and `Reservation` are already imported in this file).

- [ ] **Step 2: Run tests to verify they fail**

Run: `wsl php artisan test --filter=AdminBookingTest`
Expected: FAIL on the 5 new tests — today `update`/`cancel`/`destroy` always act on the whole `$booking`, so e.g. `update_with_single_occurrence_scope_only_changes_that_occurrence` fails because no split ever happens (only one booking exists afterwards).

- [ ] **Step 3: Add `resolveOccurrenceScope()` helper and wire it into `update()`, `cancel()`, `destroy()`**

In `app/Http/Controllers/Admin/BookingController.php`, add this private method (near `resolveOwner()`):

```php
    /** @return array{0: ?string, 1: string} */
    private function resolveOccurrenceScope(Request $request): array
    {
        $occurrence = $request->string('occurrence')->trim()->value();
        $scope = $request->string('edit_scope')->trim()->value();

        return [
            $occurrence === '' ? null : $occurrence,
            in_array($scope, ['single_occurrence', 'this_and_following'], true) ? $scope : 'single_occurrence',
        ];
    }
```

Update `update()`: replace

```php
            DB::transaction(function () use ($booking, $data, $owner, $occurrenceStarts, $durationSeconds): void {
                if (($data['status'] ?? null) !== 'cancelled') {
                    // Serialize with concurrent bookings on this square, then check conflicts.
                    Square::query()->whereKey((int) $data['sid'])->lockForUpdate()->get();
                    $conflictError = $this->batchCheckConflicts((int) $data['sid'], $occurrenceStarts, $durationSeconds, $booking->bid);
                    if ($conflictError !== null) {
                        throw new BookingValidationException($conflictError);
                    }
                }

                $effectiveStatus = ($data['status'] ?? null) === 'cancelled'
                    ? 'cancelled'
                    : (count($occurrenceStarts) > 1 ? 'subscription' : 'single');

                $booking->update([
                    'uid' => $owner['uid'],
                    'sid' => (int) $data['sid'],
                    'quantity' => (int) $data['quantity'],
                    'status' => $effectiveStatus,
                ]);

                $booking->loadMissing(['reservations.meta']);
                foreach ($booking->reservations as $reservation) {
                    $reservation->meta()->delete();
                }
                $booking->reservations()->delete();

                $rows = [];
                foreach ($occurrenceStarts as $occurrenceStart) {
                    $rows[] = [
                        'bid'        => $booking->bid,
                        'date'       => $occurrenceStart->format('Y-m-d'),
                        'time_start' => $occurrenceStart->format('H:i:s'),
                        'time_end'   => $occurrenceStart->copy()->addSeconds($durationSeconds)->format('H:i:s'),
                    ];
                }
                DB::table('bs_reservations')->insert($rows);

                $this->bookingService->syncPlayerMeta($booking, [
                    2 => $data['mitspieler'] ?? null,
                ]);

                $this->syncBookingMeta($booking, 'owner-name', $owner['ownerName']);
                $this->syncBookingMeta($booking, 'admin-note', $data['admin_note'] ?? null);
            });
```

with:

```php
            [$occurrenceDate, $scope] = $this->resolveOccurrenceScope($request);

            DB::transaction(function () use ($booking, $data, $owner, $occurrenceStarts, $durationSeconds, $occurrenceDate, $scope): void {
                $targetBooking = $this->bookingService->splitForScope($booking, $occurrenceDate, $scope);

                if (($data['status'] ?? null) !== 'cancelled') {
                    // Serialize with concurrent bookings on this square, then check conflicts.
                    Square::query()->whereKey((int) $data['sid'])->lockForUpdate()->get();
                    $conflictError = $this->batchCheckConflicts((int) $data['sid'], $occurrenceStarts, $durationSeconds, $targetBooking->bid);
                    if ($conflictError !== null) {
                        throw new BookingValidationException($conflictError);
                    }
                }

                $effectiveStatus = ($data['status'] ?? null) === 'cancelled'
                    ? 'cancelled'
                    : (count($occurrenceStarts) > 1 ? 'subscription' : 'single');

                $targetBooking->update([
                    'uid' => $owner['uid'],
                    'sid' => (int) $data['sid'],
                    'quantity' => (int) $data['quantity'],
                    'status' => $effectiveStatus,
                ]);

                $targetBooking->loadMissing(['reservations.meta']);
                foreach ($targetBooking->reservations as $reservation) {
                    $reservation->meta()->delete();
                }
                $targetBooking->reservations()->delete();

                $rows = [];
                foreach ($occurrenceStarts as $occurrenceStart) {
                    $rows[] = [
                        'bid'        => $targetBooking->bid,
                        'date'       => $occurrenceStart->format('Y-m-d'),
                        'time_start' => $occurrenceStart->format('H:i:s'),
                        'time_end'   => $occurrenceStart->copy()->addSeconds($durationSeconds)->format('H:i:s'),
                    ];
                }
                DB::table('bs_reservations')->insert($rows);

                $this->bookingService->syncPlayerMeta($targetBooking, [
                    2 => $data['mitspieler'] ?? null,
                ]);

                $this->syncBookingMeta($targetBooking, 'owner-name', $owner['ownerName']);
                $this->syncBookingMeta($targetBooking, 'admin-note', $data['admin_note'] ?? null);
            });
```

Update `cancel()`:

```php
    public function cancel(Request $request, Booking $booking): RedirectResponse|Response
    {
        [$occurrenceDate, $scope] = $this->resolveOccurrenceScope($request);
        $this->bookingService->cancelSingle($booking, $occurrenceDate, $scope);
```

(rest of the method body unchanged).

Update `destroy()`:

```php
    public function destroy(Request $request, Booking $booking): RedirectResponse|Response
    {
        [$occurrenceDate, $scope] = $this->resolveOccurrenceScope($request);
        $this->bookingService->deleteSingle($booking, $occurrenceDate, $scope);
```

(rest of the method body unchanged).

- [ ] **Step 4: Run tests to verify they pass**

Run: `wsl php artisan test --filter=AdminBookingTest`
Expected: PASS (all tests, including the 5 new ones)

- [ ] **Step 5: Run the full test suite to check for regressions**

Run: `wsl php artisan test`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/BookingController.php tests/Feature/Admin/AdminBookingTest.php
git commit -m "Accept occurrence/edit_scope on booking update/cancel/destroy to scope series edits"
```

---

## Task 4: Occurrence-aware `BookingController::edit()` view data

**Files:**
- Modify: `app/Http/Controllers/Admin/BookingController.php`
- Test: `tests/Feature/Admin/AdminBookingTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Admin/AdminBookingTest.php`:

```php
    #[Test]
    public function edit_with_occurrence_param_defaults_to_single_occurrence_scope(): void
    {
        $admin = $this->admin();
        $square = Square::factory()->create();
        $booking = $this->subscriptionBooking($admin, $square, ['2026-07-06', '2026-07-13', '2026-07-20']);

        $response = $this->actingAs($admin)->get("/admin/bookings/{$booking->bid}/edit?occurrence=2026-07-13");

        $response->assertOk();
        $this->assertTrue($response->viewData('showScopeChoice'));
        $this->assertSame('2026-07-13', $response->viewData('reservation')->date);
        $this->assertSame('once', $response->viewData('repeatType'));
        $this->assertSame('2026-07-13', $response->viewData('repeatEndDate'));
        $this->assertSame('weekly', $response->viewData('followingRepeatType'));
        $this->assertSame('2026-07-20', $response->viewData('followingRepeatEndDate'));
    }

    #[Test]
    public function edit_without_occurrence_param_does_not_show_scope_choice(): void
    {
        $admin = $this->admin();
        $square = Square::factory()->create();
        $booking = $this->subscriptionBooking($admin, $square, ['2026-07-06', '2026-07-13']);

        $response = $this->actingAs($admin)->get("/admin/bookings/{$booking->bid}/edit");

        $response->assertOk();
        $this->assertFalse($response->viewData('showScopeChoice'));
        $this->assertSame('2026-07-06', $response->viewData('reservation')->date);
    }

    #[Test]
    public function edit_with_occurrence_param_on_single_booking_does_not_show_scope_choice(): void
    {
        $booking = Booking::factory()->create(['status' => 'single']);
        Reservation::factory()->create(['bid' => $booking->bid, 'date' => '2026-07-06']);

        $response = $this->actingAs($this->admin())->get("/admin/bookings/{$booking->bid}/edit?occurrence=2026-07-06");

        $response->assertOk();
        $this->assertFalse($response->viewData('showScopeChoice'));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `wsl php artisan test --filter=AdminBookingTest`
Expected: FAIL — `showScopeChoice`/`followingRepeatType`/`followingRepeatEndDate` view data undefined, and `reservation` still defaults to the first reservation regardless of `occurrence`.

- [ ] **Step 3: Update `edit()`**

Replace the body of `BookingController::edit()`:

```php
    public function edit(Booking $booking): View
    {
        $booking->load(['user', 'square', 'reservations', 'meta']);
        $reservations = $booking->reservations->sortBy([['date', 'asc'], ['time_start', 'asc']])->values();

        $occurrenceParam = request()->query('occurrence');
        $occurrenceReservation = is_string($occurrenceParam)
            ? $reservations->firstWhere('date', $occurrenceParam)
            : null;

        $showScopeChoice = $booking->isSubscription() && $occurrenceReservation !== null;
        $reservation = $occurrenceReservation ?? $reservations->first();

        $repeatType = $this->detectRepeatType($reservations->all());
        $repeatEndDate = $reservations->last()?->date ?? $reservation?->date;
        $followingRepeatType = $repeatType;
        $followingRepeatEndDate = $repeatEndDate;

        if ($showScopeChoice) {
            $followingReservations = $reservations
                ->filter(fn (Reservation $r): bool => $r->date >= $occurrenceReservation->date)
                ->values()
                ->all();
            $followingRepeatType = $this->detectRepeatType($followingReservations);
            $followingRepeatEndDate = $reservations->last()?->date ?? $occurrenceReservation->date;
            $repeatType = 'once';
            $repeatEndDate = $occurrenceReservation->date;
        }

        return view('admin.bookings.edit', [
            'booking' => $booking,
            'users' => User::where('status', '!=', 'deleted')->orderBy('alias')->get(['uid', 'alias']),
            'squares' => Square::orderBy('priority')->orderBy('sid')->get(),
            'reservation' => $reservation,
            'bookedFor' => $booking->owner_label,
            'playerNames' => [
                2 => $booking->player_names[0] ?? '',
                3 => $booking->player_names[1] ?? '',
                4 => $booking->player_names[2] ?? '',
            ],
            'adminNote' => $booking->meta->firstWhere('key', 'admin-note')?->value ?? '',
            'repeatOptions' => self::repeatOptions(),
            'repeatType' => $repeatType,
            'repeatEndDate' => $repeatEndDate,
            'isCreate' => false,
            'showScopeChoice' => $showScopeChoice,
            'occurrenceDate' => $occurrenceReservation?->date,
            'followingRepeatType' => $followingRepeatType,
            'followingRepeatEndDate' => $followingRepeatEndDate,
        ]);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `wsl php artisan test --filter=AdminBookingTest`
Expected: PASS (all tests, including the 3 new ones and the pre-existing `edit_detects_weekly_repeat_from_reservations`, `popup_edit_page_contains_calendar_redirect`, etc.)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/BookingController.php tests/Feature/Admin/AdminBookingTest.php
git commit -m "Resolve the clicked occurrence and precompute both edit-scope field sets in BookingController::edit"
```

---

## Task 5: Translation strings for the scope choice

**Files:**
- Modify: `lang/de/booking/admin.php:211` (after `status_series`)
- Modify: `lang/en/booking/admin.php:211` (after `status_series`)

- [ ] **Step 1: Add German strings**

In `lang/de/booking/admin.php`, after the line `'status_series' => 'Serie',` (line 210), add:

```php
            'edit_scope_label' => 'Diese Änderung gilt für',
            'edit_scope_single' => 'Nur diesen Termin (:date)',
            'edit_scope_following' => 'Diesen und alle folgenden Termine',
```

- [ ] **Step 2: Add English strings**

In `lang/en/booking/admin.php`, after the line `'status_series' => 'Series',` (line 210), add:

```php
            'edit_scope_label' => 'This change applies to',
            'edit_scope_single' => 'Only this occurrence (:date)',
            'edit_scope_following' => 'This and all following occurrences',
```

- [ ] **Step 3: Verify the app still boots translations correctly**

Run: `wsl php artisan test --filter=AdminBookingTest`
Expected: PASS (no test depends on these keys yet, but this catches PHP syntax errors in the lang files)

- [ ] **Step 4: Commit**

```bash
git add lang/de/booking/admin.php lang/en/booking/admin.php
git commit -m "Add translation strings for the single-occurrence vs series edit scope"
```

---

## Task 6: Edit-page UI — scope radios and hidden fields

**Files:**
- Modify: `resources/views/admin/bookings/edit.blade.php`
- Test: `tests/Feature/Admin/AdminBookingTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Admin/AdminBookingTest.php`:

```php
    #[Test]
    public function edit_page_shows_scope_radios_when_occurrence_given_for_subscription(): void
    {
        $admin = $this->admin();
        $square = Square::factory()->create();
        $booking = $this->subscriptionBooking($admin, $square, ['2026-07-06', '2026-07-13', '2026-07-20']);

        $this->actingAs($admin)->get("/admin/bookings/{$booking->bid}/edit?occurrence=2026-07-13")
            ->assertOk()
            ->assertSee('name="edit_scope"', false)
            ->assertSee('value="single_occurrence"', false)
            ->assertSee('value="this_and_following"', false)
            ->assertSee('name="occurrence" value="2026-07-13"', false);
    }

    #[Test]
    public function edit_page_hides_scope_radios_without_occurrence(): void
    {
        $admin = $this->admin();
        $square = Square::factory()->create();
        $booking = $this->subscriptionBooking($admin, $square, ['2026-07-06', '2026-07-13']);

        $this->actingAs($admin)->get("/admin/bookings/{$booking->bid}/edit")
            ->assertOk()
            ->assertDontSee('name="edit_scope"', false);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `wsl php artisan test --filter=AdminBookingTest`
Expected: FAIL — no `edit_scope` markup exists yet.

- [ ] **Step 3: Add the scope radios and hidden fields to the edit form**

In `resources/views/admin/bookings/edit.blade.php`, in the non-create branch (`@else` block starting at line 169), insert a new block right after the closing `</div>` of the repeat section (after line 257, before the player-names `<div>` starting at line 259):

```blade
            @if($showScopeChoice)
                <div class="space-y-2 rounded-[6px] border border-[#e0dbd4] bg-[#fafaf8] p-3">
                    <p class="block text-[13px] font-medium text-[#151515]">{{ __('booking.admin.bookings.edit_scope_label') }}</p>
                    <label class="flex items-center gap-2 text-sm text-[#151515]">
                        <input type="radio" name="edit_scope" value="single_occurrence" checked>
                        {{ __('booking.admin.bookings.edit_scope_single', ['date' => \Carbon\Carbon::parse($occurrenceDate)->format('d.m.Y')]) }}
                    </label>
                    <label class="flex items-center gap-2 text-sm text-[#151515]">
                        <input type="radio" name="edit_scope" value="this_and_following">
                        {{ __('booking.admin.bookings.edit_scope_following') }}
                    </label>
                </div>
            @endif
            <input type="hidden" name="occurrence" value="{{ $occurrenceDate }}">
```

Then add the same `occurrence` hidden field (and an `edit_scope` hidden field kept in sync via JS) to the two standalone forms near the bottom of the file. Replace:

```blade
    @if($booking->status !== 'cancelled')
        <form method="POST" action="{{ route('admin.bookings.cancel', $booking) }}" id="admin-booking-cancel" class="hidden" onsubmit="return confirm({{ Js::from(__('booking.admin.bookings.confirm_cancel')) }})">
            @csrf
            @if(request('popup'))
                <input type="hidden" name="redirect_to" value="{{ route('calendar.index', ['date' => old('date', $reservation?->date ?? now()->format('Y-m-d'))]) }}">
            @endif
        </form>
    @endif

    <form method="POST" action="{{ route('admin.bookings.destroy', $booking) }}" id="admin-booking-delete" class="hidden" onsubmit="return confirm({{ Js::from(__('booking.admin.bookings.confirm_delete')) }})">
        @csrf
        @method('DELETE')
        @if(request('popup'))
            <input type="hidden" name="redirect_to" value="{{ route('calendar.index', ['date' => old('date', $reservation?->date ?? now()->format('Y-m-d'))]) }}">
        @endif
    </form>
```

with:

```blade
    @if($booking->status !== 'cancelled')
        <form method="POST" action="{{ route('admin.bookings.cancel', $booking) }}" id="admin-booking-cancel" class="hidden" onsubmit="return confirm({{ Js::from(__('booking.admin.bookings.confirm_cancel')) }})">
            @csrf
            <input type="hidden" name="occurrence" value="{{ $occurrenceDate }}">
            <input type="hidden" name="edit_scope" id="admin-booking-cancel-scope" value="single_occurrence">
            @if(request('popup'))
                <input type="hidden" name="redirect_to" value="{{ route('calendar.index', ['date' => old('date', $reservation?->date ?? now()->format('Y-m-d'))]) }}">
            @endif
        </form>
    @endif

    <form method="POST" action="{{ route('admin.bookings.destroy', $booking) }}" id="admin-booking-delete" class="hidden" onsubmit="return confirm({{ Js::from(__('booking.admin.bookings.confirm_delete')) }})">
        @csrf
        @method('DELETE')
        <input type="hidden" name="occurrence" value="{{ $occurrenceDate }}">
        <input type="hidden" name="edit_scope" id="admin-booking-delete-scope" value="single_occurrence">
        @if(request('popup'))
            <input type="hidden" name="redirect_to" value="{{ route('calendar.index', ['date' => old('date', $reservation?->date ?? now()->format('Y-m-d'))]) }}">
        @endif
    </form>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `wsl php artisan test --filter=AdminBookingTest`
Expected: PASS (all tests, including the 2 new ones)

- [ ] **Step 5: Commit**

```bash
git add resources/views/admin/bookings/edit.blade.php tests/Feature/Admin/AdminBookingTest.php
git commit -m "Show single-occurrence vs series scope radios on the admin booking edit page"
```

---

## Task 7: Edit-page JS — sync scope to the other forms and swap date fields

**Files:**
- Modify: `resources/views/admin/bookings/edit.blade.php`

This task has no automated test (it's client-side `<script>` behaviour in a Blade view with no JS test runner in this project — matches the existing untested inline script in this file). Verify manually per Step 3.

- [ ] **Step 1: Locate the existing inline `<script>` block**

At the bottom of `resources/views/admin/bookings/edit.blade.php` (around line 327), there is a `document.addEventListener('DOMContentLoaded', function () { ... });` block that wires `quantity`/`repeat`/`dateEnd` fields.

- [ ] **Step 2: Add scope-sync logic inside the same `DOMContentLoaded` callback**

Insert this code right before the closing `});` of the existing `DOMContentLoaded` listener (i.e. right after the line `syncAdminBookingFields();` and before `});`):

```javascript

    var scopeRadios = document.querySelectorAll('input[name="edit_scope"]');
    if (scopeRadios.length) {
        var cancelScopeField = document.getElementById('admin-booking-cancel-scope');
        var deleteScopeField = document.getElementById('admin-booking-delete-scope');
        var followingRepeatType = @json($followingRepeatType ?? 'once');
        var followingRepeatEndDate = @json($followingRepeatEndDate ?? '');
        var singleRepeatEndDate = @json($occurrenceDate ?? '');

        function syncScopeToActionForms() {
            var checked = document.querySelector('input[name="edit_scope"]:checked');
            var value = checked ? checked.value : 'single_occurrence';
            if (cancelScopeField) { cancelScopeField.value = value; }
            if (deleteScopeField) { deleteScopeField.value = value; }
        }

        scopeRadios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                syncScopeToActionForms();
                if (!repeat || !dateEnd) { return; }
                if (this.value === 'this_and_following' && this.checked) {
                    repeat.value = followingRepeatType;
                    dateEnd.value = followingRepeatEndDate;
                } else if (this.value === 'single_occurrence' && this.checked) {
                    repeat.value = 'once';
                    dateEnd.value = singleRepeatEndDate;
                }
                syncAdminBookingFields();
            });
        });

        syncScopeToActionForms();
    }
```

- [ ] **Step 3: Manually verify in the browser**

Run: `wsl php artisan serve` (or the project's usual local server command), then:
1. Log in as an admin, enable admin mode.
2. Create a weekly subscription booking spanning 3+ weeks via the calendar.
3. Click the middle occurrence in admin mode → edit page opens with "Nur diesen Termin" checked, date fields show that occurrence's date, repeat = "Einmalig".
4. Toggle to "Diesen und alle folgenden Termine" → repeat field switches to "Wöchentlich" and the end-date field switches to the series' last date.
5. Save with "Nur diesen Termin" selected → confirm only that date changed on the calendar and the other two occurrences are untouched.
6. Repeat for stornieren and löschen buttons, confirming the confirm-dialogs still appear and the correct scope is applied (check the calendar afterwards).

- [ ] **Step 4: Commit**

```bash
git add resources/views/admin/bookings/edit.blade.php
git commit -m "Wire scope radios to the cancel/delete forms and swap repeat fields on toggle"
```

---

## Task 8: Pass the clicked occurrence date from the calendar grid

**Files:**
- Modify: `resources/views/components/calendar/grid.blade.php:193`
- Test: `tests/Feature/CalendarControllerTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/CalendarControllerTest.php` (inside the class, matching the style of `admin_clickable_booking_contains_edit_url_for_popup`):

```php
    #[Test]
    public function admin_clickable_series_booking_contains_occurrence_query_param(): void
    {
        $admin = User::factory()->create(['status' => 'admin']);
        $owner = User::factory()->create(['alias' => 'Serie Owner']);
        $square = Square::factory()->create();
        $booking = Booking::factory()->create(['uid' => $owner->uid, 'sid' => $square->sid, 'status' => 'subscription']);
        $targetDate = Carbon::tomorrow();
        Reservation::factory()->create([
            'bid' => $booking->bid, 'date' => $targetDate->toDateString(), 'time_start' => '10:00:00', 'time_end' => '11:00:00',
        ]);
        Reservation::factory()->create([
            'bid' => $booking->bid, 'date' => $targetDate->copy()->addWeek()->toDateString(), 'time_start' => '10:00:00', 'time_end' => '11:00:00',
        ]);

        $this->actingAs($admin)->withUnencryptedCookie('admin_mode', '1')->get('/calendar?date='.$targetDate->format('Y-m-d'))
            ->assertOk()
            ->assertSee('/admin/bookings/'.$booking->bid.'/edit?occurrence='.$targetDate->format('Y-m-d'), false);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl php artisan test --filter=admin_clickable_series_booking_contains_occurrence_query_param`
Expected: FAIL — the edit URL has no `?occurrence=` query string today.

- [ ] **Step 3: Add the query parameter in the grid**

In `resources/views/components/calendar/grid.blade.php`, on the `data-edit-url` attribute (line 193), change:

```blade
                                       data-edit-url="{{ route('admin.bookings.edit', $reservation->booking) }}">
```

to:

```blade
                                       data-edit-url="{{ route('admin.bookings.edit', $reservation->booking) }}?occurrence={{ $dateKey }}">
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `wsl php artisan test --filter=CalendarControllerTest`
Expected: PASS (all tests, including the new one and the pre-existing `admin_clickable_booking_contains_edit_url_for_popup`, since that assertion only checks a substring)

- [ ] **Step 5: Commit**

```bash
git add resources/views/components/calendar/grid.blade.php tests/Feature/CalendarControllerTest.php
git commit -m "Pass the clicked occurrence date to the admin booking edit URL from the calendar grid"
```

---

## Task 9: Full regression pass

**Files:** none (verification only)

- [ ] **Step 1: Run the entire test suite**

Run: `wsl php artisan test`
Expected: PASS, 0 failures

- [ ] **Step 2: Manually re-verify the end-to-end flow from Task 7 Step 3** if any earlier step required deferring it.

- [ ] **Step 3: Update the design spec status (optional)**

No code change needed; this step is just confirming the plan's spec (`docs/superpowers/specs/2026-07-05-series-occurrence-edit-design.md`) has been fully implemented — every section (split mechanism, UI, controller/service wiring, error fallbacks) maps to Tasks 1–8 above.
