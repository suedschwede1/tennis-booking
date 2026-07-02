# Admin Mode Toggle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let admins (`can('admin.booking')`) toggle whether the calendar shows them admin booking forms or the normal member forms, with the choice persisted in a cookie (default: off).

**Architecture:** A cookie `admin_mode` (`'1'`/`'0'`/absent) gates a single existing boolean, `CalendarController::$isAdmin`, which already drives every admin-vs-member branch in `grid.blade.php`. A new `GET /admin-mode/{state}` route (guarded by `auth` + `can:admin.booking`) sets the cookie and redirects back. A header button toggles it.

**Tech Stack:** Laravel 11 (PHP), Blade, PHPUnit (`php artisan test`, run via WSL per project convention — never call `php`/`composer` directly from PowerShell).

---

### Task 1: Except `admin_mode` from cookie encryption

**Files:**
- Modify: `bootstrap/app.php`

- [ ] **Step 1: Update the except list**

Current content of `bootstrap/app.php`:

```php
<?php

use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['locale']);
        $middleware->web(append: [SetLocale::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
```

Change the `encryptCookies` line to:

```php
        $middleware->encryptCookies(except: ['locale', 'admin_mode']);
```

- [ ] **Step 2: Sanity check**

Run: `wsl -e bash -lc "cd /mnt/c/development/bookingnew && php -l bootstrap/app.php"`
Expected: "No syntax errors detected"

- [ ] **Step 3: Commit**

```bash
git add bootstrap/app.php
git commit -m "Except admin_mode cookie from encryption"
```

---

### Task 2: `AdminModeController` + toggle route

**Files:**
- Create: `app/Http/Controllers/AdminModeController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/AdminModeControllerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AdminModeControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminModeControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admin_can_switch_admin_mode_on(): void
    {
        $admin = User::factory()->create(['status' => 'admin']);

        $this->actingAs($admin)->from('/calendar')
            ->get('/admin-mode/on')
            ->assertRedirect('/calendar')
            ->assertPlainCookie('admin_mode', '1');
    }

    #[Test]
    public function admin_can_switch_admin_mode_off(): void
    {
        $admin = User::factory()->create(['status' => 'admin']);

        $this->actingAs($admin)->from('/calendar')
            ->get('/admin-mode/off')
            ->assertRedirect('/calendar')
            ->assertPlainCookie('admin_mode', '0');
    }

    #[Test]
    public function invalid_state_returns_404(): void
    {
        $admin = User::factory()->create(['status' => 'admin']);

        $this->actingAs($admin)->get('/admin-mode/maybe')->assertNotFound();
    }

    #[Test]
    public function non_admin_cannot_switch_admin_mode(): void
    {
        $member = User::factory()->create(['status' => 'enabled']);

        $this->actingAs($member)->get('/admin-mode/on')->assertForbidden();
    }

    #[Test]
    public function guest_is_redirected_to_login(): void
    {
        $this->get('/admin-mode/on')->assertRedirect('/login');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=AdminModeControllerTest"`
Expected: FAIL — route `/admin-mode/{state}` doesn't exist yet (404 for all, including the ones expecting a redirect/403).

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/AdminModeController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

/**
 * Toggles whether the calendar shows the acting admin the admin booking
 * forms or the normal member forms (see CalendarController::index()).
 * Route access is already restricted to admin.booking holders via
 * middleware, so no further permission check happens here.
 */
final class AdminModeController extends Controller
{
    private const COOKIE_MINUTES = 60 * 24 * 365;

    public function set(string $state): RedirectResponse
    {
        return redirect()->back()->withCookie(
            cookie('admin_mode', $state === 'on' ? '1' : '0', self::COOKIE_MINUTES)
        );
    }
}
```

- [ ] **Step 4: Add the route**

Modify `routes/web.php`. Add the import to the `use` block (alphabetically, after `App\Http\Controllers\Admin\UserController`... actually it's a top-level controller like `LocaleController`, so place it alphabetically among the top-level `App\Http\Controllers\*` imports, right before `use App\Http\Controllers\Auth\LoginController;`):

```php
use App\Http\Controllers\AdminModeController;
```

Add the route directly after the `Route::get('/lang/{locale}', ...)` line:

```php
Route::get('/admin-mode/{state}', [AdminModeController::class, 'set'])
    ->whereIn('state', ['on', 'off'])
    ->middleware(['auth', 'can:admin.booking'])
    ->name('admin-mode.set');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `wsl -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=AdminModeControllerTest"`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/AdminModeController.php routes/web.php tests/Feature/AdminModeControllerTest.php
