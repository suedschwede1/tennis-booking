# Block login attempts from configured countries

## Problem

There is currently no way to reject login attempts based on the origin country of the request. The site should be able to block login attempts coming from specific countries (e.g. Russia) as an abuse-prevention measure, without affecting anything else on the site.

## Design

### 1. Packages

- `stevebauman/location` — IP → location resolution facade/service.
- `geoip2/geoip2` — required by the package's `MaxMindDatabase` driver to read a local `.mmdb` file.

### 2. GeoIP database file

- MaxMind's free `GeoLite2-Country.mmdb` (country-level is sufficient; no need for the larger City database).
- Obtained via a free MaxMind account + license key (maxmind.com/en/geolite2/signup), downloaded manually.
- Stored at `storage/app/geoip/GeoLite2-Country.mmdb`.
- **Not committed to git** — added to `.gitignore`, uploaded separately (FTP/SSH) to the server, same pattern as `.env`. Needs occasional manual re-download to stay current; no automated updater (no cron on one.com shared hosting).

### 3. Configuration

- `config/location.php` — published `stevebauman/location` config, default driver set to `MaxMindDatabase`, database path from `env('GEOIP_DATABASE_PATH', storage_path('app/geoip/GeoLite2-Country.mmdb'))`.
- `config/booking.php` — new key:
  ```php
  'blocked_login_countries' => array_filter(array_map('trim', explode(',', env('BOOKING_BLOCKED_LOGIN_COUNTRIES', '')))),
  ```
  Value is a comma-separated list of ISO 3166-1 alpha-2 country codes (e.g. `RU,BY`), configured entirely via `.env` — no admin UI, no database table. Matches the existing all-env-driven style of `config/booking.php`.
- `.env.example` gets `BOOKING_BLOCKED_LOGIN_COUNTRIES=` and `GEOIP_DATABASE_PATH=` placeholders.

### 4. Enforcement point: `LoginController::login()`

At the very start of `login()`, before rate-limiting and credential checks:

- Resolve the request IP (`$request->ip()`) and look up its country via the `Location` facade.
- **Fail-open**: if the lookup throws, returns no usable result, or the IP is private/local (e.g. local dev), the login proceeds normally — a GeoIP outage or unresolvable IP must never lock out legitimate users.
- If a country code is resolved and it appears in `config('booking.blocked_login_countries')`, the request is rejected immediately:
  - Same response as an invalid-credentials failure: `redirect('/login')->withErrors(['email' => __('booking.auth.invalid')])->withInput($request->except('password'))`. Deliberately generic — it must not reveal that a country block exists (avoids tipping off attackers to try VPNs).
  - Logged via `Log::warning('Login blocked by country', [...])` with country code, IP, and attempted email — a distinct log event from the existing `failed`/`throttled`/`succeeded` cases in `logAttempt()`.
  - Does **not** hit the existing rate limiter — it's not a credential attempt, so it shouldn't count against `EMAIL_IP_MAX_ATTEMPTS`/`ACCOUNT_MAX_ATTEMPTS`/`IP_MAX_ATTEMPTS`.

Implementation shape: a new private method `isCountryBlocked(string $ip): bool` on `LoginController`, wrapping the `Location::get($ip)` call in try/catch (fail-open on any exception) and checking the resolved `countryCode` against the configured list.

Scope is login only — `showForm()` (GET /login) and every other route are unaffected.

## Tests

`stevebauman/location`'s `Location` facade can be mocked directly in tests (no real `.mmdb` access needed):

- Login attempt from a blocked country is rejected with the generic invalid-credentials error, and no session is authenticated.
- Login attempt from a non-blocked country proceeds normally (unaffected by the new check).
- A failed/unresolvable location lookup (mocked to throw or return null) does not block the login — fail-open confirmed.
- Rate limiter counters are untouched by a country-blocked attempt (throttle keys aren't hit).
