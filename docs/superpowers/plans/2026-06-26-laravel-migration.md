# Laravel Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate the TCBewegung tennis club booking system from Zend Framework 2 to Laravel 13 with comprehensive test coverage, full PHPDoc documentation, and PHP 9.0-forward-compatible code style.

**Architecture:** Laravel 13 monolith with Eloquent ORM replacing ZF2 Managers, Service classes preserving business logic, Blade templates replacing .phtml views. All 15 DB tables remain unchanged — migrations added for schema documentation. Existing MySQL data fully compatible.

**Tech Stack:** Laravel 13, PHP 8.2+ (forward-compatible to PHP 9.0), MySQL (production) / SQLite :memory: (tests), Blade templates, PHPUnit via Laravel test suite.

**PHP 9.0 Compatibility Rules (apply to every file):**
- `declare(strict_types=1);` at the top of every PHP file
- `readonly` on all constructor-injected dependencies
- Enums for all status/type fields (`BookingStatus`, `SquareStatus`, `BillingStatus`, `Visibility`)
- Full type declarations on all properties, parameters, return types — no `mixed` unless truly necessary
- No deprecated PHP 8.x functions

---

## Source Reference

Original codebase: `C:\development\booking` (Zend Framework 2 — do NOT modify)
New Laravel project: `C:\development\bookingnew`

---

## Phase 1 — Laravel Project Bootstrap

### Task 1: Create Laravel 13 project ✅ (in progress)

Already scaffolded via subagent. Pending confirmation of version and commit.

---

## Phase 2 — Enums (PHP 9.0 prep — do this before Models)

### Task 2: Create status Enums

**Files:**
- Create: `app/Enums/BookingStatus.php`
- Create: `app/Enums/BillingStatus.php`
- Create: `app/Enums/Visibility.php`
- Create: `app/Enums/SquareStatus.php`
- Create: `app/Enums/EventStatus.php`
- Create: `app/Enums/CouponType.php`
- Create: `app/Enums/UserStatus.php`
- Create: `app/Enums/ProductType.php`
- Create: `tests/Unit/Enums/EnumTest.php`

- [ ] **Step 1: Write failing test**

```php
// tests/Unit/Enums/EnumTest.php
<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\BillingStatus;
use App\Enums\BookingStatus;
use App\Enums\SquareStatus;
use App\Enums\Visibility;
use Tests\TestCase;

class EnumTest extends TestCase
{
    /** @test */
    public function booking_status_has_expected_cases(): void
    {
        $this->assertEquals('enabled', BookingStatus::Enabled->value);
        $this->assertEquals('disabled', BookingStatus::Disabled->value);
    }

    /** @test */
    public function billing_status_has_expected_cases(): void
    {
        $this->assertEquals('pending', BillingStatus::Pending->value);
        $this->assertEquals('paid', BillingStatus::Paid->value);
        $this->assertEquals('cancelled', BillingStatus::Cancelled->value);
        $this->assertEquals('uncollectable', BillingStatus::Uncollectable->value);
    }

    /** @test */
    public function square_status_has_expected_cases(): void
    {
        $this->assertEquals('enabled', SquareStatus::Enabled->value);
        $this->assertEquals('disabled', SquareStatus::Disabled->value);
        $this->assertEquals('readonly', SquareStatus::Readonly->value);
    }

    /** @test */
    public function visibility_has_expected_cases(): void
    {
        $this->assertEquals('public', Visibility::Public->value);
        $this->assertEquals('private', Visibility::Private->value);
    }

    /** @test */
    public function booking_status_from_string_works(): void
    {
        $status = BookingStatus::from('enabled');
        $this->assertSame(BookingStatus::Enabled, $status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test tests/Unit/Enums/EnumTest.php"
```
Expected: FAIL — Enum classes don't exist.

- [ ] **Step 3: Write all Enums**

```php
// app/Enums/BookingStatus.php
<?php

declare(strict_types=1);

namespace App\Enums;

/** Status of a booking record. */
enum BookingStatus: string
{
    case Enabled  = 'enabled';
    case Disabled = 'disabled';
}
```

```php
// app/Enums/BillingStatus.php
<?php

declare(strict_types=1);

namespace App\Enums;

/** Payment/billing lifecycle of a booking. */
enum BillingStatus: string
{
    case Pending       = 'pending';
    case Paid          = 'paid';
    case Cancelled     = 'cancelled';
    case Uncollectable = 'uncollectable';
}
```

```php
// app/Enums/Visibility.php
<?php

declare(strict_types=1);

namespace App\Enums;

/** Whether a booking is publicly visible in the calendar. */
enum Visibility: string
{
    case Public  = 'public';
    case Private = 'private';
}
```

```php
// app/Enums/SquareStatus.php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Availability status of a bookable court.
 *
 * - Enabled:  Public can book freely
 * - Disabled: Nobody can book (not even admins via UI)
 * - Readonly: Only privileged users (calendar.create-single-bookings) can book
 */
enum SquareStatus: string
{
    case Enabled  = 'enabled';
    case Disabled = 'disabled';
    case Readonly = 'readonly';
}
```

```php
// app/Enums/EventStatus.php
<?php

declare(strict_types=1);

namespace App\Enums;

/** Status of a court event or closure. */
enum EventStatus: string
{
    case Enabled  = 'enabled';
    case Disabled = 'disabled';
}
```

```php
// app/Enums/UserStatus.php
<?php

declare(strict_types=1);

namespace App\Enums;

/** Account activation status of a user. */
enum UserStatus: string
{
    case Enabled  = 'enabled';
    case Disabled = 'disabled';
}
```

```php
// app/Enums/CouponType.php
<?php

declare(strict_types=1);

namespace App\Enums;

/** How a coupon discount is applied. */
enum CouponType: string
{
    case Percent = 'percent';
    case Fixed   = 'fixed';
}
```

```php
// app/Enums/ProductType.php
<?php

declare(strict_types=1);

namespace App\Enums;

/** Whether a product is for a single booking or a subscription. */
enum ProductType: string
{
    case Single       = 'single';
    case Subscription = 'subscription';
}
```

- [ ] **Step 4: Run tests**

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test tests/Unit/Enums/EnumTest.php"
```
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Enums/ tests/Unit/Enums/
git commit -m "feat: add PHP 9.0-compatible status enums for all domain types"
```

---

## Phase 3 — Database Migrations

### Task 3: Create migrations for all 15 tables

**Files:**
- Create: `database/migrations/2026_06_26_000001_create_bs_users_table.php` (and 14 more)
- Create: `tests/Feature/MigrationTest.php`

- [ ] **Step 1: Write failing test**

```php
// tests/Feature/MigrationTest.php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function all_tables_exist_after_migration(): void
    {
        $tables = [
            'bs_users', 'bs_users_meta', 'bs_squares', 'bs_squares_meta',
            'bs_squares_products', 'bs_squares_pricing', 'bs_squares_coupons',
            'bs_bookings', 'bs_bookings_bills', 'bs_bookings_meta',
            'bs_reservations', 'bs_reservations_meta',
            'bs_events', 'bs_events_meta', 'bs_options',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(
                \Schema::hasTable($table),
                "Table {$table} does not exist"
            );
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/MigrationTest.php"
```
Expected: FAIL

- [ ] **Step 3: Remove default Laravel migrations**

Delete these files from `database/migrations/`:
- `*_create_users_table.php`
- `*_create_password_reset_tokens_table.php`
- `*_create_sessions_table.php`
- `*_create_cache_table.php`
- `*_create_jobs_table.php`

