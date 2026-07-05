# Google Social Login Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let members log in with their Google account (in addition to the existing
email/password login), linked automatically to their existing `bs_users` row by email —
no self-registration via Google.

**Architecture:** `laravel/socialite` handles the Google OAuth redirect/callback. A new
`SocialLoginController` looks up the Google account's email against `bs_users`; on match
it records a link in a new `oauth_identities` table and logs the user in via the existing
session-based guard, reusing the same geo-blocking check and legacy login-tracking
(`last_ip`, `last_activity`, `login_attempts`) as the existing password login. No match →
the exact same generic invalid-login error as a wrong password. Two small helpers
(`isLoginBlockedByCountry`, login-attempt tracking) are extracted out of the existing
`LoginController` into a shared trait and onto the `User` model so both login paths use
the same code.

**Tech Stack:** Laravel 13, `laravel/socialite`, MySQL (`booking_local`), PHPUnit,
Mockery.

**Spec:** [docs/superpowers/specs/2026-07-05-google-social-login-design.md](../specs/2026-07-05-google-social-login-design.md)

---

## Task 1: Extract shared login helpers (country-block + attempt tracking)

Pure refactor, no behavior change — makes the two pieces of `LoginController` logic that
`SocialLoginController` will also need (country-block check, login-attempt bookkeeping)
reusable without duplicating them. Safety net is the existing `LoginTest` suite, which
must stay green.

**Files:**
- Create: `app/Http/Controllers/Auth/Concerns/BlocksLoginsByCountry.php`
- Modify: `app/Http/Controllers/Auth/LoginController.php`
- Modify: `app/Models/User.php`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Create the country-block trait**

Create `app/Http/Controllers/Auth/Concerns/BlocksLoginsByCountry.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth\Concerns;

use Stevebauman\Location\Facades\Location;

/**
 * Shared by LoginController and SocialLoginController — the same country
 * blocklist must apply regardless of how the user authenticates.
 */
trait BlocksLoginsByCountry
{
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
}
```

- [ ] **Step 2: Add login-tracking methods to `User`**

In `app/Models/User.php`, insert these two methods directly after `isPrivileged()`
(currently ends at line 87, right before the `can()` method's docblock):

```php
    /** Reset attempt counters and stamp last-login info after a successful authentication. */
    public function recordSuccessfulLogin(string $ip): void
    {
        $this->forceFill([
            'login_attempts' => 0,
            'login_detent' => null,
            'last_activity' => now(),
            'last_ip' => $ip,
        ])->save();
    }

    /** Increment the failed-attempt counter and set the lockout stamp. */
    public function recordFailedLogin(string $ip, int $lockoutSeconds): void
    {
        $attempts = (int) ($this->login_attempts ?? 0) + 1;

        $this->forceFill([
            'login_attempts' => min($attempts, 255),
            'login_detent' => now()->addSeconds($lockoutSeconds),
            'last_ip' => $ip,
        ])->save();
    }
```

- [ ] **Step 3: Wire `LoginController` to use the trait and the new `User` methods**

In `app/Http/Controllers/Auth/LoginController.php`:

Replace the `use` block (lines 5-16) with:

```php
use App\Http\Controllers\Auth\Concerns\BlocksLoginsByCountry;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
```

(the `Stevebauman\Location\Facades\Location` import is no longer used directly here —
it now lives only in the trait.)

Add the trait to the class body, right after the opening `{`:

```php
final class LoginController extends Controller
{
    use BlocksLoginsByCountry;

    private const EMAIL_IP_MAX_ATTEMPTS = 5;
```

Delete the entire `isLoginBlockedByCountry` method (the trait now provides it):

```php
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

```

Replace the body of `syncLegacyLoginTracking`:

```php
    private function syncLegacyLoginTracking(?User $user, bool $successful, string $ip): void
    {
        if (! $user) {
            return;
        }

        if ($successful) {
            $user->forceFill([
                'login_attempts' => 0,
                'login_detent' => null,
                'last_activity' => now(),
                'last_ip' => $ip,
            ])->save();

            return;
        }

        $attempts = (int) ($user->login_attempts ?? 0) + 1;

        $user->forceFill([
            'login_attempts' => min($attempts, 255),
            'login_detent' => now()->addSeconds(self::LOCKOUT_SECONDS),
            'last_ip' => $ip,
        ])->save();
    }
```

with:

```php
    private function syncLegacyLoginTracking(?User $user, bool $successful, string $ip): void
    {
        if (! $user) {
            return;
        }

        if ($successful) {
            $user->recordSuccessfulLogin($ip);

            return;
        }

        $user->recordFailedLogin($ip, self::LOCKOUT_SECONDS);
    }
```

- [ ] **Step 4: Run the existing login test suite to confirm no regression**

Run: `wsl php artisan test tests/Feature/Auth/LoginTest.php`
Expected: all tests PASS (same count as before the refactor — this is a pure extraction,
no assertions should change).

- [ ] **Step 5: Update CHANGELOG.md**

Add this bullet under the existing `## 2026-07-05` section at the top of
`CHANGELOG.md`:

```markdown
- Extracted the login country-block check and legacy login-attempt tracking out of
  `LoginController` into a shared trait / `User` model methods, so the upcoming Google
  login can reuse them instead of duplicating the logic.
```

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Auth/Concerns/BlocksLoginsByCountry.php app/Http/Controllers/Auth/LoginController.php app/Models/User.php CHANGELOG.md
git commit -m "Extract shared login country-block and attempt-tracking helpers"
```

---

## Task 2: Install and configure Laravel Socialite for Google

**Files:**
- Modify: `composer.json` / `composer.lock` (via `composer require`)
- Modify: `config/services.php`
- Modify: `.env.example`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Install the package**

Run: `wsl composer require laravel/socialite`
Expected: composer resolves and installs `laravel/socialite` (and its dependency
`league/oauth2-client`) without version conflicts; `composer.json` gets a new
`"laravel/socialite": "^..."` line under `require`.

- [ ] **Step 2: Add the Google service config**

In `config/services.php`, add a new entry inside the returned array, after the existing
`'slack'` entry:

```php
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],
```

- [ ] **Step 3: Document the required `.env` variables**

In `.env.example`, add this block near the bottom of the file (after the `MAIL_*`
block):

```
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI="${APP_URL}/login/google/callback"
```

- [ ] **Step 4: Update CHANGELOG.md**

Add this bullet under `## 2026-07-05`:

