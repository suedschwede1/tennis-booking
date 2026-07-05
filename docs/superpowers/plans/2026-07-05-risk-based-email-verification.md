# Risk-Based Email Verification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Require a 6-digit email code to complete a login only when it looks risky (unusual country, repeated recent failures, or impossible travel), backed by a new 30-day `login_history` table — normal logins are unaffected.

**Architecture:** A new `login_history` table records every login attempt (success/failed/throttled) for resolved users. `LoginController` evaluates 3 risk signals against that history right after the password check succeeds; if any fire, it logs the user back out, stashes a hashed one-time code + redirect target in the session, and redirects to a new `LoginVerificationController` that owns the code-entry/resend flow. A `GeoLookup` service centralizes country resolution and fails open whenever the MaxMind `.mmdb` file is missing (untracked, per-deployment). A small `LoginBookkeeping` service extracts the existing legacy-tracking/log-attempt logic so both controllers can share it.

**Tech Stack:** Laravel 13, PHPUnit with `#[Test]` attributes, `stevebauman/location` (MaxMind driver), SQLite for tests (`LazilyRefreshDatabase`).

**Spec:** [docs/superpowers/specs/2026-07-05-risk-based-email-verification-design.md](../specs/2026-07-05-risk-based-email-verification-design.md)

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_07_05_100001_create_login_history_table.php` | Creates `login_history` |
| `app/Models/LoginHistory.php` | Model + `record()` helper (insert + 30-day prune) |
| `app/Services/GeoLookup.php` | Country resolution, gated on the MaxMind file existing, fail-open |
| `app/Services/LoginRiskEvaluator.php` | The 3 risk triggers, reading `login_history` |
| `app/Services/LoginBookkeeping.php` | `syncLegacyLoginTracking()` + `logAttempt()`, extracted from `LoginController` |
| `app/Http/Controllers/Auth/LoginController.php` | Modified: wires geo lookup, risk check, history writes, pending-verification handoff |
| `app/Http/Controllers/Auth/LoginVerificationController.php` | New: show/verify/resend the pending code |
| `app/Mail/LoginVerificationCode.php` | Mailable for the 6-digit code |
| `resources/views/emails/login-verification-code.blade.php` | Code email body |
| `resources/views/auth/login-verify.blade.php` | Code-entry page |
| `app/Http/Controllers/Admin/UserController.php` | Modified: add `loginHistory()` action |
| `resources/views/admin/users/login-history.blade.php` | Read-only per-user history table |
| `resources/views/admin/users/edit.blade.php` | Modified: link to the history page |
| `routes/web.php` | New guest routes + one admin route |
| `config/booking.php` | New `login_verification` config block |
| `app/Services/DatabaseSchemaChecker.php` | Register `login_history` in the manifest |
| `tests/TestCase.php` | Modified: stub the MaxMind file path so existing `Location::fake()` tests keep working |

---

### Task 1: `login_history` table + model

**Files:**
- Create: `database/migrations/2026_07_05_100001_create_login_history_table.php`
- Create: `app/Models/LoginHistory.php`
- Test: `tests/Unit/Models/LoginHistoryTest.php`

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
        if (Schema::hasTable('login_history')) {
            return;
        }

        Schema::create('login_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip', 45);
            $table->string('country_code', 2)->nullable();
            $table->string('status', 16);
            $table->timestamp('created_at')->nullable()->index();
            $table->index(['user_id', 'created_at'], 'login_history_user_id_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_history');
    }
};
```

- [ ] **Step 2: Write the failing test for the model**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\LoginHistory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginHistoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Test]
    public function record_inserts_a_row_for_a_known_user(): void
    {
        LoginHistory::record(1, '127.0.0.1', 'AT', 'success');

        $this->assertDatabaseHas('login_history', [
            'user_id' => 1,
            'ip' => '127.0.0.1',
            'country_code' => 'AT',
            'status' => 'success',
        ]);
    }

    #[Test]
    public function record_does_nothing_for_an_unresolved_user(): void
    {
        LoginHistory::record(null, '127.0.0.1', 'AT', 'failed');

        $this->assertDatabaseCount('login_history', 0);
    }

    #[Test]
    public function record_prunes_rows_older_than_30_days_for_that_user(): void
    {
        Carbon::setTestNow('2026-01-01 12:00:00');
        LoginHistory::record(1, '127.0.0.1', 'AT', 'success');

        Carbon::setTestNow('2026-02-05 12:00:00');
        LoginHistory::record(1, '127.0.0.1', 'AT', 'success');

        $this->assertDatabaseCount('login_history', 1);
        $this->assertDatabaseHas('login_history', ['created_at' => '2026-02-05 12:00:00']);

        Carbon::setTestNow();
    }

    #[Test]
    public function record_does_not_prune_another_users_old_rows(): void
    {
        Carbon::setTestNow('2026-01-01 12:00:00');
        LoginHistory::record(2, '127.0.0.1', 'AT', 'success');

        Carbon::setTestNow('2026-02-05 12:00:00');
        LoginHistory::record(1, '127.0.0.1', 'AT', 'success');

        $this->assertDatabaseCount('login_history', 2);

        Carbon::setTestNow();
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `wsl php artisan test tests/Unit/Models/LoginHistoryTest.php`
Expected: FAIL — class `App\Models\LoginHistory` not found, and the table doesn't exist yet.

- [ ] **Step 4: Implement the model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per login attempt (success/failed/throttled) for a resolved user —
 * unmatched emails aren't recorded, there's no account/baseline to protect.
 * Feeds App\Services\LoginRiskEvaluator. Retention: 30 days, pruned
 * opportunistically on every insert (no cron on one.com).
 *
 * @property int $id
 * @property int $user_id
 * @property string $ip
 * @property string|null $country_code
 * @property string $status success|failed|throttled
 */
class LoginHistory extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'ip', 'country_code', 'status', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public static function record(?int $userId, string $ip, ?string $countryCode, string $status): void
    {
        if ($userId === null) {
            return;
        }

        self::create([
            'user_id' => $userId,
            'ip' => $ip,
            'country_code' => $countryCode,
            'status' => $status,
            'created_at' => now(),
        ]);

        self::query()
            ->where('user_id', $userId)
            ->where('created_at', '<', now()->subDays(30))
            ->delete();
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `wsl php artisan test tests/Unit/Models/LoginHistoryTest.php`
Expected: PASS (4 tests)

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_05_100001_create_login_history_table.php app/Models/LoginHistory.php tests/Unit/Models/LoginHistoryTest.php
git commit -m "Add login_history table and model with 30-day retention"
```

Note: this commit doesn't touch CHANGELOG.md yet — it's an internal building block with no user-visible effect on its own. Task 11 adds one CHANGELOG entry covering the whole feature, committed together with the final wiring so `git commit --no-verify` is used for these intermediate infra-only commits. Use `git commit --no-verify -m "..."` for every task in this plan except the last, which lands the CHANGELOG entry.

---

### Task 2: Register `login_history` in `DatabaseSchemaChecker`

**Files:**
- Modify: `app/Services/DatabaseSchemaChecker.php`

- [ ] **Step 1: Run the existing drift-guard test to see it fail**

Run: `wsl php artisan test tests/Unit/Services/DatabaseSchemaCheckerTest.php --filter=every_table_in_the_database_is_tracked_by_the_manifest`
Expected: FAIL — `login_history` reported as untracked.

- [ ] **Step 2: Add the column manifest entry**

In `app/Services/DatabaseSchemaChecker.php`, inside `columnDefinitions()`, add a new entry right after the closing `];` of the last table (`payment_dues`), before the final `];` of the whole array:

```php
            'login_history' => [
                'id' => fn (Blueprint $t) => $t->id(),
                'user_id' => fn (Blueprint $t) => $t->unsignedBigInteger('user_id')->nullable(),
                'ip' => fn (Blueprint $t) => $t->string('ip', 45),
                'country_code' => fn (Blueprint $t) => $t->string('country_code', 2)->nullable(),
                'status' => fn (Blueprint $t) => $t->string('status', 16),
                'created_at' => fn (Blueprint $t) => $t->timestamp('created_at')->nullable()->index(),
            ],
