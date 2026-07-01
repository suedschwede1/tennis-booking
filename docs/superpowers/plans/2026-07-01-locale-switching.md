# Language Switching (de/en) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let users switch the UI language between German and English, with the choice persisted per-user (for logged-in members) and via cookie (for guests / as fallback).

**Architecture:** A `SetLocale` middleware in the `web` group resolves the active locale each request (user meta → cookie → config default) and calls `App::setLocale()`. A `GET /lang/{locale}` route persists a new choice (meta + cookie) and redirects back. A small header UI exposes the switch links. Hardcoded `lang="de"` attributes in Blade layouts are replaced with the dynamic locale.

**Tech Stack:** Laravel 11 (PHP), Blade, PHPUnit (`php artisan test`, run via WSL per project convention — never call `php`/`composer` directly from PowerShell).

---

### Task 1: Add `available_locales` config

**Files:**
- Modify: `config/app.php:81-85`

- [ ] **Step 1: Add the config entry**

In `config/app.php`, right after the `fallback_locale` line:

```php
    'locale' => env('APP_LOCALE', 'de'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'de'),

    /*
    |--------------------------------------------------------------------------
    | Available Locales
    |--------------------------------------------------------------------------
    |
    | The set of locales the language switcher (App\Http\Middleware\SetLocale,
    | App\Http\Controllers\LocaleController) will accept. Any locale not in
    | this list is rejected and the app falls back to the default 'locale'.
    |
    */

    'available_locales' => ['de', 'en'],

    'faker_locale' => env('APP_FAKER_LOCALE', 'de_AT'),
```

- [ ] **Step 2: Commit**

```bash
git add config/app.php
git commit -m "Add config('app.available_locales') for the language switcher"
```

---

### Task 2: `SetLocale` middleware

**Files:**
- Create: `app/Http/Middleware/SetLocale.php`
- Test: `tests/Feature/SetLocaleMiddlewareTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SetLocaleMiddlewareTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SetLocaleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function defaults_to_config_locale_with_no_cookie_or_user_preference(): void
    {
        $this->get('/calendar')->assertSee('lang="de"', false);
    }

    #[Test]
    public function cookie_overrides_the_default_locale(): void
    {
        $this->withUnencryptedCookie('locale', 'en')
            ->get('/calendar')
            ->assertSee('lang="en"', false);
    }

    #[Test]
    public function invalid_cookie_value_is_ignored(): void
    {
        $this->withUnencryptedCookie('locale', 'fr')
            ->get('/calendar')
            ->assertSee('lang="de"', false);
    }

    #[Test]
    public function authenticated_user_preference_overrides_the_cookie(): void
    {
        $user = User::factory()->create();
        $user->setMeta('locale', 'en');

        $this->actingAs($user)
            ->withUnencryptedCookie('locale', 'de')
            ->get('/calendar')
            ->assertSee('lang="en"', false);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run (via WSL): `wsl -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=SetLocaleMiddlewareTest"`