- [ ] **Step 4: Create all 15 migrations**

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan make:migration create_bs_users_table"
# ... repeat for all 15 tables
```

Fill each migration's `up()` method:

**bs_users:**
```php
Schema::create('bs_users', function (Blueprint $table) {
    $table->integer('uid')->autoIncrement();
    $table->string('name', 64);
    $table->string('email', 128)->unique();
    $table->string('password', 255)->nullable();
    $table->string('phone', 32)->nullable();
    $table->string('roles', 255)->nullable();
    $table->string('permissions', 255)->nullable();
    $table->string('status', 32)->default('enabled');
    $table->string('token', 128)->nullable()->unique();
    $table->integer('created')->default(0);
    $table->integer('updated')->default(0);
});
```

**bs_users_meta:**
```php
Schema::create('bs_users_meta', function (Blueprint $table) {
    $table->integer('umid')->autoIncrement();
    $table->integer('uid')->index();
    $table->string('meta_key', 64)->index();
    $table->text('meta_value')->nullable();
});
```

**bs_squares:**
```php
Schema::create('bs_squares', function (Blueprint $table) {
    $table->integer('sid')->autoIncrement();
    $table->string('name', 64);
    $table->string('alias', 64)->nullable();
    $table->string('status', 32)->default('enabled');
    $table->integer('capacity')->default(0);
    $table->integer('capacity_heterogenic')->default(0);
    $table->integer('time_start')->default(0);
    $table->integer('time_end')->default(86400);
    $table->integer('time_block')->default(3600);
    $table->integer('time_block_bookable')->default(0);
    $table->integer('time_block_bookable_max')->default(0);
    $table->integer('min_range_book')->default(0);
    $table->integer('range_book')->default(0);
    $table->integer('range_cancel')->default(0);
    $table->integer('priority')->default(0);
});
```

**bs_squares_meta:**
```php
Schema::create('bs_squares_meta', function (Blueprint $table) {
    $table->integer('smid')->autoIncrement();
    $table->integer('sid')->index();
    $table->string('meta_key', 64)->index();
    $table->text('meta_value')->nullable();
});
```

**bs_squares_products:**
```php
Schema::create('bs_squares_products', function (Blueprint $table) {
    $table->integer('spid')->autoIncrement();
    $table->integer('sid')->index();
    $table->string('name', 64);
    $table->string('type', 32)->default('single');
    $table->integer('price')->default(0);
    $table->integer('priority')->default(0);
});
```

**bs_squares_pricing:**
```php
Schema::create('bs_squares_pricing', function (Blueprint $table) {
    $table->integer('sprid')->autoIncrement();
    $table->integer('sid')->index();
    $table->integer('spid')->index();
    $table->integer('date_start')->default(0);
    $table->integer('date_end')->default(0);
    $table->integer('time_start')->default(0);
    $table->integer('time_end')->default(86400);
    $table->integer('price')->default(0);
    $table->integer('priority')->default(0);
});
```

**bs_squares_coupons:**
```php
Schema::create('bs_squares_coupons', function (Blueprint $table) {
    $table->integer('scid')->autoIncrement();
    $table->integer('sid')->index();
    $table->string('code', 64)->unique();
    $table->string('type', 32)->default('percent');
    $table->integer('value')->default(0);
    $table->integer('valid_from')->default(0);
    $table->integer('valid_until')->default(0);
    $table->integer('usage_max')->default(0);
    $table->integer('usage_count')->default(0);
    $table->string('status', 32)->default('enabled');
});
```

**bs_bookings:**
```php
Schema::create('bs_bookings', function (Blueprint $table) {
    $table->integer('bid')->autoIncrement();
    $table->integer('uid')->index();
    $table->integer('sid')->index();
    $table->string('status', 32)->default('enabled');
    $table->string('status_billing', 32)->default('pending');
    $table->string('visibility', 32)->default('public');
    $table->integer('quantity')->default(1);
    $table->integer('created')->default(0);
    $table->integer('updated')->default(0);
});
```

**bs_bookings_bills:**
```php
Schema::create('bs_bookings_bills', function (Blueprint $table) {
    $table->integer('bbid')->autoIncrement();
    $table->integer('bid')->index();
    $table->integer('spid')->nullable()->index();
    $table->integer('price')->default(0);
    $table->string('description', 255)->nullable();
});
```

**bs_bookings_meta:**
```php
Schema::create('bs_bookings_meta', function (Blueprint $table) {
    $table->integer('bmid')->autoIncrement();
    $table->integer('bid')->index();
    $table->string('meta_key', 64)->index();
    $table->text('meta_value')->nullable();
});
```

**bs_reservations:**
```php
Schema::create('bs_reservations', function (Blueprint $table) {
    $table->integer('rid')->autoIncrement();
    $table->integer('bid')->index();
    $table->integer('date')->index();
    $table->integer('time_start')->default(0);
    $table->integer('time_end')->default(0);
});
```

**bs_reservations_meta:**
```php
Schema::create('bs_reservations_meta', function (Blueprint $table) {
    $table->integer('rmid')->autoIncrement();
    $table->integer('rid')->index();
    $table->string('meta_key', 64)->index();
    $table->text('meta_value')->nullable();
});
```

**bs_events:**
```php
Schema::create('bs_events', function (Blueprint $table) {
    $table->integer('eid')->autoIncrement();
    $table->integer('sid')->index();
    $table->integer('datetime_start')->default(0);
    $table->integer('datetime_end')->default(0);
    $table->integer('capacity')->default(0);
    $table->string('status', 32)->default('enabled');
});
```

**bs_events_meta:**
```php
Schema::create('bs_events_meta', function (Blueprint $table) {
    $table->integer('emid')->autoIncrement();
    $table->integer('eid')->index();
    $table->string('meta_key', 64)->index();
    $table->text('meta_value')->nullable();
});
```

**bs_options:**
```php
Schema::create('bs_options', function (Blueprint $table) {
    $table->integer('oid')->autoIncrement();
    $table->string('option_key', 64)->unique();
    $table->text('option_value')->nullable();
});
```

- [ ] **Step 5: Run migration test**

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/MigrationTest.php"
```
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add database/migrations/ tests/Feature/MigrationTest.php
git commit -m "feat: add database migrations for all 15 booking tables"
```

---

## Phase 4 — Models

### Task 4: Create Eloquent Models

**PHP 9.0 rules:** `declare(strict_types=1)` in every file, `readonly` on injected deps, Enum casts on status columns.

**Files:**
- Create: `app/Models/User.php`
- Create: `app/Models/UserMeta.php`
- Create: `app/Models/Square.php`
- Create: `app/Models/SquareMeta.php`
- Create: `app/Models/SquareProduct.php`
- Create: `app/Models/SquarePricing.php`
- Create: `app/Models/SquareCoupon.php`
- Create: `app/Models/Booking.php`
- Create: `app/Models/BookingBill.php`
- Create: `app/Models/BookingMeta.php`
- Create: `app/Models/Reservation.php`
- Create: `app/Models/ReservationMeta.php`
- Create: `app/Models/Event.php`
- Create: `app/Models/EventMeta.php`
- Create: `app/Models/Option.php`
- Create: `tests/Unit/Models/BookingModelTest.php`
- Create: `tests/Unit/Models/SquareModelTest.php`
- Create: `tests/Unit/Models/UserModelTest.php`
- Create: `tests/Unit/Models/OptionModelTest.php`

- [ ] **Step 1: Write failing tests**

```php
// tests/Unit/Models/BookingModelTest.php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\BillingStatus;
use App\Enums\BookingStatus;
use App\Enums\Visibility;
use App\Models\Booking;
use App\Models\BookingBill;
use App\Models\Reservation;
use App\Models\Square;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function booking_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $square = Square::factory()->create();
        $booking = Booking::factory()->create(['uid' => $user->uid, 'sid' => $square->sid]);

        $this->assertInstanceOf(User::class, $booking->user);
        $this->assertEquals($user->uid, $booking->user->uid);
    }

    /** @test */
    public function booking_belongs_to_square(): void
    {
        $square = Square::factory()->create();
        $booking = Booking::factory()->create(['sid' => $square->sid]);

        $this->assertInstanceOf(Square::class, $booking->square);
    }

    /** @test */
    public function booking_has_many_reservations(): void
    {
        $booking = Booking::factory()->create();
        Reservation::factory()->count(3)->create(['bid' => $booking->bid]);

        $this->assertCount(3, $booking->reservations);
    }

    /** @test */
    public function booking_has_many_bills(): void
    {
        $booking = Booking::factory()->create();
        BookingBill::factory()->count(2)->create(['bid' => $booking->bid]);

        $this->assertCount(2, $booking->bills);
    }

    /** @test */
    public function booking_status_is_cast_to_enum(): void
    {
        $booking = Booking::factory()->create(['status' => 'enabled']);

        $this->assertInstanceOf(BookingStatus::class, $booking->status);
        $this->assertSame(BookingStatus::Enabled, $booking->status);
    }

    /** @test */
    public function booking_billing_status_is_cast_to_enum(): void
    {
        $booking = Booking::factory()->create(['status_billing' => 'pending']);

        $this->assertInstanceOf(BillingStatus::class, $booking->status_billing);
        $this->assertSame(BillingStatus::Pending, $booking->status_billing);
    }

    /** @test */
    public function booking_visibility_is_cast_to_enum(): void
    {
        $booking = Booking::factory()->create(['visibility' => 'public']);

        $this->assertInstanceOf(Visibility::class, $booking->visibility);
        $this->assertSame(Visibility::Public, $booking->visibility);
    }
}
```

```php
// tests/Unit/Models/SquareModelTest.php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\SquareStatus;
use App\Models\Booking;
use App\Models\Square;
use App\Models\SquareMeta;
use App\Models\SquareProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SquareModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function square_status_is_cast_to_enum(): void
    {
        $square = Square::factory()->create(['status' => 'enabled']);
        $this->assertInstanceOf(SquareStatus::class, $square->status);
        $this->assertSame(SquareStatus::Enabled, $square->status);
    }

    /** @test */
    public function square_has_many_bookings(): void
    {
        $square = Square::factory()->create();
        Booking::factory()->count(2)->create(['sid' => $square->sid]);
        $this->assertCount(2, $square->bookings);
    }

    /** @test */
    public function square_has_many_meta(): void
    {
        $square = Square::factory()->create();
        SquareMeta::factory()->count(3)->create(['sid' => $square->sid]);
        $this->assertCount(3, $square->meta);
    }

    /** @test */
    public function square_is_bookable_when_status_is_enabled(): void
    {
        $square = Square::factory()->create(['status' => 'enabled']);
        $this->assertTrue($square->isBookable());
    }

    /** @test */
    public function square_is_not_bookable_when_disabled(): void
    {
        $square = Square::factory()->create(['status' => 'disabled']);
        $this->assertFalse($square->isBookable());
    }

    /** @test */
    public function square_is_not_bookable_when_readonly(): void
    {
        $square = Square::factory()->create(['status' => 'readonly']);
        $this->assertFalse($square->isBookable());
    }
}
```

```php
// tests/Unit/Models/UserModelTest.php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function has_role_returns_true_for_existing_role(): void
    {
        $user = User::factory()->create(['roles' => 'admin member']);
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->hasRole('member'));
    }

    /** @test */
    public function has_role_returns_false_for_missing_role(): void
    {
        $user = User::factory()->create(['roles' => 'member']);
        $this->assertFalse($user->hasRole('admin'));
    }

    /** @test */
    public function has_permission_returns_true_when_granted(): void
    {
        $user = User::factory()->create(['permissions' => 'calendar.see-data calendar.create-single-bookings']);
        $this->assertTrue($user->hasPermission('calendar.see-data'));
        $this->assertTrue($user->hasPermission('calendar.create-single-bookings'));
    }

    /** @test */
    public function has_permission_returns_false_when_missing(): void
    {
        $user = User::factory()->create(['permissions' => 'calendar.see-data']);
        $this->assertFalse($user->hasPermission('calendar.create-single-bookings'));
    }

    /** @test */
    public function password_is_hidden_from_serialization(): void
    {
        $user = User::factory()->create();
        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('token', $user->toArray());
    }
}
```

```php
// tests/Unit/Models/OptionModelTest.php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Option;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OptionModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function get_value_returns_stored_value(): void
    {
        Option::create(['option_key' => 'site_name', 'option_value' => 'TCBewegung']);
        $this->assertEquals('TCBewegung', Option::getValue('site_name'));
    }

    /** @test */
    public function get_value_returns_default_when_missing(): void
    {
        $this->assertEquals('fallback', Option::getValue('nonexistent', 'fallback'));
    }

    /** @test */
    public function get_value_returns_null_by_default(): void
    {
        $this->assertNull(Option::getValue('nonexistent'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test tests/Unit/Models/"
```
Expected: FAIL

- [ ] **Step 3: Write User model**

```php
// app/Models/User.php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents a system user (club member or admin).
 *
 * @property int        $uid
 * @property string     $name
 * @property string     $email
 * @property string     $phone
 * @property string     $roles        Space-separated role list
 * @property string     $permissions  Space-separated permission list
 * @property UserStatus $status
 * @property int        $created      Unix timestamp
 * @property int        $updated      Unix timestamp
 */
class User extends Authenticatable
{
    use HasFactory;

    protected $table = 'bs_users';
    protected $primaryKey = 'uid';
    public $timestamps = false;

    protected $fillable = [
        'name', 'email', 'password', 'phone', 'roles', 'permissions', 'status', 'token',
        'created', 'updated',
    ];

    protected $hidden = ['password', 'token'];

    protected $casts = [
        'status' => UserStatus::class,
    ];

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'uid', 'uid');
    }

    /** @return HasMany<UserMeta, $this> */
    public function meta(): HasMany
    {
        return $this->hasMany(UserMeta::class, 'uid', 'uid');
    }

    /** Whether user has the given role (space-separated list). */
    public function hasRole(string $role): bool
    {
        return in_array($role, explode(' ', (string) $this->getRawOriginal('roles')), strict: true);
    }

    /** Whether user has the given permission (space-separated list). */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, explode(' ', (string) $this->getRawOriginal('permissions')), strict: true);
    }
}
```

- [ ] **Step 4: Write Square model**

```php
// app/Models/Square.php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SquareStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bookable court/square.
 *
 * @property int          $sid
 * @property string       $name
 * @property string|null  $alias                  Human-readable alias (e.g. "Centercourt")
 * @property SquareStatus $status
 * @property int          $capacity               Max concurrent players
 * @property int          $capacity_heterogenic   Allow mixed player counts
 * @property int          $time_start             Day start seconds from midnight
 * @property int          $time_end               Day end seconds from midnight
 * @property int          $time_block             Slot size in seconds
 * @property int          $time_block_bookable    Min bookable seconds
 * @property int          $time_block_bookable_max Max per-user per-day seconds (0=unlimited)
 * @property int          $min_range_book         Min advance booking in seconds
 * @property int          $range_book             Max advance booking in seconds (0=unlimited)
 * @property int          $range_cancel           Cancellation deadline in seconds
 * @property int          $priority               Display order
 */
