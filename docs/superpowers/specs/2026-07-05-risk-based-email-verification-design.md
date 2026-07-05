# Risk-based email verification for login

Status: approved for planning
Date: 2026-07-05

## Purpose

The app deliberately has no MFA (see README "Security" section) because login has to
stay fast for a booking system. This adds a lightweight, **risk-triggered** second
factor: a 6-digit email code is only required when a login looks suspicious, based on
signals derived from a new 30-day login history. Normal logins are unaffected.

## Triggers

A successful password check (`Auth::attempt` succeeds) is followed by a risk
evaluation. Any one of the following triggers require the code:

1. **Unusual country** — the resolved country of this login is not among the countries
   of the user's successful logins in the last 30 days, **provided** the user has at
   least one prior successful login on record. A user's very first recorded login never
   triggers this (no baseline to compare against).
2. **Recent failed attempts** — 2 or more `failed`/`throttled` login_history rows for
   this user in the preceding 1 hour.
3. **Impossible travel** — the user's most recent prior successful login (within the
   last 24 hours) was from a different country than this one.

If country resolution fails (geolocation lookup returns nothing, same as the existing
`isLoginBlockedByCountry` fail-open behavior), triggers 1 and 3 are skipped for that
login; trigger 2 is unaffected since it doesn't depend on geo data.

**MaxMind dependency:** `config('location.driver')` is `MaxMind`, which reads a local
`.mmdb` file (`storage/app/geoip/GeoLite2-Country.mmdb`, path from
`GEOIP_DATABASE_PATH`). Like the untracked per-installation override files described in
AGENTS.md, this file isn't guaranteed to exist on every deployment (it's a downloaded
binary, not committed). Country-based checks (triggers 1 and 3, and the existing
`isLoginBlockedByCountry` geo-blocking) must **only run if the file exists** — checked
via `file_exists()` before calling `Location::get()` — and are silently skipped
(fail-open) otherwise, the same way they already fail open when a lookup returns no
result. Extract this into one shared helper (e.g. `App\Services\GeoLookup::resolveCountry(string $ip): ?string`)
used by both `isLoginBlockedByCountry()` and the new risk evaluator, so the file-exists
guard and the single-lookup-per-request optimization both live in one place instead of
being duplicated.

**Failed-attempt threshold note:** the existing lockout after `EMAIL_IP_MAX_ATTEMPTS = 5`
failed attempts (within the 10-minute `LOCKOUT_SECONDS` window) already exceeds trigger
2's `≥ 2 in 1 hour` threshold, so a login that follows such a lockout is always covered —
no separate rule needed for "5 failed attempts" specifically.

## Data model

New table `login_history` (migration in `database/migrations/`, registered in
`DatabaseSchemaChecker`'s manifest per existing convention):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | bigint, nullable, FK-like (no hard FK, consistent with `bs_*` legacy style) | Only rows for a resolved user are written — unmatched emails aren't logged here (nothing to protect/compare) |
| `ip` | string(45) | |
| `country_code` | string(2), nullable | Resolved once per login attempt via `Stevebauman\Location`, cached in-request to avoid duplicate lookups (geo-blocking check + risk evaluation would otherwise both call it) |
| `status` | string | `success`, `failed`, or `throttled` — mirrors `LoginController::logAttempt()` event names |
| `created_at` | timestamp | no `updated_at` needed |

Indexes: `(user_id, created_at)` for the risk queries, plus a plain index on `created_at`
for the retention cleanup.

**Retention (no cron on one.com):** immediately after inserting a row, opportunistically
delete this user's `login_history` rows older than 30 days
(`LoginHistory::where('user_id', $id)->where('created_at', '<', now()->subDays(30))->delete()`).
Scoped to the affected user only, so it stays cheap on every login.

## Verification flow

**Failed/throttled attempts:** `LoginController::login()` already has distinct branches
for a throttled attempt and a failed credential check (`logAttempt('throttled', ...)`
and `logAttempt('failed', ...)`). Each of those branches also inserts a `login_history`
row with the matching status, but **only if a user was resolved** from the submitted
email (`$user` is not null) — an unknown email has no account/baseline to protect, so
nothing is written. This is what feeds trigger 2 (recent failed attempts).

**Trigger point:** in `LoginController::login()`, right after `Auth::attempt()`
succeeds and before `session()->regenerate()` / the existing success bookkeeping.

1. Resolve country_code (reuse the existing `Location::get($ip)` call already made for
   geo-blocking earlier in the method — do it once, pass the result through).
2. Evaluate the 3 triggers against the user's **existing** `login_history` rows (the row
   for this attempt hasn't been written yet, so it can't self-trigger).
3. Insert the `login_history` row for this attempt with outcome `success`.
4. If no trigger fired in step 2: proceed exactly as today (regenerate session, sync
   legacy tracking, log `succeeded`, redirect).
