# Forgot Password Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user who forgot their password request a reset link by email and set a new password themselves, without admin intervention.

**Architecture:** Laravel's built-in password broker (`password_reset_tokens` table, `Password::broker()`) handles token creation, expiry (60 min), and single-use invalidation. Two hand-written controllers (matching the existing `LoginController`/`RegisterController` style — no Breeze/Fortify traits) drive the flow; the reset email is sent directly via a custom `Mailable`, following the exact pattern `UserActivated` already uses in `Admin\UserController`.

**Tech Stack:** Laravel 11, Blade, `Illuminate\Support\Facades\Password`, `Illuminate\Support\Facades\Hash`, `Illuminate\Support\Facades\Mail`, PHPUnit feature tests, `LazilyRefreshDatabase`.

---

## Reference: spec

Full design at [docs/superpowers/specs/2026-07-06-forgot-password-design.md](../specs/2026-07-06-forgot-password-design.md). Key points repeated here for implementers:

- Response to "request a reset link" is **always identical** regardless of whether the email exists or the account is enabled — this prevents account enumeration.
- Only accounts where `$user->isEnabled()` is true (status `enabled`, `assist`, `admin`) actually get a link.
- Both POST routes get `throttle:5,1`.
- On successful reset: log the user in and redirect to `/calendar`.

## Existing conventions to follow (read before starting)

- `app/Http/Controllers/Auth/LoginController.php` — hand-written controller style, `declare(strict_types=1)`, `final class`, no framework Auth scaffolding traits.
- `app/Http/Controllers/Admin/UserController.php:154-160` — `password()` method shows the exact hashing pattern: `$user->update(['pw' => Hash::make(...)])`.
- `app/Http/Controllers/Admin/UserController.php:87` and `:148` — exact pattern for sending a custom Mailable: `Mail::to($user->email)->queue(new UserActivated($user));`. Follow this same pattern for the new mail, not `Password::sendResetLink()`'s default notification (which would send an English, unbranded email).
- `app/Mail/UserActivated.php` — Mailable structure to copy.
- `resources/views/emails/user-activated.blade.php` — email view structure/styles to copy.
- `resources/views/auth/login.blade.php` — full-page (not `@extends`) auth page structure/styles to copy for the two new pages.
- `lang/de/booking/public.php` and `lang/en/booking/public.php` — top-level `auth`/`register` translation groups; add a new top-level `password_reset` group here.
- `lang/de/booking/mail.php` and `lang/en/booking/mail.php` — `mail` translation group; add new `password_reset_*` keys here.
- `config/auth.php:95-101` — password broker already configured (`expire: 60`, `throttle: 60`); only the `password_reset_tokens` table migration is missing.
- `routes/web.php:22-27` — where the new routes go (right after `/register`).

---

## Task 1: `password_reset_tokens` migration

**Files:**
- Create: `database/migrations/2026_07_06_090001_create_password_reset_tokens_table.php`

- [ ] **Step 1: Write the migration**

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
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan migrate"`
Expected: `2026_07_06_090001_create_password_reset_tokens_table ... DONE`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_07_06_090001_create_password_reset_tokens_table.php
git commit -m "Add password_reset_tokens table for the forgot-password feature"
```

---

## Task 2: Password reset Mailable + email view + mail lang keys

**Files:**
- Create: `app/Mail/PasswordReset.php`
- Create: `resources/views/emails/password-reset.blade.php`
- Modify: `lang/de/booking/mail.php`
- Modify: `lang/en/booking/mail.php`

- [ ] **Step 1: Add mail lang keys (German)**

In `lang/de/booking/mail.php`, add these keys inside the existing `'mail' => [ ... ]` array, right after the `'login_now' => 'Jetzt anmelden',` line:

```php
        'password_reset_subject' => 'Passwort zurücksetzen – :system',
        'password_reset_heading' => 'Passwort zurücksetzen',
        'password_reset_subheading' => 'Sie haben ein neues Passwort angefordert.',
        'password_reset_body' => 'Klicken Sie auf den folgenden Button, um ein neues Passwort zu vergeben. Der Link ist 60 Minuten gültig.',
        'password_reset_button' => 'Neues Passwort vergeben',
        'password_reset_ignore' => 'Falls Sie diese Anfrage nicht gestellt haben, können Sie diese E-Mail ignorieren.',