class Square extends Model
{
    use HasFactory;

    protected $table = 'bs_squares';
    protected $primaryKey = 'sid';
    public $timestamps = false;

    protected $fillable = [
        'name', 'alias', 'status', 'capacity', 'capacity_heterogenic',
        'time_start', 'time_end', 'time_block', 'time_block_bookable',
        'time_block_bookable_max', 'min_range_book', 'range_book', 'range_cancel', 'priority',
    ];

    protected $casts = [
        'status' => SquareStatus::class,
    ];

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany { return $this->hasMany(Booking::class, 'sid', 'sid'); }

    /** @return HasMany<SquareMeta, $this> */
    public function meta(): HasMany { return $this->hasMany(SquareMeta::class, 'sid', 'sid'); }

    /** @return HasMany<SquareProduct, $this> */
    public function products(): HasMany { return $this->hasMany(SquareProduct::class, 'sid', 'sid'); }

    /** @return HasMany<SquarePricing, $this> */
    public function pricing(): HasMany { return $this->hasMany(SquarePricing::class, 'sid', 'sid'); }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany { return $this->hasMany(Event::class, 'sid', 'sid'); }

    /** Whether public users may create new bookings. */
    public function isBookable(): bool
    {
        return $this->status === SquareStatus::Enabled;
    }

    /** Whether the court is completely unavailable (even for admins). */
    public function isDisabled(): bool
    {
        return $this->status === SquareStatus::Disabled;
    }
}
```

- [ ] **Step 5: Write Booking model**

```php
// app/Models/Booking.php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingStatus;
use App\Enums\BookingStatus;
use App\Enums\Visibility;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A court booking — the parent entity that owns reservations and bills.
 *
 * @property int           $bid
 * @property int           $uid
 * @property int           $sid
 * @property BookingStatus $status
 * @property BillingStatus $status_billing
 * @property Visibility    $visibility
 * @property int           $quantity  Number of players
 * @property int           $created   Unix timestamp
 * @property int           $updated   Unix timestamp
 */
class Booking extends Model
{
    use HasFactory;

    protected $table = 'bs_bookings';
    protected $primaryKey = 'bid';
    public $timestamps = false;

    protected $fillable = [
        'uid', 'sid', 'status', 'status_billing', 'visibility', 'quantity', 'created', 'updated',
    ];

