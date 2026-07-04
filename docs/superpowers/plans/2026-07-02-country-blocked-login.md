# Country-blocked login Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reject login attempts whose request IP resolves to a country on a configurable block-list (e.g. Russia), while never locking out legitimate users if location lookup is unavailable.

**Architecture:** `stevebauman/location` resolves the request IP to a country using a local MaxMind GeoLite2-Country database (no external API calls, no login IPs leaked to a third party). `LoginController::login()` checks the resolved country against a comma-separated `.env`-configured list before any credential/rate-limit logic runs. Any lookup failure (missing DB file, unresolvable IP, private/local IP) is treated as "not blocked" — fail-open.

**Tech Stack:** Laravel 13, PHP 8.3, `stevebauman/location` ^7.6 (pulls `geoip2/geoip2` automatically), MaxMind GeoLite2-Country `.mmdb` file (operational asset, not committed to git).

**Note on `.env.example`:** unlike framework-level vars, this codebase's custom `BOOKING_*` settings (`BOOKING_NAME`, `BOOKING_SQUARE_1_NAME`, etc. in `config/booking.php`) are intentionally **not** listed in `.env.example` — see that file for the existing pattern. This plan follows the same convention and does not add `BOOKING_BLOCKED_LOGIN_COUNTRIES` or `GEOIP_DATABASE_PATH` there.

---

### Task 1: Install `stevebauman/location` and publish its config

**Files:**
- Modify: `composer.json`, `composer.lock` (via composer)
- Create: `config/location.php` (via artisan, package-published)

All commands run via WSL per project convention (PHP/composer are never run directly in PowerShell).

- [ ] **Step 1: Require the package**

Run: `wsl composer require stevebauman/location`

Expected: composer resolves `stevebauman/location` (currently ^7.6, compatible with `laravel/framework ^13.8`) and pulls in `geoip2/geoip2` automatically as a transitive dependency — no need to require it separately.

- [ ] **Step 2: Verify the dependency was added**

Run: `wsl bash -c "grep stevebauman/location composer.json"`

Expected output: `"stevebauman/location": "^7.6",` (or similar) inside the `require` block.

- [ ] **Step 3: Publish the package config**

Run: `wsl php artisan vendor:publish --provider="Stevebauman\Location\LocationServiceProvider"`

Expected: `Copied File [...] To [/config/location.php]` — creates `config/location.php` with the package's default stub (default driver `IpApi`, various API-driver fallbacks, a `maxmind` section with `local.type => 'city'` and `local.path => database_path('maxmind/GeoLite2-City.mmdb')`).

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock config/location.php
git commit -m "Add stevebauman/location package for GeoIP lookups"
```

---

### Task 2: Configure the MaxMind local-database driver for country-level lookups

**Files:**
- Modify: `config/location.php`

- [ ] **Step 1: Set the default driver to MaxMind and drop the API-based fallbacks**

`config/location.php` ships with this default (top of file, imports already include `MaxMind`):

```php
use Stevebauman\Location\Drivers\GeoPlugin;
use Stevebauman\Location\Drivers\Ip2locationio;
use Stevebauman\Location\Drivers\IpApi;
use Stevebauman\Location\Drivers\IpInfo;
use Stevebauman\Location\Drivers\MaxMind;
use Stevebauman\Location\Position;
```

Replace it with (drop the now-unused API-driver imports):

```php
use Stevebauman\Location\Drivers\MaxMind;
use Stevebauman\Location\Position;
```

Then replace:

```php
    'driver' => IpApi::class,
```

with:

```php
    'driver' => MaxMind::class,
```

And replace:

```php
    'fallbacks' => [
        Ip2locationio::class,
        IpInfo::class,
        GeoPlugin::class,
        MaxMind::class,
    ],
```

with:

```php
    'fallbacks' => [],
```

(No external API fallbacks: this lookup gates login, so it must not depend on — or leak login IPs to — a third-party service. A missing/unreadable local database already fails open, see Task 5.)

- [ ] **Step 2: Point the local database path at country-level storage and enable country mode**

Replace:

```php
        'local' => [
            'type' => 'city',
            'path' => database_path('maxmind/GeoLite2-City.mmdb'),
            'url' => sprintf('https://download.maxmind.com/app/geoip_download_by_token?edition_id=GeoLite2-City&license_key=%s&suffix=tar.gz', env('MAXMIND_LICENSE_KEY')),
        ],