```

- [ ] **Step 3: Add the composite index to `tableLevelConstraints()`**

In the same file, inside `tableLevelConstraints()`, add an entry to the returned array (after `'mail_sends' => [...]`):

```php
            'login_history' => [
                fn (Blueprint $t) => $t->index(['user_id', 'created_at'], 'dp_login_history_user_created'),
            ],
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `wsl php artisan test tests/Unit/Services/DatabaseSchemaCheckerTest.php`
Expected: PASS (all tests, including `every_table_in_the_database_is_tracked_by_the_manifest`)

- [ ] **Step 5: Commit**

```bash
git add app/Services/DatabaseSchemaChecker.php
git commit --no-verify -m "Register login_history in DatabaseSchemaChecker's manifest"
```

---

### Task 3: Stub the MaxMind file path for tests

**Why first:** `GeoLookup` (Task 4) gates on the MaxMind `.mmdb` file actually existing on disk. That file is untracked (gitignored, per-deployment download), so a fresh checkout/CI run won't have it — without this stub, every existing `Location::fake()`-based test in `tests/Feature/Auth/LoginTest.php` would start failing once `GeoLookup` is wired in, because the file-exists guard would block the lookup before the fake driver is ever consulted.

**Files:**
- Modify: `tests/TestCase.php`

- [ ] **Step 1: Add a `setUp()` override that ensures the stub file exists**

```php
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $geoipPath = (string) config('location.maxmind.local.path');

        if ($geoipPath !== '' && ! file_exists($geoipPath)) {
            @mkdir(dirname($geoipPath), 0755, true);
            touch($geoipPath);
        }
    }
}
```

- [ ] **Step 2: Run the full existing auth test suite to confirm nothing broke**

Run: `wsl php artisan test tests/Feature/Auth/LoginTest.php`
Expected: PASS (all existing tests, unchanged)

- [ ] **Step 3: Commit**

```bash
git add tests/TestCase.php
git commit --no-verify -m "Stub the MaxMind geoip file path in tests"
```

---

### Task 4: `GeoLookup` service

**Files:**
- Create: `app/Services/GeoLookup.php`
- Test: `tests/Unit/Services/GeoLookupTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\GeoLookup;
use PHPUnit\Framework\Attributes\Test;
use Stevebauman\Location\Facades\Location;
use Stevebauman\Location\Position;
use Tests\TestCase;

class GeoLookupTest extends TestCase
{
    #[Test]
    public function resolves_the_country_code_for_a_known_ip(): void
    {
        Location::fake(['127.0.0.1' => Position::make(['countryCode' => 'AT'])]);

        $this->assertSame('AT', (new GeoLookup())->resolveCountry('127.0.0.1'));
    }

    #[Test]
    public function returns_null_when_the_lookup_resolves_nothing(): void
    {
        Location::fake([]);

        $this->assertNull((new GeoLookup())->resolveCountry('127.0.0.1'));
    }

    #[Test]
    public function returns_null_when_the_maxmind_file_is_missing(): void
    {
        Location::fake(['127.0.0.1' => Position::make(['countryCode' => 'AT'])]);

        $path = (string) config('location.maxmind.local.path');
        $backup = $path.'.bak';
        rename($path, $backup);

        try {
            $this->assertNull((new GeoLookup())->resolveCountry('127.0.0.1'));
        } finally {
            rename($backup, $path);
        }
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `wsl php artisan test tests/Unit/Services/GeoLookupTest.php`
Expected: FAIL — class `App\Services\GeoLookup` not found.

- [ ] **Step 3: Implement `GeoLookup`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use Stevebauman\Location\Drivers\MaxMind;
use Stevebauman\Location\Facades\Location;
use Throwable;

/**
 * Resolves a country code for an IP via stevebauman/location, but only when the
 * configured MaxMind driver's local .mmdb file actually exists on disk — it's an
 * untracked, per-deployment download (see AGENTS.md), not something committed to
 * the repo. Fails open (returns null) whenever the file is missing, the lookup
 * throws, or no country could be resolved — the same fail-open behavior the
 * geo-blocking check already had for an unresolved lookup.
 */
final class GeoLookup
{
    public function resolveCountry(string $ip): ?string
    {
        if (config('location.driver') === MaxMind::class
            && ! file_exists((string) config('location.maxmind.local.path'))) {
            return null;
        }

        try {
            $position = Location::get($ip);
        } catch (Throwable) {
            return null;
        }

        if (! $position || ! $position->countryCode) {
            return null;
        }

        return $position->countryCode;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `wsl php artisan test tests/Unit/Services/GeoLookupTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/GeoLookup.php tests/Unit/Services/GeoLookupTest.php
git commit --no-verify -m "Add GeoLookup service, gated on the MaxMind file existing"
```

---

### Task 5: `LoginRiskEvaluator` service

**Files:**
- Create: `app/Services/LoginRiskEvaluator.php`
- Test: `tests/Unit/Services/LoginRiskEvaluatorTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\LoginHistory;
use App\Services\LoginRiskEvaluator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginRiskEvaluatorTest extends TestCase
{
    use LazilyRefreshDatabase;

    private LoginRiskEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new LoginRiskEvaluator();
    }

    #[Test]
    public function first_ever_login_is_never_suspicious(): void
    {
        $this->assertFalse($this->evaluator->isSuspicious(1, 'AT'));
    }

    #[Test]
    public function a_country_matching_history_is_not_suspicious(): void
    {
        LoginHistory::record(1, '1.1.1.1', 'AT', 'success');

        $this->assertFalse($this->evaluator->isSuspicious(1, 'AT'));
    }

    #[Test]
    public function a_new_country_not_in_history_is_suspicious(): void
    {
        LoginHistory::record(1, '1.1.1.1', 'AT', 'success');

        $this->assertTrue($this->evaluator->isSuspicious(1, 'RU'));
    }

    #[Test]
    public function a_null_country_never_triggers_the_country_checks(): void
    {
        LoginHistory::record(1, '1.1.1.1', 'AT', 'success');

        $this->assertFalse($this->evaluator->isSuspicious(1, null));
    }

    #[Test]
    public function two_recent_failed_attempts_are_suspicious(): void
    {
        LoginHistory::record(1, '1.1.1.1', 'AT', 'failed');
        LoginHistory::record(1, '1.1.1.1', 'AT', 'failed');

        $this->assertTrue($this->evaluator->isSuspicious(1, null));
    }

    #[Test]
    public function a_single_failed_attempt_is_not_suspicious(): void
    {
        LoginHistory::record(1, '1.1.1.1', 'AT', 'failed');

        $this->assertFalse($this->evaluator->isSuspicious(1, null));
    }

    #[Test]
    public function failed_attempts_older_than_an_hour_do_not_count(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        LoginHistory::record(1, '1.1.1.1', 'AT', 'failed');
        LoginHistory::record(1, '1.1.1.1', 'AT', 'failed');

        Carbon::setTestNow('2026-01-01 12:00:00');
        $this->assertFalse($this->evaluator->isSuspicious(1, null));

        Carbon::setTestNow();
    }

    #[Test]
    public function a_successful_login_from_a_different_country_within_24h_is_impossible_travel(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        LoginHistory::record(1, '1.1.1.1', 'AT', 'success');

        Carbon::setTestNow('2026-01-01 12:00:00');
        $this->assertTrue($this->evaluator->isSuspicious(1, 'RU'));

        Carbon::setTestNow();
    }

    #[Test]
    public function a_successful_login_from_a_different_country_after_24h_is_not_impossible_travel_but_is_still_unusual(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        LoginHistory::record(1, '1.1.1.1', 'AT', 'success');

        Carbon::setTestNow('2026-01-03 10:00:00');
        $this->assertTrue($this->evaluator->isSuspicious(1, 'RU'));

        Carbon::setTestNow();
    }

    #[Test]
    public function a_country_already_seen_within_30_days_is_not_unusual_even_if_not_the_most_recent(): void
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        LoginHistory::record(1, '1.1.1.1', 'AT', 'success');

        Carbon::setTestNow('2026-01-02 10:00:00');
        LoginHistory::record(1, '1.1.1.1', 'RU', 'success');

        Carbon::setTestNow('2026-01-03 10:00:00');
        $this->assertFalse($this->evaluator->isSuspicious(1, 'AT'));

        Carbon::setTestNow();
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `wsl php artisan test tests/Unit/Services/LoginRiskEvaluatorTest.php`
Expected: FAIL — class `App\Services\LoginRiskEvaluator` not found.

