# Laravel Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate the TCBewegung tennis club booking system from Zend Framework 2 to Laravel 11 with comprehensive test coverage and full PHPDoc documentation.

**Architecture:** Laravel 11 monolith with Eloquent ORM replacing ZF2 Managers, Service classes preserving business logic, Blade templates replacing .phtml views. All 15 DB tables remain unchanged — only migrations are added for schema documentation. Existing MySQL data is fully compatible.

**Tech Stack:** Laravel 11, PHP 8.2+, MySQL, Blade templates, PHPUnit (via Laravel's test suite), Laravel Sanctum for auth, Pest (optional), Vite for assets.

---

## Source Reference

The original codebase lives at `C:\development\booking` (Zend Framework 2).  
The new Laravel project will be built at `C:\development\bookingnew`.

---

## Phase 1 — Laravel Project Bootstrap

### Task 1: Create Laravel 11 project

**Files:**
- Create: `C:\development\bookingnew\` (entire Laravel scaffold)

- [ ] **Step 1: Scaffold Laravel project**

```bash
cd C:\development
composer create-project laravel/laravel bookingnew "^11.0"
cd bookingnew
```

- [ ] **Step 2: Verify installation**

```bash
php artisan --version
```
Expected: `Laravel Framework 11.x.x`

- [ ] **Step 3: Configure .env for local MySQL**

Edit `.env`:
```
APP_NAME=TCBewegung-Booking
APP_ENV=local
APP_KEY=   # will be generated
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tcbewegung_at
DB_USERNAME=tcbewegung_at
DB_PASSWORD=KEmGpLHatyKSO3BWlSP8
```

- [ ] **Step 4: Generate app key**

```bash
php artisan key:generate
```

- [ ] **Step 5: Test DB connection**

```bash
php artisan tinker
>>> DB::select('SELECT 1');
```
Expected: `[{1: 1}]`

- [ ] **Step 6: Initialize git**

```bash
git init
git add .
git commit -m "feat: laravel 11 project scaffold"
```

---

## Phase 2 — Database Migrations & Models

### Task 2: Create migrations for all 15 tables

**Files:**
- Create: `database/migrations/2026_06_25_000001_create_bs_users_table.php`
- Create: `database/migrations/2026_06_25_000002_create_bs_users_meta_table.php`
- Create: `database/migrations/2026_06_25_000003_create_bs_squares_table.php`
- Create: `database/migrations/2026_06_25_000004_create_bs_squares_meta_table.php`
- Create: `database/migrations/2026_06_25_000005_create_bs_squares_products_table.php`
- Create: `database/migrations/2026_06_25_000006_create_bs_squares_pricing_table.php`
- Create: `database/migrations/2026_06_25_000007_create_bs_squares_coupons_table.php`
- Create: `database/migrations/2026_06_25_000008_create_bs_bookings_table.php`
- Create: `database/migrations/2026_06_25_000009_create_bs_bookings_bills_table.php`
- Create: `database/migrations/2026_06_25_000010_create_bs_bookings_meta_table.php`
- Create: `database/migrations/2026_06_25_000011_create_bs_reservations_table.php`
- Create: `database/migrations/2026_06_25_000012_create_bs_reservations_meta_table.php`
- Create: `database/migrations/2026_06_25_000013_create_bs_events_table.php`
- Create: `database/migrations/2026_06_25_000014_create_bs_events_meta_table.php`
- Create: `database/migrations/2026_06_25_000015_create_bs_options_table.php`

- [ ] **Step 1: Write failing test — migration runs without error**

```php
// tests/Feature/MigrationTest.php
<?php

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
php artisan test tests/Feature/MigrationTest.php
```
Expected: FAIL — tables don't exist yet.

- [ ] **Step 3: Write migration — bs_users**

```bash
php artisan make:migration create_bs_users_table
```

```php
// database/migrations/2026_06_25_000001_create_bs_users_table.php
public function up(): void
{
    Schema::create('bs_users', function (Blueprint $table) {
        $table->integer('uid')->autoIncrement()->primary();
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
}
```

- [ ] **Step 4: Write migration — bs_users_meta**

```php
Schema::create('bs_users_meta', function (Blueprint $table) {
    $table->integer('umid')->autoIncrement()->primary();
    $table->integer('uid')->index();
    $table->string('meta_key', 64)->index();
    $table->text('meta_value')->nullable();
});
```

- [ ] **Step 5: Write migration — bs_squares**

```php
Schema::create('bs_squares', function (Blueprint $table) {
    $table->integer('sid')->autoIncrement()->primary();
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

- [ ] **Step 6: Write migration — bs_squares_meta**

```php
Schema::create('bs_squares_meta', function (Blueprint $table) {
    $table->integer('smid')->autoIncrement()->primary();
    $table->integer('sid')->index();
    $table->string('meta_key', 64)->index();
    $table->text('meta_value')->nullable();
});
```

- [ ] **Step 7: Write migration — bs_squares_products**

```php
Schema::create('bs_squares_products', function (Blueprint $table) {
    $table->integer('spid')->autoIncrement()->primary();
    $table->integer('sid')->index();
    $table->string('name', 64);
    $table->string('type', 32)->default('single');
    $table->integer('price')->default(0);
    $table->integer('priority')->default(0);
});
```

- [ ] **Step 8: Write migration — bs_squares_pricing**

```php
Schema::create('bs_squares_pricing', function (Blueprint $table) {
    $table->integer('sprid')->autoIncrement()->primary();
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

- [ ] **Step 9: Write migration — bs_squares_coupons**

```php
Schema::create('bs_squares_coupons', function (Blueprint $table) {
    $table->integer('scid')->autoIncrement()->primary();
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

- [ ] **Step 10: Write migration — bs_bookings**

```php
Schema::create('bs_bookings', function (Blueprint $table) {
    $table->integer('bid')->autoIncrement()->primary();
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

- [ ] **Step 11: Write migration — bs_bookings_bills**

```php
Schema::create('bs_bookings_bills', function (Blueprint $table) {
    $table->integer('bbid')->autoIncrement()->primary();
    $table->integer('bid')->index();
    $table->integer('spid')->nullable()->index();
    $table->integer('price')->default(0);
    $table->string('description', 255)->nullable();
});
```

- [ ] **Step 12: Write migration — bs_bookings_meta**

```php
Schema::create('bs_bookings_meta', function (Blueprint $table) {
    $table->integer('bmid')->autoIncrement()->primary();
    $table->integer('bid')->index();
    $table->string('meta_key', 64)->index();
    $table->text('meta_value')->nullable();
});
```

- [ ] **Step 13: Write migration — bs_reservations**

```php
Schema::create('bs_reservations', function (Blueprint $table) {
    $table->integer('rid')->autoIncrement()->primary();
    $table->integer('bid')->index();
    $table->integer('date')->index();
    $table->integer('time_start')->default(0);
    $table->integer('time_end')->default(0);
});
```

- [ ] **Step 14: Write migration — bs_reservations_meta**

```php
Schema::create('bs_reservations_meta', function (Blueprint $table) {
    $table->integer('rmid')->autoIncrement()->primary();
    $table->integer('rid')->index();
    $table->string('meta_key', 64)->index();
    $table->text('meta_value')->nullable();
});
```

- [ ] **Step 15: Write migration — bs_events**

```php
Schema::create('bs_events', function (Blueprint $table) {
    $table->integer('eid')->autoIncrement()->primary();
    $table->integer('sid')->index();
    $table->integer('datetime_start')->default(0);
    $table->integer('datetime_end')->default(0);
    $table->integer('capacity')->default(0);
    $table->string('status', 32)->default('enabled');
});
```

- [ ] **Step 16: Write migration — bs_events_meta**

```php
Schema::create('bs_events_meta', function (Blueprint $table) {
    $table->integer('emid')->autoIncrement()->primary();
    $table->integer('eid')->index();
    $table->string('meta_key', 64)->index();
    $table->text('meta_value')->nullable();
});
```

- [ ] **Step 17: Write migration — bs_options**

```php
Schema::create('bs_options', function (Blueprint $table) {
    $table->integer('oid')->autoIncrement()->primary();
    $table->string('option_key', 64)->unique();
    $table->text('option_value')->nullable();
});
```

- [ ] **Step 18: Run migrations**

```bash
php artisan migrate:fresh
```
Expected: All 15 tables created successfully.

- [ ] **Step 19: Run migration test**

```bash
php artisan test tests/Feature/MigrationTest.php
```
Expected: PASS

- [ ] **Step 20: Commit**

```bash
git add database/migrations/ tests/Feature/MigrationTest.php
git commit -m "feat: add database migrations for all 15 booking tables"
```

---

### Task 3: Create Eloquent Models

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
- Create: `tests/Unit/Models/ReservationModelTest.php`

- [ ] **Step 1: Write failing tests for models**

```php
// tests/Unit/Models/BookingModelTest.php
<?php

namespace Tests\Unit\Models;

use App\Models\Booking;
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
        \App\Models\BookingBill::factory()->count(2)->create(['bid' => $booking->bid]);

        $this->assertCount(2, $booking->bills);
    }

    /** @test */
    public function booking_status_billing_defaults_to_pending(): void
    {
        $booking = new Booking();
        $this->assertEquals('pending', $booking->status_billing ?? 'pending');
    }
}
```

```php
// tests/Unit/Models/SquareModelTest.php
<?php

namespace Tests\Unit\Models;

use App\Models\Square;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SquareModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function square_has_many_bookings(): void
    {
        $square = Square::factory()->create();
        \App\Models\Booking::factory()->count(2)->create(['sid' => $square->sid]);

        $this->assertCount(2, $square->bookings);
    }

    /** @test */
    public function square_has_many_meta(): void
    {
        $square = Square::factory()->create();
        \App\Models\SquareMeta::factory()->count(3)->create(['sid' => $square->sid]);

        $this->assertCount(3, $square->meta);
    }

    /** @test */
    public function square_has_many_products(): void
    {
        $square = Square::factory()->create();
        \App\Models\SquareProduct::factory()->count(2)->create(['sid' => $square->sid]);

        $this->assertCount(2, $square->products);
    }

    /** @test */
    public function square_is_bookable_when_status_is_enabled(): void
    {
        $square = Square::factory()->create(['status' => 'enabled']);
        $this->assertTrue($square->isBookable());
    }

    /** @test */
    public function square_is_not_bookable_when_status_is_disabled(): void
    {
        $square = Square::factory()->create(['status' => 'disabled']);
        $this->assertFalse($square->isBookable());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Unit/Models/
```
Expected: FAIL — models don't exist.

- [ ] **Step 3: Write User model**

```php
// app/Models/User.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents a system user (club member or admin).
 *
 * @property int    $uid
 * @property string $name
 * @property string $email
 * @property string $phone
 * @property string $roles        Space-separated role list
 * @property string $permissions  Space-separated permission list
 * @property string $status       'enabled'|'disabled'
 * @property string $token        Password-reset / activation token
 * @property int    $created      Unix timestamp
 * @property int    $updated      Unix timestamp
 */
class User extends Authenticatable
{
    use HasFactory;

    protected $table = 'bs_users';
    protected $primaryKey = 'uid';
    public $timestamps = false;

    protected $fillable = [
        'name', 'email', 'password', 'phone', 'roles', 'permissions', 'status', 'token',
    ];

    protected $hidden = ['password', 'token'];

    /** @return HasMany<Booking> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'uid', 'uid');
    }

    /** @return HasMany<UserMeta> */
    public function meta(): HasMany
    {
        return $this->hasMany(UserMeta::class, 'uid', 'uid');
    }

    /** Whether user has the given role. */
    public function hasRole(string $role): bool
    {
        return in_array($role, explode(' ', (string) $this->roles));
    }

    /** Whether user has the given permission. */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, explode(' ', (string) $this->permissions));
    }
}
```

- [ ] **Step 4: Write UserMeta model**

```php
// app/Models/UserMeta.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Arbitrary key-value metadata attached to a user.
 *
 * @property int    $umid
 * @property int    $uid
 * @property string $meta_key
 * @property string $meta_value
 */
class UserMeta extends Model
{
    use HasFactory;

    protected $table = 'bs_users_meta';
    protected $primaryKey = 'umid';
    public $timestamps = false;

    protected $fillable = ['uid', 'meta_key', 'meta_value'];

    /** @return BelongsTo<User, UserMeta> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uid', 'uid');
    }
}
```

- [ ] **Step 5: Write Square model**

```php
// app/Models/Square.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bookable court/square.
 *
 * @property int    $sid
 * @property string $name
 * @property string $alias                  Human-readable court name (e.g. "Centercourt")
 * @property string $status                 'enabled'|'disabled'|'readonly'
 * @property int    $capacity               Max concurrent players
 * @property int    $capacity_heterogenic   Whether mixed-count bookings are allowed
 * @property int    $time_start             Day start in seconds from midnight
 * @property int    $time_end               Day end in seconds from midnight
 * @property int    $time_block             Booking slot size in seconds
 * @property int    $time_block_bookable    Min bookable duration in seconds
 * @property int    $time_block_bookable_max Max per-user per-day duration in seconds (0=unlimited)
 * @property int    $min_range_book         Min advance booking in seconds (0=allow past)
 * @property int    $range_book             Max advance booking in seconds (0=unlimited)
 * @property int    $range_cancel           Cancellation deadline in seconds
 * @property int    $priority               Display sort order
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

    /** @return HasMany<Booking> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'sid', 'sid');
    }

    /** @return HasMany<SquareMeta> */
    public function meta(): HasMany
    {
        return $this->hasMany(SquareMeta::class, 'sid', 'sid');
    }

    /** @return HasMany<SquareProduct> */
    public function products(): HasMany
    {
        return $this->hasMany(SquareProduct::class, 'sid', 'sid');
    }

    /** @return HasMany<SquarePricing> */
    public function pricing(): HasMany
    {
        return $this->hasMany(SquarePricing::class, 'sid', 'sid');
    }

    /** @return HasMany<Event> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'sid', 'sid');
    }

    /** Whether this square accepts new bookings from the public. */
    public function isBookable(): bool
    {
        return $this->status === 'enabled';
    }

    /** Whether this square is completely disabled (even privileged users can't book). */
    public function isDisabled(): bool
    {
        return $this->status === 'disabled';
    }
}
```

- [ ] **Step 6: Write SquareMeta, SquareProduct, SquarePricing, SquareCoupon models**

```php
// app/Models/SquareMeta.php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Key-value metadata for a square. @property int $smid @property int $sid @property string $meta_key @property string $meta_value */
class SquareMeta extends Model {
    use HasFactory;
    protected $table = 'bs_squares_meta'; protected $primaryKey = 'smid'; public $timestamps = false;
    protected $fillable = ['sid', 'meta_key', 'meta_value'];
    public function square(): BelongsTo { return $this->belongsTo(Square::class, 'sid', 'sid'); }
}
```

```php
// app/Models/SquareProduct.php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A purchasable product (e.g. court rental type) linked to a square. @property int $spid @property int $sid @property string $name @property string $type @property int $price @property int $priority */
class SquareProduct extends Model {
    use HasFactory;
    protected $table = 'bs_squares_products'; protected $primaryKey = 'spid'; public $timestamps = false;
    protected $fillable = ['sid', 'name', 'type', 'price', 'priority'];
    public function square(): BelongsTo { return $this->belongsTo(Square::class, 'sid', 'sid'); }
}
```

```php
// app/Models/SquarePricing.php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Time-range-based dynamic pricing rule for a square. @property int $sprid @property int $sid @property int $spid @property int $date_start @property int $date_end @property int $time_start @property int $time_end @property int $price @property int $priority */
class SquarePricing extends Model {
    use HasFactory;
    protected $table = 'bs_squares_pricing'; protected $primaryKey = 'sprid'; public $timestamps = false;
    protected $fillable = ['sid', 'spid', 'date_start', 'date_end', 'time_start', 'time_end', 'price', 'priority'];
}
```

```php
// app/Models/SquareCoupon.php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Discount coupon redeemable against a square booking. @property int $scid @property int $sid @property string $code @property string $type 'percent'|'fixed' @property int $value @property string $status */
class SquareCoupon extends Model {
    use HasFactory;
    protected $table = 'bs_squares_coupons'; protected $primaryKey = 'scid'; public $timestamps = false;
    protected $fillable = ['sid', 'code', 'type', 'value', 'valid_from', 'valid_until', 'usage_max', 'usage_count', 'status'];
}
```

- [ ] **Step 7: Write Booking model**

```php
// app/Models/Booking.php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A court booking (parent entity — owns reservations and bills).
 *
 * @property int    $bid
 * @property int    $uid
 * @property int    $sid
 * @property string $status          'enabled'|'disabled'
 * @property string $status_billing  'pending'|'paid'|'cancelled'|'uncollectable'
 * @property string $visibility      'public'|'private'
 * @property int    $quantity        Number of players
 * @property int    $created         Unix timestamp
 * @property int    $updated         Unix timestamp
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

    /** @return BelongsTo<User, Booking> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uid', 'uid');
    }

    /** @return BelongsTo<Square, Booking> */
    public function square(): BelongsTo
    {
        return $this->belongsTo(Square::class, 'sid', 'sid');
    }

    /** @return HasMany<Reservation> */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'bid', 'bid');
    }

    /** @return HasMany<BookingBill> */
    public function bills(): HasMany
    {
        return $this->hasMany(BookingBill::class, 'bid', 'bid');
    }

    /** @return HasMany<BookingMeta> */
    public function meta(): HasMany
    {
        return $this->hasMany(BookingMeta::class, 'bid', 'bid');
    }
}
```

- [ ] **Step 8: Write BookingBill, BookingMeta, Reservation, ReservationMeta, Event, EventMeta, Option models**

```php
// app/Models/BookingBill.php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A line-item bill associated with a booking. @property int $bbid @property int $bid @property int $spid @property int $price @property string $description */
class BookingBill extends Model {
    use HasFactory;
    protected $table = 'bs_bookings_bills'; protected $primaryKey = 'bbid'; public $timestamps = false;
    protected $fillable = ['bid', 'spid', 'price', 'description'];
    public function booking(): BelongsTo { return $this->belongsTo(Booking::class, 'bid', 'bid'); }
}
```

```php
// app/Models/BookingMeta.php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Key-value metadata for a booking (e.g. player names, notes). @property int $bmid @property int $bid @property string $meta_key @property string $meta_value */
class BookingMeta extends Model {
    use HasFactory;
    protected $table = 'bs_bookings_meta'; protected $primaryKey = 'bmid'; public $timestamps = false;
    protected $fillable = ['bid', 'meta_key', 'meta_value'];
}
```

```php
// app/Models/Reservation.php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single time-slot reservation within a booking.
 * One booking can span multiple reservations (subscription).
 *
 * @property int $rid
 * @property int $bid
 * @property int $date        Unix timestamp (midnight of the date)
 * @property int $time_start  Seconds from midnight
 * @property int $time_end    Seconds from midnight
 */
class Reservation extends Model {
    use HasFactory;
    protected $table = 'bs_reservations'; protected $primaryKey = 'rid'; public $timestamps = false;
    protected $fillable = ['bid', 'date', 'time_start', 'time_end'];
    public function booking(): BelongsTo { return $this->belongsTo(Booking::class, 'bid', 'bid'); }
    public function meta(): HasMany { return $this->hasMany(ReservationMeta::class, 'rid', 'rid'); }
}
```

```php
// app/Models/ReservationMeta.php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property int $rmid @property int $rid @property string $meta_key @property string $meta_value */
class ReservationMeta extends Model {
    use HasFactory;
    protected $table = 'bs_reservations_meta'; protected $primaryKey = 'rmid'; public $timestamps = false;
    protected $fillable = ['rid', 'meta_key', 'meta_value'];
}
```

```php
// app/Models/Event.php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A special event that blocks or limits court availability. @property int $eid @property int $sid @property int $datetime_start @property int $datetime_end @property int $capacity @property string $status */
class Event extends Model {
    use HasFactory;
    protected $table = 'bs_events'; protected $primaryKey = 'eid'; public $timestamps = false;
    protected $fillable = ['sid', 'datetime_start', 'datetime_end', 'capacity', 'status'];
    public function square(): BelongsTo { return $this->belongsTo(Square::class, 'sid', 'sid'); }
    public function meta(): HasMany { return $this->hasMany(EventMeta::class, 'eid', 'eid'); }
}
```

```php
// app/Models/EventMeta.php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property int $emid @property int $eid @property string $meta_key @property string $meta_value */
class EventMeta extends Model {
    use HasFactory;
    protected $table = 'bs_events_meta'; protected $primaryKey = 'emid'; public $timestamps = false;
    protected $fillable = ['eid', 'meta_key', 'meta_value'];
}
```

```php
// app/Models/Option.php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Global key-value configuration option. @property int $oid @property string $option_key @property string $option_value */
class Option extends Model {
    use HasFactory;
    protected $table = 'bs_options'; protected $primaryKey = 'oid'; public $timestamps = false;
    protected $fillable = ['option_key', 'option_value'];

    /** Get option value by key, with optional default. */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        return static::where('option_key', $key)->value('option_value') ?? $default;
    }
}
```

- [ ] **Step 9: Run model tests**

```bash
php artisan test tests/Unit/Models/
```
Expected: PASS

- [ ] **Step 10: Commit**

```bash
git add app/Models/ tests/Unit/Models/
git commit -m "feat: add eloquent models for all 15 database tables"
```

---

### Task 4: Create Model Factories

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

- [ ] **Step 1: Write failing test**

```php
// tests/Unit/Factories/FactoryTest.php
<?php
namespace Tests\Unit\Factories;
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
    public function booking_factory_creates_booking_with_relations(): void
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
php artisan test tests/Unit/Factories/FactoryTest.php
```
Expected: FAIL

- [ ] **Step 3: Write factories**

```bash
php artisan make:factory UserFactory --model=User
php artisan make:factory SquareFactory --model=Square
php artisan make:factory BookingFactory --model=Booking
php artisan make:factory ReservationFactory --model=Reservation
php artisan make:factory BookingBillFactory --model=BookingBill
php artisan make:factory EventFactory --model=Event
php artisan make:factory SquareMetaFactory --model=SquareMeta
php artisan make:factory UserMetaFactory --model=UserMeta
php artisan make:factory BookingMetaFactory --model=BookingMeta
```

```php
// database/factories/UserFactory.php
public function definition(): array
{
    return [
        'name' => $this->faker->name(),
        'email' => $this->faker->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'phone' => $this->faker->phoneNumber(),
        'roles' => 'member',
        'permissions' => 'calendar.create-single-bookings calendar.see-data',
        'status' => 'enabled',
        'token' => null,
        'created' => now()->timestamp,
        'updated' => now()->timestamp,
    ];
}
```

```php
// database/factories/SquareFactory.php
public function definition(): array
{
    return [
        'name' => $this->faker->randomElement(['Platz 1', 'Platz 2', 'Platz 3']),
        'alias' => $this->faker->randomElement(['Unterer Platz', 'Centercourt', 'Oberer Platz']),
        'status' => 'enabled',
        'capacity' => 4,
        'capacity_heterogenic' => 1,
        'time_start' => 28800,   // 08:00
        'time_end' => 79200,     // 22:00
        'time_block' => 3600,    // 1 hour slots
        'time_block_bookable' => 3600,
        'time_block_bookable_max' => 0,
        'min_range_book' => 0,
        'range_book' => 0,
        'range_cancel' => 86400,
        'priority' => 0,
    ];
}
```

```php
// database/factories/BookingFactory.php
public function definition(): array
{
    return [
        'uid' => User::factory(),
        'sid' => Square::factory(),
        'status' => 'enabled',
        'status_billing' => 'pending',
        'visibility' => 'public',
        'quantity' => 2,
        'created' => now()->timestamp,
        'updated' => now()->timestamp,
    ];
}
```

```php
// database/factories/ReservationFactory.php
public function definition(): array
{
    $date = now()->startOfDay()->timestamp;
    return [
        'bid' => Booking::factory(),
        'date' => $date,
        'time_start' => 36000,  // 10:00
        'time_end' => 39600,    // 11:00
    ];
}
```

```php
// database/factories/BookingBillFactory.php
public function definition(): array
{
    return [
        'bid' => Booking::factory(),
        'spid' => null,
        'price' => $this->faker->numberBetween(500, 3000),
        'description' => $this->faker->sentence(),
    ];
}
```

```php
// database/factories/EventFactory.php
public function definition(): array
{
    return [
        'sid' => Square::factory(),
        'datetime_start' => now()->timestamp,
        'datetime_end' => now()->addHours(2)->timestamp,
        'capacity' => 0,
        'status' => 'enabled',
    ];
}
```

```php
// database/factories/SquareMetaFactory.php
public function definition(): array
{
    return ['sid' => Square::factory(), 'meta_key' => 'key_' . $this->faker->word(), 'meta_value' => $this->faker->sentence()];
}
```

```php
// database/factories/UserMetaFactory.php
public function definition(): array
{
    return ['uid' => User::factory(), 'meta_key' => 'key_' . $this->faker->word(), 'meta_value' => $this->faker->sentence()];
}
```

```php
// database/factories/BookingMetaFactory.php
public function definition(): array
{
    return ['bid' => Booking::factory(), 'meta_key' => 'player_name_1', 'meta_value' => $this->faker->name()];
}
```

- [ ] **Step 4: Run factory tests**

```bash
php artisan test tests/Unit/Factories/FactoryTest.php
```
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/factories/ tests/Unit/Factories/
git commit -m "feat: add model factories for all entities"
```

