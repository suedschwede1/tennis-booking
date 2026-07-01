# Quote locale leak fix + overridable base quotes

## Problem

The booking-confirmation quote feature (`BookingController::store()`) already guards the German-only `quotes_named` pool behind `app()->getLocale() === 'de'`, but `QuoteGroups::quotesFor()` — which reads the private, per-installation `lang/de/booking/quote_groups.php` — is merged into the pool unconditionally, regardless of locale. Since that file is hardcoded to the German path, its content always leaks German quotes into English sessions too.

There's also no way for an installation to replace the built-in base quotes (`booking.quotes` in `lang/{locale}/booking/public.php`) without editing tracked files. A generic `lang/{locale}/booking/local.php` override exists, but it's not a dedicated/discoverable mechanism for this specific list.

## Design

### 1. Fix the locale leak

In `BookingController::store()`, only merge `QuoteGroups::quotesFor(...)` when `app()->getLocale() === 'de'` — the same guard already used for `quotes_named`.

### 2. Dedicated per-locale override file for base quotes

- New optional files: `lang/de/booking/quotes.local.php`, `lang/en/booking/quotes.local.php` — not tracked in git (add to `.gitignore`, following the existing `quote_groups.php` pattern).
- Matching example files: `lang/de/booking/quotes.local.example.php`, `lang/en/booking/quotes.local.example.php`, each returning a plain list of quote strings with a header comment explaining usage (mirrors `quote_groups.example.php`'s style).
- Semantics: if the file exists and returns a non-empty array, it **fully replaces** the built-in `booking.quotes` list for that locale — no merging with the shipped quotes.
- Implementation: extend `App\Services\QuoteGroups` with a new static method, e.g. `baseQuotes(string $locale, array $default): array`, that resolves the file path for the given locale, requires it if present, and returns its content when non-empty, otherwise `$default`. `BookingController` calls this right after building `$pool` from `__('booking.quotes')`.

### 3. Tests

- Feature test: with `app()->setLocale('en')`, a booking with a `quote_group` assigned via a fake/override does not produce a German group quote (existing German-only group content must not appear).
- Unit test for `QuoteGroups::baseQuotes()`: no override file → returns `$default` unchanged; override file present with quotes → returns exactly the override list.