```

- [ ] **Step 2: Add mail lang keys (English)**

In `lang/en/booking/mail.php`, add these keys inside the existing `'mail' => [ ... ]` array, right after the `'login_now' => 'Log in now',` line:

```php
        'password_reset_subject' => 'Reset your password – :system',
        'password_reset_heading' => 'Reset your password',
        'password_reset_subheading' => 'You requested a new password.',
        'password_reset_body' => 'Click the button below to set a new password. This link is valid for 60 minutes.',
        'password_reset_button' => 'Set a new password',
        'password_reset_ignore' => 'If you did not request this, you can safely ignore this email.',
```

- [ ] **Step 3: Write the Mailable**

Create `app/Mail/PasswordReset.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordReset extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $resetUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('booking.mail.password_reset_subject', ['system' => config('booking.name')]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-reset',
            with: ['user' => $this->user, 'resetUrl' => $this->resetUrl],
        );
    }
}
```

`config('booking.name')` is confirmed correct — `config/booking.php:6` defines `'name' => env('BOOKING_NAME', env('APP_NAME', 'Tennis-Booking'))`.

- [ ] **Step 4: Write the email view**

Create `resources/views/emails/password-reset.blade.php`, copying `resources/views/emails/user-activated.blade.php`'s `<style>` block exactly:

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<style>
  body { font-family: Arial, sans-serif; color: #222; font-size: 15px; line-height: 1.6; }
  .wrap { max-width: 560px; margin: 40px auto; padding: 0 20px; }
  h1 { font-size: 20px; margin-bottom: 4px; }
  .sub { color: #666; margin-bottom: 24px; }
  .btn { display: inline-block; margin-top: 20px; padding: 10px 22px; background: #2563eb; color: #fff; text-decoration: none; border-radius: 4px; font-size: 15px; }
  .footer { margin-top: 32px; font-size: 13px; color: #888; }
</style>
</head>
<body>
<div class="wrap">
  <h1>{{ __('booking.mail.password_reset_heading') }}</h1>
  <p class="sub">{{ __('booking.mail.password_reset_subheading') }}</p>

  <p>{{ __('booking.mail.password_reset_body') }}</p>

  <a href="{{ $resetUrl }}" class="btn">{{ __('booking.mail.password_reset_button') }}</a>

  <p class="footer">{{ __('booking.mail.password_reset_ignore') }}</p>
  <div class="footer">
    {{ __('booking.mail.auto_footer') }}
  </div>
</div>
</body>
<!-- {{ config('booking.watermark') }} -->
</html>
```

- [ ] **Step 5: Commit**

```bash
git add app/Mail/PasswordReset.php resources/views/emails/password-reset.blade.php lang/de/booking/mail.php lang/en/booking/mail.php
git commit -m "Add PasswordReset mailable and email template"
```

---

## Task 3: `password_reset` page lang keys

**Files:**
- Modify: `lang/de/booking/public.php`
- Modify: `lang/en/booking/public.php`

- [ ] **Step 1: Add the "forgot password?" link text (German)**

In `lang/de/booking/public.php`, inside the `'auth' => [ ... ]` array, add this line right after `'register_now' => 'Jetzt registrieren',`:

```php
        'forgot_password' => 'Passwort vergessen?',
```

- [ ] **Step 2: Add the "forgot password?" link text (English)**

In `lang/en/booking/public.php`, inside the `'auth' => [ ... ]` array, add this line right after `'register_now' => 'Register now',` (line 45):

```php
        'forgot_password' => 'Forgot your password?',
```

- [ ] **Step 3: Add a new `password_reset` top-level group (German)**