```markdown
- Added `laravel/socialite` and Google OAuth configuration scaffolding
  (`config/services.php`, `.env.example`) in preparation for "Login with Google".
```

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock config/services.php .env.example CHANGELOG.md
git commit -m "Install and configure Laravel Socialite for Google"
```

---

## Task 3: Add the `oauth_identities` table and model

**Files:**
- Create: `database/migrations/2026_07_05_100001_create_oauth_identities_table.php`
- Create: `app/Models/OAuthIdentity.php`
- Modify: `app/Services/DatabaseSchemaChecker.php`
- Test: `tests/Unit/Services/DatabaseSchemaCheckerTest.php` (existing test, no changes
  needed — it must pass against the new manifest entry)
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_07_05_100001_create_oauth_identities_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_identities', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->string('provider', 32);
            $table->string('provider_user_id', 191);
            $table->string('email', 128);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['provider', 'provider_user_id'], 'dp_oauth_identities_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_identities');
    }
};
```

- [ ] **Step 2: Create the `OAuthIdentity` model**

Create `app/Models/OAuthIdentity.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a bs_users row to an external OAuth account (currently only Google) used
 * to log in. A user can only be created via the Members module — this table never
 * creates a bs_users row, it only links to an existing one.
 */
class OAuthIdentity extends Model
{
    protected $table = 'oauth_identities';

    protected $fillable = ['user_id', 'provider', 'provider_user_id', 'email'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'uid');
    }
}
```

- [ ] **Step 3: Register the table in `DatabaseSchemaChecker`**

In `app/Services/DatabaseSchemaChecker.php`, add a new entry to the array returned by
`columnDefinitions()`, right after the `'payment_dues'` entry (currently the last one
before the closing `];` of that method):

```php
            'oauth_identities' => [
                'id' => fn (Blueprint $t) => $t->id(),
                'user_id' => fn (Blueprint $t) => $t->unsignedInteger('user_id')->index(),
                'provider' => fn (Blueprint $t) => $t->string('provider', 32),
                'provider_user_id' => fn (Blueprint $t) => $t->string('provider_user_id', 191),
                'email' => fn (Blueprint $t) => $t->string('email', 128),
                'created_at' => fn (Blueprint $t) => $t->timestamp('created_at')->nullable(),
                'updated_at' => fn (Blueprint $t) => $t->timestamp('updated_at')->nullable(),
            ],
```

Add the composite unique index to `tableLevelConstraints()`, next to the other
`unique(...)` entries (e.g. right after the `'payment_dues'` line):

```php
            'oauth_identities' => [fn (Blueprint $t) => $t->unique(['provider', 'provider_user_id'], 'dp_oauth_identities_unique')],
```