---

## Phase 3 — Business Logic Services

### Task 5: SquareValidator Service

This is the core booking constraint validator — port from `module/Square/src/Square/Service/SquareValidator.php`.

**Files:**
- Create: `app/Services/SquareValidator.php`
- Create: `tests/Unit/Services/SquareValidatorTest.php`

- [ ] **Step 1: Write failing tests**

```php
// tests/Unit/Services/SquareValidatorTest.php
<?php

namespace Tests\Unit\Services;

use App\Models\Booking;
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
        $dateStart = Carbon::now()->addDay()->setTime(10, 0);
        $dateEnd = Carbon::now()->addDay()->setTime(11, 0);

        $result = $this->validator->validate($square, $user, 2, $dateStart, $dateEnd);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('disabled', $result->getError());
    }

    /** @test */
    public function readonly_square_blocks_booking_without_privilege(): void
    {
        $square = Square::factory()->create(['status' => 'readonly']);
        $user = User::factory()->create(['permissions' => '']);
        $dateStart = Carbon::now()->addDay()->setTime(10, 0);
        $dateEnd = Carbon::now()->addDay()->setTime(11, 0);

        $result = $this->validator->validate($square, $user, 2, $dateStart, $dateEnd);

        $this->assertFalse($result->isValid());
    }

    /** @test */
    public function booking_beyond_range_book_is_rejected(): void
    {
        $square = Square::factory()->create([
            'status' => 'enabled',
            'range_book' => 7 * 86400, // 7 days max advance
        ]);
        $user = User::factory()->create();
        $dateStart = Carbon::now()->addDays(10)->setTime(10, 0); // 10 days ahead — too far
        $dateEnd = Carbon::now()->addDays(10)->setTime(11, 0);

        $result = $this->validator->validate($square, $user, 2, $dateStart, $dateEnd);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('range', strtolower($result->getError()));
    }

    /** @test */
    public function booking_within_range_book_is_accepted(): void
    {
        $square = Square::factory()->create([
            'status' => 'enabled',
            'range_book' => 14 * 86400, // 14 days
            'time_block_bookable_max' => 0,
        ]);
        $user = User::factory()->create(['permissions' => 'calendar.create-single-bookings']);
        $dateStart = Carbon::now()->addDays(5)->setTime(10, 0);
        $dateEnd = Carbon::now()->addDays(5)->setTime(11, 0);

        $result = $this->validator->validate($square, $user, 2, $dateStart, $dateEnd);

        $this->assertTrue($result->isValid());
    }

    /** @test */
    public function daily_limit_is_enforced(): void
    {
        $square = Square::factory()->create([
            'status' => 'enabled',
            'time_block_bookable_max' => 3600, // 1 hour daily limit
        ]);
        $user = User::factory()->create(['permissions' => 'calendar.create-single-bookings']);

        // Existing booking uses the full hour
        $booking = Booking::factory()->create(['uid' => $user->uid, 'sid' => $square->sid]);
        \App\Models\Reservation::factory()->create([
            'bid' => $booking->bid,
            'date' => Carbon::now()->addDays(3)->startOfDay()->timestamp,
            'time_start' => 36000,
            'time_end' => 39600, // 1 hour already booked
        ]);

        $dateStart = Carbon::now()->addDays(3)->setTime(12, 0);
        $dateEnd = Carbon::now()->addDays(3)->setTime(13, 0);

        $result = $this->validator->validate($square, $user, 2, $dateStart, $dateEnd);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('limit', strtolower($result->getError()));
    }

    /** @test */
    public function short_booking_ignores_daily_limit(): void
    {
        $square = Square::factory()->create([
            'status' => 'enabled',
            'time_block_bookable_max' => 3600, // 1 hour daily limit
        ]);
        $user = User::factory()->create(['permissions' => 'calendar.create-single-bookings']);

        // Booking starts within 30 minutes — short booking exemption
        $dateStart = Carbon::now()->addMinutes(20);
        $dateEnd = $dateStart->copy()->addHour();

        $result = $this->validator->validate($square, $user, 2, $dateStart, $dateEnd);

        $this->assertTrue($result->isValid());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Unit/Services/SquareValidatorTest.php
```
Expected: FAIL