git commit -m "Add AdminModeController and GET /admin-mode/{state} toggle route"
```

---

### Task 3: Gate `CalendarController::$isAdmin` on the cookie

**Files:**
- Modify: `app/Http/Controllers/CalendarController.php:94`
- Modify: `tests/Feature/CalendarControllerTest.php` (update 2 existing tests, add 3 new ones)

- [ ] **Step 1: Update the two existing admin-booking tests to opt into admin mode**

These two tests currently rely on `$isAdmin` being `true` just from `can('admin.booking')`; once Step 3 changes that, they need the cookie too. Read `tests/Feature/CalendarControllerTest.php` first to confirm the exact current text around lines 302 and 323 (it may have shifted), then apply these two changes:

In `admin_clickable_booking_contains_edit_url_for_popup`, change:

```php
        $this->actingAs($admin)->get('/calendar?date='.$targetDate->format('Y-m-d'))
            ->assertOk()
            ->assertSee('data-edit-url', false)
            ->assertSee('/admin/bookings/'.$booking->bid.'/edit', false);
    }

    #[Test]
    public function admin_booking_popup_contains_delete_action(): void
```

to:

```php
        $this->actingAs($admin)->withUnencryptedCookie('admin_mode', '1')->get('/calendar?date='.$targetDate->format('Y-m-d'))
            ->assertOk()
            ->assertSee('data-edit-url', false)
            ->assertSee('/admin/bookings/'.$booking->bid.'/edit', false);
    }

    #[Test]
    public function admin_booking_popup_contains_delete_action(): void
```

And in `admin_booking_popup_contains_delete_action`, change:

```php
        $this->actingAs($admin)->get('/calendar?date='.$targetDate->format('Y-m-d'))
            ->assertOk()
            ->assertSee('data-delete-url', false)
            ->assertSee('/admin/bookings/'.$booking->bid, false);
    }
```

to:

```php
        $this->actingAs($admin)->withUnencryptedCookie('admin_mode', '1')->get('/calendar?date='.$targetDate->format('Y-m-d'))
            ->assertOk()
            ->assertSee('data-delete-url', false)
            ->assertSee('/admin/bookings/'.$booking->bid, false);
    }
```

(`admin_can_open_existing_event_from_calendar` is unaffected — it exercises `admin.event`, a separate permission from `admin.booking`, and doesn't need a change.)

- [ ] **Step 2: Add three new failing tests**

Add these `#[Test]` methods to `tests/Feature/CalendarControllerTest.php`, anywhere inside the class alongside the others:

```php
    #[Test]
    public function admin_without_admin_mode_cookie_sees_normal_booking_form_trigger(): void
    {
        $admin = User::factory()->create(['status' => 'admin']);
        $square = Square::factory()->create();

        $this->actingAs($admin)->get('/calendar?date='.Carbon::tomorrow()->format('Y-m-d'))
            ->assertOk()
            ->assertDontSee('data-action="admin-book"', false)
            ->assertDontSee(route('admin.bookings.create'), false);
    }

    #[Test]
    public function admin_with_admin_mode_cookie_sees_admin_booking_form_trigger(): void
    {
        $admin = User::factory()->create(['status' => 'admin']);
        $square = Square::factory()->create();

        $this->actingAs($admin)->withUnencryptedCookie('admin_mode', '1')
            ->get('/calendar?date='.Carbon::tomorrow()->format('Y-m-d'))
            ->assertOk()
            ->assertSee('data-action="admin-book"', false);
    }

    #[Test]
    public function non_admin_cannot_bypass_permission_via_admin_mode_cookie(): void
    {
        $member = User::factory()->create(['status' => 'enabled']);
        $square = Square::factory()->create();

        $this->actingAs($member)->withUnencryptedCookie('admin_mode', '1')
            ->get('/calendar?date='.Carbon::tomorrow()->format('Y-m-d'))
            ->assertOk()
            ->assertDontSee('data-action="admin-book"', false);
    }
```

- [ ] **Step 3: Run tests to verify the new ones fail and confirm the updated ones still pass**

Run: `wsl -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=CalendarControllerTest"`
Expected: the 3 new tests FAIL (current code has no `admin_mode` cookie check, so `$isAdmin` is `true` for any admin regardless of cookie — `admin_without_admin_mode_cookie_sees_normal_booking_form_trigger` and `non_admin_cannot_bypass_permission_via_admin_mode_cookie` fail because admin-book IS shown). The two updated tests and everything else should already pass.

- [ ] **Step 4: Make the change**

Read `app/Http/Controllers/CalendarController.php` around line 94 to confirm exact current text, then change:

```php
        $isAdmin = $isLoggedIn && $authUser->can('admin.booking');
```

to:

```php
        $isAdmin = $isLoggedIn && $authUser->can('admin.booking') && $request->cookie('admin_mode') === '1';
```