In `lang/de/booking/public.php`, add a new top-level array entry after the closing `],` of the `'register' => [ ... ]` block (before `'calendar' => [`):

```php
'password_reset' => [
        'forgot_title' => 'Passwort vergessen – :system',
        'forgot_heading' => 'Passwort vergessen',
        'forgot_intro' => 'Geben Sie Ihre E-Mail-Adresse ein. Falls ein Konto dazu existiert, senden wir Ihnen einen Link zum Zurücksetzen des Passworts.',
        'forgot_email_placeholder' => 'ihre@email.at',
        'forgot_submit' => 'Link senden',
        'forgot_sent' => 'Falls diese E-Mail-Adresse bei uns registriert ist, haben wir einen Link zum Zurücksetzen des Passworts gesendet.',
        'back_to_login' => 'Zurück zur Anmeldung',
        'reset_title' => 'Neues Passwort vergeben – :system',
        'reset_heading' => 'Neues Passwort vergeben',
        'reset_intro' => 'Bitte vergeben Sie ein neues Passwort für Ihr Konto.',
        'reset_password' => 'Neues Passwort',
        'reset_password_confirm' => 'Neues Passwort bestätigen',
        'reset_submit' => 'Passwort setzen',
        'reset_invalid' => 'Dieser Link ist ungültig oder abgelaufen. Bitte fordern Sie einen neuen Link an.',
        'reset_success' => 'Ihr Passwort wurde erfolgreich geändert.',
    ],
```

- [ ] **Step 4: Add the same `password_reset` group (English)**

In `lang/en/booking/public.php`, add the matching top-level entry in the same position:

```php
'password_reset' => [
        'forgot_title' => 'Forgot password – :system',
        'forgot_heading' => 'Forgot password',
        'forgot_intro' => 'Enter your email address. If an account exists for it, we will send you a link to reset your password.',
        'forgot_email_placeholder' => 'your@email.com',
        'forgot_submit' => 'Send link',
        'forgot_sent' => 'If that email address is registered with us, we have sent a link to reset your password.',
        'back_to_login' => 'Back to login',
        'reset_title' => 'Set a new password – :system',
        'reset_heading' => 'Set a new password',
        'reset_intro' => 'Please set a new password for your account.',
        'reset_password' => 'New password',
        'reset_password_confirm' => 'Confirm new password',
        'reset_submit' => 'Set password',
        'reset_invalid' => 'This link is invalid or has expired. Please request a new one.',
        'reset_success' => 'Your password has been changed successfully.',
    ],
```

- [ ] **Step 5: Verify the lang files still parse**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php -l lang/de/booking/public.php && php -l lang/en/booking/public.php"`
Expected: `No syntax errors detected` for both files.

- [ ] **Step 6: Commit**

```bash
git add lang/de/booking/public.php lang/en/booking/public.php
git commit -m "Add password_reset translation keys"
```

---

## Task 4: `ForgotPasswordController` + request-link page + route

**Files:**
- Create: `app/Http/Controllers/Auth/ForgotPasswordController.php`
- Create: `resources/views/auth/forgot-password.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Auth/ForgotPasswordTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Auth/ForgotPasswordTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Mail\PasswordReset;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Test]
    public function forgot_password_page_renders(): void
    {
        $this->get('/password/forgot')->assertOk()->assertViewIs('auth.forgot-password');
    }

    #[Test]
    public function requesting_a_reset_link_for_an_existing_enabled_user_queues_the_mail(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'test@example.com', 'status' => 'enabled']);

        $this->post('/password/forgot', ['email' => 'test@example.com'])
            ->assertRedirect('/password/forgot')
            ->assertSessionHas('status');

        Mail::assertQueued(PasswordReset::class, fn (PasswordReset $mail) => $mail->user->is($user));
    }

    #[Test]
    public function requesting_a_reset_link_for_an_unknown_email_shows_the_same_generic_message(): void
    {
        Mail::fake();

        $this->post('/password/forgot', ['email' => 'nobody@example.com'])
            ->assertRedirect('/password/forgot')
            ->assertSessionHas('status');

        Mail::assertNothingQueued();
    }

    #[Test]
    public function requesting_a_reset_link_for_a_disabled_user_does_not_send_mail(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'disabled@example.com', 'status' => 'disabled']);

        $this->post('/password/forgot', ['email' => 'disabled@example.com'])
            ->assertRedirect('/password/forgot')
            ->assertSessionHas('status');

        Mail::assertNothingQueued();
    }

    #[Test]
    public function the_forgot_password_route_is_rate_limited_after_five_requests_per_minute(): void
    {
        Mail::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/password/forgot', ['email' => 'nobody@example.com'])
                ->assertRedirect('/password/forgot');
        }

        $this->post('/password/forgot', ['email' => 'nobody@example.com'])
            ->assertStatus(429);
    }
}
```