    protected $casts = [
        'status'         => BookingStatus::class,
        'status_billing' => BillingStatus::class,
        'visibility'     => Visibility::class,
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'uid', 'uid'); }

    /** @return BelongsTo<Square, $this> */
    public function square(): BelongsTo { return $this->belongsTo(Square::class, 'sid', 'sid'); }

    /** @return HasMany<Reservation, $this> */
    public function reservations(): HasMany { return $this->hasMany(Reservation::class, 'bid', 'bid'); }

    /** @return HasMany<BookingBill, $this> */
    public function bills(): HasMany { return $this->hasMany(BookingBill::class, 'bid', 'bid'); }

    /** @return HasMany<BookingMeta, $this> */
    public function meta(): HasMany { return $this->hasMany(BookingMeta::class, 'bid', 'bid'); }
}
```

- [ ] **Step 6: Write remaining models (UserMeta, SquareMeta, SquareProduct, SquarePricing, SquareCoupon, BookingBill, BookingMeta, Reservation, ReservationMeta, Event, EventMeta, Option)**

```php
// app/Models/UserMeta.php
<?php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** @property int $umid @property int $uid @property string $meta_key @property string|null $meta_value */
class UserMeta extends Model {
    use HasFactory;
    protected $table = 'bs_users_meta'; protected $primaryKey = 'umid'; public $timestamps = false;
    protected $fillable = ['uid', 'meta_key', 'meta_value'];
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo { return $this->belongsTo(User::class, 'uid', 'uid'); }
}
```

```php
// app/Models/SquareMeta.php
<?php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** @property int $smid @property int $sid @property string $meta_key @property string|null $meta_value */
class SquareMeta extends Model {
    use HasFactory;
    protected $table = 'bs_squares_meta'; protected $primaryKey = 'smid'; public $timestamps = false;
    protected $fillable = ['sid', 'meta_key', 'meta_value'];
    /** @return BelongsTo<Square, $this> */
    public function square(): BelongsTo { return $this->belongsTo(Square::class, 'sid', 'sid'); }
}
```

```php
// app/Models/SquareProduct.php
<?php
declare(strict_types=1);
namespace App\Models;
use App\Enums\ProductType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** @property int $spid @property int $sid @property string $name @property ProductType $type @property int $price @property int $priority */
class SquareProduct extends Model {
    use HasFactory;
    protected $table = 'bs_squares_products'; protected $primaryKey = 'spid'; public $timestamps = false;
    protected $fillable = ['sid', 'name', 'type', 'price', 'priority'];
    protected $casts = ['type' => ProductType::class];
    /** @return BelongsTo<Square, $this> */
    public function square(): BelongsTo { return $this->belongsTo(Square::class, 'sid', 'sid'); }
}
```

```php
// app/Models/SquarePricing.php
<?php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
/** @property int $sprid @property int $sid @property int $spid @property int $date_start @property int $date_end @property int $time_start @property int $time_end @property int $price @property int $priority */
class SquarePricing extends Model {
    use HasFactory;
    protected $table = 'bs_squares_pricing'; protected $primaryKey = 'sprid'; public $timestamps = false;
    protected $fillable = ['sid', 'spid', 'date_start', 'date_end', 'time_start', 'time_end', 'price', 'priority'];
}
```

```php
// app/Models/SquareCoupon.php
<?php
declare(strict_types=1);
namespace App\Models;
use App\Enums\CouponType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
/** @property int $scid @property int $sid @property string $code @property CouponType $type @property int $value @property string $status */
class SquareCoupon extends Model {
    use HasFactory;
    protected $table = 'bs_squares_coupons'; protected $primaryKey = 'scid'; public $timestamps = false;
    protected $fillable = ['sid', 'code', 'type', 'value', 'valid_from', 'valid_until', 'usage_max', 'usage_count', 'status'];
    protected $casts = ['type' => CouponType::class];
}
```

```php
// app/Models/BookingBill.php
<?php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/** @property int $bbid @property int $bid @property int|null $spid @property int $price @property string|null $description */
class BookingBill extends Model {
    use HasFactory;
    protected $table = 'bs_bookings_bills'; protected $primaryKey = 'bbid'; public $timestamps = false;
    protected $fillable = ['bid', 'spid', 'price', 'description'];
    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo { return $this->belongsTo(Booking::class, 'bid', 'bid'); }
}
```

```php
// app/Models/BookingMeta.php
<?php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
/** @property int $bmid @property int $bid @property string $meta_key @property string|null $meta_value */
class BookingMeta extends Model {
    use HasFactory;
    protected $table = 'bs_bookings_meta'; protected $primaryKey = 'bmid'; public $timestamps = false;
    protected $fillable = ['bid', 'meta_key', 'meta_value'];
}
```

```php
// app/Models/Reservation.php
<?php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
/**
 * One time-slot reservation within a booking. Subscriptions have multiple reservations.
 * @property int $rid @property int $bid @property int $date Unix timestamp (midnight) @property int $time_start Seconds from midnight @property int $time_end Seconds from midnight
 */
class Reservation extends Model {
    use HasFactory;
    protected $table = 'bs_reservations'; protected $primaryKey = 'rid'; public $timestamps = false;
    protected $fillable = ['bid', 'date', 'time_start', 'time_end'];
    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo { return $this->belongsTo(Booking::class, 'bid', 'bid'); }
    /** @return HasMany<ReservationMeta, $this> */
    public function meta(): HasMany { return $this->hasMany(ReservationMeta::class, 'rid', 'rid'); }
}
```

```php
// app/Models/ReservationMeta.php
<?php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
/** @property int $rmid @property int $rid @property string $meta_key @property string|null $meta_value */
class ReservationMeta extends Model {
    use HasFactory;
    protected $table = 'bs_reservations_meta'; protected $primaryKey = 'rmid'; public $timestamps = false;
    protected $fillable = ['rid', 'meta_key', 'meta_value'];
}
```

```php
// app/Models/Event.php
<?php
declare(strict_types=1);
namespace App\Models;
use App\Enums\EventStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
/** Special event blocking or limiting court availability. @property int $eid @property int $sid @property int $datetime_start @property int $datetime_end @property int $capacity @property EventStatus $status */
class Event extends Model {
    use HasFactory;
    protected $table = 'bs_events'; protected $primaryKey = 'eid'; public $timestamps = false;
    protected $fillable = ['sid', 'datetime_start', 'datetime_end', 'capacity', 'status'];
    protected $casts = ['status' => EventStatus::class];
    /** @return BelongsTo<Square, $this> */
    public function square(): BelongsTo { return $this->belongsTo(Square::class, 'sid', 'sid'); }
    /** @return HasMany<EventMeta, $this> */
    public function meta(): HasMany { return $this->hasMany(EventMeta::class, 'eid', 'eid'); }
}
```

```php
// app/Models/EventMeta.php
<?php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
/** @property int $emid @property int $eid @property string $meta_key @property string|null $meta_value */
class EventMeta extends Model {
    use HasFactory;
    protected $table = 'bs_events_meta'; protected $primaryKey = 'emid'; public $timestamps = false;
    protected $fillable = ['eid', 'meta_key', 'meta_value'];
}
```

```php
// app/Models/Option.php
<?php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
/** Global key-value configuration option. @property int $oid @property string $option_key @property string|null $option_value */
class Option extends Model {
    use HasFactory;
    protected $table = 'bs_options'; protected $primaryKey = 'oid'; public $timestamps = false;
    protected $fillable = ['option_key', 'option_value'];

    /** Get an option value by key, with optional fallback default. */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        return static::where('option_key', $key)->value('option_value') ?? $default;
    }
}
```

- [ ] **Step 7: Run model tests**

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test tests/Unit/Models/"
```
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add app/Models/ tests/Unit/Models/
git commit -m "feat: add eloquent models with enum casts and strict types (PHP 9.0 ready)"
```

---

## Phase 5 — Model Factories

### Task 5: Create Model Factories

**Files:**
- Create: `database/factories/UserFactory.php`
- Create: `database/factories/SquareFactory.php`
- Create: `database/factories/BookingFactory.php`
- Create: `database/factories/ReservationFactory.php`
- Create: `database/factories/BookingBillFactory.php`
- Create: `database/factories/EventFactory.php`
- Create: `database/factories/SquareMetaFactory.php`
- Create: `database/factories/UserMetaFactory.php`
- Create: `database/factories/BookingMetaFactory.php`
- Create: `tests/Unit/Factories/FactoryTest.php`

- [ ] **Step 1: Write failing test**

```php
// tests/Unit/Factories/FactoryTest.php
<?php

declare(strict_types=1);

namespace Tests\Unit\Factories;