- [ ] **Step 4: Run the migration and the schema checker test**

Run: `wsl php artisan migrate`
Expected: `2026_07_05_100001_create_oauth_identities_table` runs successfully.

Run: `wsl php artisan test tests/Unit/Services/DatabaseSchemaCheckerTest.php`
Expected: all tests PASS, including
`every_table_in_the_database_is_tracked_by_the_manifest` — this is what proves
`oauth_identities` is correctly registered.

- [ ] **Step 5: Update CHANGELOG.md**

Add this bullet under `## 2026-07-05`:

```markdown
- Added the `oauth_identities` table (links a member to an external login account,
  starting with Google), registered in `DatabaseSchemaChecker`.
```

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_05_100001_create_oauth_identities_table.php app/Models/OAuthIdentity.php app/Services/DatabaseSchemaChecker.php CHANGELOG.md
git commit -m "Add oauth_identities table and model"
```

---

## Task 4: Add the Google login redirect/callback flow

**Files:**
- Create: `app/Http/Controllers/Auth/SocialLoginController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Auth/SocialLoginTest.php`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Auth/SocialLoginTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\OAuthIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Socialite\Contracts\Provider as SocialiteProvider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use PHPUnit\Framework\Attributes\Test;
use Stevebauman\Location\Facades\Location;
use Stevebauman\Location\Position;
use Tests\TestCase;

class SocialLoginTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Test]
    public function redirect_delegates_to_the_google_driver(): void
    {
        $provider = \Mockery::mock(SocialiteProvider::class);
        $provider->shouldReceive('redirect')->once()->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));

        Socialite::shouldReceive('driver')->with('google')->once()->andReturn($provider);

        $this->get('/login/google/redirect')
            ->assertRedirect('https://accounts.google.com/o/oauth2/auth');
    }

    #[Test]
    public function callback_logs_in_a_user_whose_email_matches(): void
    {
        RateLimiter::clear('login:social:google:127.0.0.1');

        $user = User::factory()->create([
            'email' => 'test@example.com',
            'status' => 'enabled',
        ]);

        $this->fakeGoogleUser('google-sub-1', 'test@example.com');

        $this->get('/login/google/callback')->assertRedirect('/calendar');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('oauth_identities', [
            'user_id' => $user->uid,
            'provider' => 'google',
            'provider_user_id' => 'google-sub-1',
            'email' => 'test@example.com',
        ]);
    }

    #[Test]
    public function callback_does_not_duplicate_the_link_on_a_repeat_login(): void
    {
        RateLimiter::clear('login:social:google:127.0.0.1');

        $user = User::factory()->create([
            'email' => 'test@example.com',
            'status' => 'enabled',
        ]);

        OAuthIdentity::create([
            'user_id' => $user->uid,
            'provider' => 'google',
            'provider_user_id' => 'google-sub-1',
            'email' => 'test@example.com',
        ]);

        $this->fakeGoogleUser('google-sub-1', 'test@example.com');

        $this->get('/login/google/callback')->assertRedirect('/calendar');

        $this->assertDatabaseCount('oauth_identities', 1);
    }

    #[Test]
    public function callback_fails_when_no_bs_users_email_matches(): void
    {
        RateLimiter::clear('login:social:google:127.0.0.1');

        $this->fakeGoogleUser('google-sub-2', 'unknown@example.com');

        $this->get('/login/google/callback')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => __('booking.auth.invalid')]);

        $this->assertGuest();
        $this->assertDatabaseCount('oauth_identities', 0);
    }

    #[Test]
    public function callback_fails_for_a_disabled_account(): void
    {
        RateLimiter::clear('login:social:google:127.0.0.1');

        User::factory()->create([
            'email' => 'test@example.com',
            'status' => 'disabled',
        ]);

        $this->fakeGoogleUser('google-sub-3', 'test@example.com');

        $this->get('/login/google/callback')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => __('booking.auth.invalid')]);

        $this->assertGuest();
    }

    #[Test]
    public function callback_respects_the_country_blocklist(): void
    {
        RateLimiter::clear('login:social:google:127.0.0.1');
        config(['booking.blocked_login_countries' => ['RU']]);
        Location::fake(['127.0.0.1' => Position::make(['countryCode' => 'RU'])]);

        User::factory()->create([
            'email' => 'test@example.com',
            'status' => 'enabled',
        ]);

        $this->fakeGoogleUser('google-sub-4', 'test@example.com');

        $this->get('/login/google/callback')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => __('booking.auth.invalid')]);

        $this->assertGuest();
    }

    #[Test]
    public function callback_is_throttled_after_too_many_failed_attempts(): void
    {
        // Note: a *successful* callback clears the throttle key (see the controller),
        // so only failed attempts (here: no matching email) accumulate towards the limit.
        $key = 'login:social:google:127.0.0.1';
        RateLimiter::clear($key);

        $this->fakeGoogleUser('google-sub-unmatched', 'unknown@example.com');

        for ($i = 0; $i < 15; $i++) {
            $this->get('/login/google/callback')->assertRedirect('/login');
        }

        $this->get('/login/google/callback')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => __('booking.auth.invalid')]);

        $this->assertGreaterThan(0, RateLimiter::availableIn($key));
        $this->assertGuest();
    }

    private function fakeGoogleUser(string $id, string $email): void
    {
        $socialiteUser = new SocialiteUser();
        $socialiteUser->id = $id;
        $socialiteUser->email = $email;

        $provider = \Mockery::mock(SocialiteProvider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `wsl php artisan test tests/Feature/Auth/SocialLoginTest.php`
Expected: FAIL — routes `/login/google/redirect` and `/login/google/callback` don't
exist yet (404s), and `App\Http\Controllers\Auth\SocialLoginController` doesn't exist.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/Auth/SocialLoginController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\BlocksLoginsByCountry;
use App\Http\Controllers\Controller;
use App\Models\OAuthIdentity;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/**
 * "Login with Google" — links to an existing bs_users row by email, never
 * creates a new one. See docs/superpowers/specs/2026-07-05-google-social-login-design.md.
 *
 * Routes: GET /login/google/redirect (redirect), GET /login/google/callback (callback)
 */
final class SocialLoginController extends Controller
{
    use BlocksLoginsByCountry;

    private const CALLBACK_MAX_ATTEMPTS = 15;

    private const LOCKOUT_SECONDS = 600;

    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $ip = (string) $request->ip();
        $throttleKey = 'login:social:google:'.$ip;

        if (RateLimiter::tooManyAttempts($throttleKey, self::CALLBACK_MAX_ATTEMPTS)) {
            Log::warning('Social login throttled', ['provider' => 'google', 'ip' => $ip]);

            return redirect('/login')->withErrors(['email' => __('booking.auth.invalid')]);
        }

        RateLimiter::hit($throttleKey, self::LOCKOUT_SECONDS);

        if ($this->isLoginBlockedByCountry($ip)) {
            Log::warning('Social login blocked by country', ['provider' => 'google', 'ip' => $ip]);

            return redirect('/login')->withErrors(['email' => __('booking.auth.invalid')]);
        }

        $socialUser = Socialite::driver('google')->user();
        $email = Str::lower(trim((string) $socialUser->getEmail()));

        $user = $email !== '' ? User::where('email', $email)->first() : null;

        if (! $user || ! $user->isEnabled()) {
            Log::warning('Social login failed', [
                'provider' => 'google',
                'email' => $email,
                'ip' => $ip,
                'disabled_account' => $user?->isEnabled() === false,
            ]);

            return redirect('/login')->withErrors(['email' => __('booking.auth.invalid')]);
        }

        OAuthIdentity::firstOrCreate(
            ['provider' => 'google', 'provider_user_id' => (string) $socialUser->getId()],
            ['user_id' => $user->getKey(), 'email' => $email],
        );

        Auth::login($user);
        $request->session()->regenerate();
        RateLimiter::clear($throttleKey);
        $user->recordSuccessfulLogin($ip);

        Log::info('Social login succeeded', [
            'provider' => 'google',
            'user_id' => $user->getKey(),
            'email' => $email,
            'ip' => $ip,
        ]);

        return redirect()->to(route('calendar.index'));
    }
}
```

