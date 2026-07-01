# Language switching (de/en)

## Problem

The app locale is fixed to `config('app.locale')` (`de`) for every request — nothing ever calls `App::setLocale()` outside of tests. There is no UI element or route to switch languages, and several Blade layouts hardcode `<html lang="de">` even though `lang/de` and `lang/en` translation files both already exist.

## Design

### 1. Per-user / per-guest locale resolution

No new column on `bs_users` — that table maps to the real legacy schema and must not be extended. Instead, reuse the existing `bs_users_meta` key/value mechanism already used for `allow.*` flags and profile fields:

- Logged-in users: preference stored via `$user->setMeta('locale', $locale)` / read via `$user->getMeta('locale')`.
- Guests (and as a fallback for logged-in users before their first switch): a `locale` cookie, 1 year lifetime.

`config/app.php` gets a new `available_locales` entry: `['de', 'en']`.

### 2. `SetLocale` middleware

New `App\Http\Middleware\SetLocale`, registered in the `web` middleware group (`bootstrap/app.php` → `withMiddleware(fn ($m) => $m->appendToGroup('web', SetLocale::class))` or `$m->web(append: [...])`).

Resolution order per request:
1. Authenticated user's `bs_users_meta` `locale` value, if set and in `available_locales`.
2. `locale` cookie, if set and in `available_locales`.
3. `config('app.locale')`.

Calls `App::setLocale($locale)` and `Carbon::setLocale($locale)` (mirrors the existing call in `CalendarController::index`, which becomes redundant but harmless once the middleware runs first).

### 3. Switch route

```
GET /lang/{locale}
```

- `App\Http\Controllers\LocaleController::switch(string $locale)`.
- 404 if `$locale` not in `config('app.available_locales')`.
- If authenticated: `auth()->user()->setMeta('locale', $locale)`.
- Always: queue a `locale` cookie (1 year) with the chosen value, so guests and logged-out visits keep the choice too.
- Redirect: `back()` (falls back to `calendar.index` if there's no previous URL, Laravel's default).

No CSRF concerns (GET, no state mutation visible to other users); matches the simplicity of the rest of the nav links.

### 4. Header UI

In `resources/views/components/layout/header.blade.php`, add a small "DE | EN" switcher next to the login/logout controls (desktop actions area). Current locale rendered as plain text (not a link), the other as a link to `route('lang.switch', ['locale' => $other])`. Add the route in `routes/web.php` as `Route::get('/lang/{locale}', ...)->name('lang.switch');` (outside the `auth` group — must work for guests).

### 5. Fix hardcoded `lang="de"`

Replace `<html lang="de">` with `<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">` in:
- `resources/views/layouts/app.blade.php`
- `resources/views/auth/login.blade.php`
- `resources/views/auth/register.blade.php`
- `resources/views/layouts/popup.blade.php`

(`admin.blade.php` and the email templates already do this.)

## Tests

- `SetLocale` middleware (feature or unit-with-request): user meta takes priority over cookie; cookie takes priority over default; invalid/missing values fall back to default.
- `LocaleController::switch`: valid locale → redirect + cookie present + (when authenticated) meta persisted; invalid locale → 404.
- Header renders both locale links pointing at `lang.switch` with the correct current-locale highlighted (light Blade/feature assertion, e.g. `assertSee` on `href="/lang/en"` when current locale is `de`).