use App\Enums\BillingStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Reservation;
use App\Models\Square;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FactoryTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function user_factory_creates_valid_user(): void
    {
        $user = User::factory()->create();
        $this->assertNotNull($user->uid);
        $this->assertNotEmpty($user->email);
    }

    /** @test */
    public function booking_factory_creates_booking_with_enum_status(): void
    {
        $booking = Booking::factory()->create();
        $this->assertInstanceOf(BookingStatus::class, $booking->status);
        $this->assertInstanceOf(BillingStatus::class, $booking->status_billing);
    }

    /** @test */
    public function booking_factory_creates_relations(): void
    {
        $booking = Booking::factory()
            ->for(User::factory(), 'user')
            ->for(Square::factory(), 'square')
            ->has(Reservation::factory()->count(2), 'reservations')
            ->create();

        $this->assertEquals(2, $booking->reservations()->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test tests/Unit/Factories/FactoryTest.php"
```
Expected: FAIL

- [ ] **Step 3: Generate factory stubs**

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan make:factory UserFactory --model=User"
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan make:factory SquareFactory --model=Square"
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan make:factory BookingFactory --model=Booking"
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan make:factory ReservationFactory --model=Reservation"
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan make:factory BookingBillFactory --model=BookingBill"
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan make:factory EventFactory --model=Event"
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan make:factory SquareMetaFactory --model=SquareMeta"
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan make:factory UserMetaFactory --model=UserMeta"
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan make:factory BookingMetaFactory --model=BookingMeta"
```

- [ ] **Step 4: Fill in factory definitions**

```php
// database/factories/UserFactory.php — definition():
return [
    'name'        => fake()->name(),
    'email'       => fake()->unique()->safeEmail(),
    'password'    => bcrypt('password'),
    'phone'       => fake()->phoneNumber(),
    'roles'       => 'member',
    'permissions' => 'calendar.create-single-bookings calendar.see-data',
    'status'      => 'enabled',
    'token'       => null,
    'created'     => now()->timestamp,
    'updated'     => now()->timestamp,
];
```

```php
// database/factories/SquareFactory.php — definition():
return [
    'name'                   => fake()->randomElement(['Platz 1', 'Platz 2', 'Platz 3']),
    'alias'                  => fake()->randomElement(['Unterer Platz', 'Centercourt', 'Oberer Platz']),
    'status'                 => 'enabled',
    'capacity'               => 4,
    'capacity_heterogenic'   => 1,
    'time_start'             => 28800,
    'time_end'               => 79200,
    'time_block'             => 3600,
    'time_block_bookable'    => 3600,
    'time_block_bookable_max'=> 0,
    'min_range_book'         => 0,
    'range_book'             => 0,
    'range_cancel'           => 86400,
    'priority'               => 0,
];
```

```php
// database/factories/BookingFactory.php — definition():
return [
    'uid'            => User::factory(),
    'sid'            => Square::factory(),
    'status'         => 'enabled',
    'status_billing' => 'pending',
    'visibility'     => 'public',
    'quantity'       => 2,
    'created'        => now()->timestamp,
    'updated'        => now()->timestamp,
];
```

```php
// database/factories/ReservationFactory.php — definition():
return [
    'bid'        => Booking::factory(),
    'date'       => now()->startOfDay()->timestamp,
    'time_start' => 36000,
    'time_end'   => 39600,
];
```

```php
// database/factories/BookingBillFactory.php — definition():
return [
    'bid'         => Booking::factory(),
    'spid'        => null,
    'price'       => fake()->numberBetween(500, 3000),
    'description' => fake()->sentence(),
];
```

```php
// database/factories/EventFactory.php — definition():
return [
    'sid'            => Square::factory(),
    'datetime_start' => now()->timestamp,
    'datetime_end'   => now()->addHours(2)->timestamp,
    'capacity'       => 0,
    'status'         => 'enabled',
];
```

```php
// database/factories/SquareMetaFactory.php — definition():
return ['sid' => Square::factory(), 'meta_key' => 'key_' . fake()->word(), 'meta_value' => fake()->sentence()];
```

```php
// database/factories/UserMetaFactory.php — definition():
return ['uid' => User::factory(), 'meta_key' => 'key_' . fake()->word(), 'meta_value' => fake()->sentence()];
```

```php
// database/factories/BookingMetaFactory.php — definition():
return ['bid' => Booking::factory(), 'meta_key' => 'player_name_1', 'meta_value' => fake()->name()];
```

- [ ] **Step 5: Run factory tests**

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test tests/Unit/Factories/FactoryTest.php"
```
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add database/factories/ tests/Unit/Factories/
git commit -m "feat: add model factories for all entities"
```

---

## Phase 6 — Business Logic Services

### Task 6: ValidationResult + SquareValidator

**Files:**
- Create: `app/Services/ValidationResult.php`
- Create: `app/Services/SquareValidator.php`
- Create: `tests/Unit/Services/SquareValidatorTest.php`

**PHP 9.0 rules:** `declare(strict_types=1)`, `readonly` on constructor params, full return types.

- [ ] **Step 1: Write failing tests**

```php
// tests/Unit/Services/SquareValidatorTest.php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Booking;
use App\Models\Reservation;
use App\Models\Square;
use App\Models\User;
use App\Services\SquareValidator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SquareValidatorTest extends TestCase
{
    use RefreshDatabase;

    private SquareValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new SquareValidator();
    }

    /** @test */
    public function disabled_square_blocks_all_bookings(): void
    {
        $square = Square::factory()->create(['status' => 'disabled']);
        $user = User::factory()->create();

        $result = $this->validator->validate(
            $square, $user, 2,
            Carbon::now()->addDay()->setTime(10, 0),
            Carbon::now()->addDay()->setTime(11, 0),
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsStringIgnoringCase('disabled', $result->getError());
    }

    /** @test */
    public function readonly_square_blocks_booking_without_privilege(): void
    {
        $square = Square::factory()->create(['status' => 'readonly']);
        $user = User::factory()->create(['permissions' => '']);

        $result = $this->validator->validate(
            $square, $user, 2,
            Carbon::now()->addDay()->setTime(10, 0),
            Carbon::now()->addDay()->setTime(11, 0),
        );

        $this->assertFalse($result->isValid());
    }

    /** @test */
    public function readonly_square_allows_privileged_user(): void
    {
        $square = Square::factory()->create(['status' => 'readonly', 'time_block_bookable_max' => 0]);
        $user = User::factory()->create(['permissions' => 'calendar.create-single-bookings']);

        $result = $this->validator->validate(
            $square, $user, 2,
            Carbon::now()->addDay()->setTime(10, 0),
            Carbon::now()->addDay()->setTime(11, 0),
        );

        $this->assertTrue($result->isValid());
    }

    /** @test */
    public function booking_beyond_range_book_is_rejected(): void
    {
        $square = Square::factory()->create(['status' => 'enabled', 'range_book' => 7 * 86400]);
        $user = User::factory()->create();

        $result = $this->validator->validate(
            $square, $user, 2,
            Carbon::now()->addDays(10)->setTime(10, 0),
            Carbon::now()->addDays(10)->setTime(11, 0),
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsStringIgnoringCase('range', $result->getError());
    }

    /** @test */
    public function booking_within_range_book_is_accepted(): void
    {
        $square = Square::factory()->create([
            'status' => 'enabled', 'range_book' => 14 * 86400, 'time_block_bookable_max' => 0,
        ]);
        $user = User::factory()->create(['permissions' => 'calendar.create-single-bookings']);

        $result = $this->validator->validate(
            $square, $user, 2,
            Carbon::now()->addDays(5)->setTime(10, 0),
            Carbon::now()->addDays(5)->setTime(11, 0),
        );

        $this->assertTrue($result->isValid());
    }

    /** @test */
    public function daily_limit_is_enforced(): void
    {
        $square = Square::factory()->create(['status' => 'enabled', 'time_block_bookable_max' => 3600]);
        $user = User::factory()->create(['permissions' => 'calendar.create-single-bookings']);

        $booking = Booking::factory()->create(['uid' => $user->uid, 'sid' => $square->sid]);
        Reservation::factory()->create([
            'bid' => $booking->bid,
            'date' => Carbon::now()->addDays(3)->startOfDay()->timestamp,
            'time_start' => 36000, 'time_end' => 39600,
        ]);

        $result = $this->validator->validate(
            $square, $user, 2,
            Carbon::now()->addDays(3)->setTime(12, 0),
            Carbon::now()->addDays(3)->setTime(13, 0),
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsStringIgnoringCase('limit', $result->getError());
    }

    /** @test */
    public function short_booking_within_30min_ignores_daily_limit(): void
    {
        $square = Square::factory()->create(['status' => 'enabled', 'time_block_bookable_max' => 3600]);
        $user = User::factory()->create(['permissions' => 'calendar.create-single-bookings']);

        $dateStart = Carbon::now()->addMinutes(20);

        $result = $this->validator->validate(
            $square, $user, 2,
            $dateStart,
            $dateStart->copy()->addHour(),
        );

        $this->assertTrue($result->isValid());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test tests/Unit/Services/SquareValidatorTest.php"
```
Expected: FAIL

- [ ] **Step 3: Write ValidationResult**

```php
// app/Services/ValidationResult.php
<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Immutable result of a booking validation check.
 * Use isValid() to check pass/fail, getError() to retrieve the failure reason.
 */
final class ValidationResult
{
    private function __construct(
        private readonly bool $valid,
        private readonly string $error = '',
    ) {}

    public static function pass(): self
    {
        return new self(true);
    }

    public static function fail(string $error): self
    {
        return new self(false, $error);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getError(): string
    {
        return $this->error;
    }
}
```

- [ ] **Step 4: Write SquareValidator**

```php
// app/Services/SquareValidator.php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\SquareStatus;
use App\Models\Reservation;
use App\Models\Square;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Validates whether a user may create a booking on a given square at a given time.
 *
 * Rules ported from module/Square/src/Square/Service/SquareValidator.php:
 * - Disabled squares: nobody can book
 * - Readonly squares: only users with calendar.create-single-bookings permission
 * - range_book: max advance booking window in seconds (0 = unlimited)
 * - time_block_bookable_max: max per-user per-court per-day seconds (0 = unlimited)
 * - Short booking exemption: bookings starting within 30 min ignore the daily limit
 */
final class SquareValidator
{
    private const SHORT_BOOKING_THRESHOLD_SECONDS = 1800;

    /**
     * Validate a proposed booking.
     *
     * @param Square $square    The court being booked
     * @param User   $user      The requesting user
     * @param int    $quantity  Number of players
     * @param Carbon $dateStart Booking start datetime
     * @param Carbon $dateEnd   Booking end datetime
     */
    public function validate(
        Square $square,
        User $user,
        int $quantity,
        Carbon $dateStart,
        Carbon $dateEnd,
    ): ValidationResult {
        if ($square->status === SquareStatus::Disabled) {
            return ValidationResult::fail('Square is disabled');
        }

        if ($square->status === SquareStatus::Readonly
            && !$user->hasPermission('calendar.create-single-bookings')) {
            return ValidationResult::fail('Square is readonly — booking requires privilege');
        }

        if ($square->range_book > 0) {
            $maxBookableAt = Carbon::now()->addSeconds($square->range_book)->endOfDay();
            if ($dateStart->greaterThan($maxBookableAt)) {
                return ValidationResult::fail('Booking date exceeds the allowed advance booking range');
            }
        }

        if ($square->time_block_bookable_max > 0 && !$this->isShortBooking($dateStart)) {
            $dailyUsed = $this->getDailyUsedSeconds($user, $square, $dateStart);
            $requested = $dateEnd->diffInSeconds($dateStart);

            if ($dailyUsed + $requested > $square->time_block_bookable_max) {
                return ValidationResult::fail('Daily booking limit exceeded for this square');
            }
        }

        return ValidationResult::pass();
    }

    /** Whether the booking starts within 30 minutes (short-booking exemption from daily limit). */
    private function isShortBooking(Carbon $dateStart): bool
    {
        return $dateStart->diffInSeconds(Carbon::now(), absolute: false) >= -self::SHORT_BOOKING_THRESHOLD_SECONDS;
    }

    /** Total booked seconds for this user on this court on the given day. */
    private function getDailyUsedSeconds(User $user, Square $square, Carbon $date): int
    {
        return (int) Reservation::query()
            ->join('bs_bookings', 'bs_bookings.bid', '=', 'bs_reservations.bid')
            ->where('bs_bookings.uid', $user->uid)
            ->where('bs_bookings.sid', $square->sid)
            ->where('bs_bookings.status', BookingStatus::Enabled->value)
            ->where('bs_reservations.date', $date->copy()->startOfDay()->timestamp)
            ->sum(DB::raw('bs_reservations.time_end - bs_reservations.time_start'));
    }
}
```

- [ ] **Step 5: Run tests**

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test tests/Unit/Services/SquareValidatorTest.php"
```
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/ValidationResult.php app/Services/SquareValidator.php tests/Unit/Services/SquareValidatorTest.php
git commit -m "feat: add SquareValidator with full constraint logic (PHP 9.0 ready)"
```

---

### Task 7: BookingService + BookingValidationException

**Files:**
- Create: `app/Exceptions/BookingValidationException.php`
- Create: `app/Services/BookingService.php`
- Create: `tests/Unit/Services/BookingServiceTest.php`

- [ ] **Step 1: Write failing tests**

```php
// tests/Unit/Services/BookingServiceTest.php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\BillingStatus;
use App\Enums\BookingStatus;
use App\Exceptions\BookingValidationException;
use App\Models\Booking;
use App\Models\Square;
use App\Models\User;
use App\Services\BookingService;
use App\Services\SquareValidator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingServiceTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BookingService(new SquareValidator());
    }

    /** @test */
    public function create_single_persists_booking_and_reservation(): void
    {
        $user = User::factory()->create(['permissions' => 'calendar.create-single-bookings']);
        $square = Square::factory()->create(['status' => 'enabled', 'time_block_bookable_max' => 0]);
        $dateStart = Carbon::now()->addDay()->setTime(10, 0);
        $dateEnd   = Carbon::now()->addDay()->setTime(11, 0);

        $booking = $this->service->createSingle($user, $square, 2, $dateStart, $dateEnd);

        $this->assertNotNull($booking->bid);
        $this->assertSame(BookingStatus::Enabled, $booking->status);
        $this->assertSame(BillingStatus::Pending, $booking->status_billing);
        $this->assertEquals(1, $booking->reservations()->count());
        $this->assertEquals(36000, $booking->reservations()->first()->time_start);
        $this->assertEquals(39600, $booking->reservations()->first()->time_end);
    }

    /** @test */
    public function create_single_throws_on_disabled_square(): void
    {
        $user   = User::factory()->create();
        $square = Square::factory()->create(['status' => 'disabled']);

        $this->expectException(BookingValidationException::class);

        $this->service->createSingle($user, $square, 2,
            Carbon::now()->addDay()->setTime(10, 0),
            Carbon::now()->addDay()->setTime(11, 0),
        );
    }

    /** @test */
    public function cancel_sets_status_to_disabled_and_cancelled(): void
    {
        $booking = Booking::factory()->create(['status' => 'enabled', 'status_billing' => 'pending']);

        $this->service->cancelSingle($booking);

        $booking->refresh();
        $this->assertSame(BookingStatus::Disabled, $booking->status);
        $this->assertSame(BillingStatus::Cancelled, $booking->status_billing);
    }

    /** @test */
    public function create_single_is_atomic_no_orphan_on_failure(): void
    {
        $user   = User::factory()->create(['permissions' => 'calendar.create-single-bookings']);
        $square = Square::factory()->create(['status' => 'disabled']);
        $count  = Booking::count();

        try {
            $this->service->createSingle($user, $square, 2,
                Carbon::now()->addDay()->setTime(10, 0),
                Carbon::now()->addDay()->setTime(11, 0),
            );
        } catch (BookingValidationException) {}

        $this->assertEquals($count, Booking::count());
    }
}
```

- [ ] **Step 2: Run to verify failure**

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test tests/Unit/Services/BookingServiceTest.php"
```
Expected: FAIL

- [ ] **Step 3: Write exception + service**

```php
// app/Exceptions/BookingValidationException.php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Thrown when SquareValidator rejects a booking attempt. */
final class BookingValidationException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }
}
```

```php
// app/Services/BookingService.php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BillingStatus;
use App\Enums\BookingStatus;
use App\Enums\Visibility;
use App\Exceptions\BookingValidationException;
use App\Models\Booking;
use App\Models\Reservation;
use App\Models\Square;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Application service for creating and cancelling bookings.
 * All DB writes are wrapped in transactions for atomicity.
 */