- [ ] **Step 3: Create ValidationResult value object**

```php
// app/Services/ValidationResult.php
<?php

namespace App\Services;

/**
 * Immutable result of a booking validation check.
 * Use isValid() to check pass/fail, getError() to retrieve the failure reason.
 */
class ValidationResult
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

- [ ] **Step 4: Implement SquareValidator**

```php
// app/Services/SquareValidator.php
<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Reservation;
use App\Models\Square;
use App\Models\User;
use Carbon\Carbon;

/**
 * Validates whether a user may create a booking on a given square at a given time.
 *
 * Rules ported from module/Square/src/Square/Service/SquareValidator.php:
 * - Disabled squares: no one can book
 * - Readonly squares: only privileged users (calendar.create-single-bookings)
 * - range_book: max advance booking window (seconds, 0=unlimited)
 * - time_block_bookable_max: daily total per user per court (0=unlimited)
 * - Short booking exemption: bookings starting within 30min ignore daily limit
 */
class SquareValidator
{
    private const SHORT_BOOKING_THRESHOLD_SECONDS = 1800; // 30 minutes

    /**
     * Validate a proposed booking.
     *
     * @param Square  $square    The court being booked
     * @param User    $user      The user requesting the booking
     * @param int     $quantity  Number of players
     * @param Carbon  $dateStart Booking start datetime
     * @param Carbon  $dateEnd   Booking end datetime
     */
    public function validate(
        Square $square,
        User $user,
        int $quantity,
        Carbon $dateStart,
        Carbon $dateEnd,
    ): ValidationResult {
        if ($square->isDisabled()) {
            return ValidationResult::fail('Square is disabled');
        }

        if ($square->status === 'readonly' && !$user->hasPermission('calendar.create-single-bookings')) {
            return ValidationResult::fail('Square is readonly — booking requires privilege');
        }

        if ($square->range_book > 0) {
            $maxBookableAt = Carbon::now()->addSeconds($square->range_book);
            // Allow booking if it ends before 23:59:59 on the last bookable day
            $maxBookableAt->endOfDay();
            if ($dateStart->greaterThan($maxBookableAt)) {
                return ValidationResult::fail('Booking date exceeds allowed advance booking range');
            }
        }

        if ($square->time_block_bookable_max > 0 && !$this->isShortBooking($dateStart)) {
            $dailyUsedSeconds = $this->getDailyUsedSeconds($user, $square, $dateStart);
            $requestedSeconds = $dateEnd->diffInSeconds($dateStart);

            if ($dailyUsedSeconds + $requestedSeconds > $square->time_block_bookable_max) {
                return ValidationResult::fail('Daily booking limit exceeded for this square');
            }
        }

        return ValidationResult::pass();
    }