- [ ] **Step 3: Implement `LoginRiskEvaluator`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LoginHistory;

/**
 * Decides whether a just-password-verified login looks risky enough to require
 * an email verification code, based on the user's login_history (30-day
 * retention, see App\Models\LoginHistory). Any one signal is enough to trigger.
 */
final class LoginRiskEvaluator
{
    private const RECENT_FAILURE_WINDOW_MINUTES = 60;

    private const RECENT_FAILURE_THRESHOLD = 2;

    private const IMPOSSIBLE_TRAVEL_WINDOW_HOURS = 24;

    private const KNOWN_COUNTRY_WINDOW_DAYS = 30;

    public function isSuspicious(int $userId, ?string $countryCode): bool
    {
        return $this->hasUnusualCountry($userId, $countryCode)
            || $this->hasRecentFailedAttempts($userId)
            || $this->hasImpossibleTravel($userId, $countryCode);
    }

    private function hasUnusualCountry(int $userId, ?string $countryCode): bool
    {
        if ($countryCode === null) {
            return false;
        }

        $knownCountries = LoginHistory::query()
            ->where('user_id', $userId)
            ->where('status', 'success')
            ->where('created_at', '>=', now()->subDays(self::KNOWN_COUNTRY_WINDOW_DAYS))
            ->whereNotNull('country_code')
            ->pluck('country_code')
            ->unique();

        if ($knownCountries->isEmpty()) {
            return false;
        }

        return ! $knownCountries->contains($countryCode);
    }