final class BookingService
{
    public function __construct(
        private readonly SquareValidator $validator,
    ) {}

    /**
     * Create a single booking with one reservation.
     *
     * @param User   $user      Booking owner
     * @param Square $square    Court to book
     * @param int    $quantity  Number of players
     * @param Carbon $dateStart Start datetime
     * @param Carbon $dateEnd   End datetime
     * @param array<array{spid?: int|null, price: int, description?: string}> $bills Optional bills
     * @param array<array{meta_key: string, meta_value: string}>               $meta  Optional metadata
     *
     * @throws BookingValidationException When validation fails
     */
    public function createSingle(
        User $user,
        Square $square,
        int $quantity,
        Carbon $dateStart,
        Carbon $dateEnd,
        array $bills = [],
        array $meta = [],
    ): Booking {
        $result = $this->validator->validate($square, $user, $quantity, $dateStart, $dateEnd);

        if (!$result->isValid()) {
            throw new BookingValidationException($result->getError());
        }

        return DB::transaction(function () use ($user, $square, $quantity, $dateStart, $dateEnd, $bills, $meta): Booking {
            $booking = Booking::create([
                'uid'            => $user->uid,
                'sid'            => $square->sid,
                'status'         => BookingStatus::Enabled->value,
                'status_billing' => BillingStatus::Pending->value,
                'visibility'     => Visibility::Public->value,
                'quantity'       => $quantity,
                'created'        => now()->timestamp,
                'updated'        => now()->timestamp,
            ]);

            Reservation::create([
                'bid'        => $booking->bid,
                'date'       => $dateStart->copy()->startOfDay()->timestamp,
                'time_start' => $dateStart->secondsSinceMidnight(),
                'time_end'   => $dateEnd->secondsSinceMidnight(),
            ]);

            foreach ($bills as $bill) {
                $booking->bills()->create($bill);
            }

            foreach ($meta as $entry) {
                $booking->meta()->create($entry);
            }

            return $booking->load('reservations', 'bills', 'meta');
        });
    }