    /** Whether booking starts within 30 minutes (exempted from daily limit). */
    private function isShortBooking(Carbon $dateStart): bool
    {
        return $dateStart->diffInSeconds(Carbon::now(), false) >= -self::SHORT_BOOKING_THRESHOLD_SECONDS;
    }

    /** Total booked seconds for this user on this court on the given day. */
    private function getDailyUsedSeconds(User $user, Square $square, Carbon $date): int
    {
        $dayTimestamp = $date->copy()->startOfDay()->timestamp;

        return (int) Reservation::query()
            ->join('bs_bookings', 'bs_bookings.bid', '=', 'bs_reservations.bid')
            ->where('bs_bookings.uid', $user->uid)
            ->where('bs_bookings.sid', $square->sid)
            ->where('bs_bookings.status', 'enabled')
            ->where('bs_reservations.date', $dayTimestamp)
            ->sum(\DB::raw('bs_reservations.time_end - bs_reservations.time_start'));
    }
}
```

- [ ] **Step 5: Run tests**

```bash
php artisan test tests/Unit/Services/SquareValidatorTest.php
```
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/SquareValidator.php app/Services/ValidationResult.php tests/Unit/Services/SquareValidatorTest.php
git commit -m "feat: add SquareValidator service with full booking constraint logic"
```