```

with:

```php
        'local' => [
            'type' => 'country',
            'path' => env('GEOIP_DATABASE_PATH', storage_path('app/geoip/GeoLite2-Country.mmdb')),
            'url' => sprintf('https://download.maxmind.com/app/geoip_download_by_token?edition_id=GeoLite2-Country&license_key=%s&suffix=tar.gz', env('MAXMIND_LICENSE_KEY')),
        ],
```

(Only `type`, `path`, and the `edition_id` inside `url` change — the rest of the `sprintf` stays as published.)

- [ ] **Step 3: Verify the config loads without errors**

Run: `wsl php artisan tinker --execute="echo config('location.driver').PHP_EOL.config('location.maxmind.local.type').PHP_EOL.config('location.maxmind.local.path');"`

Expected output (three lines):
```
Stevebauman\Location\Drivers\MaxMind
country
<absolute path>/storage/app/geoip/GeoLite2-Country.mmdb
```

- [ ] **Step 4: Commit**

```bash
git add config/location.php
git commit -m "Configure stevebauman/location to use a local MaxMind country database"
```

---

### Task 3: Add a configurable blocked-country list

**Files:**
- Modify: `config/booking.php`

- [ ] **Step 1: Add the config key**

In `config/booking.php`, after the `'peak_limit'` array (currently the last entry before the closing `];`), add:

```php
    'blocked_login_countries' => array_values(array_filter(array_map(
        static fn (string $code): string => strtoupper(trim($code)),
        explode(',', (string) env('BOOKING_BLOCKED_LOGIN_COUNTRIES', ''))
    ))),
```

So the full file reads:

```php
<?php

declare(strict_types=1);

return [
    'name' => env('BOOKING_NAME', env('APP_NAME', 'Tennis-Booking')),
    'logo_path' => env('BOOKING_LOGO_PATH', 'imgs-client/layout/client-logo.jpg'),
    'square_names' => [
        '1' => env('BOOKING_SQUARE_1_NAME', 'Platz1'),
        '2' => env('BOOKING_SQUARE_2_NAME', 'Platz2'),
        '3' => env('BOOKING_SQUARE_3_NAME', 'Platz3'),
    ],
    'peak_limit' => [
        'window_1_start' => '08:00',
        'window_1_end'   => '12:00',
        'window_2_start' => '17:00',
        'window_2_end'   => '21:00',
    ],
    'blocked_login_countries' => array_values(array_filter(array_map(
        static fn (string $code): string => strtoupper(trim($code)),
        explode(',', (string) env('BOOKING_BLOCKED_LOGIN_COUNTRIES', ''))
    ))),
];
```

Values are ISO 3166-1 alpha-2 codes, comma-separated (e.g. `BOOKING_BLOCKED_LOGIN_COUNTRIES=RU,BY`), normalized to uppercase regardless of how the env var is cased, empty entries dropped.

- [ ] **Step 2: Verify defaults and parsing**

Run: `wsl php artisan tinker --execute="var_export(config('booking.blocked_login_countries'));"`

Expected (no env var set): `array (\n)` (empty array).

Run: `wsl bash -c "BOOKING_BLOCKED_LOGIN_COUNTRIES='ru, by ,' php artisan tinker --execute=\"var_export(config('booking.blocked_login_countries'));\""`

Expected: `array (\n  0 => 'RU',\n  1 => 'BY',\n)`

- [ ] **Step 3: Commit**

```bash
git add config/booking.php
git commit -m "Add configurable blocked_login_countries setting"
```

---

### Task 4: Keep the local GeoIP database file out of git

**Status: done without a code change.** Before adding a `.gitignore` entry, check first whether one is even needed: `git check-ignore -v storage/app/geoip/GeoLite2-Country.mmdb`. Laravel ships a blanket `storage/app/.gitignore` (`*`, with exceptions for `private/`, `public/`, `.gitignore`) that already excludes everything under `storage/app/`, including `storage/app/geoip/`. Adding `/storage/app/geoip` to the root `.gitignore` would be redundant — confirmed via `git check-ignore -v`, which reported the file already ignored by `storage/app/.gitignore:1:*`. No commit was made for this task.

---

### Task 5 (manual/operational — not part of the automated test suite): Obtain the GeoLite2-Country database

This step requires a personal MaxMind account and is **not** required for Task 6's tests to pass (they fake the `Location` facade). It's required for the feature to actually block anyone in production.

- [ ] Sign up for a free account at `https://www.maxmind.com/en/geolite2/signup` and generate a license key under Account → License Keys.
- [ ] Temporarily add `MAXMIND_LICENSE_KEY=<your key>` to your local `.env`.
- [ ] Run: `wsl php artisan location:update` — this downloads and extracts the database to the configured path (`storage/app/geoip/GeoLite2-Country.mmdb`, created automatically by the command).
- [ ] Verify the file exists: `wsl bash -c "ls -la storage/app/geoip/"`
- [ ] Remove `MAXMIND_LICENSE_KEY` from your local `.env` again (not needed once the file exists locally; keep it only if you want to re-run `location:update` later to refresh the database).
- [ ] Upload `storage/app/geoip/GeoLite2-Country.mmdb` to the same relative path on the one.com server (FTP/SSH) — it is git-ignored, so it must be transferred out-of-band, same as `.env`.
- [ ] Repeat this task every few months to keep country data current (no cron/automation available on one.com shared hosting).