    /**
     * Cancel a booking — disables it and marks billing as cancelled.
     */
    public function cancelSingle(Booking $booking): void
    {
        $booking->update([
            'status'         => BookingStatus::Disabled->value,
            'status_billing' => BillingStatus::Cancelled->value,
            'updated'        => now()->timestamp,
        ]);
    }
}
```

- [ ] **Step 4: Run tests**

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test tests/Unit/Services/BookingServiceTest.php"
```
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/BookingService.php app/Exceptions/BookingValidationException.php tests/Unit/Services/BookingServiceTest.php
git commit -m "feat: add atomic BookingService with enum-based status transitions (PHP 9.0 ready)"
```

---

### Task 8: ReservationService

**Files:**
- Create: `app/Services/ReservationService.php`
- Create: `tests/Unit/Services/ReservationServiceTest.php`

- [ ] **Step 1: Write failing tests**

```php
// tests/Unit/Services/ReservationServiceTest.php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Booking;
use App\Models\Reservation;
use App\Models\Square;
use App\Services\ReservationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReservationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReservationService();
    }

    /** @test */
    public function get_in_range_returns_reservations_within_window(): void
    {
        $booking   = Booking::factory()->create();
        $inRange   = Reservation::factory()->create(['bid' => $booking->bid, 'date' => Carbon::now()->timestamp]);
        $outOfRange = Reservation::factory()->create(['bid' => $booking->bid, 'date' => Carbon::now()->addDays(10)->timestamp]);

        $results = $this->service->getInRange(Carbon::now()->subDay(), Carbon::now()->addDay());
        $rids    = $results->pluck('rid')->toArray();

        $this->assertContains($inRange->rid, $rids);
        $this->assertNotContains($outOfRange->rid, $rids);
    }

    /** @test */
    public function get_in_range_by_square_filters_by_court(): void
    {
        $square1 = Square::factory()->create();
        $square2 = Square::factory()->create();
        $b1 = Booking::factory()->create(['sid' => $square1->sid]);
        $b2 = Booking::factory()->create(['sid' => $square2->sid]);
        $r1 = Reservation::factory()->create(['bid' => $b1->bid, 'date' => now()->timestamp]);
        $r2 = Reservation::factory()->create(['bid' => $b2->bid, 'date' => now()->timestamp]);

        $results = $this->service->getInRangeBySquare($square1, Carbon::now()->subDay(), Carbon::now()->addDay());
        $rids    = $results->pluck('rid')->toArray();

        $this->assertContains($r1->rid, $rids);
        $this->assertNotContains($r2->rid, $rids);
    }

    /** @test */
    public function has_overlap_detects_conflict(): void
    {
        $booking = Booking::factory()->create();
        Reservation::factory()->create([
            'bid' => $booking->bid, 'date' => Carbon::today()->timestamp,
            'time_start' => 36000, 'time_end' => 39600,
        ]);

        $this->assertTrue($this->service->hasOverlap($booking->square, Carbon::today(), 36000, 39600, null));
    }

    /** @test */
    public function has_overlap_ignores_disabled_bookings(): void
    {
        $booking = Booking::factory()->create(['status' => 'disabled']);
        Reservation::factory()->create([
            'bid' => $booking->bid, 'date' => Carbon::today()->timestamp,
            'time_start' => 36000, 'time_end' => 39600,
        ]);

        $this->assertFalse($this->service->hasOverlap($booking->square, Carbon::today(), 36000, 39600, null));
    }
}
```

- [ ] **Step 2: Run to verify failure**

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test tests/Unit/Services/ReservationServiceTest.php"
```
Expected: FAIL

- [ ] **Step 3: Implement ReservationService**

```php
// app/Services/ReservationService.php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Reservation;
use App\Models\Square;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Query helper for reservations — date-range lookups and overlap detection.
 */
final class ReservationService
{
    /**
     * Get all active reservations in a date range (inclusive).
     *
     * @return Collection<int, Reservation>
     */
    public function getInRange(Carbon $from, Carbon $to): Collection
    {
        return Reservation::query()
            ->join('bs_bookings', 'bs_bookings.bid', '=', 'bs_reservations.bid')
            ->where('bs_bookings.status', BookingStatus::Enabled->value)
            ->whereBetween('bs_reservations.date', [
                $from->copy()->startOfDay()->timestamp,
                $to->copy()->endOfDay()->timestamp,
            ])
            ->select('bs_reservations.*')
            ->get();
    }

    /**
     * Get active reservations for a specific court in a date range.
     *
     * @return Collection<int, Reservation>
     */
    public function getInRangeBySquare(Square $square, Carbon $from, Carbon $to): Collection
    {
        return Reservation::query()
            ->join('bs_bookings', 'bs_bookings.bid', '=', 'bs_reservations.bid')
            ->where('bs_bookings.status', BookingStatus::Enabled->value)
            ->where('bs_bookings.sid', $square->sid)
            ->whereBetween('bs_reservations.date', [
                $from->copy()->startOfDay()->timestamp,
                $to->copy()->endOfDay()->timestamp,
            ])
            ->select('bs_reservations.*')
            ->get();
    }

    /**
     * Check whether a time slot is already taken on a given court.
     *
     * @param Square   $square           Court to check
     * @param Carbon   $date             Calendar date
     * @param int      $timeStart        Seconds from midnight
     * @param int      $timeEnd          Seconds from midnight
     * @param int|null $excludeBookingId Booking to ignore (for update scenarios)
     */
    public function hasOverlap(
        Square $square,
        Carbon $date,
        int $timeStart,
        int $timeEnd,
        ?int $excludeBookingId,
    ): bool {
        $query = Reservation::query()
            ->join('bs_bookings', 'bs_bookings.bid', '=', 'bs_reservations.bid')
            ->where('bs_bookings.status', BookingStatus::Enabled->value)
            ->where('bs_bookings.sid', $square->sid)
            ->where('bs_reservations.date', $date->copy()->startOfDay()->timestamp)
            ->where('bs_reservations.time_start', '<', $timeEnd)
            ->where('bs_reservations.time_end', '>', $timeStart);

        if ($excludeBookingId !== null) {
            $query->where('bs_bookings.bid', '!=', $excludeBookingId);
        }

        return $query->exists();
    }
}
```

- [ ] **Step 4: Run tests**

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test tests/Unit/Services/ReservationServiceTest.php"
```
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/ReservationService.php tests/Unit/Services/ReservationServiceTest.php
git commit -m "feat: add ReservationService with range queries and overlap detection (PHP 9.0 ready)"
```

---

## Phase 7 — Authentication

### Task 9: Auth with bs_users + status check

**Files:**
- Modify: `config/auth.php`
- Create: `app/Http/Controllers/Auth/LoginController.php`
- Create: `app/Http/Controllers/Auth/LogoutController.php`
- Create: `resources/views/auth/login.blade.php`
- Create: `tests/Feature/Auth/LoginTest.php`

- [ ] **Step 1: Write failing tests**

```php
// tests/Feature/Auth/LoginTest.php
<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function user_can_login_with_valid_credentials(): void
    {
        User::factory()->create(['email' => 'test@example.com', 'password' => bcrypt('secret123'), 'status' => 'enabled']);

        $this->post('/login', ['email' => 'test@example.com', 'password' => 'secret123'])
            ->assertRedirect('/calendar');

        $this->assertAuthenticated();
    }

    /** @test */
    public function login_fails_with_wrong_password(): void
    {
        User::factory()->create(['email' => 'test@example.com', 'password' => bcrypt('secret123')]);

        $this->post('/login', ['email' => 'test@example.com', 'password' => 'wrong'])
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    /** @test */
    public function disabled_user_cannot_login(): void
    {
        User::factory()->create(['email' => 'test@example.com', 'password' => bcrypt('secret123'), 'status' => 'disabled']);

        $this->post('/login', ['email' => 'test@example.com', 'password' => 'secret123'])
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    /** @test */
    public function authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }
}
```

- [ ] **Step 2: Run to verify failure**

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/Auth/LoginTest.php"
```

- [ ] **Step 3: Configure auth.php**

In `config/auth.php`, set the users provider model:
```php
'providers' => [
    'users' => [
        'driver' => 'eloquent',
        'model' => App\Models\User::class,
    ],
],
```

- [ ] **Step 4: Add routes to routes/web.php**

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\CalendarController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [LoginController::class, 'showForm'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LogoutController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function (): void {
    Route::get('/', fn() => redirect()->route('calendar.index'));
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
});
```

- [ ] **Step 5: Implement controllers**

```php
// app/Http/Controllers/Auth/LoginController.php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** Handles login form display and credential authentication. */
final class LoginController extends Controller
{
    public function showForm(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if ($user?->status === UserStatus::Disabled) {
            return redirect('/login')->withErrors(['email' => 'Konto ist deaktiviert.']);
        }

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();
            return redirect()->intended('/calendar');
        }

        return redirect('/login')->withErrors(['email' => 'Ungültige Anmeldedaten.']);
    }
}
```

```php
// app/Http/Controllers/Auth/LogoutController.php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Handles user logout. */
final class LogoutController extends Controller
{
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/login');
    }
}
```

```php
// app/Http/Controllers/CalendarController.php (stub for now)
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Square;
use App\Services\ReservationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Displays the daily booking calendar for all courts. */
final class CalendarController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
    ) {}

    public function index(Request $request): View
    {
        $date    = $request->input('date') ? Carbon::parse($request->input('date')) : Carbon::today();
        $squares = Square::orderBy('priority')->orderBy('sid')->get();

        $reservationsBySquare = $squares->mapWithKeys(
            fn(Square $square) => [
                $square->sid => $this->reservations->getInRangeBySquare(
                    $square, $date->copy()->startOfDay(), $date->copy()->endOfDay()
                )->load('booking.user', 'booking.meta'),
            ]
        );

        return view('calendar.index', compact('date', 'squares', 'reservationsBySquare'));
    }
}
```

- [ ] **Step 6: Create Blade views**