Check `User` model's factory exists at `database/factories/UserFactory.php` and supports `['email' => ..., 'status' => ...]` — `tests/Feature/Auth/LoginTest.php` already uses `User::factory()->create(['email' => 'test@example.com', ...])`, so this is a safe assumption; if the factory doesn't default `status` to something falsy, no change needed since we pass it explicitly here.

- [ ] **Step 2: Run tests to verify they fail**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=ForgotPasswordTest"`
Expected: FAIL — route `/password/forgot` doesn't exist yet (404s), and `App\Mail\PasswordReset` class doesn't exist (already created in Task 2, so this failure is specifically about the missing route/controller).

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/Auth/ForgotPasswordController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordReset;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

/**
 * ForgotPasswordController — request a password reset link by email.
 *
 * Routes: GET /password/forgot (showForm), POST /password/forgot (sendResetLink)
 * Auth: guest only
 */
final class ForgotPasswordController extends Controller
{
    public function showForm(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Always responds with the same generic message, regardless of whether the
     * email exists or the account is enabled — this prevents account enumeration.
     */
    public function sendResetLink(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user && $user->isEnabled()) {
            $token = Password::broker()->createToken($user);
            $resetUrl = url(route('password.reset', ['token' => $token, 'email' => $user->email], false));

            Mail::to($user->email)->queue(new PasswordReset($user, $resetUrl));
        }

        return redirect()->route('password.forgot')
            ->with('status', __('booking.password_reset.forgot_sent'));
    }
}
```

- [ ] **Step 4: Write the view**

Create `resources/views/auth/forgot-password.blade.php`, copying the page shell from `resources/views/auth/login.blade.php` (same `<head>`, header, `<main>` wrapper):

```blade
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ str_replace(':system', $bookingName, __('booking.password_reset.forgot_title')) }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Red+Hat+Display:wght@600;700&family=Red+Hat+Text:wght@400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('css/booking.css') }}">
</head>
<body class="min-h-screen bg-[#f0ede6] flex flex-col" style="font-family: var(--font-body)">
    <header class="bg-white border-b border-[#e0ddd7] px-4 sm:px-6 py-3 sm:h-14 flex items-center gap-3">
        @if($bookingLogoPath && file_exists(public_path($bookingLogoPath)))
            <img src="{{ asset($bookingLogoPath) }}" alt="{{ $bookingName }}" class="h-9 w-auto object-contain">
        @endif
        <span class="font-semibold text-[#151515] text-sm" style="font-family: var(--font-display)">{{ $bookingName }}</span>
    </header>

    <main class="flex-1 flex items-center justify-center p-4 sm:p-6">
        <div class="w-full max-w-md bg-white rounded-xl shadow-sm border border-[#e0ddd7] overflow-hidden">
            <div class="p-6 sm:p-10 flex flex-col gap-5">
                <h1 class="text-2xl font-bold text-[#151515] leading-tight" style="font-family: var(--font-display)">{{ __('booking.password_reset.forgot_heading') }}</h1>
                <p class="text-sm text-[#6a6e73] leading-relaxed">{{ __('booking.password_reset.forgot_intro') }}</p>

                @if(session('status'))
                    <div class="text-sm text-green-700 bg-green-50 border border-green-200 rounded-lg px-4 py-3">
                        {{ session('status') }}
                    </div>
                @else
                    <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-5">
                        @csrf
                        <div class="flex flex-col gap-1">
                            <label for="email" class="text-sm font-medium text-[#151515]">{{ __('booking.auth.email') }}</label>
                            <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email" placeholder="{{ __('booking.password_reset.forgot_email_placeholder') }}" class="w-full border rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent {{ $errors->has('email') ? 'border-red-400' : 'border-[#cccccc]' }}">
                            @error('email')
                                <p class="text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <button type="submit" class="w-full bg-[#bf4316] hover:bg-[#9e3412] text-white font-medium py-2 px-4 rounded transition-colors mt-1">{{ __('booking.password_reset.forgot_submit') }}</button>
                    </form>
                @endif

                <p class="mt-2 text-center text-xs text-[#6a6e73]">
                    <a href="{{ route('login') }}" class="text-[#bf4316] hover:underline">{{ __('booking.password_reset.back_to_login') }}</a>
                </p>
            </div>
        </div>
    </main>
</body>
<!-- {{ config('booking.watermark') }} -->
</html>
```

`$bookingName` and `$bookingLogoPath` are available in every view automatically — `app/Providers/AppServiceProvider.php:27` and `:35` set them via `View::share(...)`, which is global and not scoped to specific routes/controllers. No extra code needed in `ForgotPasswordController::showForm` to make them available.

- [ ] **Step 5: Add the routes**

In `routes/web.php`, add after the existing `/register` routes (after line 27):

```php
Route::get('/password/forgot', [ForgotPasswordController::class, 'showForm'])->name('password.forgot');
Route::post('/password/forgot', [ForgotPasswordController::class, 'sendResetLink'])
    ->middleware('throttle:5,1')->name('password.email');
```

Add the import near the other `Auth` controller imports:

```php
use App\Http\Controllers\Auth\ForgotPasswordController;
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=ForgotPasswordTest"`
Expected: PASS (4 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Auth/ForgotPasswordController.php resources/views/auth/forgot-password.blade.php routes/web.php tests/Feature/Auth/ForgotPasswordTest.php
git commit -m "Add forgot-password request flow"
```

---

## Task 5: `ResetPasswordController` + reset page + route

**Files:**
- Create: `app/Http/Controllers/Auth/ResetPasswordController.php`
- Create: `resources/views/auth/reset-password.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Auth/ResetPasswordTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Auth/ResetPasswordTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResetPasswordTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Test]
    public function reset_password_page_renders_with_a_token(): void
    {
        $this->get('/password/reset/some-token?email=test@example.com')
            ->assertOk()
            ->assertViewIs('auth.reset-password')
            ->assertViewHas('token', 'some-token')
            ->assertViewHas('email', 'test@example.com');
    }

    #[Test]
    public function submitting_a_valid_token_resets_the_password_and_logs_in(): void
    {
        $user = User::factory()->create(['email' => 'test@example.com', 'pw' => Hash::make('old-password'), 'status' => 'enabled']);
        $token = Password::broker()->createToken($user);

        $response = $this->post('/password/reset', [
            'token' => $token,
            'email' => 'test@example.com',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ]);

        $response->assertRedirect('/calendar');
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('new-password123', $user->refresh()->pw));
    }

    #[Test]
    public function submitting_an_invalid_token_fails_without_changing_the_password(): void
    {
        $user = User::factory()->create(['email' => 'test@example.com', 'pw' => Hash::make('old-password'), 'status' => 'enabled']);

        $response = $this->post('/password/reset', [
            'token' => 'not-a-real-token',
            'email' => 'test@example.com',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ]);

        $response->assertRedirect(route('password.forgot'));
        $this->assertGuest();
        $this->assertTrue(Hash::check('old-password', $user->refresh()->pw));
    }

    #[Test]
    public function submitting_an_unknown_email_fails_gracefully(): void
    {
        $response = $this->post('/password/reset', [
            'token' => 'irrelevant',
            'email' => 'nobody@example.com',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ]);

        $response->assertRedirect(route('password.forgot'));
        $this->assertGuest();
    }

    #[Test]
    public function password_confirmation_mismatch_fails_validation(): void
    {
        $user = User::factory()->create(['email' => 'test@example.com', 'status' => 'enabled']);
        $token = Password::broker()->createToken($user);

        $this->post('/password/reset', [
            'token' => $token,
            'email' => 'test@example.com',
            'password' => 'new-password123',
            'password_confirmation' => 'does-not-match',
        ])->assertSessionHasErrors('password');
    }

    #[Test]
    public function the_reset_route_is_rate_limited_after_five_requests_per_minute(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/password/reset', [
                'token' => 'irrelevant',
                'email' => 'nobody@example.com',
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ]);
        }

        $this->post('/password/reset', [
            'token' => 'irrelevant',
            'email' => 'nobody@example.com',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertStatus(429);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=ResetPasswordTest"`
Expected: FAIL — route `/password/reset` doesn't exist yet.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/Auth/ResetPasswordController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

/**
 * ResetPasswordController — set a new password using a token from the emailed link.
 *
 * Routes: GET /password/reset/{token} (showForm), POST /password/reset (reset)
 * Auth: guest only
 */
final class ResetPasswordController extends Controller
{
    public function showForm(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Password::broker()->tokenExists($user, $data['token'])) {
            return redirect()->route('password.forgot')
                ->withErrors(['email' => __('booking.password_reset.reset_invalid')]);
        }

        $user->update(['pw' => Hash::make($data['password'])]);
        Password::broker()->deleteToken($user);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('calendar.index')
            ->with('success', __('booking.password_reset.reset_success'));
    }
}
```

- [ ] **Step 4: Write the view**

Create `resources/views/auth/reset-password.blade.php`, same shell as the forgot-password page:

```blade
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ str_replace(':system', $bookingName, __('booking.password_reset.reset_title')) }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Red+Hat+Display:wght@600;700&family=Red+Hat+Text:wght@400;500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('css/booking.css') }}">
</head>
<body class="min-h-screen bg-[#f0ede6] flex flex-col" style="font-family: var(--font-body)">
    <header class="bg-white border-b border-[#e0ddd7] px-4 sm:px-6 py-3 sm:h-14 flex items-center gap-3">
        @if($bookingLogoPath && file_exists(public_path($bookingLogoPath)))
            <img src="{{ asset($bookingLogoPath) }}" alt="{{ $bookingName }}" class="h-9 w-auto object-contain">
        @endif
        <span class="font-semibold text-[#151515] text-sm" style="font-family: var(--font-display)">{{ $bookingName }}</span>
    </header>

    <main class="flex-1 flex items-center justify-center p-4 sm:p-6">
        <div class="w-full max-w-md bg-white rounded-xl shadow-sm border border-[#e0ddd7] overflow-hidden">
            <div class="p-6 sm:p-10 flex flex-col gap-5">
                <h1 class="text-2xl font-bold text-[#151515] leading-tight" style="font-family: var(--font-display)">{{ __('booking.password_reset.reset_heading') }}</h1>
                <p class="text-sm text-[#6a6e73] leading-relaxed">{{ __('booking.password_reset.reset_intro') }}</p>

                <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-5">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <input type="hidden" name="email" value="{{ $email }}">

                    <div class="flex flex-col gap-1">
                        <label for="password" class="text-sm font-medium text-[#151515]">{{ __('booking.password_reset.reset_password') }}</label>
                        <input type="password" id="password" name="password" required autofocus autocomplete="new-password" class="w-full border rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent {{ $errors->has('password') ? 'border-red-400' : 'border-[#cccccc]' }}">
                        @error('password')
                            <p class="text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex flex-col gap-1">
                        <label for="password_confirmation" class="text-sm font-medium text-[#151515]">{{ __('booking.password_reset.reset_password_confirm') }}</label>
                        <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password" class="w-full border rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent border-[#cccccc]">
                    </div>

                    @if($errors->has('email'))
                        <div class="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-4 py-3">
                            {{ $errors->first('email') }}
                        </div>
                    @endif

                    <button type="submit" class="w-full bg-[#bf4316] hover:bg-[#9e3412] text-white font-medium py-2 px-4 rounded transition-colors mt-1">{{ __('booking.password_reset.reset_submit') }}</button>
                </form>
            </div>
        </div>
    </main>
</body>
<!-- {{ config('booking.watermark') }} -->
</html>
```

- [ ] **Step 5: Add the routes**

In `routes/web.php`, add right after the `/password/forgot` routes from Task 4:

```php
Route::get('/password/reset/{token}', [ResetPasswordController::class, 'showForm'])->name('password.reset');
Route::post('/password/reset', [ResetPasswordController::class, 'reset'])
    ->middleware('throttle:5,1')->name('password.update');
```

Add the import:

```php
use App\Http\Controllers\Auth\ResetPasswordController;
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=ResetPasswordTest"`
Expected: PASS (6 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Auth/ResetPasswordController.php resources/views/auth/reset-password.blade.php routes/web.php tests/Feature/Auth/ResetPasswordTest.php
git commit -m "Add password reset flow"
```

---

## Task 6: Link from the login page

**Files:**
- Modify: `resources/views/auth/login.blade.php:52-58`
- Test: `tests/Feature/Auth/LoginTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Auth/LoginTest.php` (inside the existing `LoginTest` class, near `login_page_renders`):

```php
    #[Test]
    public function login_page_has_a_forgot_password_link(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee(route('password.forgot'), false);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=login_page_has_a_forgot_password_link"`
Expected: FAIL — link not present on the page yet.

- [ ] **Step 3: Add the link**

In `resources/views/auth/login.blade.php`, change the password field block (lines 52-58) to add the link right after the label:

```blade
                    <div class="flex flex-col gap-1">
                        <div class="flex items-center justify-between">
                            <label for="password" class="text-sm font-medium text-[#151515]">{{ __('booking.auth.password') }}</label>
                            <a href="{{ route('password.forgot') }}" class="text-xs text-[#bf4316] hover:underline">{{ __('booking.auth.forgot_password') }}</a>
                        </div>
                        <input type="password" id="password" name="password" required autocomplete="current-password" class="w-full border rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent {{ $errors->has('password') ? 'border-red-400' : 'border-[#cccccc]' }}">
                        @error('password')
                            <p class="text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=LoginTest"`
Expected: PASS (all `LoginTest` tests, including the new one)

- [ ] **Step 5: Commit**

```bash
git add resources/views/auth/login.blade.php tests/Feature/Auth/LoginTest.php
git commit -m "Link to the forgot-password page from login"
```

---

## Task 7: Full regression pass + changelog

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Run the full Auth feature test suite**

Run: `wsl bash -lc "cd /mnt/c/development/bookingnew && php artisan test --filter=Auth"`
Expected: All tests pass, including pre-existing `LoginTest`/`RegisterTest` plus the three new files from this plan.

- [ ] **Step 2: Add a CHANGELOG entry**

This repo's pre-commit hook requires a `CHANGELOG.md` entry for behavior-relevant changes (seen when the register-throttle commit was made earlier). Add a new dated entry (or append to today's date section if one already exists) at the top of `CHANGELOG.md`:

```markdown
- Added a self-service "forgot password" flow: request a reset link by email,
  set a new password via a 60-minute single-use token. Disabled/blocked accounts
  and unknown emails get the same generic response to prevent account
  enumeration.
```

- [ ] **Step 3: Commit**

```bash
git add CHANGELOG.md
git commit -m "Document the forgot-password feature in the changelog"
```