---

### Task 6: Block login attempts from configured countries (TDD)

**Files:**
- Modify: `app/Http/Controllers/Auth/LoginController.php`
- Modify: `tests/Feature/Auth/LoginTest.php`

- [ ] **Step 1: Write the first failing test**

In `tests/Feature/Auth/LoginTest.php`, add these two imports (alphabetically, between the existing `PHPUnit\Framework\Attributes\Test` and `Symfony\Component\HttpFoundation\Cookie` imports):

```php
use Stevebauman\Location\Facades\Location;
use Stevebauman\Location\Position;
```

So the import block reads:

```php
use App\Models\Option;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Stevebauman\Location\Facades\Location;
use Stevebauman\Location\Position;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;
```

Then add this test method, right after `disabled_user_gets_the_same_error_message_as_invalid_credentials` (which ends at the original line 184, `}`):

```php
    #[Test]
    public function login_is_blocked_when_the_request_country_is_configured_as_blocked(): void
    {
        config(['booking.blocked_login_countries' => ['RU']]);
        Location::fake(['127.0.0.1' => Position::make(['countryCode' => 'RU'])]);

        User::factory()->create([
            'email' => 'test@example.com',
            'pw' => bcrypt('secret123'),
            'status' => 'enabled',
        ]);

        $this->post('/login', ['email' => 'test@example.com', 'password' => 'secret123'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => __('booking.auth.invalid')]);

        $this->assertGuest();
    }
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `wsl php artisan test --filter=login_is_blocked_when_the_request_country_is_configured_as_blocked`

Expected: FAIL — the test asserts a redirect to `/login` with an error, but today's `login()` has no country check, so valid credentials from `127.0.0.1` log in successfully and the response redirects to `/calendar` instead.

- [ ] **Step 3: Implement the country check**

In `app/Http/Controllers/Auth/LoginController.php`, add the import (after the existing `use Illuminate\View\View;`, alphabetically last):

```php
use Illuminate\View\View;
use Stevebauman\Location\Facades\Location;
```

Then in `login()`, replace:

```php
        $email = Str::lower(trim($credentials['email']));
        $ip = (string) $request->ip();
        $user = User::where('email', $credentials['email'])->first();
```

with:

```php
        $email = Str::lower(trim($credentials['email']));
        $ip = (string) $request->ip();

        if ($this->isLoginBlockedByCountry($ip)) {
            Log::warning('Login blocked by country', [
                'email' => $email,
                'ip' => $ip,
            ]);

            return redirect('/login')->withErrors([
                'email' => __('booking.auth.invalid'),
            ])->withInput($request->except('password'));
        }

        $user = User::where('email', $credentials['email'])->first();