Expected: FAIL — all four assertions fail because `layouts/app.blade.php` still renders `lang="de"` unconditionally, so the `en` assertions fail (and there's no middleware to read the cookie/meta yet).

- [ ] **Step 3: Write the middleware**

Create `app/Http/Middleware/SetLocale.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active UI locale for the request: authenticated user's
 * stored preference (bs_users_meta 'locale') > 'locale' cookie > config
 * default. Unknown/invalid values are ignored at each step.
 */
final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $available */
        $available = config('app.available_locales', ['de']);

        $locale = $this->fromUser($request, $available)
            ?? $this->fromCookie($request, $available)
            ?? (string) config('app.locale');

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }

    private function fromUser(Request $request, array $available): ?string
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            return null;
        }

        $locale = $user->getMeta('locale');

        return $locale !== null && in_array($locale, $available, true) ? $locale : null;
    }

    private function fromCookie(Request $request, array $available): ?string
    {
        $locale = $request->cookie('locale');

        return $locale !== null && in_array($locale, $available, true) ? $locale : null;
    }
}
```

- [ ] **Step 4: Register the middleware in the `web` group**

Modify `bootstrap/app.php`:

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
        $middleware->web(append: [SetLocale::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
```

- [ ] **Step 5: Fix the hardcoded `lang="de"` in `layouts/app.blade.php`**

The test targets `/calendar`, which renders `layouts/app.blade.php`. Modify `resources/views/layouts/app.blade.php:2`:

```blade
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
```

(replacing the current `<html lang="de">`)

- [ ] **Step 6: Run test to verify it passes**

Run: `wsl -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=SetLocaleMiddlewareTest"`

Expected: PASS (4 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Middleware/SetLocale.php bootstrap/app.php resources/views/layouts/app.blade.php tests/Feature/SetLocaleMiddlewareTest.php
git commit -m "Add SetLocale middleware resolving locale from user meta, cookie, or default"
```

---

### Task 3: `LocaleController` + switch route

**Files:**
- Create: `app/Http/Controllers/LocaleController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/LocaleControllerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LocaleControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LocaleControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function switching_to_a_valid_locale_sets_the_cookie_and_redirects_back(): void
    {
        $this->from('/calendar')
            ->get('/lang/en')
            ->assertRedirect('/calendar')
            ->assertCookie('locale', 'en');
    }

    #[Test]
    public function switching_to_an_invalid_locale_returns_404(): void
    {
        $this->get('/lang/fr')->assertNotFound();
    }

    #[Test]
    public function authenticated_user_switch_is_persisted_to_meta(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from('/calendar')->get('/lang/en');

        $this->assertSame('en', $user->getMeta('locale'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=LocaleControllerTest"`

Expected: FAIL — route `/lang/{locale}` doesn't exist yet (404/`RouteNotFoundException` for all three, first two fail on redirect/cookie assertions, third fails because nothing is persisted).

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/LocaleController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Switches the active UI language. Persists the choice to bs_users_meta for
 * authenticated users and to a long-lived cookie for everyone (guests rely
 * on the cookie alone; it also seeds the choice before first login).
 */
final class LocaleController extends Controller
{
    private const COOKIE_MINUTES = 60 * 24 * 365;

    public function switch(Request $request, string $locale): RedirectResponse
    {
        $available = config('app.available_locales', ['de']);
        if (! in_array($locale, $available, true)) {
            throw new NotFoundHttpException();
        }

        if ($request->user() !== null) {
            $request->user()->setMeta('locale', $locale);
        }

        return redirect()->back()->withCookie(
            cookie('locale', $locale, self::COOKIE_MINUTES)
        );
    }
}
```

- [ ] **Step 4: Add the route**

Modify `routes/web.php` — add near the top, alongside the other unauthenticated routes (after the `register` routes, before `Route::get('/', ...)`):

```php
use App\Http\Controllers\LocaleController;
```

(add to the `use` block at the top, alphabetically after `LoginController`)

```php
Route::get('/lang/{locale}', [LocaleController::class, 'switch'])->name('lang.switch');
```

(add directly after the `Route::post('/register', ...)` line, before `Route::get('/', ...)`)

- [ ] **Step 5: Run test to verify it passes**

Run: `wsl -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=LocaleControllerTest"`

Expected: PASS (3 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/LocaleController.php routes/web.php tests/Feature/LocaleControllerTest.php
git commit -m "Add LocaleController and GET /lang/{locale} switch route"
```

---

### Task 4: Header UI switcher

**Files:**
- Modify: `resources/views/components/layout/header.blade.php`
- Test: `tests/Feature/CalendarControllerTest.php` (new test method)

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/CalendarControllerTest.php` (inside the `CalendarControllerTest` class, alongside the existing `#[Test]` methods):

```php
    #[Test]
    public function calendar_header_shows_locale_switch_links(): void
    {
        $this->get('/calendar')
            ->assertOk()
            ->assertSee('href="'.route('lang.switch', ['locale' => 'en']).'"', false)
            ->assertDontSee('href="'.route('lang.switch', ['locale' => 'de']).'"', false);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=calendar_header_shows_locale_switch_links"`

Expected: FAIL — no such links exist in the header yet.

- [ ] **Step 3: Add the switcher to the header**

Modify `resources/views/components/layout/header.blade.php` — insert as the first child of the `app-header__actions` div (right after the opening `<div id="app-header-actions" ...>` tag on line 66):

```blade
            <div id="app-header-actions" class="app-header__actions ml-auto flex shrink-0 items-center gap-2" :class="{ 'is-open': mobileMenuOpen }">
                <div class="app-header__locale flex items-center gap-1 text-[13px] font-medium text-[#6a6e73]">
                    @foreach(config('app.available_locales') as $loc)
                        @if(! $loop->first)
                            <span aria-hidden="true">|</span>
                        @endif
                        @if($loc === app()->getLocale())
                            <span class="font-bold text-[#151515]">{{ strtoupper($loc) }}</span>
                        @else
                            <a href="{{ route('lang.switch', ['locale' => $loc]) }}" class="hover:text-[#bf4316]">{{ strtoupper($loc) }}</a>
                        @endif
                    @endforeach
                </div>
```

(the rest of the `app-header__actions` block — `@hasSection('calendar-system-info')` onward — stays unchanged, just now nested after this new `div`)

- [ ] **Step 4: Run test to verify it passes**

Run: `wsl -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=CalendarControllerTest"`

Expected: PASS (all `CalendarControllerTest` methods, including the new one)

- [ ] **Step 5: Commit**

```bash
git add resources/views/components/layout/header.blade.php tests/Feature/CalendarControllerTest.php
git commit -m "Add DE/EN language switcher to the header"
```

---

### Task 5: Fix remaining hardcoded `lang="de"`

**Files:**
- Modify: `resources/views/auth/login.blade.php:2`
- Modify: `resources/views/auth/register.blade.php:2`
- Modify: `resources/views/layouts/popup.blade.php:2`
- Test: `tests/Feature/Auth/LoginTest.php` (new test method)

`layouts/app.blade.php` was already fixed in Task 2. This task covers the remaining three layouts (`admin.blade.php` and the email templates already use `app()->getLocale()` — no change needed there).

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Auth/LoginTest.php` (inside the existing test class, alongside its other `#[Test]` methods — check the class name/namespace by reading the file first if unsure):

```php
    #[Test]
    public function login_page_reflects_the_active_locale(): void
    {
        $this->withUnencryptedCookie('locale', 'en')
            ->get('/login')
            ->assertSee('lang="en"', false);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=login_page_reflects_the_active_locale"`

Expected: FAIL — `login.blade.php` still has `<html lang="de">` hardcoded.

- [ ] **Step 3: Fix the three layouts**

In each of `resources/views/auth/login.blade.php:2`, `resources/views/auth/register.blade.php:2`, `resources/views/layouts/popup.blade.php:2`, replace:

```blade
<html lang="de">
```

with:

```blade
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
```

- [ ] **Step 4: Run test to verify it passes**

Run: `wsl -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=Auth"`

Expected: PASS (all `tests/Feature/Auth` tests, including the new one)

- [ ] **Step 5: Commit**

```bash
git add resources/views/auth/login.blade.php resources/views/auth/register.blade.php resources/views/layouts/popup.blade.php tests/Feature/Auth/LoginTest.php
git commit -m "Use dynamic locale for <html lang> in login/register/popup layouts"
```

---

### Task 6: Full regression run

**Files:** none (verification only)

- [ ] **Step 1: Run the full test suite**

Run: `wsl -e bash -lc "cd /mnt/c/development/bookingnew && php artisan test"`

Expected: PASS — every test in the suite green, no regressions from the middleware being added to the global `web` group.

- [ ] **Step 2: Manually smoke-test in the browser**

Start the dev server, open `/calendar`, click "EN" in the header, confirm the URL redirects back to `/calendar` and German UI strings switch to English; log in, switch again, log out and back in, confirm the preference persisted (survives logout because it's stored in `bs_users_meta`, not the session).