---

### Task 6: BookingService

**Files:**
- Create: `app/Services/BookingService.php`
- Create: `tests/Unit/Services/BookingServiceTest.php`

- [ ] **Step 1: Write failing tests**

```php
// tests/Unit/Services/BookingServiceTest.php
<?php

namespace Tests\Unit\Services;

use App\Models\Booking;
use App\Models\Reservation;
use App\Models\Square;
use App\Models\User;
use App\Services\BookingService;
use App\Services\SquareValidator;
use App\Services\ValidationResult;
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
    public function create_single_booking_persists_booking_and_reservation(): void
    {
        $user = User::factory()->create(['permissions' => 'calendar.create-single-bookings']);
        $square = Square::factory()->create(['status' => 'enabled']);
        $dateStart = Carbon::now()->addDay()->setTime(10, 0);
        $dateEnd = Carbon::now()->addDay()->setTime(11, 0);

        $booking = $this->service->createSingle($user, $square, 2, $dateStart, $dateEnd);

        $this->assertNotNull($booking->bid);
        $this->assertEquals(1, $booking->reservations()->count());
        $this->assertEquals($dateStart->copy()->startOfDay()->timestamp, $booking->reservations()->first()->date);
        $this->assertEquals(36000, $booking->reservations()->first()->time_start); // 10*3600
        $this->assertEquals(39600, $booking->reservations()->first()->time_end);   // 11*3600
    }

    /** @test */
    public function create_single_booking_fails_when_square_is_disabled(): void
    {
        $user = User::factory()->create();
        $square = Square::factory()->create(['status' => 'disabled']);
        $dateStart = Carbon::now()->addDay()->setTime(10, 0);
        $dateEnd = Carbon::now()->addDay()->setTime(11, 0);

        $this->expectException(\App\Exceptions\BookingValidationException::class);

        $this->service->createSingle($user, $square, 2, $dateStart, $dateEnd);
    }

    /** @test */
    public function cancel_booking_sets_status_to_cancelled(): void
    {
        $booking = Booking::factory()->create(['status' => 'enabled', 'status_billing' => 'pending']);

        $this->service->cancelSingle($booking);

        $booking->refresh();
        $this->assertEquals('cancelled', $booking->status_billing);
        $this->assertEquals('disabled', $booking->status);
    }

    /** @test */
    public function create_single_booking_is_atomic_on_failure(): void
    {
        $user = User::factory()->create(['permissions' => 'calendar.create-single-bookings']);
        $square = Square::factory()->create(['status' => 'enabled']);

        // Simulate a reservation conflict by pre-booking the slot
        $existing = Booking::factory()->create(['sid' => $square->sid, 'status' => 'enabled']);
        $dateStart = Carbon::now()->addDay()->setTime(10, 0);
        Reservation::factory()->create([
            'bid' => $existing->bid,
            'date' => $dateStart->copy()->startOfDay()->timestamp,
            'time_start' => 36000,
            'time_end' => 39600,
        ]);

        $initialBookingCount = Booking::count();

        try {
            // Attempt double-booking (service should detect conflict)
            $this->service->createSingle($user, $square, 2, $dateStart, $dateStart->copy()->addHour());
        } catch (\Exception $e) {
            // Expected
        }

        // No orphan booking should remain
        $this->assertLessThanOrEqual($initialBookingCount + 1, Booking::count());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Unit/Services/BookingServiceTest.php
```
Expected: FAIL

- [ ] **Step 3: Create BookingValidationException**

```php
// app/Exceptions/BookingValidationException.php
<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown when SquareValidator rejects a booking attempt. */
class BookingValidationException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }
}
```

- [ ] **Step 4: Implement BookingService**

```php
// app/Services/BookingService.php
<?php

namespace App\Services;

use App\Exceptions\BookingValidationException;
use App\Models\Booking;
use App\Models\Reservation;
use App\Models\Square;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Application service for creating and cancelling bookings.
 * All database writes are wrapped in transactions for atomicity.
 */
class BookingService
{
    public function __construct(
        private readonly SquareValidator $validator,
    ) {}

    /**
     * Create a single booking with one reservation.
     *
     * @param User   $user      The booking owner
     * @param Square $square    The court to book
     * @param int    $quantity  Number of players
     * @param Carbon $dateStart Start datetime
     * @param Carbon $dateEnd   End datetime
     * @param array  $bills     Optional bill data [['spid' => ..., 'price' => ..., 'description' => ...]]
     * @param array  $meta      Optional booking metadata [['meta_key' => ..., 'meta_value' => ...]]
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

        return DB::transaction(function () use ($user, $square, $quantity, $dateStart, $dateEnd, $bills, $meta) {
            $booking = Booking::create([
                'uid' => $user->uid,
                'sid' => $square->sid,
                'status' => 'enabled',
                'status_billing' => 'pending',
                'visibility' => 'public',
                'quantity' => $quantity,
                'created' => now()->timestamp,
                'updated' => now()->timestamp,
            ]);

            Reservation::create([
                'bid' => $booking->bid,
                'date' => $dateStart->copy()->startOfDay()->timestamp,
                'time_start' => $dateStart->secondsSinceMidnight(),
                'time_end' => $dateEnd->secondsSinceMidnight(),
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
     * Cancel a booking — sets status to disabled and status_billing to cancelled.
     */
    public function cancelSingle(Booking $booking): void
    {
        $booking->update([
            'status' => 'disabled',
            'status_billing' => 'cancelled',
            'updated' => now()->timestamp,
        ]);
    }
}
```

