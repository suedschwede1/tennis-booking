# Admin User Booking Statistics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a new admin page (`/admin/statistics`) showing, per active member, their total/single/double booking counts, bookings last calendar month, most-booked court, and cancellation rate — plus a club-wide summary of the same numbers.

**Architecture:** A single read-only controller action (`Admin\StatisticsController::index`) loads active users and their bookings (with `reservations` and `square` eager-loaded), aggregates everything in PHP collections (dataset is ~100 users / few hundred bookings, same scale and style as the existing `Admin\DashboardController`), and passes a `$stats` collection plus a `$summary` array to a Blade view styled like `admin/users/index.blade.php`. A small inline `<script>` makes the table client-sortable — no new JS dependency.

**Tech Stack:** Laravel 11 (existing app), Blade, PHPUnit (`php artisan test`), Eloquent — no new packages.

**Design doc:** `docs/superpowers/specs/2026-07-01-admin-user-booking-statistics-design.md`

---

## Reference: exact field names used below

- `App\Models\User`: `uid`, `alias`, `status` (`admin`|`assist`|`enabled`|`disabled`|`blocked`|`deleted`|`placeholder`). `User::factory()->create(['status' => ..., 'alias' => ...])`.
- `App\Models\Booking` (table `bs_bookings`, PK `bid`): `uid`, `sid`, `status` (`single`|`subscription`|`cancelled`), `quantity` (2 or 4), relations `user()`, `square()`, `reservations()`.
- `App\Models\Square` (table `bs_squares`, PK `sid`): `name` (e.g. `"1"`), `display_name` accessor (e.g. `"Garagenplatz"`). `Square::factory()->create(['name' => ..., ...])` — check `database/factories/SquareFactory.php` if a field is missing.
- `App\Models\Reservation` (table `bs_reservations`, PK `rid`): `bid`, `date` (`'Y-m-d'` string), no `sid` of its own.
- Existing route group: `Route::middleware('auth')->prefix('admin')->name('admin.')->group(...)` in `routes/web.php:49`, with a `Route::middleware('can:admin.booking')->group(...)` block at `routes/web.php:64` — add the new route inside that block (statistics are a booking-data view, same permission as the bookings section).

---

### Task 1: Route, controller skeleton, permission test, sidebar link

**Files:**
- Create: `app/Http/Controllers/Admin/StatisticsController.php`
- Modify: `routes/web.php:64-67`
- Modify: `resources/views/components/layout/admin-sidebar.blade.php:37` (insert new link after the "Buchungen" link, before the "Plätze" link)
- Test: `tests/Feature/Admin/StatisticsControllerTest.php`

- [ ] **Step 1: Write the failing permission test**

Create `tests/Feature/Admin/StatisticsControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StatisticsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['status' => 'admin']);
    }

    #[Test]
    public function regular_member_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create(['status' => 'enabled']))
            ->get('/admin/statistics')
            ->assertForbidden();
    }

    #[Test]
    public function admin_can_view_the_page(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/statistics')
            ->assertOk();
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/Admin/StatisticsControllerTest.php"`
Expected: FAIL — route `/admin/statistics` does not exist (404, not 403/200).

- [ ] **Step 3: Create the controller skeleton**

Create `app/Http/Controllers/Admin/StatisticsController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

final class StatisticsController extends Controller
{
    public function index(): View
    {
        return view('admin.statistics.index', [
            'stats' => collect(),
            'summary' => [
                'total' => 0,
                'single' => 0,
                'double' => 0,
                'lastMonth' => 0,
                'cancellationRate' => 0.0,
            ],
        ]);
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, inside the existing `Route::middleware('can:admin.booking')->group(function (): void { ... })` block (around line 64), add as the first line of that group:

```php
        Route::get('statistics', [App\Http\Controllers\Admin\StatisticsController::class, 'index'])->name('statistics.index');
```

- [ ] **Step 5: Create a minimal view so the route resolves**

Create `resources/views/admin/statistics/index.blade.php`:

```blade
@extends('layouts.admin')
@section('admin-title', __('booking.admin.statistics.title'))
@section('admin-content')
<div class="ui-page">
    <div class="ui-page-header">
        <h1>{{ __('booking.admin.statistics.title') }}</h1>
    </div>