- [ ] **Step 4: Add the routes**

In `routes/web.php`, add the import next to the other `Auth` controller imports:

```php
use App\Http\Controllers\Auth\SocialLoginController;
```

Add the routes right after the existing `/login` routes:

```php
Route::get('/login/google/redirect', [SocialLoginController::class, 'redirect'])->name('login.google.redirect');
Route::get('/login/google/callback', [SocialLoginController::class, 'callback'])->name('login.google.callback');
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `wsl php artisan test tests/Feature/Auth/SocialLoginTest.php`
Expected: all 7 tests PASS.

- [ ] **Step 6: Run the full test suite to check for regressions**

Run: `wsl php artisan test`
Expected: all tests PASS.

- [ ] **Step 7: Update CHANGELOG.md**

Add this bullet under `## 2026-07-05`:

```markdown
- Members can now log in with Google (`/login/google/redirect`); the Google account's
  email must match an existing, enabled member account — Google login never creates
  a new member.
```

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Auth/SocialLoginController.php routes/web.php tests/Feature/Auth/SocialLoginTest.php CHANGELOG.md
git commit -m "Add Google login redirect/callback flow"
```

---

## Task 5: Add the "Login with Google" button

**Files:**
- Modify: `lang/de/booking/public.php`
- Modify: `lang/en/booking/public.php`
- Modify: `resources/views/auth/login.blade.php`
- Test: `tests/Feature/Auth/LoginTest.php`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Write the failing test**

Add this test to `tests/Feature/Auth/LoginTest.php` (inside the `LoginTest` class, e.g.
right after `login_page_renders`):

```php
    #[Test]
    public function login_page_shows_a_google_login_button(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee(route('login.google.redirect'), false)
            ->assertSee(__('booking.auth.google_login'));
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `wsl php artisan test tests/Feature/Auth/LoginTest.php --filter=login_page_shows_a_google_login_button`
Expected: FAIL — `booking.auth.google_login` translation key doesn't exist and the
button isn't in the view yet.

- [ ] **Step 3: Add the translation keys**

In `lang/de/booking/public.php`, add a key to the existing `'auth'` array (right after
`'register_now'`):

```php
        'register_now' => 'Jetzt registrieren',
        'or_divider' => 'oder',
        'google_login' => 'Mit Google anmelden',
```

In `lang/en/booking/public.php`, add the equivalent English keys in the same place in
its `'auth'` array (match the existing English wording style there):

```php
        'or_divider' => 'or',
        'google_login' => 'Sign in with Google',
```

- [ ] **Step 4: Add the button to the login view**

In `resources/views/auth/login.blade.php`, insert this block right after the closing
`</form>` tag (before the `<p class="mt-2 text-center text-xs ...">` "no account" line):

```blade
                <div class="flex items-center gap-3 text-xs text-[#6a6e73]">
                    <div class="flex-1 border-t border-[#e0ddd7]"></div>
                    {{ __('booking.auth.or_divider') }}
                    <div class="flex-1 border-t border-[#e0ddd7]"></div>
                </div>

                <a href="{{ route('login.google.redirect') }}" class="w-full flex items-center justify-center gap-2 border border-[#cccccc] hover:bg-[#f0ede6] text-[#151515] font-medium py-2 px-4 rounded transition-colors">
                    {{ __('booking.auth.google_login') }}
                </a>
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `wsl php artisan test tests/Feature/Auth/LoginTest.php`
Expected: all tests PASS, including the new one.

- [ ] **Step 6: Sanity-check the Blade syntax**

Run: `wsl php artisan view:cache`
Expected: no errors (catches Blade syntax mistakes).

Run: `wsl php artisan view:clear`
Expected: cache cleared, no errors.

- [ ] **Step 7: Update CHANGELOG.md**

Add this bullet under `## 2026-07-05`:

```markdown
- Added a "Mit Google anmelden" / "Sign in with Google" button to the login page.
```

- [ ] **Step 8: Commit**

```bash
git add lang/de/booking/public.php lang/en/booking/public.php resources/views/auth/login.blade.php tests/Feature/Auth/LoginTest.php CHANGELOG.md
git commit -m "Add Login with Google button to the login page"
```

---

## Task 6: Update README with setup instructions

**Files:**
- Modify: `README.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Document the Google OAuth setup under a new subsection**

In `README.md`, add a new subsection right after the `## Security` section (after the
geo-blocking bullet, before `## Local development`):

```markdown
### Google login setup

Members can additionally log in with Google (`app/Http/Controllers/Auth/SocialLoginController.php`).
It only links to an existing, enabled `bs_users` row matched by email — it never creates
new members.

To enable it for a deployment:

1. In the [Google Cloud Console](https://console.cloud.google.com), create/select a
   project, configure the OAuth consent screen (External, scopes `email` + `profile`),
   and publish it to production.
2. Create an OAuth Client ID (Web application) with an authorized redirect URI of
   `https://<domain>/login/google/callback` (and `http://localhost:8001/login/google/callback`
   for local development).
3. Set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, and `GOOGLE_REDIRECT_URI` in `.env`
   (see `.env.example`).
```

- [ ] **Step 2: Update CHANGELOG.md**

Add this bullet under `## 2026-07-05`:

```markdown
- Documented Google login setup (Google Cloud Console steps + required `.env` vars)
  in the README.
```

- [ ] **Step 3: Commit**

```bash
git add README.md CHANGELOG.md
git commit -m "Document Google login setup in the README"
```