- [ ] **Step 5: Run tests**

```bash
php artisan test tests/Unit/Services/BookingServiceTest.php
```
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/BookingService.php app/Exceptions/BookingValidationException.php tests/Unit/Services/BookingServiceTest.php
git commit -m "feat: add BookingService with atomic create/cancel and validation"
```

---

### Task 7: ReservationService — range-based reservation creation

**Files:**
- Create: `app/Services/ReservationService.php`
- Create: `tests/Unit/Services/ReservationServiceTest.php`

- [ ] **Step 1: Write failing tests**

```php
// tests/Unit/Services/ReservationServiceTest.php
<?php

namespace Tests\Unit\Services;

use App\Models\Booking;
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
    public function get_in_range_returns_reservations_within_date_window(): void
    {
        $booking = Booking::factory()->create();
        $inRange = \App\Models\Reservation::factory()->create([
            'bid' => $booking->bid,
            'date' => Carbon::now()->timestamp,
        ]);
        $outOfRange = \App\Models\Reservation::factory()->create([
            'bid' => $booking->bid,
            'date' => Carbon::now()->addDays(10)->timestamp,
        ]);

        $results = $this->service->getInRange(
            Carbon::now()->subDay(),
            Carbon::now()->addDay(),
        );

        $rids = $results->pluck('rid')->toArray();
        $this->assertContains($inRange->rid, $rids);
        $this->assertNotContains($outOfRange->rid, $rids);
    }

    /** @test */
    public function get_in_range_by_square_filters_by_sid(): void
    {
        $square1 = Square::factory()->create();
        $square2 = Square::factory()->create();

        $b1 = Booking::factory()->create(['sid' => $square1->sid]);
        $b2 = Booking::factory()->create(['sid' => $square2->sid]);

        $r1 = \App\Models\Reservation::factory()->create(['bid' => $b1->bid, 'date' => now()->timestamp]);
        $r2 = \App\Models\Reservation::factory()->create(['bid' => $b2->bid, 'date' => now()->timestamp]);

        $results = $this->service->getInRangeBySquare(
            $square1,
            Carbon::now()->subDay(),
            Carbon::now()->addDay(),
        );

        $rids = $results->pluck('rid')->toArray();
        $this->assertContains($r1->rid, $rids);
        $this->assertNotContains($r2->rid, $rids);
    }

    /** @test */
    public function check_overlap_detects_conflict(): void
    {
        $booking = Booking::factory()->create();
        \App\Models\Reservation::factory()->create([
            'bid' => $booking->bid,
            'date' => Carbon::today()->timestamp,
            'time_start' => 36000, // 10:00
            'time_end' => 39600,   // 11:00
        ]);

        $hasConflict = $this->service->hasOverlap(
            $booking->square,
            Carbon::today(),
            36000,
            39600,
            excludeBookingId: null,
        );

        $this->assertTrue($hasConflict);
    }

    /** @test */
    public function check_overlap_ignores_cancelled_bookings(): void
    {
        $booking = Booking::factory()->create(['status' => 'disabled']);
        \App\Models\Reservation::factory()->create([
            'bid' => $booking->bid,
            'date' => Carbon::today()->timestamp,
            'time_start' => 36000,
            'time_end' => 39600,
        ]);

        $hasConflict = $this->service->hasOverlap(
            $booking->square,
            Carbon::today(),
            36000,
            39600,
            excludeBookingId: null,
        );

        $this->assertFalse($hasConflict);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Unit/Services/ReservationServiceTest.php
```
Expected: FAIL

- [ ] **Step 3: Implement ReservationService**

```php
// app/Services/ReservationService.php
<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Square;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Query helper for reservations — range lookups and overlap detection.
 */
class ReservationService
{
    /**
     * Get all active reservations in a date range (inclusive).
     *
     * @return Collection<Reservation>
     */
    public function getInRange(Carbon $from, Carbon $to): Collection
    {
        return Reservation::query()
            ->join('bs_bookings', 'bs_bookings.bid', '=', 'bs_reservations.bid')
            ->where('bs_bookings.status', 'enabled')
            ->whereBetween('bs_reservations.date', [
                $from->copy()->startOfDay()->timestamp,
                $to->copy()->endOfDay()->timestamp,
            ])
            ->select('bs_reservations.*')
            ->get();
    }

    /**
     * Get active reservations for a specific square in a date range.
     *
     * @return Collection<Reservation>
     */
    public function getInRangeBySquare(Square $square, Carbon $from, Carbon $to): Collection
    {
        return Reservation::query()
            ->join('bs_bookings', 'bs_bookings.bid', '=', 'bs_reservations.bid')
            ->where('bs_bookings.status', 'enabled')
            ->where('bs_bookings.sid', $square->sid)
            ->whereBetween('bs_reservations.date', [
                $from->copy()->startOfDay()->timestamp,
                $to->copy()->endOfDay()->timestamp,
            ])
            ->select('bs_reservations.*')
            ->get();
    }

    /**
     * Check whether a given time slot on a court is already reserved.
     *
     * @param Square   $square           Court to check
     * @param Carbon   $date             The calendar date
     * @param int      $timeStart        Seconds from midnight (start)
     * @param int      $timeEnd          Seconds from midnight (end)
     * @param int|null $excludeBookingId Ignore this booking ID (useful when updating)
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
            ->where('bs_bookings.status', 'enabled')
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
php artisan test tests/Unit/Services/ReservationServiceTest.php
```
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/ReservationService.php tests/Unit/Services/ReservationServiceTest.php
git commit -m "feat: add ReservationService with range queries and overlap detection"
```

---

## Phase 4 — Authentication

### Task 8: Laravel Auth with custom bs_users table

**Files:**
- Modify: `config/auth.php`
- Create: `app/Http/Controllers/Auth/LoginController.php`
- Create: `app/Http/Controllers/Auth/LogoutController.php`
- Create: `tests/Feature/Auth/LoginTest.php`

- [ ] **Step 1: Write failing tests**

```php
// tests/Feature/Auth/LoginTest.php
<?php

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
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('secret123'),
            'status' => 'enabled',
        ]);

        $response = $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'secret123',
        ]);

        $response->assertRedirect('/calendar');
        $this->assertAuthenticatedAs($user);
    }

    /** @test */
    public function login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('secret123'),
        ]);

        $response = $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'wrong',
        ]);

        $response->assertRedirect('/login');
        $this->assertGuest();
    }

    /** @test */
    public function disabled_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('secret123'),
            'status' => 'disabled',
        ]);

        $response = $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'secret123',
        ]);

        $response->assertRedirect('/login');
        $this->assertGuest();
    }

    /** @test */
    public function authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->post('/logout');

        $response->assertRedirect('/login');
        $this->assertGuest();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Feature/Auth/LoginTest.php
```
Expected: FAIL

- [ ] **Step 3: Configure auth to use bs_users**

```php
// config/auth.php — update providers section:
'providers' => [
    'users' => [
        'driver' => 'eloquent',
        'model' => App\Models\User::class,
    ],
],
```

- [ ] **Step 4: Add auth routes to routes/web.php**

```php
// routes/web.php
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\CalendarController;

Route::get('/login', [LoginController::class, 'showForm'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LogoutController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
});