(`$request` is already the method's `Request $request` parameter — confirm this by checking the `index(Request $request)` signature at the top of the method; it is already in scope, no new parameter needed.)

- [ ] **Step 5: Run tests to verify everything passes**

Run: `wsl -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=CalendarControllerTest"`
Expected: PASS — all tests in the file, including the 3 new ones and the 2 updated ones.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/CalendarController.php tests/Feature/CalendarControllerTest.php
git commit -m "Gate admin booking forms on the admin_mode cookie, default off"
```

---

### Task 4: Header toggle button + translations

**Files:**
- Modify: `lang/de/booking/public.php`
- Modify: `lang/en/booking/public.php`
- Modify: `resources/views/components/layout/header.blade.php`
- Test: add a method to `tests/Feature/CalendarControllerTest.php`

- [ ] **Step 1: Add translation keys**

In `lang/de/booking/public.php`, inside the `'nav' => [ ... ]` array, add two keys. Current relevant lines:

```php
        'actions' => 'Aktionen',
        'more_actions' => 'Mehr Aktionen',
        'login' => 'Anmelden',
```

Change to:

```php
        'actions' => 'Aktionen',
        'more_actions' => 'Mehr Aktionen',
        'login' => 'Anmelden',
        'admin_mode_on' => 'Admin-Modus einschalten',
        'admin_mode_off' => 'Admin-Modus ausschalten',
```

In `lang/en/booking/public.php`, the equivalent lines:

```php
        'actions' => 'Actions',
        'more_actions' => 'More actions',
        'login' => 'Log In',
```

Change to:

```php
        'actions' => 'Actions',
        'more_actions' => 'More actions',
        'login' => 'Log In',
        'admin_mode_on' => 'Turn on admin mode',
        'admin_mode_off' => 'Turn off admin mode',
```

(Read both files first to confirm the exact surrounding lines before editing, in case the array has additional keys inserted since — insert the two new lines anywhere inside the `nav` array, this exact placement is not load-bearing.)

- [ ] **Step 2: Write the failing test**

Add to `tests/Feature/CalendarControllerTest.php`:

```php
    #[Test]
    public function admin_mode_toggle_button_shown_only_to_admins(): void
    {
        $admin = User::factory()->create(['status' => 'admin']);
        $member = User::factory()->create(['status' => 'enabled']);

        $this->actingAs($admin)->get('/calendar')
            ->assertOk()
            ->assertSee(route('admin-mode.set', ['state' => 'on']), false);

        $this->actingAs($member)->get('/calendar')
            ->assertOk()
            ->assertDontSee('admin-mode', false);
    }
```

Run: `wsl -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=admin_mode_toggle_button_shown_only_to_admins"`
Expected: FAIL — no such button exists in the header yet.

- [ ] **Step 3: Add the button to the header**

Read `resources/views/components/layout/header.blade.php` first (it's short). Find the closing of the `@auth ... @else ... @endauth` block inside `#app-header-actions` (the login/logout + my-bookings/my-account/admin-dashboard links), and insert the new button as a sibling immediately after that `@endauth`, still inside the `#app-header-actions` div and before its closing `</div>`. The button must be wrapped in `@can('admin.booking')`:

```blade
                @can('admin.booking')
                    @php $adminModeOn = request()->cookie('admin_mode') === '1'; @endphp
                    <a href="{{ route('admin-mode.set', ['state' => $adminModeOn ? 'off' : 'on']) }}"
                       class="inline-flex h-8 items-center rounded-[6px] border border-[#d4cec3] bg-white px-4 text-[13px] font-medium text-[#6a6e73] transition-colors hover:border-[#bf4316] hover:text-[#bf4316]">{{ $adminModeOn ? __('booking.nav.admin_mode_off') : __('booking.nav.admin_mode_on') }}</a>
                @endcan
```

Place it right after `@endauth` and before the locale-switcher `<div class="app-header__locale ...">` block that's already last in that container (from the earlier locale-switching feature) — i.e., the admin-mode button sits between the auth links and the locale switcher, both still inside `#app-header-actions`.

- [ ] **Step 4: Run test to verify it passes**

Run: `wsl -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=CalendarControllerTest"`
Expected: PASS — all tests in the file, including the new one.

- [ ] **Step 5: Commit**

```bash
git add lang/de/booking/public.php lang/en/booking/public.php resources/views/components/layout/header.blade.php tests/Feature/CalendarControllerTest.php
git commit -m "Add admin-mode toggle button to the header"
```

---

### Task 5: Full regression run

**Files:** none (verification only)

- [ ] **Step 1: Run the full test suite**

Run: `wsl -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test"`
Expected: PASS on every locale/admin-mode-related test; any pre-existing unrelated failures (from concurrent work elsewhere in the repo, if any) are not introduced by this change — compare the failure count/names against a baseline run before Task 1 if anything is red.

- [ ] **Step 2: Manual smoke test in the browser**

Log in as an admin. On `/calendar`, confirm no admin-book styling/behavior appears by default (clicking a free slot opens the normal member booking dialog, not the admin popup). Click "Admin-Modus einschalten" in the header, confirm redirect back to `/calendar` and the button now reads "Admin-Modus ausschalten"; click a free slot and confirm the admin popup (with player/owner/repeat fields) now opens. Click an existing booking owned by someone else and confirm it's editable. Toggle back off and confirm someone else's booking is now read-only (no click) while your own booking still shows the normal cancel dialog.