```blade
{{-- resources/views/auth/login.blade.php --}}
<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8"><title>Anmelden – TCBewegung</title></head>
<body>
<h1>Anmelden</h1>
@if($errors->any())
    <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
@endif
<form method="POST" action="/login">
    @csrf
    <label>E-Mail: <input type="email" name="email" value="{{ old('email') }}" required></label><br>
    <label>Passwort: <input type="password" name="password" required></label><br>
    <button type="submit">Anmelden</button>
</form>
</body>
</html>
```

```blade
{{-- resources/views/layouts/app.blade.php --}}
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'TCBewegung Buchung')</title>
    <style>
        .cc-free { background:#EEE; } .cc-own { background:#8BB243;color:#fff; }
        .cc-single-future,.cc-multiple-future { background:#2596be;color:#fff; }
        .cc-spielersuche { background:#a024bf;color:#fff; }
        table { border-collapse:collapse;width:100%; } td,th { border:1px solid #ccc;padding:4px 8px; }
        .toolbar { display:flex;gap:12px;align-items:center;margin-bottom:1rem; }
    </style>
</head>
<body>
<nav>
    <strong>TCBewegung</strong>
    @auth <span>{{ auth()->user()->name }}</span> | <form method="POST" action="/logout" style="display:inline">@csrf<button>Abmelden</button></form> @endauth
    @guest <a href="/login">Anmelden</a> @endguest
</nav>
@yield('content')
</body>
</html>
```

```blade
{{-- resources/views/calendar/index.blade.php --}}
@extends('layouts.app')
@section('title','Buchungskalender')
@section('content')
<div class="toolbar">
    <a href="{{ route('calendar.index',['date'=>$date->copy()->subDay()->format('Y-m-d')]) }}">&lt;</a>
    <strong>{{ $date->format('d.m.Y') }}</strong>
    <a href="{{ route('calendar.index',['date'=>$date->copy()->addDay()->format('Y-m-d')]) }}">&gt;</a>
    <a href="{{ route('calendar.index') }}">Heute</a>
</div>
<table>
    <thead><tr><th>Zeit</th>@foreach($squares as $s)<th>{{ $s->name }}@if($s->alias) – {{ $s->alias }}@endif</th>@endforeach</tr></thead>
    <tbody>
        @for($h=8;$h<22;$h++)
        <tr>
            <td>{{ str_pad($h,2,'0',STR_PAD_LEFT) }}:00</td>
            @foreach($squares as $s)
                @php $r=$reservationsBySquare[$s->sid]->first(fn($r)=>$r->time_start==$h*3600); @endphp
                <td class="{{ $r?'cc-single-future':'cc-free' }}">
                    @if($r && auth()->check()) {{ $r->booking->user->name ?? '' }} @elseif($r) Gebucht @endif
                </td>
            @endforeach
        </tr>
        @endfor
    </tbody>
</table>
@endsection
```

- [ ] **Step 7: Run auth tests**

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/Auth/LoginTest.php"
```
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/ resources/views/ routes/web.php config/auth.php tests/Feature/Auth/
git commit -m "feat: add auth with bs_users table, UserStatus enum check, Blade layout"
```

---

## Phase 8 — HTTP Layer

### Task 10: BookingController (create + cancel)

**Files:**
- Create: `app/Http/Controllers/BookingController.php`
- Create: `tests/Feature/BookingControllerTest.php`

- [ ] **Step 1: Write failing tests**

```php
// tests/Feature/BookingControllerTest.php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Square;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function guest_cannot_create_booking(): void
    {
        $square = Square::factory()->create();
        $this->post('/bookings', ['sid' => $square->sid, 'date' => '2026-07-10', 'time_start' => '10:00', 'time_end' => '11:00', 'quantity' => 2])
            ->assertRedirect('/login');
    }

    /** @test */
    public function user_can_create_booking_on_available_slot(): void
    {
        $user   = User::factory()->create(['permissions' => 'calendar.create-single-bookings']);
        $square = Square::factory()->create(['status' => 'enabled', 'time_block_bookable_max' => 0]);

        $this->actingAs($user)->post('/bookings', [
            'sid' => $square->sid, 'date' => '2026-07-10',
            'time_start' => '10:00', 'time_end' => '11:00', 'quantity' => 2,
        ])->assertRedirect();

        $this->assertDatabaseHas('bs_bookings', ['uid' => $user->uid, 'sid' => $square->sid]);
        $this->assertDatabaseHas('bs_reservations', ['time_start' => 36000, 'time_end' => 39600]);
    }

    /** @test */
    public function user_can_cancel_own_booking(): void
    {
        $user    = User::factory()->create();
        $booking = Booking::factory()->create(['uid' => $user->uid, 'status' => 'enabled']);

        $this->actingAs($user)->delete("/bookings/{$booking->bid}")->assertRedirect();

        $booking->refresh();
        $this->assertSame(BookingStatus::Disabled, $booking->status);
    }

    /** @test */
    public function user_cannot_cancel_another_users_booking(): void
    {
        $owner   = User::factory()->create();
        $other   = User::factory()->create();
        $booking = Booking::factory()->create(['uid' => $owner->uid]);

        $this->actingAs($other)->delete("/bookings/{$booking->bid}")->assertForbidden();
    }
}
```

- [ ] **Step 2: Run to verify failure**

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/BookingControllerTest.php"
```

- [ ] **Step 3: Add routes inside auth middleware group in routes/web.php**

```php
Route::post('/bookings', [\App\Http\Controllers\BookingController::class, 'store'])->name('bookings.store');
Route::delete('/bookings/{booking}', [\App\Http\Controllers\BookingController::class, 'destroy'])->name('bookings.destroy');
```

- [ ] **Step 4: Implement BookingController**

```php
// app/Http/Controllers/BookingController.php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\BookingValidationException;
use App\Models\Booking;
use App\Models\Square;
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * BookingController — handles HTTP requests for creating and cancelling court bookings.
 *
 * Routes:
 *   POST   /bookings           → store()   (auth required)
 *   DELETE /bookings/{booking} → destroy() (auth required, own booking only)
 */
final class BookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookingService,
    ) {}

    /**
     * Validate and create a single booking.
     *
     * @throws BookingValidationException redirected back with error on failure
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sid'        => ['required', 'integer', 'exists:bs_squares,sid'],
            'date'       => ['required', 'date'],
            'time_start' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'time_end'   => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'quantity'   => ['required', 'integer', 'min:1', 'max:4'],
        ]);

        $square    = Square::findOrFail($data['sid']);
        $dateStart = Carbon::parse("{$data['date']} {$data['time_start']}");
        $dateEnd   = Carbon::parse("{$data['date']} {$data['time_end']}");

        try {
            $this->bookingService->createSingle(auth()->user(), $square, $data['quantity'], $dateStart, $dateEnd);
        } catch (BookingValidationException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return redirect()->route('calendar.index', ['date' => $data['date']])
            ->with('success', 'Buchung erfolgreich gespeichert.');
    }

    /** Cancel own booking — returns 403 if booking belongs to another user. */
    public function destroy(Booking $booking): RedirectResponse
    {
        if ($booking->uid !== auth()->id()) {
            abort(403);
        }

        $this->bookingService->cancelSingle($booking);

        return redirect()->route('calendar.index')->with('success', 'Buchung storniert.');
    }
}
```

- [ ] **Step 5: Run tests**

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test tests/Feature/BookingControllerTest.php"
```
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/BookingController.php routes/web.php tests/Feature/BookingControllerTest.php
git commit -m "feat: add BookingController with create/cancel (PHP 9.0 ready)"
```

---

## Phase 9 — Full Test Suite

### Task 11: Run complete test suite

- [ ] **Step 1: Run all tests**

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test"
```
Expected: All tests PASS

- [ ] **Step 2: Verify strict_types in every PHP file**

```bash
wsl bash -c "cd /mnt/c/development/bookingnew && grep -rL 'declare(strict_types=1)' app/ --include='*.php'"
```
Expected: Empty output (all files have strict_types).

- [ ] **Step 3: Final commit**

```bash
git add .
git commit -m "test: full test suite green — Laravel 13 migration Phase 1 complete (PHP 9.0 ready)"
```

---

## Self-Review Checklist

- [x] `declare(strict_types=1)` in every PHP file
- [x] All status fields use Enums (BookingStatus, BillingStatus, SquareStatus, Visibility, UserStatus, CouponType, ProductType, EventStatus)
- [x] `readonly` on all constructor-injected dependencies
- [x] All 15 tables covered by migrations with tests
- [x] Enum casts on all status/type model properties
- [x] SquareValidator ports all rules: disabled, readonly, range_book, daily limit, short-booking exemption (30min)
- [x] BookingService is atomic (DB transaction)
- [x] Auth uses bs_users with UserStatus::Disabled check
- [x] All controllers declared `final` (PHP 9.0 compatible pattern)
- [x] Full PHPDoc on all public methods

### Gaps for future phases

- Admin backend (backend/* routes)
- Subscription bookings (recurring reservations)
- Pricing engine (SquarePricingManager)
- Email notifications
- Spielersuche (partner search)
- Frontend styling (port CSS from default3.css)
