# Forgot Password — Design

## Problem

There is currently no self-service way to recover a forgotten password. Password
changes only work while logged in (`/my-account/password`), or via an admin manually
resetting a user's password (`/admin/users/{user}/password`). Users who forget their
password and can't log in have no way to help themselves.

## Approach

Use Laravel's built-in password broker (`Illuminate\Support\Facades\Password`,
`password_reset_tokens` table) for token generation, expiry, and single-use
invalidation — this is battle-tested and avoids re-implementing token security
ourselves. Controller logic stays hand-written (matching `LoginController` /
`RegisterController`'s style in this codebase), not Laravel's Breeze/Fortify
scaffolding traits.

## Flow

1. User clicks "Passwort vergessen?" on the login page → `GET /password/forgot`
2. User submits their email → `POST /password/forgot`
   - **Only if** a user with that email exists and `isEnabled()` is true (status
     `enabled`, `assist`, or `admin`): a token is created via
     `Password::broker()->createToken($user)` and the custom `PasswordReset`
     mailable is queued directly (not `Password::sendResetLink()`'s default
     notification, which would send an unbranded English email — see the "New
     files" section below)
   - Regardless of whether a link was actually sent, the response is identical:
     a generic "if that address is registered, a reset link has been sent" message.
     This prevents account enumeration (existence *and* status) via response
     timing/content.
3. Email contains a link to `GET /password/reset/{token}?email=...`
4. User submits new password → `POST /password/reset`
   - `Password::reset()` validates the token (60 min expiry, single-use, per
     Laravel defaults) and, in the callback, sets `pw` via `Hash::make()` and saves
   - On success: log the user in (`Auth::login($user)`, `session()->regenerate()`)
     and redirect to `/calendar`
   - On failure (bad/expired token): redirect back to the forgot-password form with
     an error

## New files

- `database/migrations/xxxx_create_password_reset_tokens_table.php` — standard
  Laravel schema (`email` string PK, `token` string, `created_at` nullable timestamp)
- `app/Http/Controllers/Auth/ForgotPasswordController.php`
  - `showForm(): View` — renders the email-entry form
  - `sendResetLink(Request $request): RedirectResponse` — validates email, checks
    `isEnabled()`, calls `Password::sendResetLink()` conditionally, always returns
    the generic success message
- `app/Http/Controllers/Auth/ResetPasswordController.php`
  - `showForm(Request $request, string $token): View` — renders the new-password
    form, passing `token` and `email` (from query string) into hidden fields
  - `reset(Request $request): RedirectResponse` — validates `token`, `email`,
    `password` (`required|min:8|confirmed`), calls `Password::reset()`, logs in on
    success
- `app/Mail/PasswordReset.php` — `Mailable implements ShouldQueue`, analogous to
  `UserActivated.php`; carries the reset URL
- `resources/views/emails/password-reset.blade.php`
- `resources/views/auth/forgot-password.blade.php` — styled like
  `auth/login.blade.php`
- `resources/views/auth/reset-password.blade.php` — styled like
  `auth/login.blade.php`

## Routes (`routes/web.php`)

```php
Route::get('/password/forgot', [ForgotPasswordController::class, 'showForm'])->name('password.forgot');
Route::post('/password/forgot', [ForgotPasswordController::class, 'sendResetLink'])
    ->middleware('throttle:5,1')->name('password.email');
Route::get('/password/reset/{token}', [ResetPasswordController::class, 'showForm'])->name('password.reset');
Route::post('/password/reset', [ResetPasswordController::class, 'reset'])
    ->middleware('throttle:5,1')->name('password.update');
```

Both POST routes get `throttle:5,1` (same rate as the register endpoint added
earlier) — the request endpoint to stop enumeration/spam abuse, the reset endpoint
to slow down token-guessing attempts.

## Login page change

Add a "Passwort vergessen?" link next to the password field in
`auth/login.blade.php`, pointing to `route('password.forgot')`.

## Out of scope

- No changes to the admin-triggered password reset (`UserController::password`) —
  that stays as is.
- No "logout other devices" on reset — the app has no `remember_token`/session-list
  mechanism to revoke; out of scope for this feature.
- No UI change for disabled/blocked accounts beyond the generic message — they
  simply don't receive a link.

## Testing

- Feature test: requesting a reset link for an existing enabled user queues the
  mail (`Mail::fake()`) and shows the generic message
- Feature test: requesting a reset link for a non-existent email shows the same
  generic message and does not queue mail
- Feature test: requesting a reset link for a disabled/blocked user shows the same
  generic message and does not queue mail
- Feature test: submitting a valid token + new password resets `pw`, logs the user
  in, redirects to `/calendar`
- Feature test: submitting an expired/invalid token fails with an error, `pw`
  unchanged
- Feature test: the forgot-password POST route is rate-limited after 5 requests/min
- Feature test: login page shows the "Passwort vergessen?" link