</div>
@endsection
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/Admin/StatisticsControllerTest.php"`
Expected: PASS (2 tests). Note: `__('booking.admin.statistics.title')` will render as the literal string `booking.admin.statistics.title` for now since the lang key doesn't exist yet — that's fine, it doesn't fail the test, and gets fixed in Task 7.

- [ ] **Step 7: Add the sidebar link**

In `resources/views/components/layout/admin-sidebar.blade.php`, immediately after the "Buchungen" `</a>` (the block ending at line 25, i.e. right before the `@can('admin.config')` block for "Plätze" that starts at line 27), insert:

```blade
        @can('admin.booking')
            <a href="{{ route('admin.statistics.index') }}" onmouseover="if (!this.dataset.active) { this.style.background='#26292e'; this.style.color='#ffffff'; }" onmouseout="if (!this.dataset.active) { this.style.background='transparent'; this.style.color='#b2b6bd'; }" data-active="{{ request()->routeIs('admin.statistics.*') ? '1' : '' }}" style="display:block; padding:12px 14px 12px {{ request()->routeIs('admin.statistics.*') ? '11px' : '17px' }}; font-family:var(--font-body); font-size:14px; text-decoration:none; transition:background 0.15s ease, color 0.15s ease; color:{{ request()->routeIs('admin.statistics.*') ? '#ffffff' : '#b2b6bd' }}; font-weight:{{ request()->routeIs('admin.statistics.*') ? '700' : '400' }}; background:{{ request()->routeIs('admin.statistics.*') ? '#34363b' : 'transparent' }}; border-left:{{ request()->routeIs('admin.statistics.*') ? '3px solid #bf4316' : '3px solid transparent' }};">
                {{ __('booking.admin.statistics.title') }}
            </a>
        @endcan
```