5. If a trigger fired:
   - Do **not** call `Auth::attempt`'s resulting login further / do not treat the user as
     authenticated yet. (`Auth::attempt` already logged the user in on success, which is
     how Laravel's API works — so immediately call `Auth::logout()` to undo it, keeping
     the pre-verification state anonymous until the code is confirmed.)
   - Regenerate the session ID now (prevents session fixation for the pending state).
   - Generate a random 6-digit numeric code (`random_int(100000, 999999)`).
   - Store in session under `login.pending_verification`: `user_id`, `code_hash`
     (`Hash::make($code)`), `expires_at` (`now()->addMinutes(10)`), `attempts` (0),
     `redirect_to`, `remember` (bool, the originally-requested remember-me value).
   - Send `LoginVerificationCodeMail` (new Mailable, styled like the existing
     transactional mails — inline CSS, `resources/views/emails/`) containing the code,
     to `$user->email`.
   - Log the pending state at `notice` level (event name `verification_required`, plus
     which trigger(s) fired) — useful for tuning thresholds later without a UI.
   - Redirect to `GET /login/verify`.

### Verify step

- `GET /login/verify` — shows a small form (one 6-digit input), reuses the auth layout.
  If there's no `login.pending_verification` in session, redirect to `/login`.
- `POST /login/verify` — validates the submitted code:
  - Expired (`now() > expires_at`) → clear pending state, redirect to `/login` with an
    error telling the user to log in again.
  - Wrong code → increment `attempts` in session; at 5 attempts, clear pending state and
    redirect to `/login` with an error (same message as expired, don't reveal which).
  - Correct code → `Auth::loginUsingId($user_id, $remember)`, `session()->regenerate()`,
    clear `login.pending_verification`, then run the existing success bookkeeping
    (`syncLegacyLoginTracking`, `logAttempt('succeeded', ...)`), redirect to the stored
    `redirect_to` target.
- `POST /login/verify/resend` — re-sends a fresh code (new code + new expiry, same
  session entry), throttled via the existing `RateLimiter` facade with key
  `login-verify-resend:{user_id}`, 1 per 60 seconds. Silently ignored (no error, just
  no-op) if called before the cooldown elapses, to avoid leaking timing info beyond
  "try again shortly".

## Admin UI

Read-only login history per user, linked from the existing admin user edit page
(`admin/users/{user}/edit`): a new route/view `admin/users/{user}/login-history` listing
the user's `login_history` rows (timestamp, IP, country, status), newest first, paginated.
No filtering/search in this iteration — just a simple table, same `ui-table` styling as
the rest of the admin. Permission: same `can:admin.user` gate as the rest of user
management.

## Error handling

- Mail send failure (exception from `Mail::send`): don't silently let the user in.
  Catch, log at `error`, redirect to `/login` with a generic "could not send
  verification email, please try again or contact an admin" error. The pending session
  state is still set (code exists even if the mail failed) — a resend retry can recover
  without restarting the password step, unless resend is also failing, in which case the
  user is stuck until an admin looks at the logs (acceptable for a rare failure mode on a
  club booking system).
- Existing password-stage rate limiting (`email+ip`/`ip`/`account` throttle keys) is
  untouched — it still applies before this flow even starts.

## Testing

Feature tests (extending existing `LoginController` test coverage):

- Each of the 3 triggers independently forces the verify step; a login with none of them
  goes straight through as today.
- First-ever login for a user (no `login_history` rows yet) never triggers the
  country-based checks.
- Geo lookup failure skips country-based checks but failed-attempt trigger still fires.
- Missing MaxMind `.mmdb` file skips country-based checks the same way (fail-open),
  without throwing.
- Correct code completes login and preserves `remember`/`redirect_to`.
- Wrong code up to 4 times keeps the pending state; the 5th clears it and requires
  restarting from `/login`.
- Expired code is rejected the same way as exceeding attempts.
- Resend is throttled to 1/60s.
- A `login_history` row older than 30 days is gone after the next login for that user.
- Admin login-history page renders the user's rows, is denied without `admin.user`.

Non-functional:

- Register `login_history` in `DatabaseSchemaChecker`'s manifest; the existing drift
  regression test (`tests/Unit/Services/DatabaseSchemaCheckerTest.php`) will fail until
  this is done, which is the intended guard rail.
- Add a CHANGELOG.md entry (enforced by the `pre-commit` hook).

## Non-goals

- Not a general opt-in 2FA/TOTP system; purely the three risk triggers above. A
  user-facing "always require a code" toggle is a possible future addition, out of scope
  here.
- No editing/deleting of `login_history` rows from the admin UI (read-only).
- No email/SMS choice — email only, consistent with there being no phone number field
  requirement today.