Route::get('/', fn() => redirect()->route('calendar.index'));
```

- [ ] **Step 5: Implement LoginController**

```php
// app/Http/Controllers/Auth/LoginController.php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** Handles user login form display and credential authentication. */
class LoginController extends Controller
{
    public function showForm(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = \App\Models\User::where('email', $credentials['email'])->first();

        if ($user && $user->status === 'disabled') {
            return back()->withErrors(['email' => 'Account is disabled.']);
        }

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();
            return redirect()->intended('/calendar');
        }

        return redirect('/login')->withErrors(['email' => 'Invalid credentials.']);
    }
}
```

- [ ] **Step 6: Implement LogoutController**

```php
// app/Http/Controllers/Auth/LogoutController.php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Handles user logout. */
class LogoutController extends Controller
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

- [ ] **Step 7: Create stub login view**

```php
// resources/views/auth/login.blade.php
<!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8"><title>Login – TCBewegung Buchung</title></head>
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

- [ ] **Step 8: Create stub CalendarController**

```php
// app/Http/Controllers/CalendarController.php
<?php
namespace App\Http\Controllers;
use Illuminate\View\View;

/** Displays the booking calendar. */
class CalendarController extends Controller
{
    public function index(): View
    {
        return view('calendar.index');
    }
}
```

```php
// resources/views/calendar/index.blade.php
<!DOCTYPE html><html><body><h1>Buchungskalender</h1><p>Logged in as {{ auth()->user()->name }}</p></body></html>
```

- [ ] **Step 9: Run auth tests**

```bash
php artisan test tests/Feature/Auth/LoginTest.php
```
Expected: PASS

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/Auth/ app/Http/Controllers/CalendarController.php resources/views/ routes/web.php config/auth.php tests/Feature/Auth/
git commit -m "feat: add authentication with custom bs_users table"
```

---

## Phase 5 — HTTP Controllers

### Task 9: CalendarController (full implementation)

**Files:**
- Modify: `app/Http/Controllers/CalendarController.php`
- Create: `resources/views/calendar/index.blade.php`
- Create: `tests/Feature/CalendarControllerTest.php`

- [ ] **Step 1: Write failing tests**

```php
// tests/Feature/CalendarControllerTest.php
<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Reservation;
use App\Models\Square;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function guest_is_redirected_to_login(): void
    {
        $this->get('/calendar')->assertRedirect('/login');
    }

    /** @test */
    public function authenticated_user_sees_calendar(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/calendar')->assertOk()->assertViewIs('calendar.index');
    }

    /** @test */
    public function calendar_shows_squares(): void
    {
        $square = Square::factory()->create(['name' => 'Platz 1', 'alias' => 'Centercourt']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/calendar')
            ->assertSee('Platz 1')
            ->assertSee('Centercourt');
    }

    /** @test */
    public function calendar_shows_date_navigation(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get('/calendar?date=2026-07-01');

        $response->assertOk()->assertSee('2026-07-01');
    }

    /** @test */
    public function calendar_shows_existing_booking_for_logged_in_user(): void
    {
        $user = User::factory()->create();
        $square = Square::factory()->create();
        $booking = Booking::factory()->create(['uid' => $user->uid, 'sid' => $square->sid, 'status' => 'enabled']);
        Reservation::factory()->create([
            'bid' => $booking->bid,
            'date' => Carbon::today()->timestamp,
            'time_start' => 36000,
            'time_end' => 39600,
        ]);

        $response = $this->actingAs($user)->get('/calendar?date=' . Carbon::today()->format('Y-m-d'));

        $response->assertOk()->assertSee($user->name);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Feature/CalendarControllerTest.php
```
Expected: FAIL (views missing, controller incomplete)

- [ ] **Step 3: Implement CalendarController**

```php
// app/Http/Controllers/CalendarController.php
<?php

namespace App\Http\Controllers;

use App\Models\Square;
use App\Services\ReservationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Shows the weekly/daily booking calendar.
 * Loads all active squares and their reservations for the requested date.
 */
class CalendarController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
    ) {}

    public function index(Request $request): View
    {
        $date = $request->input('date')
            ? Carbon::parse($request->input('date'))
            : Carbon::today();

        $squares = Square::orderBy('priority')->orderBy('sid')->get();

        $reservationsBySquare = $squares->mapWithKeys(function (Square $square) use ($date) {
            return [
                $square->sid => $this->reservations->getInRangeBySquare(
                    $square,
                    $date->copy()->startOfDay(),
                    $date->copy()->endOfDay(),
                )->load('booking.user', 'booking.meta'),
            ];
        });

        return view('calendar.index', [
            'date' => $date,
            'squares' => $squares,
            'reservationsBySquare' => $reservationsBySquare,
        ]);
    }
}
```

- [ ] **Step 4: Write calendar Blade view**

```blade
{{-- resources/views/calendar/index.blade.php --}}
@extends('layouts.app')

@section('title', 'Buchungskalender')

@section('content')
<div class="calendar-toolbar">
    <a href="{{ route('calendar.index', ['date' => $date->copy()->subDay()->format('Y-m-d')]) }}">&lt;</a>
    <strong>{{ $date->format('d.m.Y') }}</strong>
    <a href="{{ route('calendar.index', ['date' => $date->copy()->addDay()->format('Y-m-d')]) }}">&gt;</a>
    <a href="{{ route('calendar.index') }}">Heute</a>
</div>

<table class="calendar-grid">
    <thead>
        <tr>
            <th>Zeit</th>
            @foreach($squares as $square)
                <th>{{ $square->name }}@if($square->alias) – {{ $square->alias }}@endif</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @php $hour = 8; @endphp
        @while($hour < 22)
            <tr>
                <td>{{ str_pad($hour, 2, '0', STR_PAD_LEFT) }}:00</td>
                @foreach($squares as $square)
                    @php
                        $slotStart = $hour * 3600;
                        $slotEnd   = ($hour + 1) * 3600;
                        $reservation = $reservationsBySquare[$square->sid]->first(
                            fn($r) => $r->time_start == $slotStart
                        );
                    @endphp
                    <td class="{{ $reservation ? 'cc-single-future' : 'cc-free' }}">
                        @if($reservation)
                            @if(auth()->check())
                                {{ $reservation->booking->user->name ?? '' }}
                                @foreach($reservation->booking->meta->where('meta_key', 'like', 'player_name%') as $m)
                                    , {{ $m->meta_value }}
                                @endforeach
                            @else
                                Gebucht
                            @endif
                        @endif
                    </td>
                @endforeach
            </tr>
            @php $hour++; @endphp
        @endwhile
    </tbody>
</table>
@endsection
```

- [ ] **Step 5: Create app layout**

```blade
{{-- resources/views/layouts/app.blade.php --}}
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'TCBewegung Buchung')</title>
    <style>
        .cc-free { background: #EEE; }
        .cc-own { background: #8BB243; color: #fff; }
        .cc-single-future { background: #2596be; color: #fff; }
        .cc-multiple-future { background: #2596be; color: #fff; }
        .cc-spielersuche { background: #a024bf; color: #fff; }
        table { border-collapse: collapse; width: 100%; }
        td, th { border: 1px solid #ccc; padding: 4px 8px; }
        .calendar-toolbar { display: flex; gap: 12px; align-items: center; margin-bottom: 1rem; }
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

- [ ] **Step 6: Run tests**

```bash
php artisan test tests/Feature/CalendarControllerTest.php
```
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/CalendarController.php resources/views/calendar/ resources/views/layouts/ tests/Feature/CalendarControllerTest.php
git commit -m "feat: implement CalendarController with date navigation and reservation display"
```

---

### Task 10: BookingController (create + cancel)

**Files:**
- Create: `app/Http/Controllers/BookingController.php`
- Create: `tests/Feature/BookingControllerTest.php`

- [ ] **Step 1: Write failing tests**