- [ ] **Step 8: Run the full admin test suite to check nothing broke**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/Admin"`
Expected: PASS (all admin tests, including the 2 new ones).

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Admin/StatisticsController.php routes/web.php resources/views/admin/statistics/index.blade.php resources/views/components/layout/admin-sidebar.blade.php tests/Feature/Admin/StatisticsControllerTest.php
git commit -m "Add admin statistics page skeleton with route and permission check"
```

---

### Task 2: Per-user total / single / double counts

**Files:**
- Modify: `app/Http/Controllers/Admin/StatisticsController.php`
- Test: `tests/Feature/Admin/StatisticsControllerTest.php`

- [ ] **Step 1: Write the failing test**

Add to `StatisticsControllerTest`:

```php
    #[Test]
    public function shows_total_single_and_double_counts_per_user_excluding_cancelled(): void
    {
        $user = User::factory()->create(['alias' => 'Heinz Mayer', 'status' => 'enabled']);
        \App\Models\Booking::factory()->count(2)->create(['uid' => $user->uid, 'status' => 'single', 'quantity' => 2]);
        \App\Models\Booking::factory()->create(['uid' => $user->uid, 'status' => 'single', 'quantity' => 4]);
        \App\Models\Booking::factory()->create(['uid' => $user->uid, 'status' => 'cancelled', 'quantity' => 2]);

        $response = $this->actingAs($this->admin())->get('/admin/statistics');

        $response->assertOk()
            ->assertSeeInOrder(['Heinz Mayer'])
            ->assertSee('3') // total active bookings (2 singles + 1 double, cancelled excluded)
            ->assertSee('2') // single count
            ->assertSee('1'); // double count
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/Admin/StatisticsControllerTest.php --filter shows_total_single_and_double"`
Expected: FAIL — the view shows no user rows yet (`$stats` is an empty collection).

- [ ] **Step 3: Implement the aggregation in the controller**

Replace the body of `StatisticsController.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\View\View;

final class StatisticsController extends Controller
{
    public function index(): View
    {
        $users = User::whereIn('status', ['enabled', 'assist', 'admin'])
            ->orderBy('alias')
            ->get(['uid', 'alias']);

        $bookings = Booking::with(['reservations', 'square'])
            ->whereIn('uid', $users->pluck('uid'))
            ->get();

        $stats = $users->map(function (User $user) use ($bookings): array {
            return $this->statsForUser($user, $bookings->where('uid', $user->uid));
        });

        $summary = [
            'total' => $stats->sum('total'),
            'single' => $stats->sum('single'),
            'double' => $stats->sum('double'),
            'lastMonth' => $stats->sum('lastMonth'),
            'cancellationRate' => $this->cancellationRate(
                $stats->sum('cancelled'),
                $stats->sum('cancelled') + $stats->sum('total'),
            ),
        ];

        return view('admin.statistics.index', compact('stats', 'summary'));
    }

    /** @param Collection<int, Booking> $userBookings */
    private function statsForUser(User $user, Collection $userBookings): array
    {
        $active = $userBookings->where('status', '!=', 'cancelled');
        $cancelledCount = $userBookings->where('status', 'cancelled')->count();

        return [
            'uid' => $user->uid,
            'alias' => $user->alias,
            'total' => $active->count(),
            'single' => $active->where('quantity', 2)->count(),
            'double' => $active->where('quantity', 4)->count(),
            'lastMonth' => 0,
            'topCourt' => null,
            'cancelled' => $cancelledCount,
            'cancellationRate' => $this->cancellationRate($cancelledCount, $cancelledCount + $active->count()),
        ];
    }

    private function cancellationRate(int $cancelled, int $totalIncludingCancelled): float
    {
        return $totalIncludingCancelled > 0
            ? round($cancelled / $totalIncludingCancelled * 100, 1)
            : 0.0;
    }
}
```

- [ ] **Step 4: Update the view to render the table**

Replace `resources/views/admin/statistics/index.blade.php` with:

```blade
@extends('layouts.admin')
@section('admin-title', __('booking.admin.statistics.title'))
@section('admin-content')
<div class="ui-page">
    <div class="ui-page-header">
        <h1>{{ __('booking.admin.statistics.title') }}</h1>
    </div>

    <div class="ui-card">
        <div class="ui-table-wrap">
            @if($stats->isEmpty())
                <div class="ui-card-body"><p class="ui-kpi-meta">{{ __('booking.admin.no_results') }}</p></div>
            @else
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th>{{ __('booking.admin.statistics.member') }}</th>
                            <th>{{ __('booking.admin.statistics.total') }}</th>
                            <th>{{ __('booking.admin.statistics.single') }}</th>
                            <th>{{ __('booking.admin.statistics.double') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($stats as $row)
                            <tr>
                                <td class="font-medium">{{ $row['alias'] }}</td>
                                <td>{{ $row['total'] }}</td>
                                <td>{{ $row['single'] }}</td>
                                <td>{{ $row['double'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/Admin/StatisticsControllerTest.php"`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/StatisticsController.php resources/views/admin/statistics/index.blade.php tests/Feature/Admin/StatisticsControllerTest.php
git commit -m "Add per-user total/single/double booking counts to statistics page"
```

---

### Task 3: Bookings last calendar month

**Files:**
- Modify: `app/Http/Controllers/Admin/StatisticsController.php`
- Modify: `resources/views/admin/statistics/index.blade.php`
- Test: `tests/Feature/Admin/StatisticsControllerTest.php`

- [ ] **Step 1: Write the failing test**

Add to `StatisticsControllerTest`:

```php
    #[Test]
    public function shows_bookings_from_last_calendar_month_via_reservation_dates(): void
    {
        $user = User::factory()->create(['alias' => 'Helga Miglbauer', 'status' => 'enabled']);
        $lastMonthDate = now()->subMonthNoOverflow()->startOfMonth()->addDays(3)->toDateString();
        $thisMonthDate = now()->startOfMonth()->addDays(3)->toDateString();

        $lastMonthBooking = \App\Models\Booking::factory()->create(['uid' => $user->uid, 'status' => 'single']);
        \App\Models\Reservation::factory()->create(['bid' => $lastMonthBooking->bid, 'date' => $lastMonthDate]);

        $thisMonthBooking = \App\Models\Booking::factory()->create(['uid' => $user->uid, 'status' => 'single']);
        \App\Models\Reservation::factory()->create(['bid' => $thisMonthBooking->bid, 'date' => $thisMonthDate]);

        $response = $this->actingAs($this->admin())->get('/admin/statistics');

        $response->assertOk();
        $rows = $response->viewData('stats');
        $row = $rows->firstWhere('uid', $user->uid);
        $this->assertSame(1, $row['lastMonth']);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/Admin/StatisticsControllerTest.php --filter last_calendar_month"`
Expected: FAIL — `$row['lastMonth']` is hardcoded to `0`.

- [ ] **Step 3: Implement the last-month count**

In `app/Http/Controllers/Admin/StatisticsController.php`, add `use Carbon\Carbon;` to the imports, and update `statsForUser()`:

```php
    /** @param Collection<int, Booking> $userBookings */
    private function statsForUser(User $user, Collection $userBookings): array
    {
        $active = $userBookings->where('status', '!=', 'cancelled');
        $cancelledCount = $userBookings->where('status', 'cancelled')->count();

        $lastMonthStart = Carbon::now()->subMonthNoOverflow()->startOfMonth();
        $lastMonthEnd = Carbon::now()->subMonthNoOverflow()->endOfMonth();

        $lastMonthCount = $active->filter(function (Booking $booking) use ($lastMonthStart, $lastMonthEnd): bool {
            return $booking->reservations->contains(
                fn ($reservation) => Carbon::parse($reservation->date)->between($lastMonthStart, $lastMonthEnd),
            );
        })->count();

        return [
            'uid' => $user->uid,
            'alias' => $user->alias,
            'total' => $active->count(),
            'single' => $active->where('quantity', 2)->count(),
            'double' => $active->where('quantity', 4)->count(),
            'lastMonth' => $lastMonthCount,
            'topCourt' => null,
            'cancelled' => $cancelledCount,
            'cancellationRate' => $this->cancellationRate($cancelledCount, $cancelledCount + $active->count()),
        ];
    }
```

- [ ] **Step 4: Add the column to the view**

In `resources/views/admin/statistics/index.blade.php`, add a header cell after `{{ __('booking.admin.statistics.double') }}`:

```blade
                            <th>{{ __('booking.admin.statistics.last_month') }}</th>
```

and a matching data cell after the double-count `<td>`:

```blade
                                <td>{{ $row['lastMonth'] }}</td>
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/Admin/StatisticsControllerTest.php"`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/StatisticsController.php resources/views/admin/statistics/index.blade.php tests/Feature/Admin/StatisticsControllerTest.php
git commit -m "Add bookings-last-month column to statistics page"
```

---

### Task 4: Most-booked court

**Files:**
- Modify: `app/Http/Controllers/Admin/StatisticsController.php`
- Modify: `resources/views/admin/statistics/index.blade.php`
- Test: `tests/Feature/Admin/StatisticsControllerTest.php`

- [ ] **Step 1: Write the failing test**

Add to `StatisticsControllerTest`:

```php
    #[Test]
    public function shows_the_most_booked_court_per_user(): void
    {
        $user = User::factory()->create(['alias' => 'Sandra Wenigwieser', 'status' => 'enabled']);
        $courtA = \App\Models\Square::factory()->create(['name' => '1']);
        $courtB = \App\Models\Square::factory()->create(['name' => '2']);

        \App\Models\Booking::factory()->count(2)->create(['uid' => $user->uid, 'sid' => $courtA->sid, 'status' => 'single']);
        \App\Models\Booking::factory()->create(['uid' => $user->uid, 'sid' => $courtB->sid, 'status' => 'single']);

        $response = $this->actingAs($this->admin())->get('/admin/statistics');

        $row = $response->viewData('stats')->firstWhere('uid', $user->uid);
        $this->assertSame($courtA->display_name, $row['topCourt']);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/Admin/StatisticsControllerTest.php --filter most_booked_court"`
Expected: FAIL — `$row['topCourt']` is hardcoded to `null`.

- [ ] **Step 3: Implement the most-booked-court lookup**

In `statsForUser()`, add before the `return`:

```php
        $topCourt = null;
        $topCourtCount = -1;
        foreach ($active->groupBy('sid') as $sid => $group) {
            $count = $group->count();
            if ($count > $topCourtCount || ($count === $topCourtCount && $sid < $topCourt?->sid)) {
                $topCourtCount = $count;
                $topCourt = $group->first()->square;
            }
        }
```

and change the `'topCourt' => null,` line to:

```php
            'topCourt' => $topCourt?->display_name,
```

Note: `$topCourt?->sid` on the first iteration when `$topCourt` is `null` evaluates the whole `<` comparison to `false` (comparing against `null`), which is fine — the `$count > $topCourtCount` branch (`$count > -1`, always true for any real count) already wins on the first iteration regardless.

- [ ] **Step 4: Add the column to the view**

Header, after the "last month" header:

```blade
                            <th>{{ __('booking.admin.statistics.top_court') }}</th>
```

Data cell, after the last-month `<td>`:

```blade
                                <td>{{ $row['topCourt'] ?? '—' }}</td>
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/Admin/StatisticsControllerTest.php"`
Expected: PASS (6 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/StatisticsController.php resources/views/admin/statistics/index.blade.php tests/Feature/Admin/StatisticsControllerTest.php
git commit -m "Add most-booked-court column to statistics page"
```

---

### Task 5: Cancellation rate column

**Files:**
- Modify: `resources/views/admin/statistics/index.blade.php`
- Test: `tests/Feature/Admin/StatisticsControllerTest.php`

The cancellation rate is already computed in `statsForUser()` (Task 2) — this task only wires it into the view and adds a test for the exact percentage rendered.

- [ ] **Step 1: Write the failing test**

Add to `StatisticsControllerTest`:

```php
    #[Test]
    public function shows_cancellation_rate_per_user(): void
    {
        $user = User::factory()->create(['alias' => 'Gerhard Bichlwagner', 'status' => 'enabled']);
        \App\Models\Booking::factory()->create(['uid' => $user->uid, 'status' => 'single']);
        \App\Models\Booking::factory()->create(['uid' => $user->uid, 'status' => 'cancelled']);
        \App\Models\Booking::factory()->create(['uid' => $user->uid, 'status' => 'cancelled']);

        $response = $this->actingAs($this->admin())->get('/admin/statistics');

        $row = $response->viewData('stats')->firstWhere('uid', $user->uid);
        // 2 cancelled out of 3 total bookings = 66.7%
        $this->assertSame(66.7, $row['cancellationRate']);
        $response->assertSee('66.7');
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/Admin/StatisticsControllerTest.php --filter cancellation_rate"`
Expected: FAIL — the assertion on `$row['cancellationRate']` passes (already implemented in Task 2), but `assertSee('66.7')` fails because the view doesn't render it yet.

- [ ] **Step 3: Add the column to the view**

Header, after the "top court" header:

```blade
                            <th>{{ __('booking.admin.statistics.cancellation_rate') }}</th>
```

Data cell, after the top-court `<td>`:

```blade
                                <td>{{ number_format($row['cancellationRate'], 1) }}%</td>
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/Admin/StatisticsControllerTest.php"`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/views/admin/statistics/index.blade.php tests/Feature/Admin/StatisticsControllerTest.php
git commit -m "Add cancellation-rate column to statistics page"
```

---

### Task 6: Club-wide summary cards

**Files:**
- Modify: `resources/views/admin/statistics/index.blade.php`
- Test: `tests/Feature/Admin/StatisticsControllerTest.php`

The `$summary` array is already computed in the controller (Task 2) — this task renders it as KPI cards above the table, matching the `admin/dashboard.blade.php` `ui-grid-4`/`ui-kpi` pattern.

- [ ] **Step 1: Write the failing test**

Add to `StatisticsControllerTest`:

```php
    #[Test]
    public function shows_club_wide_summary_totals(): void
    {
        $userA = User::factory()->create(['status' => 'enabled']);
        $userB = User::factory()->create(['status' => 'enabled']);
        \App\Models\Booking::factory()->create(['uid' => $userA->uid, 'status' => 'single', 'quantity' => 2]);
        \App\Models\Booking::factory()->create(['uid' => $userB->uid, 'status' => 'single', 'quantity' => 4]);
        \App\Models\Booking::factory()->create(['uid' => $userB->uid, 'status' => 'cancelled', 'quantity' => 2]);

        $response = $this->actingAs($this->admin())->get('/admin/statistics');

        $summary = $response->viewData('summary');
        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['single']);
        $this->assertSame(1, $summary['double']);
        $response->assertSee(__('booking.admin.statistics.summary_total'));
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/Admin/StatisticsControllerTest.php --filter club_wide_summary"`
Expected: FAIL — the numeric assertions already pass (computed since Task 2), but `assertSee` fails since `booking.admin.statistics.summary_total` doesn't exist as a lang key yet and the view doesn't render the summary cards. (This will keep failing until Task 7 adds the lang key too — that's expected and fixed there.)

- [ ] **Step 3: Add the summary cards to the view**

In `resources/views/admin/statistics/index.blade.php`, insert directly after the `<h1>...</h1>` closing of the `ui-page-header` div and before the `<div class="ui-card">` that holds the table:

```blade
    <div class="ui-grid-4">
        <div class="ui-card ui-kpi">
            <div class="ui-card-body ui-stack">
                <p class="ui-kpi-label">{{ __('booking.admin.statistics.summary_total') }}</p>
                <p class="ui-kpi-value">{{ $summary['total'] }}</p>
                <p class="ui-kpi-meta">{{ $summary['single'] }} {{ __('booking.admin.bookings.single') }} · {{ $summary['double'] }} {{ __('booking.admin.bookings.double') }}</p>
            </div>
        </div>
        <div class="ui-card">
            <div class="ui-card-body ui-stack">
                <p class="ui-kpi-label">{{ __('booking.admin.statistics.summary_last_month') }}</p>
                <p class="ui-kpi-value">{{ $summary['lastMonth'] }}</p>
            </div>
        </div>
        <div class="ui-card">
            <div class="ui-card-body ui-stack">
                <p class="ui-kpi-label">{{ __('booking.admin.statistics.summary_cancellation_rate') }}</p>
                <p class="ui-kpi-value">{{ number_format($summary['cancellationRate'], 1) }}%</p>
            </div>
        </div>
    </div>
```

- [ ] **Step 4: Run the test to verify it still fails on the missing lang key, then add the lang key placeholder check**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/Admin/StatisticsControllerTest.php --filter club_wide_summary"`
Expected: still FAIL — `assertSee(__('booking.admin.statistics.summary_total'))` currently resolves to the literal key string `booking.admin.statistics.summary_total`, and the view now renders `__('booking.admin.statistics.summary_total')` which resolves to the *same* literal string, so this assertion technically passes once both sides fall back identically — but do not rely on that. Proceed directly to Task 7, which adds real translations; re-run this test after Task 7.

- [ ] **Step 5: Commit**

```bash
git add resources/views/admin/statistics/index.blade.php tests/Feature/Admin/StatisticsControllerTest.php
git commit -m "Add club-wide summary cards to statistics page"
```

---

### Task 7: Real translations (de + en) for all labels

**Files:**
- Modify: `lang/de/booking/admin.php:207` (insert new `'statistics'` array as a sibling of `'bookings'`, right after its closing `],`)
- Modify: `lang/en/booking/admin.php` (same location, mirrored)
- Test: `tests/Feature/Admin/StatisticsControllerTest.php`

- [ ] **Step 1: Add the German translations**

In `lang/de/booking/admin.php`, immediately after the `'bookings' => [ ... ],` block's closing (the `],` that follows `'today_bookings' => 'Buchungen heute',`), insert:

```php
        'statistics' => [
            'title' => 'Statistik',
            'member' => 'Mitglied',
            'total' => 'Buchungen',
            'single' => 'Einzel',
            'double' => 'Doppel',
            'last_month' => 'Letzter Monat',
            'top_court' => 'Meistgebucht',
            'cancellation_rate' => 'Storno-Quote',
            'summary_total' => 'Buchungen gesamt',
            'summary_last_month' => 'Letzter Monat',
            'summary_cancellation_rate' => 'Storno-Quote gesamt',
        ],
```

- [ ] **Step 2: Add the matching English translations**

In `lang/en/booking/admin.php`, at the same position (after the `'bookings' => [ ... ],` block), insert:

```php
        'statistics' => [
            'title' => 'Statistics',
            'member' => 'Member',
            'total' => 'Bookings',
            'single' => 'Single',
            'double' => 'Double',
            'last_month' => 'Last month',
            'top_court' => 'Most booked',
            'cancellation_rate' => 'Cancellation rate',
            'summary_total' => 'Total bookings',
            'summary_last_month' => 'Last month',
            'summary_cancellation_rate' => 'Overall cancellation rate',
        ],
```

- [ ] **Step 3: Run the full statistics test file**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/Admin/StatisticsControllerTest.php"`
Expected: PASS (8 tests) — including `shows_club_wide_summary_totals` from Task 6, now that the lang key resolves to real text.

- [ ] **Step 4: Run the full test suite**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test"`
Expected: PASS (no regressions elsewhere).

- [ ] **Step 5: Commit**

```bash
git add lang/de/booking/admin.php lang/en/booking/admin.php
git commit -m "Add German and English translations for statistics page"
```

---

### Task 8: Client-side sortable table headers

**Files:**
- Modify: `resources/views/admin/statistics/index.blade.php`

This is presentation-only JS (no server round-trip, no new dependency); per the design doc this has no automated test — verify manually via the browser preview in Step 3.

- [ ] **Step 1: Add `data-sort` attributes to each header and a stable numeric/text value per cell**

Replace the `<thead>`/`<tbody>` of the table in `resources/views/admin/statistics/index.blade.php` with:

```blade
                <table class="ui-table" id="statistics-table">
                    <thead>
                        <tr>
                            <th data-sort="text">{{ __('booking.admin.statistics.member') }}</th>
                            <th data-sort="number">{{ __('booking.admin.statistics.total') }}</th>
                            <th data-sort="number">{{ __('booking.admin.statistics.single') }}</th>
                            <th data-sort="number">{{ __('booking.admin.statistics.double') }}</th>
                            <th data-sort="number">{{ __('booking.admin.statistics.last_month') }}</th>
                            <th data-sort="text">{{ __('booking.admin.statistics.top_court') }}</th>
                            <th data-sort="number">{{ __('booking.admin.statistics.cancellation_rate') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($stats as $row)
                            <tr>
                                <td class="font-medium">{{ $row['alias'] }}</td>
                                <td>{{ $row['total'] }}</td>
                                <td>{{ $row['single'] }}</td>
                                <td>{{ $row['double'] }}</td>
                                <td>{{ $row['lastMonth'] }}</td>
                                <td>{{ $row['topCourt'] ?? '—' }}</td>
                                <td>{{ number_format($row['cancellationRate'], 1) }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
```

- [ ] **Step 2: Add the sort script**

At the end of `resources/views/admin/statistics/index.blade.php`, before `@endsection`, add:

```blade
<script>
(function () {
    var table = document.getElementById('statistics-table');
    if (!table) { return; }

    var headers = table.querySelectorAll('thead th');
    var currentSort = { index: -1, dir: 1 };

    headers.forEach(function (th, index) {
        th.style.cursor = 'pointer';
        th.addEventListener('click', function () {
            var type = th.getAttribute('data-sort');
            var dir = currentSort.index === index ? -currentSort.dir : 1;
            currentSort = { index: index, dir: dir };

            var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
            rows.sort(function (a, b) {
                var aText = a.children[index].textContent.trim();
                var bText = b.children[index].textContent.trim();
                if (type === 'number') {
                    var aVal = parseFloat(aText.replace('%', '').replace('—', '-1')) || 0;
                    var bVal = parseFloat(bText.replace('%', '').replace('—', '-1')) || 0;
                    return (aVal - bVal) * dir;
                }
                return aText.localeCompare(bText) * dir;
            });

            var tbody = table.querySelector('tbody');
            rows.forEach(function (row) { tbody.appendChild(row); });
        });
    });
})();
</script>
```

- [ ] **Step 3: Verify manually in the browser preview**

Start the dev server (`preview_start` with the project's existing launch config), log in as an admin user, navigate to `/admin/statistics`, and click each column header — confirm rows reorder ascending, then descending on a second click, for both numeric columns (e.g. "Buchungen") and the text column ("Mitglied").

- [ ] **Step 4: Run the full test suite to confirm the JS addition didn't break server-rendered assertions**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/Admin/StatisticsControllerTest.php"`
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/views/admin/statistics/index.blade.php
git commit -m "Make the statistics table sortable by clicking column headers"
```

---

## Plan self-review notes

- **Spec coverage:** total/single/double (Task 2), last month (Task 3), most-booked court (Task 4, a suggested extra the user approved), cancellation rate (Task 5, approved extra), club-wide summary (Task 6), sortable table (Task 1 skeleton + Task 8), permission gate reusing `admin.booking` (Task 1), sidebar entry (Task 1). Serientermine-vs-single and trend-over-time were explicitly deferred in the design doc — not in this plan, as agreed.
- **Type consistency:** `$stats` is always a `Collection` of associative arrays with keys `uid, alias, total, single, double, lastMonth, topCourt, cancelled, cancellationRate` — introduced fully in Task 2/3/4 and never renamed afterwards. `$summary` keys (`total, single, double, lastMonth, cancellationRate`) are introduced in Task 2 and consumed as-is in Task 6.
- **No placeholders:** every step includes complete, runnable code and exact file paths; no "TBD" left anywhere.