```

Then add the new private method right after `login()`'s closing brace, before the existing `throttleKeys()` method:

```php
        return redirect()->to($this->normalizeRedirectTarget($credentials['redirect_to'] ?? null));
    }

    private function isLoginBlockedByCountry(string $ip): bool
    {
        $blockedCountries = config('booking.blocked_login_countries', []);

        if ($blockedCountries === []) {
            return false;
        }

        $position = Location::get($ip);

        if (! $position || ! $position->countryCode) {
            return false;
        }

        return in_array($position->countryCode, $blockedCountries, true);
    }

    /** @return array<string, int> */
    private function throttleKeys(string $email, string $ip, ?User $user): array
```

`Location::get()` never throws for the MaxMind driver — a missing database file, an unreadable file, or an unresolvable/private IP are all caught internally by the package (via Laravel's `rescue()` helper) and surfaced as `false`, which the `! $position` check above treats as "not blocked". This is what makes the check fail-open without any try/catch of our own.

- [ ] **Step 4: Run the test again and confirm it passes**

Run: `wsl php artisan test --filter=login_is_blocked_when_the_request_country_is_configured_as_blocked`

Expected: PASS

- [ ] **Step 5: Add the remaining coverage**

Add these three tests directly after the one from Step 1:

```php
    #[Test]
    public function login_succeeds_when_the_request_country_is_not_blocked(): void
    {
        config(['booking.blocked_login_countries' => ['RU']]);
        Location::fake(['127.0.0.1' => Position::make(['countryCode' => 'US'])]);

        User::factory()->create([
            'email' => 'test@example.com',
            'pw' => bcrypt('secret123'),
            'status' => 'enabled',
        ]);

        $this->post('/login', ['email' => 'test@example.com', 'password' => 'secret123'])
            ->assertRedirect('/calendar');

        $this->assertAuthenticated();
    }

    #[Test]
    public function login_is_not_blocked_when_the_location_lookup_cannot_resolve_a_country(): void
    {
        config(['booking.blocked_login_countries' => ['RU']]);
        Location::fake([]);

        User::factory()->create([
            'email' => 'test@example.com',
            'pw' => bcrypt('secret123'),
            'status' => 'enabled',
        ]);

        $this->post('/login', ['email' => 'test@example.com', 'password' => 'secret123'])
            ->assertRedirect('/calendar');

        $this->assertAuthenticated();
    }

    #[Test]
    public function login_blocked_by_country_does_not_count_against_the_rate_limiter(): void
    {
        config(['booking.blocked_login_countries' => ['RU']]);
        Location::fake(['127.0.0.1' => Position::make(['countryCode' => 'RU'])]);

        User::factory()->create([
            'email' => 'test@example.com',
            'pw' => bcrypt('secret123'),
            'status' => 'enabled',
        ]);

        $key = $this->emailIpThrottleKey('test@example.com');
        RateLimiter::clear($key);

        $this->post('/login', ['email' => 'test@example.com', 'password' => 'secret123'])
            ->assertRedirect('/login');

        $this->assertSame(0, RateLimiter::attempts($key));
    }
```

- [ ] **Step 6: Run the full LoginTest suite**

Run: `wsl php artisan test --filter=LoginTest`

Expected: All tests pass (the 3 new ones plus all pre-existing ones — pre-existing tests don't fake `Location` at all, so they hit the real `MaxMind` driver against a non-existent `.mmdb` file in the test environment, which fails open exactly like the "cannot resolve a country" case, leaving their behavior unchanged).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Auth/LoginController.php tests/Feature/Auth/LoginTest.php
git commit -m "Block login attempts from configured countries"
```

---

### Task 7: Full verification pass

- [ ] **Step 1: Run the whole test suite**

Run: `wsl composer test`

Expected: all tests pass, no regressions outside `LoginTest`.

- [ ] **Step 2: Run lint + static analysis**

Run: `wsl composer lint`

Expected: Pint reports no style issues, PHPStan reports no errors. Fix anything flagged in `LoginController.php`, `config/location.php`, or `config/booking.php` and re-run.
