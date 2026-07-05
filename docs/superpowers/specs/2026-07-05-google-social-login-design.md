# Google Social Login

## Goal

Let existing members log in with their Google account, in addition to the existing
email/password login (which remains unchanged).

## Scope

- Provider: **Google only**, via the core `laravel/socialite` package (no additional
  community provider package needed — Google is natively supported).
- Apple and Microsoft were considered and explicitly rejected for now: both require
  extra setup overhead (Apple needs a paid Developer membership, a Service ID,
  Team-ID/Key-ID, a `.p8` private key, and a JWT client secret that expires every 6
  months and must be regenerated; Microsoft needs an Azure app registration with
  tenant configuration). Can be added later since the data model is provider-agnostic.
- **Account linking only, no self-registration.** A Google login only succeeds if the
  Google account's email matches an existing, enabled `bs_users` row. Unknown Google
  accounts cannot create a new member record — membership is managed exclusively
  through the Members module / admin user management.
- Linking happens **automatically on first successful login** by email match — no
  separate "link account" step in the account area.

## Data model

New table `oauth_identities` (a user can in principle link more than one provider in
the future, so this is a separate table rather than columns on `bs_users`):

```
oauth_identities
  id                 bigint, PK
  user_id            FK -> bs_users.id
  provider           string  ('google')
  provider_user_id   string  (Google's stable "sub" claim, NOT the email)
  email              string  (email as supplied by the provider at link time)
  created_at
  unique(provider, provider_user_id)
```

Must be registered in `App\Services\DatabaseSchemaChecker`'s manifest (project
convention — otherwise the drift checker flags it as untracked; a test guards this,
see `tests/Unit/Services/DatabaseSchemaCheckerTest.php`).

## Login flow

New routes, guest-only (same middleware as the existing login routes):

- `GET /login/google/redirect`
- `GET /login/google/callback`

New `App\Http\Controllers\Auth\SocialLoginController`:

1. `redirect()` — `Socialite::driver('google')->redirect()`.
2. `callback()`:
   - Fetch the Google user via Socialite.
   - Normalize the email the same way as `LoginController` (lowercase, trim).
   - Apply the existing geo-blocking check (`BOOKING_BLOCKED_LOGIN_COUNTRIES`,
     reused from `LoginController`).
   - Look up `bs_users` by email.
   - **No match, or user not `isEnabled()`**: redirect to `/login` with the same
     generic error message used for invalid password login
     (`booking.auth.invalid`), and log it the same way as a failed password login
     (`Log::warning('Login failed', ...)`).
   - **Match**:
     - Insert an `oauth_identities` row if one doesn't already exist for this
       `(provider, provider_user_id)` (idempotent — repeat logins don't duplicate).
     - `Auth::login($user)`, regenerate the session.
     - Apply the same legacy login tracking as `LoginController`
       (`last_ip`, `last_activity`, `login_attempts` reset).
     - Log success the same way as a successful password login
       (`Log::info('Login succeeded', ...)`).
     - Redirect to the intended destination the same way `LoginController` does
       (default: `calendar.index`).
3. Rate limiting: a dedicated `RateLimiter` throttle key per IP on the callback
   endpoint (e.g. 15 attempts / 10 minutes), separate from the password-login
   throttle keys, to prevent abuse of the callback endpoint itself.

Password login (`LoginController`) is not modified and continues to work exactly as
today.

## Configuration

`.env`:

```
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=https://tcbewegung.at/login/google/callback
```

`config/services.php` gets a `google` entry wired to those env vars (Socialite's
standard convention).

Setup is a one-time, free step in the Google Cloud Console (project → OAuth consent
screen with `email`/`profile` scopes, published to production → OAuth client ID of
type "Web application" with the redirect URI above registered, both for production
and `http://localhost:8001/login/google/callback` for local development).

## UI

One "Mit Google anmelden" button below the existing login form in `auth.login`.
Button text/labels added to `lang/de/booking/public.php` and
`lang/en/booking/public.php` (existing `auth` text group).

## Tests

Feature tests for `SocialLoginController`, mocking the `Socialite` facade:

- Successful login (email match, account enabled) → authenticated, redirected,
  `oauth_identities` row created.
- Repeat login with the same Google account → no duplicate `oauth_identities` row.
- No matching `bs_users` email → generic invalid-login error, not authenticated.
- Matching email but account disabled (`isEnabled()` false) → generic invalid-login
  error, not authenticated.
- Geo-blocked IP → generic invalid-login error, not authenticated.
- Callback endpoint throttling after repeated attempts from the same IP.