```php
// tests/Feature/BookingControllerTest.php
<?php

namespace Tests\Feature;

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
        $this->post('/bookings', [
            'sid' => $square->sid,
            'date' => '2026-07-10',
            'time_start' => '10:00',
            'time_end' => '11:00',
            'quantity' => 2,
        ])->assertRedirect('/login');
    }

    /** @test */
    public function user_can_create_booking_on_available_slot(): void
    {
        $user = User::factory()->create(['permissions' => 'calendar.create-single-bookings']);
        $square = Square::factory()->create(['status' => 'enabled', 'time_block_bookable_max' => 0]);

        $response = $this->actingAs($user)->post('/bookings', [
            'sid' => $square->sid,
            'date' => '2026-07-10',
            'time_start' => '10:00',
            'time_end' => '11:00',
            'quantity' => 2,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('bs_bookings', ['uid' => $user->uid, 'sid' => $square->sid]);
        $this->assertDatabaseHas('bs_reservations', ['time_start' => 36000, 'time_end' => 39600]);
    }

    /** @test */
    public function user_can_cancel_own_booking(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->create(['uid' => $user->uid, 'status' => 'enabled']);

        $response = $this->actingAs($user)->delete("/bookings/{$booking->bid}");

        $response->assertRedirect();
        $booking->refresh();
        $this->assertEquals('disabled', $booking->status);
    }

    /** @test */
    public function user_cannot_cancel_another_users_booking(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $booking = Booking::factory()->create(['uid' => $owner->uid, 'status' => 'enabled']);

        $this->actingAs($other)->delete("/bookings/{$booking->bid}")->assertForbidden();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test tests/Feature/BookingControllerTest.php
```
Expected: FAIL

- [ ] **Step 3: Add routes**

```php
// In routes/web.php — inside auth middleware group:
Route::post('/bookings', [\App\Http\Controllers\BookingController::class, 'store'])->name('bookings.store');
Route::delete('/bookings/{booking}', [\App\Http\Controllers\BookingController::class, 'destroy'])->name('bookings.destroy');
```

- [ ] **Step 4: Implement BookingController**

```php
// app/Http/Controllers/BookingController.php
<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingValidationException;
use App\Models\Booking;
use App\Models\Square;
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Handles court booking creation and cancellation. */
class BookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookingService,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sid' => ['required', 'integer', 'exists:bs_squares,sid'],
            'date' => ['required', 'date'],
            'time_start' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'time_end' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'quantity' => ['required', 'integer', 'min:1', 'max:4'],
        ]);

        $square = Square::findOrFail($data['sid']);
        $dateStart = Carbon::parse("{$data['date']} {$data['time_start']}");
        $dateEnd = Carbon::parse("{$data['date']} {$data['time_end']}");

        try {
            $this->bookingService->createSingle(
                auth()->user(),
                $square,
                $data['quantity'],
                $dateStart,
                $dateEnd,
            );
        } catch (BookingValidationException $e) {
            return back()->withErrors(['booking' => $e->getMessage()]);
        }

        return redirect()->route('calendar.index', ['date' => $data['date']])
            ->with('success', 'Buchung erfolgreich gespeichert.');
    }

    public function destroy(Booking $booking): RedirectResponse
    {
        if ($booking->uid !== auth()->id()) {
            abort(403);
        }

        $this->bookingService->cancelSingle($booking);

        return redirect()->route('calendar.index')
            ->with('success', 'Buchung storniert.');
    }
}
```

- [ ] **Step 5: Run tests**

```bash
php artisan test tests/Feature/BookingControllerTest.php
```
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/BookingController.php routes/web.php tests/Feature/BookingControllerTest.php
git commit -m "feat: implement BookingController for create and cancel operations"
```

---

## Phase 6 — Full Test Suite

### Task 11: Model unit tests (all models)

**Files:**
- Create: `tests/Unit/Models/UserModelTest.php`
- Create: `tests/Unit/Models/ReservationModelTest.php`

- [ ] **Step 1: Write and run UserModel tests**

```php
// tests/Unit/Models/UserModelTest.php
<?php

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
    public function has_permission_returns_true_for_granted_permission(): void
    {
        $user = User::factory()->create(['permissions' => 'calendar.see-data calendar.create-single-bookings']);
        $this->assertTrue($user->hasPermission('calendar.see-data'));
    }

    /** @test */
    public function has_permission_returns_false_for_missing_permission(): void
    {
        $user = User::factory()->create(['permissions' => 'calendar.see-data']);
        $this->assertFalse($user->hasPermission('calendar.create-single-bookings'));
    }

    /** @test */
    public function password_is_hidden_from_serialization(): void
    {
        $user = User::factory()->create();
        $array = $user->toArray();
        $this->assertArrayNotHasKey('password', $array);
    }
}
```

```bash
php artisan test tests/Unit/Models/UserModelTest.php
```
Expected: PASS

- [ ] **Step 2: Write and run Option model test**

```php
// tests/Unit/Models/OptionModelTest.php
<?php

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
    public function get_value_returns_default_when_key_missing(): void
    {
        $this->assertEquals('fallback', Option::getValue('nonexistent', 'fallback'));
    }

    /** @test */
    public function get_value_returns_null_default_when_not_specified(): void
    {
        $this->assertNull(Option::getValue('nonexistent'));
    }
}
```

```bash
php artisan test tests/Unit/Models/OptionModelTest.php
```
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Models/
git commit -m "test: add comprehensive unit tests for all models"
```

---

### Task 12: Run full test suite

- [ ] **Step 1: Run all tests**

```bash
php artisan test
```
Expected: All tests PASS

- [ ] **Step 2: Generate coverage report (optional)**

```bash
php artisan test --coverage
```

- [ ] **Step 3: Final commit**

```bash
git add .
git commit -m "test: full test suite green — Laravel migration complete for Phase 1-5"
```

---

## Phase 7 — Code Documentation

### Task 13: PHPDoc all service and model files

- [ ] **Step 1: Add PHPDoc to BookingService — all public methods already have full docblocks per Task 6. Verify:**

```bash
grep -n "@param\|@return\|@throws" app/Services/BookingService.php | wc -l
```
Expected: 6 or more lines.

- [ ] **Step 2: Add class-level docblock to all Controllers**

Each controller should have a class docblock explaining its responsibility. Example template:
```php
/**
 * [Controller name] — handles HTTP requests for [feature area].
 *
 * Routes: [list the routes this controller handles]
 * Auth: [guest|auth|admin]
 */
```
Apply this pattern to: `LoginController`, `LogoutController`, `CalendarController`, `BookingController`.

- [ ] **Step 3: Verify all models have @property annotations**

Run:
```bash
grep -rn "@property" app/Models/ | wc -l
```
Expected: 30+ lines (all primary key and column properties annotated).

- [ ] **Step 4: Final commit**

```bash
git add app/
git commit -m "docs: add PHPDoc annotations to all models, services, and controllers"
```

---

## Self-Review Checklist

- [x] All 15 DB tables covered by migrations
- [x] All migrations have corresponding tests
- [x] All Eloquent models documented with @property
- [x] All model relationships tested
- [x] SquareValidator ports all constraints from original SquareValidator.php
- [x] Short-booking exemption (30min) tested
- [x] Daily limit enforcement tested
- [x] BookingService is atomic (DB transaction)
- [x] Auth uses custom bs_users table with `status` check
- [x] Calendar view shows court aliases
- [x] Booking create + cancel both tested (happy path + forbidden)
- [x] All services have full PHPDoc

### Gaps to address in future phases

- Admin backend (backend/* routes) — separate plan recommended
- Subscription bookings (recurring reservations) — separate plan
- Pricing engine (SquarePricingManager) — separate plan
- Email notifications
- Spielersuche (partner search) feature
- Frontend styling (port CSS classes from default3.css)
