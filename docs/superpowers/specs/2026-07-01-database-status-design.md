# Database status & validation

## Problem

The app runs against the real legacy MySQL database `booking_local`. Migrations in `database/migrations/` mirror that schema and are idempotent (`if (Schema::hasTable(...)) return;`), so they're safe to run against the live database. But the production server (one.com shared hosting) has **no SSH or cron access** — there is no way to run `php artisan migrate` from the command line there. Today, schema changes have to be applied manually against the live database, and there's no way to see from the admin UI whether the schema is up to date.

## Goals

- Detect which migrations are pending (not yet applied) without needing CLI access.
- Detect column-level drift: a table exists but is missing columns the app expects.
- Let an admin apply pending migrations from a button in the web UI — the only execution path available on this host.
- Keep it usable without extra PHP extensions that may not be enabled on shared hosting (rules out spinning up a second SQLite connection to diff live).

## Non-goals

- No type-level or index-level diffing (column presence only, per user's choice of scope).
- No automatic/unattended execution — migrations only run when an admin clicks the button.
- No rollback UI. `down()` migrations remain a manual/CLI concern for local dev.

## Design

### 1. Migration status

Reuse Laravel's own migration bookkeeping instead of building a new mechanism: the `migrations` table already records which migration files have run. A small service reads:

- All migration files in `database/migrations/` (via `Illuminate\Database\Migrations\MigrationRepositoryInterface` / `DatabaseMigrationRepository`).
- Which of them are recorded as run.

The difference is the pending list — this is exactly what `php artisan migrate:status` computes, just consumed programmatically instead of via CLI.

### 2. Column validation

A new class `App\Services\DatabaseSchemaChecker` holds a small **declarative manifest** of expected tables and their columns, e.g.:

```php
private const EXPECTED_COLUMNS = [
    'bs_users' => ['uid', 'alias', 'status', 'email', 'pw', 'login_attempts', 'login_detent', 'last_activity', 'last_ip', 'created'],
    'bs_users_meta' => [...],
    // ... one entry per bs_* table, mirrored from the migration files
];
```

For each table: check existence via `Schema::hasTable()`, then diff `Schema::getColumnListing($table)` against the expected list. Report missing columns (present in manifest, absent in DB) and extra columns (present in DB, absent in manifest — informational only, not an error, since the legacy DB may have columns the app doesn't use yet).

This manifest is manually kept in sync with the migration files — same maintenance model already used for the migration docblocks ("Mirrors the real legacy booking_local schema"). No new PHP extension or second DB connection required, so it works unmodified on shared hosting.

### 3. Admin page

- New route `admin.database.index` (`GET /admin/database`), controller `Admin\DatabaseController`.
- New sidebar link "Database" between "Courts" and "Texts", gated behind the same `admin.config` permission used for other sensitive config pages.
- View shows two sections:
  - **Migrations**: table of migration file → status (✅ run / ⏳ pending).
  - **Tables**: table of expected table → exists (✅/❌) → missing columns (list, if any).
- **"Run pending migrations"** button (`POST /admin/database/migrate`): calls `Artisan::call('migrate', ['--force' => true])` in-process, captures output, flashes a success/error message, redirects back to the status page. Confirmation dialog before submit (same JS `confirm()` pattern as the existing bulk user actions).
- The status view itself is read-only and requires no confirmation — it just runs the two checks above on page load.

### 4. Security

- Route group requires `can('admin.config')`, consistent with other admin configuration pages.
- The migrate button is a POST request (not a link) with CSRF protection, plus a client-side confirm dialog warning that it modifies the live database.

### 5. Testing

- Feature test: non-admin gets 403 on both routes.
- Feature test: admin sees pending vs. run migrations correctly (seed the `migrations` table with a subset of known migration names, assert the rest show as pending).
- Feature test: admin sees a missing column flagged when a test table is created without one of its expected columns.
- Feature test: POST to `/admin/database/migrate` runs migrations and redirects with a success flash message.

## Open questions / future extensions (not in scope now)

- Type/index-level diffing (explicitly deferred per user's chosen scope).
- Notifying admins automatically when new migrations land (e.g. a dashboard badge) — could reuse the same pending-migrations check on the existing dashboard, but not requested now.