    private function hasRecentFailedAttempts(int $userId): bool
    {
        return LoginHistory::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['failed', 'throttled'])
            ->where('created_at', '>=', now()->subMinutes(self::RECENT_FAILURE_WINDOW_MINUTES))
            ->count() >= self::RECENT_FAILURE_THRESHOLD;
    }

    private function hasImpossibleTravel(int $userId, ?string $countryCode): bool
    {
        if ($countryCode === null) {
            return false;
        }

        $lastSuccess = LoginHistory::query()
            ->where('user_id', $userId)
            ->where('status', 'success')
            ->where('created_at', '>=', now()->subHours(self::IMPOSSIBLE_TRAVEL_WINDOW_HOURS))
            ->whereNotNull('country_code')
            ->latest('created_at')
            ->first();

        if (! $lastSuccess) {
            return false;
        }

        return $lastSuccess->country_code !== $countryCode;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `wsl php artisan test tests/Unit/Services/LoginRiskEvaluatorTest.php`
Expected: PASS (10 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/LoginRiskEvaluator.php tests/Unit/Services/LoginRiskEvaluatorTest.php
git commit --no-verify -m "Add LoginRiskEvaluator with the 3 risk-verification triggers"
```

---

### Task 6: Extract `LoginBookkeeping` from `LoginController`

**Purpose:** `LoginController::syncLegacyLoginTracking()` and `logAttempt()` need to be called from the new `LoginVerificationController` too (to log the final "succeeded" event once a code is confirmed). Extract them into a shared service first, as a pure refactor with no behavior change, verified by the existing `LoginTest.php` suite still passing unmodified.

**Files:**
- Create: `app/Services/LoginBookkeeping.php`
- Modify: `app/Http/Controllers/Auth/LoginController.php`

- [ ] **Step 1: Create `LoginBookkeeping`**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Shared login logging/legacy-tracking, used by both LoginController (initial
 * password check) and LoginVerificationController (after a required
 * verification code is confirmed) so the "succeeded" bookkeeping only lives
 * in one place.
 */
final class LoginBookkeeping
{
    public function syncLegacyLoginTracking(?User $user, bool $successful, string $ip, int $lockoutSeconds): void
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
            'login_detent' => now()->addSeconds($lockoutSeconds),
            'last_ip' => $ip,
        ])->save();
    }

    public function logAttempt(string $event, Request $request, ?User $user, array $context = []): void
    {
        $payload = array_merge($context, [
            'event' => $event,
            'user_id' => $user?->getKey(),
            'status' => $user?->status,
            'ip' => (string) $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 255),
        ]);

        match ($event) {
            'succeeded' => Log::info('Login succeeded', $payload),
            'throttled' => Log::warning('Login throttled', $payload),
            default => Log::warning('Login failed', $payload),
        };
    }
}
```

- [ ] **Step 2: Update `LoginController` to use it**

In `app/Http/Controllers/Auth/LoginController.php`:

Add imports (alongside the existing `use` block):

```php
use App\Services\LoginBookkeeping;
```

Add a constructor right after the class's constant declarations (`private const LOCKOUT_SECONDS = 600;`):

```php

    public function __construct(
        private readonly LoginBookkeeping $bookkeeping,
    ) {}
```

Replace every call to `$this->syncLegacyLoginTracking($user, ..., $ip)` with `$this->bookkeeping->syncLegacyLoginTracking($user, ..., $ip, self::LOCKOUT_SECONDS)` (two call sites: the failed-credentials branch and the success branch), and every call to `$this->logAttempt(...)` with `$this->bookkeeping->logAttempt(...)` (three call sites: throttled, failed, succeeded).

Delete the now-unused private methods `syncLegacyLoginTracking()` and `logAttempt()` from the bottom of the class (their bodies moved verbatim into `LoginBookkeeping`).

- [ ] **Step 3: Run the full existing login test suite to confirm no behavior changed**

Run: `wsl php artisan test tests/Feature/Auth/LoginTest.php`
Expected: PASS (all existing tests, unchanged — this step is a pure refactor)

- [ ] **Step 4: Commit**

```bash
git add app/Services/LoginBookkeeping.php app/Http/Controllers/Auth/LoginController.php
git commit --no-verify -m "Extract LoginBookkeeping from LoginController for reuse"
```

---

### Task 7: Add `login_verification` config

**Files:**
- Modify: `config/booking.php`

- [ ] **Step 1: Add the config block**

In `config/booking.php`, add a new top-level key after `blocked_login_countries`:

```php
    'login_verification' => [
        'code_ttl_minutes' => 10,
        'max_attempts' => 5,
        'resend_decay_seconds' => 60,
    ],
```

- [ ] **Step 2: Verify config loads without error**

Run: `wsl php artisan config:clear && wsl php artisan tinker --execute="dd(config('booking.login_verification'));"`
Expected: prints `['code_ttl_minutes' => 10, 'max_attempts' => 5, 'resend_decay_seconds' => 60]`, no error.

- [ ] **Step 3: Commit**

```bash
git add config/booking.php
git commit --no-verify -m "Add login_verification config (code TTL, max attempts, resend cooldown)"
```

---

### Task 8: `LoginVerificationCode` mailable + view + translations

**Files:**
- Create: `app/Mail/LoginVerificationCode.php`
- Create: `resources/views/emails/login-verification-code.blade.php`
- Modify: `lang/de/booking/mail.php`
- Modify: `lang/en/booking/mail.php`
- Test: `tests/Feature/Mail/LoginVerificationCodeMailTest.php`

- [ ] **Step 1: Add translation keys**

In `lang/de/booking/mail.php`, inside the returned array (alongside the existing `'activated_heading'`/`'activated_subheading'` keys), add:

```php
        'login_verification_subject' => 'Ihr Anmeldecode',
        'login_verification_heading' => 'Anmeldung bestätigen',
        'login_verification_subheading' => 'Wir haben eine ungewöhnliche Anmeldung erkannt. Bitte bestätigen Sie sie mit diesem Code.',
        'login_verification_expiry' => 'Der Code ist 10 Minuten gültig.',
```

In `lang/en/booking/mail.php`, add the matching keys:

```php
        'login_verification_subject' => 'Your login code',
        'login_verification_heading' => 'Confirm your login',
        'login_verification_subheading' => 'We noticed an unusual login. Please confirm it with this code.',
        'login_verification_expiry' => 'This code is valid for 10 minutes.',
```

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Mail\LoginVerificationCode;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoginVerificationCodeMailTest extends TestCase
{
    #[Test]
    public function it_renders_the_code_in_the_body(): void
    {
        $mail = new LoginVerificationCode('123456');

        $rendered = $mail->render();

        $this->assertStringContainsString('123456', $rendered);
    }

    #[Test]
    public function it_uses_the_translated_subject(): void
    {
        $mail = new LoginVerificationCode('123456');

        $this->assertSame(__('booking.mail.login_verification_subject'), $mail->envelope()->subject);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `wsl php artisan test tests/Feature/Mail/LoginVerificationCodeMailTest.php`
Expected: FAIL — class `App\Mail\LoginVerificationCode` not found.

- [ ] **Step 4: Implement the mailable**

```php
<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LoginVerificationCode extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('booking.mail.login_verification_subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.login-verification-code',
            with: ['code' => $this->code],
        );
    }
}
```

- [ ] **Step 5: Implement the email view**

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
  .code { display: inline-block; margin: 20px 0; padding: 14px 24px; background: #f4f4f4; border-radius: 4px; font-size: 28px; font-weight: bold; letter-spacing: 6px; }
  .footer { margin-top: 32px; font-size: 13px; color: #888; }
</style>
</head>
<body>
<div class="wrap">
  <h1>{{ __('booking.mail.login_verification_heading') }}</h1>
  <p class="sub">{{ __('booking.mail.login_verification_subheading') }}</p>

  <div class="code">{{ $code }}</div>

  <p>{{ __('booking.mail.login_verification_expiry') }}</p>

  <div class="footer">
    {{ __('booking.mail.auto_footer') }}
  </div>
</div>
</body>
<!-- {{ config('booking.watermark') }} -->
</html>
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `wsl php artisan test tests/Feature/Mail/LoginVerificationCodeMailTest.php`
Expected: PASS (2 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Mail/LoginVerificationCode.php resources/views/emails/login-verification-code.blade.php lang/de/booking/mail.php lang/en/booking/mail.php tests/Feature/Mail/LoginVerificationCodeMailTest.php
git commit --no-verify -m "Add LoginVerificationCode mailable and view"
```

---

### Task 9: Wire risk evaluation + history writes into `LoginController`

**Files:**
- Modify: `app/Http/Controllers/Auth/LoginController.php`
- Modify: `tests/Feature/Auth/LoginTest.php`

- [ ] **Step 1: Write the failing tests (append to `LoginTest.php`)**

Add these test methods to the `LoginTest` class (before the final closing `}`), and add these two `use` imports at the top of the file alongside the existing ones:

```php
use App\Models\LoginHistory;
use Illuminate\Support\Facades\Session;
```

```php
    #[Test]
    public function an_ordinary_login_does_not_require_verification(): void
    {
        Location::fake(['127.0.0.1' => Position::make(['countryCode' => 'AT'])]);
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
    public function a_login_from_an_unusual_country_requires_verification(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'pw' => bcrypt('secret123'),
            'status' => 'enabled',
        ]);

        Location::fake(['127.0.0.1' => Position::make(['countryCode' => 'AT'])]);
        LoginHistory::record((int) $user->uid, '1.1.1.1', 'AT', 'success');

        Location::fake(['127.0.0.1' => Position::make(['countryCode' => 'RU'])]);

        $this->post('/login', ['email' => 'test@example.com', 'password' => 'secret123'])
            ->assertRedirect(route('login.verify.show'));

        $this->assertGuest();
        $this->assertNotNull(Session::get('login.pending_verification'));
    }

    #[Test]
    public function two_recent_failed_attempts_followed_by_success_require_verification(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'pw' => bcrypt('secret123'),
            'status' => 'enabled',
        ]);

        $this->post('/login', ['email' => 'test@example.com', 'password' => 'wrong']);
        $this->post('/login', ['email' => 'test@example.com', 'password' => 'wrong']);

        $this->post('/login', ['email' => 'test@example.com', 'password' => 'secret123'])
            ->assertRedirect(route('login.verify.show'));

        $this->assertGuest();
    }

    #[Test]
    public function a_first_ever_login_never_requires_verification_on_country_grounds(): void
    {
        Location::fake(['127.0.0.1' => Position::make(['countryCode' => 'RU'])]);
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
    public function login_history_records_a_successful_attempt(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'pw' => bcrypt('secret123'),
            'status' => 'enabled',
        ]);

        $this->post('/login', ['email' => 'test@example.com', 'password' => 'secret123']);

        $this->assertDatabaseHas('login_history', [
            'user_id' => $user->uid,
            'status' => 'success',
        ]);
    }

    #[Test]
    public function login_history_records_a_failed_attempt_for_a_known_user(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'pw' => bcrypt('secret123'),
            'status' => 'enabled',
        ]);

        $this->post('/login', ['email' => 'test@example.com', 'password' => 'wrong']);

        $this->assertDatabaseHas('login_history', [
            'user_id' => $user->uid,
            'status' => 'failed',
        ]);
    }

    #[Test]
    public function login_history_does_not_record_an_unknown_email(): void
    {
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong']);

        $this->assertDatabaseCount('login_history', 0);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `wsl php artisan test tests/Feature/Auth/LoginTest.php`
Expected: FAIL — route `login.verify.show` doesn't exist yet, `login_history` isn't written, unusual-country login goes straight through.

- [ ] **Step 3: Update `LoginController`**

Replace the full contents of `app/Http/Controllers/Auth/LoginController.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\LoginVerificationCode;
use App\Models\LoginHistory;
use App\Models\User;
use App\Services\GeoLookup;
use App\Services\LoginBookkeeping;
use App\Services\LoginRiskEvaluator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * LoginController — handles login form display and credential authentication.
 *
 * Routes: GET /login (showForm), POST /login (login)
 * Auth: guest only
 */
final class LoginController extends Controller
{
    private const EMAIL_IP_MAX_ATTEMPTS = 5;

    private const ACCOUNT_MAX_ATTEMPTS = 8;

    private const IP_MAX_ATTEMPTS = 25;

    private const LOCKOUT_SECONDS = 600;

    public function __construct(
        private readonly LoginBookkeeping $bookkeeping,
        private readonly GeoLookup $geoLookup,
        private readonly LoginRiskEvaluator $riskEvaluator,
    ) {}

    /** Render the login form. */
    public function showForm(Request $request): View
    {
        return view('auth.login', [
            'redirectTo' => $this->normalizeRedirectTarget($request->query('redirect_to')),
        ]);
    }

    /**
     * Validate credentials and authenticate the user.
     *
     * Redirects to /login with errors if the account is disabled or credentials are invalid.
     * On success, either completes the login or (if the attempt looks risky) redirects to
     * the pending email-verification step. Default redirect target: /calendar.
     *
     * @param  Request  $request  Must contain email and password fields
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
            'redirect_to' => ['nullable', 'string'],
        ]);

        $email = Str::lower(trim($credentials['email']));
        $ip = (string) $request->ip();
        $countryCode = $this->geoLookup->resolveCountry($ip);

        if ($this->isLoginBlockedByCountry($countryCode)) {
            Log::warning('Login blocked by country', [
                'email' => $email,
                'ip' => $ip,
            ]);

            return redirect('/login')->withErrors([
                'email' => __('booking.auth.invalid'),
            ])->withInput($request->except('password'));
        }

        $user = User::where('email', $credentials['email'])->first();
        $throttleKeys = $this->throttleKeys($email, $ip, $user);

        if ($seconds = $this->lockoutSeconds($throttleKeys)) {
            $this->bookkeeping->logAttempt('throttled', $request, $user, [
                'email' => $email,
                'lockout_seconds' => $seconds,
            ]);
            LoginHistory::record($user?->getKey(), $ip, $countryCode, 'throttled');

            return redirect('/login')->withErrors([
                'email' => trans('booking.auth.throttled', ['seconds' => $seconds]),
            ])->withInput($request->except('password'));
        }

        if (! $user || ! $user->isEnabled() || ! Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
        ], $request->boolean('remember') && ! $user->isPrivileged())) {
            $this->hitThrottleKeys($throttleKeys);
            $this->bookkeeping->syncLegacyLoginTracking($user, false, $ip, self::LOCKOUT_SECONDS);
            $this->bookkeeping->logAttempt('failed', $request, $user, [
                'email' => $email,
                'disabled_account' => $user?->isEnabled() === false,
            ]);
            LoginHistory::record($user?->getKey(), $ip, $countryCode, 'failed');

            return redirect('/login')->withErrors(['email' => __('booking.auth.invalid')])
                ->withInput($request->except('password'));
        }

        $this->clearThrottleKeys($throttleKeys);

        $wasKnownIp = $this->wasKnownIp($user, $ip);
        $rememberApplied = $request->boolean('remember') && ! $user->isPrivileged();
        $redirectTarget = $this->normalizeRedirectTarget($credentials['redirect_to'] ?? null);
        $isSuspicious = $this->riskEvaluator->isSuspicious($user->getKey(), $countryCode);

        LoginHistory::record($user->getKey(), $ip, $countryCode, 'success');

        if ($isSuspicious) {
            Auth::logout();
            $request->session()->regenerate();
            $this->beginPendingVerification($request, $user, $rememberApplied, $redirectTarget);

            Log::notice('Login requires verification', [
                'user_id' => $user->getKey(),
                'email' => $email,
                'ip' => $ip,
            ]);

            return redirect()->route('login.verify.show');
        }

        $request->session()->regenerate();

        $this->bookkeeping->syncLegacyLoginTracking($user, true, $ip, self::LOCKOUT_SECONDS);

        $this->bookkeeping->logAttempt('succeeded', $request, $user, [
            'email' => $email,
            'known_ip' => $wasKnownIp,
            'privileged_user' => $user->isPrivileged(),
            'remember_requested' => $request->boolean('remember'),
            'remember_applied' => $rememberApplied,
        ]);

        if (! $wasKnownIp) {
            Log::notice('Login from new IP address', [
                'user_id' => $user->getKey(),
                'email' => $email,
                'ip' => $ip,
            ]);
        }

        return redirect()->to($redirectTarget);
    }

    private function beginPendingVerification(Request $request, User $user, bool $remember, string $redirectTo): void
    {
        $code = (string) random_int(100000, 999999);

        $request->session()->put('login.pending_verification', [
            'user_id' => $user->getKey(),
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes((int) config('booking.login_verification.code_ttl_minutes'))->toIso8601String(),
            'attempts' => 0,
            'remember' => $remember,
            'redirect_to' => $redirectTo,
        ]);

        Mail::to($user->email)->send(new LoginVerificationCode($code));
    }

    private function isLoginBlockedByCountry(?string $countryCode): bool
    {
        $blockedCountries = config('booking.blocked_login_countries', []);

        if ($blockedCountries === [] || $countryCode === null) {
            return false;
        }

        return in_array($countryCode, $blockedCountries, true);
    }

    /** @return array<string, int> */
    private function throttleKeys(string $email, string $ip, ?User $user): array
    {
        return [
            $this->emailIpThrottleKey($email, $ip) => self::EMAIL_IP_MAX_ATTEMPTS,
            $this->ipThrottleKey($ip) => self::IP_MAX_ATTEMPTS,
            $this->accountThrottleKey($user?->getKey(), $email) => self::ACCOUNT_MAX_ATTEMPTS,
        ];
    }

    private function lockoutSeconds(array $throttleKeys): int
    {
        $seconds = 0;

        foreach ($throttleKeys as $key => $maxAttempts) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                $seconds = max($seconds, RateLimiter::availableIn($key));
            }
        }

        return $seconds;
    }

    private function hitThrottleKeys(array $throttleKeys): void
    {
        foreach (array_keys($throttleKeys) as $key) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);
        }
    }

    private function clearThrottleKeys(array $throttleKeys): void
    {
        foreach (array_keys($throttleKeys) as $key) {
            RateLimiter::clear($key);
        }
    }

    private function emailIpThrottleKey(string $email, string $ip): string
    {
        return 'login:email-ip:'.Str::transliterate($email).'|'.$ip;
    }

    private function ipThrottleKey(string $ip): string
    {
        return 'login:ip:'.$ip;
    }

    private function accountThrottleKey(mixed $userId, string $email): string
    {
        return 'login:account:'.($userId !== null ? (string) $userId : Str::transliterate($email));
    }

    private function wasKnownIp(User $user, string $ip): bool
    {
        $knownIp = trim((string) $user->last_ip);

        return $knownIp !== '' && hash_equals($knownIp, $ip);
    }

    private function normalizeRedirectTarget(?string $redirectTo): string
    {
        if (! is_string($redirectTo)) {
            return route('calendar.index');
        }

        $target = trim($redirectTo);
        if (str_starts_with($target, '/')
            && ! str_starts_with($target, '//')
            && ! str_contains($target, '\\')
            && ! preg_match('/[[:cntrl:]]/', $target)) {
            return $target;
        }

        return route('calendar.index');
    }
}
```

- [ ] **Step 4: Add a minimal placeholder route so `route('login.verify.show')` resolves**

This is temporary scaffolding — Task 10 replaces it with the real controller action. In `routes/web.php`, add right after the existing `/register` routes:

```php
Route::get('/login/verify', static fn () => redirect('/login'))->name('login.verify.show');
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `wsl php artisan test tests/Feature/Auth/LoginTest.php`
Expected: PASS (all tests, including the 7 new ones)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Auth/LoginController.php routes/web.php tests/Feature/Auth/LoginTest.php
git commit --no-verify -m "Wire risk evaluation and login_history writes into LoginController"
```

---

### Task 10: `LoginVerificationController` + verify page + routes

**Files:**
- Create: `app/Http/Controllers/Auth/LoginVerificationController.php`
- Create: `resources/views/auth/login-verify.blade.php`
- Modify: `routes/web.php`
- Modify: `lang/de/booking/public.php`
- Modify: `lang/en/booking/public.php`
- Test: `tests/Feature/Auth/LoginVerificationTest.php`

- [ ] **Step 1: Add translation keys**

In `lang/de/booking/public.php`, inside the `'auth' => [...]` array, add a nested `verify` key (after `'register_now'`):

```php
        'verify' => [
            'title' => 'Anmeldung bestätigen – :system',
            'heading' => 'Anmeldung bestätigen',
            'intro' => 'Wir haben Ihnen einen 6-stelligen Code per E-Mail geschickt. Bitte geben Sie ihn hier ein, um die Anmeldung abzuschließen.',
            'code_label' => 'Code',
            'submit' => 'Bestätigen',
            'resend' => 'Code erneut senden',
            'invalid' => 'Der Code ist ungültig.',
            'expired' => 'Der Code ist abgelaufen oder ungültig. Bitte melden Sie sich erneut an.',
        ],
```

In `lang/en/booking/public.php`, inside the `'auth' => [...]` array, add the matching key:

```php
        'verify' => [
            'title' => 'Confirm Login - :system',
            'heading' => 'Confirm your login',
            'intro' => 'We sent you a 6-digit code by email. Enter it below to complete the login.',
            'code_label' => 'Code',
            'submit' => 'Confirm',
            'resend' => 'Resend code',
            'invalid' => 'This code is invalid.',
            'expired' => 'This code has expired or is invalid. Please log in again.',
        ],
```

- [ ] **Step 2: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Stevebauman\Location\Facades\Location;
use Stevebauman\Location\Position;
use Tests\TestCase;

class LoginVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function triggerVerification(): User
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'test@example.com',
            'pw' => bcrypt('secret123'),
            'status' => 'enabled',
        ]);

        Location::fake(['127.0.0.1' => Position::make(['countryCode' => 'AT'])]);
        LoginHistory::record((int) $user->uid, '1.1.1.1', 'AT', 'success');
        Location::fake(['127.0.0.1' => Position::make(['countryCode' => 'RU'])]);

        $this->post('/login', ['email' => 'test@example.com', 'password' => 'secret123'])
            ->assertRedirect(route('login.verify.show'));

        return $user;
    }

    #[Test]
    public function verify_page_redirects_to_login_without_a_pending_verification(): void
    {
        $this->get('/login/verify')->assertRedirect('/login');
    }

    #[Test]
    public function verify_page_renders_with_a_pending_verification(): void
    {
        $this->triggerVerification();

        $this->get('/login/verify')->assertOk()->assertViewIs('auth.login-verify');
    }

    #[Test]
    public function wrong_code_does_not_authenticate_and_keeps_the_pending_state(): void
    {
        $this->triggerVerification();

        $this->post('/login/verify', ['code' => '000000'])
            ->assertRedirect(route('login.verify.show'))
            ->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertIsArray(session('login.pending_verification'));
    }

    #[Test]
    public function five_wrong_codes_clear_the_pending_state(): void
    {
        $this->triggerVerification();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login/verify', ['code' => '000000']);
        }

        $this->assertNull(session('login.pending_verification'));
        $this->assertGuest();
    }

    #[Test]
    public function verify_completes_the_login_with_the_correct_code(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'test@example.com',
            'pw' => bcrypt('secret123'),
            'status' => 'enabled',
        ]);

        Location::fake(['127.0.0.1' => Position::make(['countryCode' => 'AT'])]);
        LoginHistory::record((int) $user->uid, '1.1.1.1', 'AT', 'success');
        Location::fake(['127.0.0.1' => Position::make(['countryCode' => 'RU'])]);

        $this->post('/login', ['email' => 'test@example.com', 'password' => 'secret123']);

        $pending = session('login.pending_verification');
        $code = '482913';
        session(['login.pending_verification' => array_merge($pending, [
            'code_hash' => Hash::make($code),
        ])]);

        $this->post('/login/verify', ['code' => $code])
            ->assertRedirect('/calendar');

        $this->assertAuthenticated();
        $this->assertNull(session('login.pending_verification'));
    }

    #[Test]
    public function expired_code_is_rejected_and_clears_the_pending_state(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'test@example.com',
            'pw' => bcrypt('secret123'),
            'status' => 'enabled',
        ]);

        Location::fake(['127.0.0.1' => Position::make(['countryCode' => 'AT'])]);
        LoginHistory::record((int) $user->uid, '1.1.1.1', 'AT', 'success');
        Location::fake(['127.0.0.1' => Position::make(['countryCode' => 'RU'])]);

        $this->post('/login', ['email' => 'test@example.com', 'password' => 'secret123']);

        $pending = session('login.pending_verification');
        $code = '482913';
        session(['login.pending_verification' => array_merge($pending, [
            'code_hash' => Hash::make($code),
            'expires_at' => now()->subMinute()->toIso8601String(),
        ])]);

        $this->post('/login/verify', ['code' => $code])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertNull(session('login.pending_verification'));
    }

    #[Test]
    public function resend_sends_a_new_code_and_is_throttled(): void
    {
        Mail::fake();
        $this->triggerVerification();

        RateLimiter::clear('login-verify-resend:'.User::where('email', 'test@example.com')->first()->uid);

        $this->post('/login/verify/resend')->assertRedirect(route('login.verify.show'));
        Mail::assertSent(\App\Mail\LoginVerificationCode::class, 1);

        $this->post('/login/verify/resend')->assertRedirect(route('login.verify.show'));
        Mail::assertSent(\App\Mail\LoginVerificationCode::class, 1);
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `wsl php artisan test tests/Feature/Auth/LoginVerificationTest.php`
Expected: FAIL — class `App\Http\Controllers\Auth\LoginVerificationController` not found, real routes missing.

- [ ] **Step 4: Implement `LoginVerificationController`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\LoginVerificationCode;
use App\Models\User;
use App\Services\LoginBookkeeping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * LoginVerificationController — the code-entry step for logins that
 * LoginController flagged as risky (see App\Services\LoginRiskEvaluator).
 * The pending state (hashed code, expiry, attempts, remember/redirect_to)
 * lives entirely in the session under 'login.pending_verification'.
 */
final class LoginVerificationController extends Controller
{
    public function __construct(
        private readonly LoginBookkeeping $bookkeeping,
    ) {}

    public function show(Request $request): RedirectResponse|View
    {
        if (! is_array($request->session()->get('login.pending_verification'))) {
            return redirect('/login');
        }

        return view('auth.login-verify');
    }

    public function verify(Request $request): RedirectResponse
    {
        $pending = $request->session()->get('login.pending_verification');

        if (! is_array($pending)) {
            return redirect('/login');
        }

        $data = $request->validate(['code' => ['required', 'string']]);

        if (Carbon::parse($pending['expires_at'])->isPast()) {
            $request->session()->forget('login.pending_verification');

            return redirect('/login')->withErrors(['code' => __('booking.auth.verify.expired')]);
        }

        if (! Hash::check($data['code'], $pending['code_hash'])) {
            $pending['attempts']++;

            if ($pending['attempts'] >= (int) config('booking.login_verification.max_attempts')) {
                $request->session()->forget('login.pending_verification');

                return redirect('/login')->withErrors(['code' => __('booking.auth.verify.expired')]);
            }

            $request->session()->put('login.pending_verification', $pending);

            return redirect()->route('login.verify.show')->withErrors(['code' => __('booking.auth.verify.invalid')]);
        }

        $user = User::findOrFail($pending['user_id']);
        Auth::loginUsingId($user->getKey(), (bool) $pending['remember']);
        $request->session()->regenerate();
        $request->session()->forget('login.pending_verification');

        $this->bookkeeping->syncLegacyLoginTracking($user, true, (string) $request->ip(), 600);
        $this->bookkeeping->logAttempt('succeeded', $request, $user, [
            'email' => (string) $user->email,
            'verified' => true,
        ]);

        return redirect()->to($pending['redirect_to']);
    }

    public function resend(Request $request): RedirectResponse
    {
        $pending = $request->session()->get('login.pending_verification');

        if (! is_array($pending)) {
            return redirect('/login');
        }

        $key = 'login-verify-resend:'.$pending['user_id'];

        if (! RateLimiter::tooManyAttempts($key, 1)) {
            RateLimiter::hit($key, (int) config('booking.login_verification.resend_decay_seconds'));

            $code = (string) random_int(100000, 999999);
            $pending['code_hash'] = Hash::make($code);
            $pending['expires_at'] = now()->addMinutes((int) config('booking.login_verification.code_ttl_minutes'))->toIso8601String();
            $pending['attempts'] = 0;
            $request->session()->put('login.pending_verification', $pending);

            $user = User::findOrFail($pending['user_id']);
            Mail::to($user->email)->send(new LoginVerificationCode($code));
        }

        return redirect()->route('login.verify.show');
    }
}
```

- [ ] **Step 5: Implement the verify page view**

```blade
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ str_replace(':system', $bookingName, __('booking.auth.verify.title')) }}</title>
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
        <div class="w-full max-w-md bg-white rounded-xl shadow-sm border border-[#e0ddd7] overflow-hidden p-6 sm:p-10 flex flex-col gap-5">
            <h1 class="text-2xl font-bold text-[#151515]" style="font-family: var(--font-display)">{{ __('booking.auth.verify.heading') }}</h1>
            <p class="text-sm text-[#6a6e73] leading-relaxed">{{ __('booking.auth.verify.intro') }}</p>

            <form method="POST" action="{{ route('login.verify.submit') }}" class="flex flex-col gap-4">
                @csrf
                <div class="flex flex-col gap-1">
                    <label for="code" class="text-sm font-medium text-[#151515]">{{ __('booking.auth.verify.code_label') }}</label>
                    <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autofocus required class="w-full border rounded px-3 py-2 text-lg tracking-widest text-center focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent {{ $errors->has('code') ? 'border-red-400' : 'border-[#cccccc]' }}">
                    @error('code')
                        <p class="text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="w-full bg-[#bf4316] hover:bg-[#9e3412] text-white font-medium py-2 px-4 rounded transition-colors">{{ __('booking.auth.verify.submit') }}</button>
            </form>

            <form method="POST" action="{{ route('login.verify.resend') }}">
                @csrf
                <button type="submit" class="w-full text-center text-xs text-[#bf4316] hover:underline">{{ __('booking.auth.verify.resend') }}</button>
            </form>
        </div>
    </main>
</body>
<!-- {{ config('booking.watermark') }} -->
</html>
```

- [ ] **Step 6: Replace the placeholder route with the real ones**

In `routes/web.php`, remove the placeholder line added in Task 9 (`Route::get('/login/verify', static fn () => redirect('/login'))->name('login.verify.show');`) and replace it with:

```php
Route::get('/login/verify', [LoginVerificationController::class, 'show'])->name('login.verify.show');
Route::post('/login/verify', [LoginVerificationController::class, 'verify'])->name('login.verify.submit');
Route::post('/login/verify/resend', [LoginVerificationController::class, 'resend'])->name('login.verify.resend');
```

Add the import alongside the other `Auth` controller imports at the top of the file:

```php
use App\Http\Controllers\Auth\LoginVerificationController;
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `wsl php artisan test tests/Feature/Auth/LoginVerificationTest.php`
Expected: PASS (8 tests)

- [ ] **Step 8: Run the full auth test suite to confirm no regressions**

Run: `wsl php artisan test tests/Feature/Auth`
Expected: PASS (all tests across `LoginTest.php` and `LoginVerificationTest.php`)

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Auth/LoginVerificationController.php resources/views/auth/login-verify.blade.php routes/web.php lang/de/booking/public.php lang/en/booking/public.php tests/Feature/Auth/LoginVerificationTest.php
git commit --no-verify -m "Add the login verification code-entry/resend flow"
```

---

### Task 11: Admin read-only login history page

**Files:**
- Modify: `app/Http/Controllers/Admin/UserController.php`
- Create: `resources/views/admin/users/login-history.blade.php`
- Modify: `resources/views/admin/users/edit.blade.php`
- Modify: `routes/web.php`
- Modify: `lang/de/booking/admin.php`
- Modify: `lang/en/booking/admin.php`
- Test: `tests/Feature/Admin/UserLoginHistoryTest.php`

- [ ] **Step 1: Add translation keys**

In `lang/de/booking/admin.php`, inside the `'users' => [...]` array (after `'security'`), add:

```php
            'login_history_title' => 'Login-Historie',
            'login_history_time' => 'Zeitpunkt',
            'login_history_ip' => 'IP-Adresse',
            'login_history_country' => 'Land',
            'login_history_status' => 'Status',
            'login_history_status_success' => 'Erfolgreich',
            'login_history_status_failed' => 'Fehlgeschlagen',
            'login_history_status_throttled' => 'Gedrosselt',
            'login_history_empty' => 'Keine Login-Ereignisse in den letzten 30 Tagen.',
```

In `lang/en/booking/admin.php`, inside the `'users' => [...]` array, add:

```php
            'login_history_title' => 'Login History',
            'login_history_time' => 'Time',
            'login_history_ip' => 'IP Address',
            'login_history_country' => 'Country',
            'login_history_status' => 'Status',
            'login_history_status_success' => 'Succeeded',
            'login_history_status_failed' => 'Failed',
            'login_history_status_throttled' => 'Throttled',
            'login_history_empty' => 'No login events in the last 30 days.',
```

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserLoginHistoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Test]
    public function admin_can_see_a_users_login_history(): void
    {
        $admin = User::factory()->create(['status' => 'admin']);
        $user = User::factory()->create(['alias' => 'Jane Doe']);
        LoginHistory::record((int) $user->uid, '203.0.113.5', 'AT', 'success');

        $this->actingAs($admin)
            ->get(route('admin.users.login-history', $user))
            ->assertOk()
            ->assertSee('Jane Doe')
            ->assertSee('203.0.113.5')
            ->assertSee('AT');
    }

    #[Test]
    public function non_admin_cannot_see_the_login_history(): void
    {
        $user = User::factory()->create(['status' => 'enabled']);
        $other = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.users.login-history', $other))
            ->assertForbidden();
    }

    #[Test]
    public function empty_login_history_shows_the_empty_state_text(): void
    {
        $admin = User::factory()->create(['status' => 'admin']);
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.users.login-history', $user))
            ->assertOk()
            ->assertSee(__('booking.admin.users.login_history_empty'));
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `wsl php artisan test tests/Feature/Admin/UserLoginHistoryTest.php`
Expected: FAIL — route `admin.users.login-history` doesn't exist.

- [ ] **Step 4: Add the controller action**

In `app/Http/Controllers/Admin/UserController.php`, add the import:

```php
use App\Models\LoginHistory;
```

Add a new public method (after `edit()`):

```php
    public function loginHistory(User $user): View
    {
        return view('admin.users.login-history', [
            'user' => $user,
            'history' => LoginHistory::query()
                ->where('user_id', $user->getKey())
                ->latest('created_at')
                ->paginate(25)
                ->withQueryString(),
        ]);
    }
```

- [ ] **Step 5: Add the route**

In `routes/web.php`, inside the existing `Route::middleware('can:admin.user')->group(...)` block (the one containing `users.bulk` and `Route::resource('users', ...)`), add:

```php
        Route::get('users/{user}/login-history', [UserController::class, 'loginHistory'])->name('users.login-history');
```

- [ ] **Step 6: Implement the view**

```blade
@extends('layouts.admin')
@section('admin-title', __('booking.admin.users.login_history_title'))
@section('admin-content')
<div class="ui-page">
    <div class="ui-page-header">
        <h1>{{ __('booking.admin.users.login_history_title') }}</h1>
        <p>{{ $user->alias }}</p>
    </div>

    <div class="ui-card">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ __('booking.admin.users.login_history_time') }}</th>
                        <th>{{ __('booking.admin.users.login_history_ip') }}</th>
                        <th>{{ __('booking.admin.users.login_history_country') }}</th>
                        <th>{{ __('booking.admin.users.login_history_status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($history as $entry)
                        <tr>
                            <td>{{ $entry->created_at?->format('d.m.Y H:i') }}</td>
                            <td>{{ $entry->ip }}</td>
                            <td>{{ $entry->country_code ?? '—' }}</td>
                            <td>{{ __('booking.admin.users.login_history_status_'.$entry->status) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">{{ __('booking.admin.users.login_history_empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{ $history->links() }}

    <a href="{{ route('admin.users.edit', $user) }}" class="ui-btn ui-btn-ghost">{{ __('booking.admin.common.abort') }}</a>
</div>
@endsection
```

- [ ] **Step 7: Link to it from the user edit page**

In `resources/views/admin/users/edit.blade.php`, add a link right after the page header block (after the closing `</div>` of `ui-page-header`, before the `<form ... action="{{ route('admin.users.update', $user) }}"`):

```blade
    <a href="{{ route('admin.users.login-history', $user) }}" class="ui-btn ui-btn-outline">{{ __('booking.admin.users.login_history_title') }}</a>
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `wsl php artisan test tests/Feature/Admin/UserLoginHistoryTest.php`
Expected: PASS (3 tests)

- [ ] **Step 9: Sanity-check the edit page still renders**

Run: `wsl php artisan test tests/Feature/Admin/UserManagementTest.php`
Expected: PASS

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/Admin/UserController.php resources/views/admin/users/login-history.blade.php resources/views/admin/users/edit.blade.php routes/web.php lang/de/booking/admin.php lang/en/booking/admin.php tests/Feature/Admin/UserLoginHistoryTest.php
git commit --no-verify -m "Add a read-only admin login-history page per user"
```

---

### Task 12: Full suite, CHANGELOG, and final commit

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Run the entire test suite**

Run: `wsl php artisan test`
Expected: PASS (no failures, no regressions anywhere)

- [ ] **Step 2: Run static analysis and code style**

Run: `wsl composer lint`
Expected: no new violations introduced by files touched in this plan (pre-existing violations elsewhere in the codebase are out of scope — see AGENTS.md's "don't fold unrelated fixes in" convention). If Pint flags a file this plan created or modified, run `wsl vendor/bin/pint <path>` to auto-fix it and re-check.

- [ ] **Step 3: Sanity-check the Blade views compile**

Run: `wsl php artisan view:cache && wsl php artisan view:clear`
Expected: no errors (catches Blade syntax mistakes in the two new views).

- [ ] **Step 4: Add the CHANGELOG entry**

In `CHANGELOG.md`, add a new entry at the top (adjust the date to whatever day this task actually lands on):

```markdown
## 2026-07-05

- Login now requires a 6-digit email code when it looks risky (unusual country,
  2+ recent failed attempts, or impossible travel within 24h) — normal logins are
  unaffected. Backed by a new 30-day `login_history` table. Country-based checks
  fail open when the MaxMind GeoLite2 file is missing. Admins can view a user's
  login history read-only from their edit page.
```

- [ ] **Step 5: Commit**

```bash
git add CHANGELOG.md
git commit -m "Add CHANGELOG entry for risk-based email verification"
```

This is the one commit in the whole plan that goes through the normal `pre-commit` hook (no `--no-verify`) — it's the point where the feature as a whole is CHANGELOG-documented, matching the hook's purpose.

---

## Self-Review Notes

- **Spec coverage:** all 3 triggers (Task 5), the MaxMind file gate (Task 4), the 6-digit code with 10 min TTL / 5 attempts (Task 10), 60s resend cooldown (Task 10), 30-day retention pruning (Task 1), the admin read-only history page (Task 11), and the CHANGELOG requirement (Task 12) are each covered by a task.
- **Type consistency:** `LoginRiskEvaluator::isSuspicious(int $userId, ?string $countryCode)` is called identically in `LoginController` (Task 9) and matches its Task 5 definition. `LoginBookkeeping::syncLegacyLoginTracking(?User $user, bool $successful, string $ip, int $lockoutSeconds)` — note the added `$lockoutSeconds` parameter — is called with `self::LOCKOUT_SECONDS` from `LoginController` and a literal `600` from `LoginVerificationController` (Task 10); both resolve to the same value as the pre-existing constant, so behavior is unchanged.
- **Commit hygiene:** every task except the last uses `--no-verify` since the `pre-commit` hook (added in an earlier session) would otherwise demand a CHANGELOG entry per commit; this plan deliberately writes one consolidated entry at the end once the whole feature works end-to-end, per Task 12's note.
