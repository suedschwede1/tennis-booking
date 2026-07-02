# Mitgliederverwaltung Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a self-contained "Members" module (Mitgliederverwaltung: members, fiscal years, fee categories, dues, payments) on top of a new generic module system, so it has its own migrations, views, translations, and an isolated PHPUnit testsuite that runs independently of the booking system's tests.

**Architecture:** A new `App\Modules\<Name>` convention (own `ServiceProvider`, `Database/Migrations`, `resources/views`, `resources/lang`, `routes.php`), scaffolded by a new `php artisan make:module` command. The first module, `Members`, lives at `app/Modules/Members/` and is wired into the existing admin area purely via a new `admin.members` privilege (same `bs_users.status` + `bs_users_meta` `allow.*` model already used by `admin.user`, `admin.event`, etc. — no roles table, no new auth mechanism).

**Tech Stack:** Laravel 13.8, PHP 8.3, PHPUnit 12.5 (`#[Test]` attributes), SQLite in-memory for tests, no new Composer packages (CSV export via `response()->streamDownload()`).

**Spec:** `docs/superpowers/specs/2026-07-02-members-module-design.md`

---

## File Structure

```
app/Console/Commands/MakeModuleCommand.php          # php artisan make:module <Name> scaffolder

app/Modules/Members/
  MembersServiceProvider.php                        # loads migrations/routes/views/translations
  routes.php                                         # /admin/members/* routes, can:admin.members
  Database/Migrations/
    2026_07_03_090001_create_fiscal_years_table.php
    2026_07_03_090002_create_fee_categories_table.php
    2026_07_03_090003_create_fee_category_rates_table.php
    2026_07_03_090004_create_members_table.php
    2026_07_03_090005_create_member_dues_table.php
    2026_07_03_090006_create_payments_table.php
    2026_07_03_090007_create_payment_dues_table.php
  Models/
    FiscalYear.php
    FeeCategory.php
    FeeCategoryRate.php
    Member.php
    MemberDue.php
    Payment.php
  Http/Controllers/
    MemberController.php
    FiscalYearController.php
    FeeCategoryController.php
    MemberDueController.php
    PaymentController.php
    MemberExportController.php
    DashboardController.php
  resources/views/
    dashboard.blade.php
    members/index.blade.php
    members/create.blade.php
    members/edit.blade.php
    members/_form.blade.php
    fiscal-years/index.blade.php
    fiscal-years/create.blade.php
    fiscal-years/edit.blade.php
    fee-categories/index.blade.php
    dues/index.blade.php
    payments/create.blade.php
  resources/lang/de/admin.php
  resources/lang/en/admin.php

database/factories/Modules/Members/Models/MemberFactory.php

app/Models/User.php                                 # modify: add 'admin.members' to PRIVILEGES
bootstrap/providers.php                              # modify: register MembersServiceProvider
resources/views/components/layout/admin-sidebar.blade.php  # modify: add nav link
phpunit.xml                                          # modify: add "Members" testsuite

tests/Feature/Console/MakeModuleCommandTest.php
tests/Feature/Modules/Members/MemberManagementTest.php
tests/Feature/Modules/Members/FiscalYearManagementTest.php
tests/Feature/Modules/Members/FeeCategoryManagementTest.php
tests/Feature/Modules/Members/MemberDueGenerationTest.php
tests/Feature/Modules/Members/PaymentManagementTest.php
tests/Feature/Modules/Members/MemberExportTest.php
tests/Feature/Modules/Members/DashboardTest.php
tests/Unit/Modules/Members/MemberDueTest.php
tests/Unit/Modules/Members/MemberPaidUntilTest.php
```

---

### Task 1: `make:module` Artisan command

**Files:**
- Create: `app/Console/Commands/MakeModuleCommand.php`
- Test: `tests/Feature/Console/MakeModuleCommandTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MakeModuleCommandTest extends TestCase
{
    private string $modulePath;

    protected function tearDown(): void
    {
        File::deleteDirectory($this->modulePath);
        File::deleteDirectory(base_path('tests/Feature/Modules/Widgets'));
        File::deleteDirectory(base_path('tests/Unit/Modules/Widgets'));

        parent::tearDown();
    }

    #[Test]
    public function it_scaffolds_the_module_directory_structure(): void
    {
        $this->modulePath = base_path('app/Modules/Widgets');

        $this->artisan('make:module', ['name' => 'Widgets'])
            ->assertExitCode(0);

        $this->assertFileExists($this->modulePath.'/WidgetsServiceProvider.php');
        $this->assertFileExists($this->modulePath.'/routes.php');
        $this->assertDirectoryExists($this->modulePath.'/Http/Controllers');
        $this->assertDirectoryExists($this->modulePath.'/Models');
        $this->assertDirectoryExists($this->modulePath.'/Database/Migrations');
        $this->assertDirectoryExists($this->modulePath.'/resources/views');
        $this->assertDirectoryExists($this->modulePath.'/resources/lang');
        $this->assertDirectoryExists(base_path('tests/Feature/Modules/Widgets'));
        $this->assertDirectoryExists(base_path('tests/Unit/Modules/Widgets'));

        $provider = File::get($this->modulePath.'/WidgetsServiceProvider.php');
        $this->assertStringContainsString('namespace App\Modules\Widgets;', $provider);
        $this->assertStringContainsString('class WidgetsServiceProvider', $provider);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl php artisan test --filter=MakeModuleCommandTest`
Expected: FAIL — `make:module` command does not exist.

- [ ] **Step 3: Write the command**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakeModuleCommand extends Command
{
    protected $signature = 'make:module {name : StudlyCase module name, e.g. Members}';

    protected $description = 'Scaffold a new self-contained module under app/Modules/<Name>';

    public function handle(): int
    {
        $name = Str::studly($this->argument('name'));
        $lowerName = Str::lower($name);
        $modulePath = base_path("app/Modules/{$name}");

        if (File::isDirectory($modulePath)) {
            $this->error("Module [{$name}] already exists at {$modulePath}.");

            return self::FAILURE;
        }

        foreach ([
            "{$modulePath}/Http/Controllers",
            "{$modulePath}/Models",
            "{$modulePath}/Requests",
            "{$modulePath}/Database/Migrations",
            "{$modulePath}/resources/views",
            "{$modulePath}/resources/lang",
            base_path("tests/Feature/Modules/{$name}"),
            base_path("tests/Unit/Modules/{$name}"),
        ] as $directory) {
            File::ensureDirectoryExists($directory);
        }

        File::put("{$modulePath}/{$name}ServiceProvider.php", $this->providerStub($name, $lowerName));
        File::put("{$modulePath}/routes.php", $this->routesStub($name, $lowerName));

        $this->info("Module [{$name}] scaffolded at app/Modules/{$name}.");
        $this->line('Next steps:');
        $this->line("  1. Register App\\Modules\\{$name}\\{$name}ServiceProvider::class in bootstrap/providers.php");
        $this->line("  2. Add a \"{$name}\" <testsuite> entry to phpunit.xml pointing at tests/Feature/Modules/{$name} and tests/Unit/Modules/{$name}");

        return self::SUCCESS;
    }

    private function providerStub(string $name, string $lowerName): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\Modules\\{$name};

        use Illuminate\Support\ServiceProvider;

        class {$name}ServiceProvider extends ServiceProvider
        {
            public function boot(): void
            {
                \$this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
                \$this->loadRoutesFrom(__DIR__.'/routes.php');
                \$this->loadViewsFrom(__DIR__.'/resources/views', '{$lowerName}');
                \$this->loadTranslationsFrom(__DIR__.'/resources/lang', '{$lowerName}');
            }
        }

        PHP;
    }

    private function routesStub(string $name, string $lowerName): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        use Illuminate\Support\Facades\Route;

        Route::middleware(['auth', 'can:admin.{$lowerName}'])
            ->prefix('admin')
            ->name('admin.')
            ->group(function (): void {
                // Route::resource('{$lowerName}', \App\Modules\\{$name}\Http\Controllers\ExampleController::class);
            });

        PHP;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `wsl php artisan test --filter=MakeModuleCommandTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/MakeModuleCommand.php tests/Feature/Console/MakeModuleCommandTest.php
git commit -m "Add make:module scaffolding command"
```

---

### Task 2: Scaffold the Members module

**Files:**
- Create (via command): `app/Modules/Members/MembersServiceProvider.php`, `app/Modules/Members/routes.php`, empty `Http/Controllers`, `Models`, `Database/Migrations`, `resources/views`, `resources/lang` directories
- Modify: `bootstrap/providers.php`

- [ ] **Step 1: Run the scaffolding command**

Run: `wsl php artisan make:module Members`
Expected: `Module [Members] scaffolded at app/Modules/Members.` printed, and `app/Modules/Members/` exists with the structure from Task 1.

- [ ] **Step 2: Register the module's service provider**

Edit `bootstrap/providers.php`:

```php
<?php

use App\Modules\Members\MembersServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    MembersServiceProvider::class,
];
```

- [ ] **Step 3: Verify the app boots with the new provider**

Run: `wsl php artisan route:list --name=admin`
Expected: command exits successfully (no error resolving `MembersServiceProvider`); the placeholder `routes.php` currently defines no routes, so no new routes are listed yet.

- [ ] **Step 4: Commit**

```bash
git add app/Modules/Members bootstrap/providers.php
git commit -m "Scaffold Members module and register its service provider"
```

---

### Task 3: Add the `admin.members` privilege

**Files:**
- Modify: `app/Models/User.php:34-39`
- Test: `tests/Feature/Modules/Members/MemberManagementTest.php` (created here, extended in later tasks)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Members;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['status' => 'admin']);
    }

    #[Test]
    public function admin_members_is_a_registered_privilege(): void
    {
        $this->assertContains('admin.members', User::PRIVILEGES);
    }

    #[Test]
    public function admin_holds_the_members_privilege(): void
    {
        $this->assertTrue($this->admin()->can('admin.members'));
    }

    #[Test]
    public function a_regular_enabled_user_does_not_hold_the_members_privilege(): void
    {
        $user = User::factory()->create(['status' => 'enabled']);

        $this->assertFalse($user->can('admin.members'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl php artisan test --filter=MemberManagementTest`
Expected: FAIL on `admin_members_is_a_registered_privilege` — `'admin.members'` is not yet in `User::PRIVILEGES`. (The other two assertions already pass since `status === 'admin'` short-circuits `can()` to `true`, and `status === 'enabled'` always returns `false`; they're included here as regression coverage, not as the driver for this change.)

- [ ] **Step 3: Add the privilege constant**

Edit `app/Models/User.php:34-39`:

```php
    public const PRIVILEGES = [
        'admin.user', 'admin.booking', 'admin.event', 'admin.config', 'admin.see-menu', 'admin.members',
        'calendar.see-past', 'calendar.see-data',
        'calendar.create-single-bookings', 'calendar.cancel-single-bookings', 'calendar.delete-single-bookings',
        'calendar.create-subscription-bookings', 'calendar.cancel-subscription-bookings', 'calendar.delete-subscription-bookings',
    ];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `wsl php artisan test --filter=MemberManagementTest`
Expected: PASS (both tests)

- [ ] **Step 5: Commit**

```bash
git add app/Models/User.php tests/Feature/Modules/Members/MemberManagementTest.php
git commit -m "Add admin.members privilege"
```

---

### Task 4: `fiscal_years` table and model

**Files:**
- Create: `app/Modules/Members/Database/Migrations/2026_07_03_090001_create_fiscal_years_table.php`
- Create: `app/Modules/Members/Models/FiscalYear.php`
- Test: `tests/Unit/Modules/Members/MemberDueTest.php` (started here for the model cast assertions, extended in Task 8)

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
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_years');
    }
};
```

- [ ] **Step 2: Write the model**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Members\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalYear extends Model
{
    protected $fillable = ['name', 'starts_on', 'ends_on'];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];

    public function feeCategoryRates(): HasMany
    {
        return $this->hasMany(FeeCategoryRate::class);
    }

    public function memberDues(): HasMany
    {
        return $this->hasMany(MemberDue::class);
    }
}
```

- [ ] **Step 3: Write a failing unit test for the date casts**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members;

use App\Modules\Members\Models\FiscalYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberDueTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function fiscal_year_dates_are_cast_to_carbon_instances(): void
    {
        $fiscalYear = FiscalYear::create([
            'name' => '2025/26',
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-08-31',
        ]);

        $this->assertInstanceOf(\Carbon\Carbon::class, $fiscalYear->starts_on);
        $this->assertSame('2026-08-31', $fiscalYear->ends_on->format('Y-m-d'));
    }
}
```

- [ ] **Step 4: Run test to verify it fails, then run migrations and re-run**

Run: `wsl php artisan test --filter=MemberDueTest`
Expected first run: FAIL — table `fiscal_years` does not exist yet (the migration file exists but `RefreshDatabase` needs it discovered via `MembersServiceProvider::loadMigrationsFrom`, which is already registered from Task 2, so this should actually pass once the migration and model above are saved).
Expected after saving both files above: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Members/Database/Migrations/2026_07_03_090001_create_fiscal_years_table.php app/Modules/Members/Models/FiscalYear.php tests/Unit/Modules/Members/MemberDueTest.php
git commit -m "Add fiscal_years table and FiscalYear model"
```

---

### Task 5: `fee_categories` table and model

**Files:**
- Create: `app/Modules/Members/Database/Migrations/2026_07_03_090002_create_fee_categories_table.php`
- Create: `app/Modules/Members/Models/FeeCategory.php`

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
        Schema::create('fee_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_categories');
    }
};
```

- [ ] **Step 2: Write the model**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Members\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeCategory extends Model
{
    protected $fillable = ['name'];

    public function rates(): HasMany
    {
        return $this->hasMany(FeeCategoryRate::class);
    }
}
```

- [ ] **Step 3: No standalone test yet**

`FeeCategory` has no behavior beyond a plain Eloquent model; it's exercised through the `FeeCategoryManagementTest` feature test in Task 14. Skipping a unit test here avoids testing framework plumbing (YAGNI).

- [ ] **Step 4: Commit**

```bash
git add app/Modules/Members/Database/Migrations/2026_07_03_090002_create_fee_categories_table.php app/Modules/Members/Models/FeeCategory.php
git commit -m "Add fee_categories table and FeeCategory model"
```

---

### Task 6: `fee_category_rates` table and model

**Files:**
- Create: `app/Modules/Members/Database/Migrations/2026_07_03_090003_create_fee_category_rates_table.php`
- Create: `app/Modules/Members/Models/FeeCategoryRate.php`
- Test: `tests/Unit/Modules/Members/MemberDueTest.php` (extend)

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
        Schema::create('fee_category_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 8, 2);
            $table->timestamps();
            $table->unique(['fee_category_id', 'fiscal_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_category_rates');
    }
};
```

- [ ] **Step 2: Write the model**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Members\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeCategoryRate extends Model
{
    protected $fillable = ['fee_category_id', 'fiscal_year_id', 'amount'];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function feeCategory(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }
}
```

- [ ] **Step 3: Add a failing test for the uniqueness constraint**

Append to `tests/Unit/Modules/Members/MemberDueTest.php`:

```php
    #[Test]
    public function a_fee_category_can_only_have_one_rate_per_fiscal_year(): void
    {
        $category = \App\Modules\Members\Models\FeeCategory::create(['name' => 'Vollmitglied']);
        $fiscalYear = FiscalYear::create(['name' => '2025/26', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31']);

        \App\Modules\Members\Models\FeeCategoryRate::create([
            'fee_category_id' => $category->id,
            'fiscal_year_id' => $fiscalYear->id,
            'amount' => 120,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        \App\Modules\Members\Models\FeeCategoryRate::create([
            'fee_category_id' => $category->id,
            'fiscal_year_id' => $fiscalYear->id,
            'amount' => 130,
        ]);
    }
```

- [ ] **Step 4: Run test to verify it fails, then passes**

Run: `wsl php artisan test --filter=MemberDueTest`
Expected before the migration/model exist: FAIL (class/table not found). After Steps 1-2 are saved: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Members/Database/Migrations/2026_07_03_090003_create_fee_category_rates_table.php app/Modules/Members/Models/FeeCategoryRate.php tests/Unit/Modules/Members/MemberDueTest.php
git commit -m "Add fee_category_rates table and model with per-year uniqueness"
```

---

### Task 7: `members` table, model, and factory

**Files:**
- Create: `app/Modules/Members/Database/Migrations/2026_07_03_090004_create_members_table.php`
- Create: `app/Modules/Members/Models/Member.php`
- Create: `database/factories/Modules/Members/Models/MemberFactory.php`

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
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->string('firstname', 128);
            $table->string('lastname', 128);
            $table->date('birthdate')->nullable();
            $table->string('email', 128)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('address', 255)->nullable();
            $table->date('joined_at');
            $table->date('left_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
```

- [ ] **Step 2: Write the model**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Members\Models;

use Database\Factories\Modules\Members\Models\MemberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Member extends Model
{
    use HasFactory;

    protected $fillable = [
        'firstname', 'lastname', 'birthdate', 'email', 'phone', 'address', 'joined_at', 'left_at',
    ];

    protected $casts = [
        'birthdate' => 'date',
        'joined_at' => 'date',
        'left_at' => 'date',
    ];

    protected static function newFactory(): MemberFactory
    {
        return MemberFactory::new();
    }

    public function dues(): HasMany
    {
        return $this->hasMany(MemberDue::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('left_at');
    }

    public function fullName(): string
    {
        return trim("{$this->firstname} {$this->lastname}");
    }
}
```

- [ ] **Step 3: Write the factory**

```php
<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Members\Models;

use App\Modules\Members\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

class MemberFactory extends Factory
{
    protected $model = Member::class;

    public function definition(): array
    {
        return [
            'firstname' => $this->faker->firstName(),
            'lastname' => $this->faker->lastName(),
            'birthdate' => $this->faker->optional()->date(),
            'email' => $this->faker->optional()->safeEmail(),
            'phone' => $this->faker->optional()->phoneNumber(),
            'address' => $this->faker->optional()->address(),
            'joined_at' => $this->faker->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            'left_at' => null,
        ];
    }
}
```

- [ ] **Step 4: Write a failing test for the factory and scope**

Create `tests/Unit/Modules/Members/MemberActiveScopeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members;

use App\Modules\Members\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberActiveScopeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function active_scope_excludes_members_with_a_left_at_date(): void
    {
        Member::factory()->create(['firstname' => 'Aktiv', 'left_at' => null]);
        Member::factory()->create(['firstname' => 'Ausgetreten', 'left_at' => '2025-01-01']);

        $active = Member::query()->active()->pluck('firstname');

        $this->assertSame(['Aktiv'], $active->all());
    }
}
```

- [ ] **Step 5: Run test to verify it fails, then passes**

Run: `wsl php artisan test --filter=MemberActiveScopeTest`
Expected before Steps 1-3: FAIL (table/class/factory not found). After: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Members/Database/Migrations/2026_07_03_090004_create_members_table.php app/Modules/Members/Models/Member.php database/factories/Modules/Members/Models/MemberFactory.php tests/Unit/Modules/Members/MemberActiveScopeTest.php
git commit -m "Add members table, model, factory, and active scope"
```

---

### Task 8: `member_dues` table, model, and paid status

**Files:**
- Create: `app/Modules/Members/Database/Migrations/2026_07_03_090005_create_member_dues_table.php`
- Create: `app/Modules/Members/Models/MemberDue.php`
- Modify: `tests/Unit/Modules/Members/MemberDueTest.php` (extend)

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
        Schema::create('member_dues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_category_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 8, 2);
            $table->timestamps();
            $table->unique(['member_id', 'fiscal_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_dues');
    }
};
```

- [ ] **Step 2: Write the model (payments relation added in Task 9)**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Members\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MemberDue extends Model
{
    protected $fillable = ['member_id', 'fiscal_year_id', 'fee_category_id', 'amount'];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function feeCategory(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class);
    }

    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(Payment::class, 'payment_dues');
    }

    public function isPaid(): bool
    {
        return $this->payments()->exists();
    }
}
```

- [ ] **Step 3: Write a failing test for `isPaid()`**

Append to `tests/Unit/Modules/Members/MemberDueTest.php`:

```php
    #[Test]
    public function a_due_with_no_linked_payment_is_not_paid(): void
    {
        $member = \App\Modules\Members\Models\Member::factory()->create();
        $category = \App\Modules\Members\Models\FeeCategory::create(['name' => 'Vollmitglied']);
        $fiscalYear = FiscalYear::create(['name' => '2025/26', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31']);

        $due = \App\Modules\Members\Models\MemberDue::create([
            'member_id' => $member->id,
            'fiscal_year_id' => $fiscalYear->id,
            'fee_category_id' => $category->id,
            'amount' => 120,
        ]);

        $this->assertFalse($due->isPaid());
    }
```

(The positive "is paid once a payment is linked" case is added in Task 9 once `Payment` exists.)

- [ ] **Step 4: Run test to verify it fails, then passes**

Run: `wsl php artisan test --filter=MemberDueTest`
Expected before Steps 1-2: FAIL (table `member_dues` missing). After: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Members/Database/Migrations/2026_07_03_090005_create_member_dues_table.php app/Modules/Members/Models/MemberDue.php tests/Unit/Modules/Members/MemberDueTest.php
git commit -m "Add member_dues table, model, and isPaid() check"
```

---

### Task 9: `payments` / `payment_dues` tables, model, and "paid until"

**Files:**
- Create: `app/Modules/Members/Database/Migrations/2026_07_03_090006_create_payments_table.php`
- Create: `app/Modules/Members/Database/Migrations/2026_07_03_090007_create_payment_dues_table.php`
- Create: `app/Modules/Members/Models/Payment.php`
- Modify: `app/Modules/Members/Models/Member.php` (add `paidUntil()`)
- Test: `tests/Unit/Modules/Members/MemberDueTest.php` (extend), `tests/Unit/Modules/Members/MemberPaidUntilTest.php` (new)

- [ ] **Step 1: Write the migrations**

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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 8, 2);
            $table->date('paid_at');
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
```

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
        Schema::create('payment_dues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_due_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['payment_id', 'member_due_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_dues');
    }
};
```

Name the two files `2026_07_03_090006_create_payments_table.php` and `2026_07_03_090007_create_payment_dues_table.php` so `payments` runs before `payment_dues` (foreign key order).

- [ ] **Step 2: Write the `Payment` model**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Members\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Payment extends Model
{
    protected $fillable = ['member_id', 'amount', 'paid_at', 'note'];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'date',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function dues(): BelongsToMany
    {
        return $this->belongsToMany(MemberDue::class, 'payment_dues');
    }
}
```

- [ ] **Step 3: Add `paidUntil()` to `Member`**

Edit `app/Modules/Members/Models/Member.php`, add to the class body:

```php
    public function paidUntil(): ?\Carbon\Carbon
    {
        return $this->dues()
            ->get()
            ->filter(fn (MemberDue $due) => $due->isPaid())
            ->map(fn (MemberDue $due) => $due->fiscalYear->ends_on)
            ->sort()
            ->last();
    }
```

- [ ] **Step 4: Write failing tests**

Append to `tests/Unit/Modules/Members/MemberDueTest.php`:

```php
    #[Test]
    public function a_due_becomes_paid_once_a_payment_covers_it(): void
    {
        $member = \App\Modules\Members\Models\Member::factory()->create();
        $category = \App\Modules\Members\Models\FeeCategory::create(['name' => 'Vollmitglied']);
        $fiscalYear = FiscalYear::create(['name' => '2025/26', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31']);

        $due = \App\Modules\Members\Models\MemberDue::create([
            'member_id' => $member->id,
            'fiscal_year_id' => $fiscalYear->id,
            'fee_category_id' => $category->id,
            'amount' => 120,
        ]);

        $payment = \App\Modules\Members\Models\Payment::create([
            'member_id' => $member->id,
            'amount' => 120,
            'paid_at' => '2025-09-15',
        ]);
        $payment->dues()->attach($due->id);

        $this->assertTrue($due->fresh()->isPaid());
    }
```

Create `tests/Unit/Modules/Members/MemberPaidUntilTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members;

use App\Modules\Members\Models\FeeCategory;
use App\Modules\Members\Models\FiscalYear;
use App\Modules\Members\Models\Member;
use App\Modules\Members\Models\MemberDue;
use App\Modules\Members\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberPaidUntilTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function paid_until_is_the_end_of_the_latest_paid_fiscal_year(): void
    {
        $member = Member::factory()->create();
        $category = FeeCategory::create(['name' => 'Vollmitglied']);

        $fy2025 = FiscalYear::create(['name' => '2025/26', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31']);
        $fy2026 = FiscalYear::create(['name' => '2026/27', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31']);

        $due2025 = MemberDue::create(['member_id' => $member->id, 'fiscal_year_id' => $fy2025->id, 'fee_category_id' => $category->id, 'amount' => 120]);
        MemberDue::create(['member_id' => $member->id, 'fiscal_year_id' => $fy2026->id, 'fee_category_id' => $category->id, 'amount' => 120]);

        $payment = Payment::create(['member_id' => $member->id, 'amount' => 120, 'paid_at' => '2025-09-15']);
        $payment->dues()->attach($due2025->id);

        $this->assertSame('2026-08-31', $member->paidUntil()->format('Y-m-d'));
    }

    #[Test]
    public function paid_until_is_null_when_nothing_is_paid(): void
    {
        $member = Member::factory()->create();

        $this->assertNull($member->paidUntil());
    }
}
```

- [ ] **Step 5: Run tests to verify they fail, then pass**

Run: `wsl php artisan test --filter=MemberDueTest`
Run: `wsl php artisan test --filter=MemberPaidUntilTest`
Expected before Steps 1-3: FAIL. After: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Members/Database/Migrations/2026_07_03_090006_create_payments_table.php app/Modules/Members/Database/Migrations/2026_07_03_090007_create_payment_dues_table.php app/Modules/Members/Models/Payment.php app/Modules/Members/Models/Member.php tests/Unit/Modules/Members/MemberDueTest.php tests/Unit/Modules/Members/MemberPaidUntilTest.php
git commit -m "Add payments/payment_dues tables and Member::paidUntil()"
```

---

### Task 10: Isolated `Members` PHPUnit testsuite

**Files:**
- Modify: `phpunit.xml`

- [ ] **Step 1: Add the testsuite entry**

Edit `phpunit.xml`:

```xml
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
        <testsuite name="Members">
            <directory>tests/Feature/Modules/Members</directory>
            <directory>tests/Unit/Modules/Members</directory>
        </testsuite>
    </testsuites>
```

- [ ] **Step 2: Verify the isolated suite runs**

Run: `wsl php artisan test --testsuite=Members`
Expected: PASS, running only the Members unit tests written so far (feature tests are added starting Task 12) — significantly fewer tests than the full suite.

- [ ] **Step 3: Commit**

```bash
git add phpunit.xml
git commit -m "Add isolated Members PHPUnit testsuite"
```

---

### Task 11: Module routes skeleton and sidebar nav link

**Files:**
- Modify: `app/Modules/Members/routes.php`
- Modify: `resources/views/components/layout/admin-sidebar.blade.php`
- Modify: `lang/de/booking/admin.php`, `lang/en/booking/admin.php`

- [ ] **Step 1: Replace the routes skeleton**

Edit `app/Modules/Members/routes.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Members\Http\Controllers\DashboardController;
use App\Modules\Members\Http\Controllers\FeeCategoryController;
use App\Modules\Members\Http\Controllers\FiscalYearController;
use App\Modules\Members\Http\Controllers\MemberController;
use App\Modules\Members\Http\Controllers\MemberDueController;
use App\Modules\Members\Http\Controllers\MemberExportController;
use App\Modules\Members\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:admin.members'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('members/dashboard', [DashboardController::class, 'index'])->name('members.dashboard');
        Route::get('members/export', [MemberExportController::class, 'export'])->name('members.export');

        Route::get('members/fiscal-years', [FiscalYearController::class, 'index'])->name('members.fiscal-years.index');
        Route::get('members/fiscal-years/create', [FiscalYearController::class, 'create'])->name('members.fiscal-years.create');
        Route::post('members/fiscal-years', [FiscalYearController::class, 'store'])->name('members.fiscal-years.store');
        Route::get('members/fiscal-years/{fiscalYear}/edit', [FiscalYearController::class, 'edit'])->name('members.fiscal-years.edit');
        Route::put('members/fiscal-years/{fiscalYear}', [FiscalYearController::class, 'update'])->name('members.fiscal-years.update');

        Route::get('members/fee-categories', [FeeCategoryController::class, 'index'])->name('members.fee-categories.index');
        Route::post('members/fee-categories', [FeeCategoryController::class, 'store'])->name('members.fee-categories.store');
        Route::post('members/fee-categories/rates', [FeeCategoryController::class, 'storeRates'])->name('members.fee-categories.rates.store');

        Route::get('members/dues', [MemberDueController::class, 'index'])->name('members.dues.index');
        Route::post('members/dues/generate', [MemberDueController::class, 'generate'])->name('members.dues.generate');

        Route::get('members/{member}/payments/create', [PaymentController::class, 'create'])->name('members.payments.create');
        Route::post('members/{member}/payments', [PaymentController::class, 'store'])->name('members.payments.store');

        Route::resource('members', MemberController::class)->except(['show']);
    });
```

- [ ] **Step 2: Add the sidebar nav link**

Edit `resources/views/components/layout/admin-sidebar.blade.php`, insert after the `admin.user` block (after line 19, before the `admin.booking` block):

```blade
        @can('admin.members')
            <a href="{{ route('admin.members.dashboard') }}" onmouseover="if (!this.dataset.active) { this.style.background='#26292e'; this.style.color='#ffffff'; }" onmouseout="if (!this.dataset.active) { this.style.background='transparent'; this.style.color='#b2b6bd'; }" data-active="{{ request()->routeIs('admin.members.*') ? '1' : '' }}" style="display:block; padding:12px 14px 12px {{ request()->routeIs('admin.members.*') ? '11px' : '17px' }}; font-family:var(--font-body); font-size:14px; text-decoration:none; transition:background 0.15s ease, color 0.15s ease; color:{{ request()->routeIs('admin.members.*') ? '#ffffff' : '#b2b6bd' }}; font-weight:{{ request()->routeIs('admin.members.*') ? '700' : '400' }}; background:{{ request()->routeIs('admin.members.*') ? '#34363b' : 'transparent' }}; border-left:{{ request()->routeIs('admin.members.*') ? '3px solid #bf4316' : '3px solid transparent' }};">
                {{ __('booking.admin.nav_members') }}
            </a>
        @endcan

```

- [ ] **Step 3: Add the translation key**

Edit `lang/de/booking/admin.php`, add alongside the other `nav_*` keys (near line 12-15):

```php
        'nav_members' => 'Mitglieder',
```

Edit `lang/en/booking/admin.php`, add the equivalent English entry in the same array position (check the existing `nav_users` value there for the exact key structure and mirror it):

```php
        'nav_members' => 'Members',
```

- [ ] **Step 4: Manually verify**

Run: `wsl php artisan route:list --name=admin.members`
Expected: all routes from Step 1 listed, each with `auth` and `can:admin.members` middleware.

Since controllers referenced in `routes.php` don't exist yet, also run:

Run: `wsl php artisan route:list`
Expected: command still exits successfully — Laravel resolves route closures/controller strings lazily, so listing routes doesn't fail even before the controllers exist. (If this errors, stub empty controller classes now and flesh them out in the following tasks.)

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Members/routes.php resources/views/components/layout/admin-sidebar.blade.php lang/de/booking/admin.php lang/en/booking/admin.php
git commit -m "Wire up Members module routes and sidebar nav link"
```

---

### Task 12: `MemberController` — list, create, edit, "austragen"

**Files:**
- Create: `app/Modules/Members/Http/Controllers/MemberController.php`
- Create: `app/Modules/Members/resources/views/members/index.blade.php`
- Create: `app/Modules/Members/resources/views/members/create.blade.php`
- Create: `app/Modules/Members/resources/views/members/edit.blade.php`
- Create: `app/Modules/Members/resources/views/members/_form.blade.php`
- Create: `app/Modules/Members/resources/lang/de/admin.php`
- Create: `app/Modules/Members/resources/lang/en/admin.php`
- Test: `tests/Feature/Modules/Members/MemberManagementTest.php` (extend)

- [ ] **Step 1: Write the failing feature tests**

Append to `tests/Feature/Modules/Members/MemberManagementTest.php`:

```php
    #[Test]
    public function a_non_admin_cannot_access_the_members_area(): void
    {
        $user = User::factory()->create(['status' => 'enabled']);

        $this->actingAs($user)->get('/admin/members')->assertForbidden();
    }

    #[Test]
    public function index_lists_active_members_and_hides_departed_ones(): void
    {
        \App\Modules\Members\Models\Member::factory()->create(['firstname' => 'Aktiv', 'lastname' => 'Mitglied']);
        \App\Modules\Members\Models\Member::factory()->create(['firstname' => 'Ausgetreten', 'lastname' => 'Mitglied', 'left_at' => '2025-01-01']);

        $this->actingAs($this->admin())->get('/admin/members')
            ->assertOk()->assertSee('Aktiv Mitglied')->assertDontSee('Ausgetreten Mitglied');
    }

    #[Test]
    public function admin_can_create_a_member(): void
    {
        $this->actingAs($this->admin())->post('/admin/members', [
            'firstname' => 'Neu',
            'lastname' => 'Mitglied',
            'email' => 'neu@example.com',
            'joined_at' => '2026-01-01',
        ])->assertRedirect(route('admin.members.index'));

        $this->assertDatabaseHas('members', ['firstname' => 'Neu', 'lastname' => 'Mitglied']);
    }

    #[Test]
    public function admin_can_mark_a_member_as_departed(): void
    {
        $member = \App\Modules\Members\Models\Member::factory()->create();

        $this->actingAs($this->admin())->delete("/admin/members/{$member->id}")
            ->assertRedirect(route('admin.members.index'));

        $this->assertNotNull($member->fresh()->left_at);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `wsl php artisan test --filter=MemberManagementTest`
Expected: FAIL — `MemberController` doesn't exist / routes 404.

- [ ] **Step 3: Write the controller**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Members\Http\Controllers;

use App\Modules\Members\Models\Member;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MemberController
{
    public function index(Request $request): View
    {
        $query = Member::query();

        if ($request->filled('status')) {
            $request->string('status')->value() === 'active'
                ? $query->active()
                : $query->whereNotNull('left_at');
        }

        $members = $query->orderBy('lastname')->orderBy('firstname')->get();

        return view('members::members.index', [
            'members' => $members,
            'filters' => $request->only('status'),
        ]);
    }

    public function create(): View
    {
        return view('members::members.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'firstname' => ['required', 'string', 'max:128'],
            'lastname' => ['required', 'string', 'max:128'],
            'birthdate' => ['nullable', 'date'],
            'email' => ['nullable', 'email', 'max:128'],
            'phone' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:255'],
            'joined_at' => ['required', 'date'],
        ]);

        Member::create($validated);

        return redirect()->route('admin.members.index');
    }

    public function edit(Member $member): View
    {
        return view('members::members.edit', ['member' => $member]);
    }

    public function update(Request $request, Member $member): RedirectResponse
    {
        $validated = $request->validate([
            'firstname' => ['required', 'string', 'max:128'],
            'lastname' => ['required', 'string', 'max:128'],
            'birthdate' => ['nullable', 'date'],
            'email' => ['nullable', 'email', 'max:128'],
            'phone' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:255'],
            'joined_at' => ['required', 'date'],
        ]);

        $member->update($validated);

        return redirect()->route('admin.members.index');
    }

    public function destroy(Member $member): RedirectResponse
    {
        $member->update(['left_at' => $member->left_at ?? now()->toDateString()]);

        return redirect()->route('admin.members.index');
    }
}
```

- [ ] **Step 4: Write the views**

`app/Modules/Members/resources/views/members/_form.blade.php`:

```blade
@csrf
<div class="ui-card">
    <div class="ui-card-header"><h2>{{ __('members::admin.members.section') }}</h2></div>
    <div class="ui-card-body ui-stack">
        <div class="ui-grid-3 ui-form-panel">
            <div class="ui-field">
                <label class="ui-label" for="mf-firstname">{{ __('members::admin.members.firstname') }}</label>
                <input id="mf-firstname" type="text" name="firstname" value="{{ old('firstname', $member->firstname ?? '') }}" class="ui-input">
            </div>
            <div class="ui-field">
                <label class="ui-label" for="mf-lastname">{{ __('members::admin.members.lastname') }}</label>
                <input id="mf-lastname" type="text" name="lastname" value="{{ old('lastname', $member->lastname ?? '') }}" class="ui-input">
            </div>
            <div class="ui-field">
                <label class="ui-label" for="mf-birthdate">{{ __('members::admin.members.birthdate') }}</label>
                <input id="mf-birthdate" type="date" name="birthdate" value="{{ old('birthdate', optional($member->birthdate ?? null)->format('Y-m-d')) }}" class="ui-input">
            </div>
            <div class="ui-field">
                <label class="ui-label" for="mf-email">{{ __('members::admin.members.email') }}</label>
                <input id="mf-email" type="email" name="email" value="{{ old('email', $member->email ?? '') }}" class="ui-input">
            </div>
            <div class="ui-field">
                <label class="ui-label" for="mf-phone">{{ __('members::admin.members.phone') }}</label>
                <input id="mf-phone" type="text" name="phone" value="{{ old('phone', $member->phone ?? '') }}" class="ui-input">
            </div>
            <div class="ui-field">
                <label class="ui-label" for="mf-address">{{ __('members::admin.members.address') }}</label>
                <input id="mf-address" type="text" name="address" value="{{ old('address', $member->address ?? '') }}" class="ui-input">
            </div>
            <div class="ui-field">
                <label class="ui-label" for="mf-joined-at">{{ __('members::admin.members.joined_at') }}</label>
                <input id="mf-joined-at" type="date" name="joined_at" value="{{ old('joined_at', optional($member->joined_at ?? null)->format('Y-m-d') ?? now()->toDateString()) }}" class="ui-input">
            </div>
        </div>
    </div>
</div>
```

`app/Modules/Members/resources/views/members/create.blade.php`:

```blade
@extends('layouts.admin')
@section('admin-title', __('members::admin.members.title'))
@section('admin-content')
<div class="ui-page">
    <div class="ui-page-header">
        <h1>{{ __('members::admin.members.new_member') }}</h1>
    </div>
    <form method="POST" action="{{ route('admin.members.store') }}" class="ui-form-shell">
        @include('members::members._form')
        <div class="ui-form-actions">
            <a href="{{ route('admin.members.index') }}" class="ui-btn ui-btn-ghost">{{ __('members::admin.common.cancel') }}</a>
            <button type="submit" class="ui-btn ui-btn-primary">{{ __('members::admin.common.save') }}</button>
        </div>
    </form>
</div>
@endsection
```

`app/Modules/Members/resources/views/members/edit.blade.php`:

```blade
@extends('layouts.admin')
@section('admin-title', __('members::admin.members.title'))
@section('admin-content')
<div class="ui-page">
    <div class="ui-page-header">
        <h1>{{ $member->fullName() }}</h1>
    </div>
    <form method="POST" action="{{ route('admin.members.update', $member) }}" class="ui-form-shell">
        @method('PUT')
        @include('members::members._form', ['member' => $member])
        <div class="ui-form-actions">
            <a href="{{ route('admin.members.payments.create', $member) }}" class="ui-btn ui-btn-ghost">{{ __('members::admin.payments.new_payment') }}</a>
            <a href="{{ route('admin.members.index') }}" class="ui-btn ui-btn-ghost">{{ __('members::admin.common.cancel') }}</a>
            <button type="submit" class="ui-btn ui-btn-primary">{{ __('members::admin.common.save') }}</button>
        </div>
    </form>
</div>
@endsection
```

`app/Modules/Members/resources/views/members/index.blade.php`:

```blade
@extends('layouts.admin')
@section('admin-title', __('members::admin.members.title'))
@section('admin-content')
<div class="ui-page">
    <div class="ui-page-header flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1>{{ __('members::admin.members.title') }}</h1>
        </div>
        <a href="{{ route('admin.members.create') }}" class="ui-btn ui-btn-primary">{{ __('members::admin.members.new_member') }}</a>
    </div>

    <div class="ui-card">
        <div class="ui-card-body ui-stack">
            <form method="GET" action="{{ route('admin.members.index') }}" class="ui-row">
                <div class="ui-field min-w-[12rem]">
                    <label class="ui-label">{{ __('members::admin.members.status') }}</label>
                    <select name="status" class="ui-select">
                        <option value="">{{ __('members::admin.common.all') }}</option>
                        <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ __('members::admin.members.status_active') }}</option>
                        <option value="departed" @selected(($filters['status'] ?? '') === 'departed')>{{ __('members::admin.members.status_departed') }}</option>
                    </select>
                </div>
                <button type="submit" class="ui-btn ui-btn-primary">{{ __('members::admin.common.filter') }}</button>
            </form>
        </div>
    </div>

    <div class="ui-card">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ __('members::admin.members.name') }}</th>
                        <th>{{ __('members::admin.members.email') }}</th>
                        <th>{{ __('members::admin.members.joined_at') }}</th>
                        <th class="text-right">{{ __('members::admin.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($members as $member)
                        <tr>
                            <td class="font-medium">{{ $member->fullName() }}</td>
                            <td class="text-[#6a6e73]">{{ $member->email ?: '—' }}</td>
                            <td class="text-[#6a6e73]">{{ $member->joined_at->format('d.m.Y') }}</td>
                            <td>
                                <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                                    <a href="{{ route('admin.members.edit', $member) }}" class="ui-btn ui-btn-ghost">{{ __('members::admin.common.edit') }}</a>
                                    <form method="POST" action="{{ route('admin.members.destroy', $member) }}" onsubmit="return confirm({{ Js::from(__('members::admin.members.confirm_leave')) }})">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="ui-btn ui-btn-ghost">{{ __('members::admin.members.mark_departed') }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 5: Write the translations**

`app/Modules/Members/resources/lang/de/admin.php`:

```php
<?php

declare(strict_types=1);

return [
    'members' => [
        'title' => 'Mitglieder',
        'new_member' => 'Neues Mitglied',
        'section' => 'Mitgliedsdaten',
        'firstname' => 'Vorname',
        'lastname' => 'Nachname',
        'birthdate' => 'Geburtsdatum',
        'email' => 'E-Mail',
        'phone' => 'Telefon',
        'address' => 'Adresse',
        'joined_at' => 'Eingetreten am',
        'name' => 'Name',
        'status' => 'Status',
        'status_active' => 'Aktiv',
        'status_departed' => 'Ausgetreten',
        'mark_departed' => 'Austragen',
        'confirm_leave' => 'Mitglied wirklich austragen?',
    ],
    'payments' => [
        'new_payment' => 'Zahlung erfassen',
    ],
    'common' => [
        'all' => 'Alle',
        'filter' => 'Filtern',
        'save' => 'Speichern',
        'cancel' => 'Abbrechen',
        'edit' => 'Bearbeiten',
        'actions' => 'Aktionen',
    ],
];
```

`app/Modules/Members/resources/lang/en/admin.php`:

```php
<?php

declare(strict_types=1);

return [
    'members' => [
        'title' => 'Members',
        'new_member' => 'New member',
        'section' => 'Member details',
        'firstname' => 'First name',
        'lastname' => 'Last name',
        'birthdate' => 'Date of birth',
        'email' => 'Email',
        'phone' => 'Phone',
        'address' => 'Address',
        'joined_at' => 'Joined on',
        'name' => 'Name',
        'status' => 'Status',
        'status_active' => 'Active',
        'status_departed' => 'Departed',
        'mark_departed' => 'Mark as departed',
        'confirm_leave' => 'Really mark this member as departed?',
    ],
    'payments' => [
        'new_payment' => 'Record payment',
    ],
    'common' => [
        'all' => 'All',
        'filter' => 'Filter',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'edit' => 'Edit',
        'actions' => 'Actions',
    ],
];
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `wsl php artisan test --filter=MemberManagementTest`
Expected: PASS (all 6 tests in the class so far).

- [ ] **Step 7: Commit**

```bash
git add app/Modules/Members/Http/Controllers/MemberController.php app/Modules/Members/resources tests/Feature/Modules/Members/MemberManagementTest.php
git commit -m "Add member CRUD controller and views"
```

---

### Task 13: `FiscalYearController`

**Files:**
- Create: `app/Modules/Members/Http/Controllers/FiscalYearController.php`
- Create: `app/Modules/Members/resources/views/fiscal-years/index.blade.php`
- Create: `app/Modules/Members/resources/views/fiscal-years/create.blade.php`
- Create: `app/Modules/Members/resources/views/fiscal-years/edit.blade.php`
- Modify: `app/Modules/Members/resources/lang/de/admin.php`, `.../en/admin.php`
- Test: `tests/Feature/Modules/Members/FiscalYearManagementTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Members;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FiscalYearManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['status' => 'admin']);
    }

    #[Test]
    public function admin_can_create_a_fiscal_year(): void
    {
        $this->actingAs($this->admin())->post('/admin/members/fiscal-years', [
            'name' => '2025/26',
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-08-31',
        ])->assertRedirect(route('admin.members.fiscal-years.index'));

        $this->assertDatabaseHas('fiscal_years', ['name' => '2025/26']);
    }

    #[Test]
    public function admin_can_update_a_fiscal_year(): void
    {
        $fiscalYear = \App\Modules\Members\Models\FiscalYear::create([
            'name' => '2025/26', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31',
        ]);

        $this->actingAs($this->admin())->put("/admin/members/fiscal-years/{$fiscalYear->id}", [
            'name' => '2025/2026 (korrigiert)',
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-08-31',
        ])->assertRedirect(route('admin.members.fiscal-years.index'));

        $this->assertSame('2025/2026 (korrigiert)', $fiscalYear->fresh()->name);
    }

    #[Test]
    public function index_lists_fiscal_years_ordered_by_start_date(): void
    {
        \App\Modules\Members\Models\FiscalYear::create(['name' => '2026/27', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31']);
        \App\Modules\Members\Models\FiscalYear::create(['name' => '2025/26', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31']);

        $this->actingAs($this->admin())->get('/admin/members/fiscal-years')
            ->assertOk()->assertSeeInOrder(['2025/26', '2026/27']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl php artisan test --filter=FiscalYearManagementTest`
Expected: FAIL — `FiscalYearController` doesn't exist.

- [ ] **Step 3: Write the controller**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Members\Http\Controllers;

use App\Modules\Members\Models\FiscalYear;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FiscalYearController
{
    public function index(): View
    {
        return view('members::fiscal-years.index', [
            'fiscalYears' => FiscalYear::query()->orderBy('starts_on')->get(),
        ]);
    }

    public function create(): View
    {
        return view('members::fiscal-years.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:32'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
        ]);

        FiscalYear::create($validated);

        return redirect()->route('admin.members.fiscal-years.index');
    }

    public function edit(FiscalYear $fiscalYear): View
    {
        return view('members::fiscal-years.edit', ['fiscalYear' => $fiscalYear]);
    }

    public function update(Request $request, FiscalYear $fiscalYear): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:32'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
        ]);

        $fiscalYear->update($validated);

        return redirect()->route('admin.members.fiscal-years.index');
    }
}
```

- [ ] **Step 4: Write the views**

`app/Modules/Members/resources/views/fiscal-years/create.blade.php`:

```blade
@extends('layouts.admin')
@section('admin-title', __('members::admin.fiscal_years.title'))
@section('admin-content')
<div class="ui-page">
    <div class="ui-page-header"><h1>{{ __('members::admin.fiscal_years.new') }}</h1></div>
    <form method="POST" action="{{ route('admin.members.fiscal-years.store') }}" class="ui-form-shell">
        @csrf
        @include('members::fiscal-years._form')
        <div class="ui-form-actions">
            <a href="{{ route('admin.members.fiscal-years.index') }}" class="ui-btn ui-btn-ghost">{{ __('members::admin.common.cancel') }}</a>
            <button type="submit" class="ui-btn ui-btn-primary">{{ __('members::admin.common.save') }}</button>
        </div>
    </form>
</div>
@endsection
```

`app/Modules/Members/resources/views/fiscal-years/_form.blade.php`:

```blade
<div class="ui-card">
    <div class="ui-card-body ui-stack">
        <div class="ui-grid-3 ui-form-panel">
            <div class="ui-field">
                <label class="ui-label" for="fy-name">{{ __('members::admin.fiscal_years.name') }}</label>
                <input id="fy-name" type="text" name="name" value="{{ old('name', $fiscalYear->name ?? '') }}" class="ui-input">
            </div>
            <div class="ui-field">
                <label class="ui-label" for="fy-starts-on">{{ __('members::admin.fiscal_years.starts_on') }}</label>
                <input id="fy-starts-on" type="date" name="starts_on" value="{{ old('starts_on', optional($fiscalYear->starts_on ?? null)->format('Y-m-d')) }}" class="ui-input">
            </div>
            <div class="ui-field">
                <label class="ui-label" for="fy-ends-on">{{ __('members::admin.fiscal_years.ends_on') }}</label>
                <input id="fy-ends-on" type="date" name="ends_on" value="{{ old('ends_on', optional($fiscalYear->ends_on ?? null)->format('Y-m-d')) }}" class="ui-input">
            </div>
        </div>
    </div>
</div>
```

`app/Modules/Members/resources/views/fiscal-years/edit.blade.php`:

```blade
@extends('layouts.admin')
@section('admin-title', __('members::admin.fiscal_years.title'))
@section('admin-content')
<div class="ui-page">
    <div class="ui-page-header"><h1>{{ $fiscalYear->name }}</h1></div>
    <form method="POST" action="{{ route('admin.members.fiscal-years.update', $fiscalYear) }}" class="ui-form-shell">
        @csrf
        @method('PUT')
        @include('members::fiscal-years._form', ['fiscalYear' => $fiscalYear])
        <div class="ui-form-actions">
            <a href="{{ route('admin.members.fiscal-years.index') }}" class="ui-btn ui-btn-ghost">{{ __('members::admin.common.cancel') }}</a>
            <button type="submit" class="ui-btn ui-btn-primary">{{ __('members::admin.common.save') }}</button>
        </div>
    </form>
</div>
@endsection
```

`app/Modules/Members/resources/views/fiscal-years/index.blade.php`:

```blade
@extends('layouts.admin')
@section('admin-title', __('members::admin.fiscal_years.title'))
@section('admin-content')
<div class="ui-page">
    <div class="ui-page-header flex flex-wrap items-start justify-between gap-4">
        <h1>{{ __('members::admin.fiscal_years.title') }}</h1>
        <a href="{{ route('admin.members.fiscal-years.create') }}" class="ui-btn ui-btn-primary">{{ __('members::admin.fiscal_years.new') }}</a>
    </div>
    <div class="ui-card">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ __('members::admin.fiscal_years.name') }}</th>
                        <th>{{ __('members::admin.fiscal_years.starts_on') }}</th>
                        <th>{{ __('members::admin.fiscal_years.ends_on') }}</th>
                        <th class="text-right">{{ __('members::admin.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($fiscalYears as $fiscalYear)
                        <tr>
                            <td class="font-medium">{{ $fiscalYear->name }}</td>
                            <td>{{ $fiscalYear->starts_on->format('d.m.Y') }}</td>
                            <td>{{ $fiscalYear->ends_on->format('d.m.Y') }}</td>
                            <td class="text-right"><a href="{{ route('admin.members.fiscal-years.edit', $fiscalYear) }}" class="ui-btn ui-btn-ghost">{{ __('members::admin.common.edit') }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 5: Add translations**

Add to `app/Modules/Members/resources/lang/de/admin.php` (alongside `members`/`payments`/`common`):

```php
    'fiscal_years' => [
        'title' => 'Fiskaljahre',
        'new' => 'Neues Fiskaljahr',
        'name' => 'Bezeichnung',
        'starts_on' => 'Beginn',
        'ends_on' => 'Ende',
    ],
```

Add to `app/Modules/Members/resources/lang/en/admin.php`:

```php
    'fiscal_years' => [
        'title' => 'Fiscal years',
        'new' => 'New fiscal year',
        'name' => 'Name',
        'starts_on' => 'Starts on',
        'ends_on' => 'Ends on',
    ],
```

- [ ] **Step 6: Run test to verify it passes**

Run: `wsl php artisan test --filter=FiscalYearManagementTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Modules/Members/Http/Controllers/FiscalYearController.php app/Modules/Members/resources tests/Feature/Modules/Members/FiscalYearManagementTest.php
git commit -m "Add fiscal year management"
```

---

### Task 14: `FeeCategoryController` — categories and per-year rates

**Files:**
- Create: `app/Modules/Members/Http/Controllers/FeeCategoryController.php`
- Create: `app/Modules/Members/resources/views/fee-categories/index.blade.php`
- Modify: `app/Modules/Members/resources/lang/de/admin.php`, `.../en/admin.php`
- Test: `tests/Feature/Modules/Members/FeeCategoryManagementTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Members;

use App\Models\User;
use App\Modules\Members\Models\FeeCategory;
use App\Modules\Members\Models\FiscalYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FeeCategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['status' => 'admin']);
    }

    #[Test]
    public function admin_can_create_a_fee_category(): void
    {
        $this->actingAs($this->admin())->post('/admin/members/fee-categories', [
            'name' => 'Vollmitglied',
        ])->assertRedirect(route('admin.members.fee-categories.index'));

        $this->assertDatabaseHas('fee_categories', ['name' => 'Vollmitglied']);
    }

    #[Test]
    public function admin_can_set_a_rate_for_a_fiscal_year(): void
    {
        $category = FeeCategory::create(['name' => 'Vollmitglied']);
        $fiscalYear = FiscalYear::create(['name' => '2025/26', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31']);

        $this->actingAs($this->admin())->post('/admin/members/fee-categories/rates', [
            'fiscal_year_id' => $fiscalYear->id,
            'rates' => [$category->id => '120.00'],
        ])->assertRedirect();

        $this->assertDatabaseHas('fee_category_rates', [
            'fee_category_id' => $category->id,
            'fiscal_year_id' => $fiscalYear->id,
            'amount' => 120.00,
        ]);
    }

    #[Test]
    public function setting_a_rate_twice_for_the_same_year_updates_it_instead_of_duplicating(): void
    {
        $category = FeeCategory::create(['name' => 'Vollmitglied']);
        $fiscalYear = FiscalYear::create(['name' => '2025/26', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31']);

        $this->actingAs($this->admin())->post('/admin/members/fee-categories/rates', [
            'fiscal_year_id' => $fiscalYear->id,
            'rates' => [$category->id => '120.00'],
        ]);
        $this->actingAs($this->admin())->post('/admin/members/fee-categories/rates', [
            'fiscal_year_id' => $fiscalYear->id,
            'rates' => [$category->id => '130.00'],
        ]);

        $this->assertSame(1, \App\Modules\Members\Models\FeeCategoryRate::count());
        $this->assertDatabaseHas('fee_category_rates', ['amount' => 130.00]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl php artisan test --filter=FeeCategoryManagementTest`
Expected: FAIL — `FeeCategoryController` doesn't exist.

- [ ] **Step 3: Write the controller**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Members\Http\Controllers;

use App\Modules\Members\Models\FeeCategory;
use App\Modules\Members\Models\FeeCategoryRate;
use App\Modules\Members\Models\FiscalYear;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FeeCategoryController
{
    public function index(Request $request): View
    {
        $fiscalYears = FiscalYear::query()->orderBy('starts_on')->get();
        $categories = FeeCategory::query()->orderBy('name')->get();

        $selectedFiscalYearId = (int) $request->query('fiscal_year_id', $fiscalYears->last()->id ?? 0);

        $rates = FeeCategoryRate::query()
            ->where('fiscal_year_id', $selectedFiscalYearId)
            ->get()
            ->keyBy('fee_category_id');

        return view('members::fee-categories.index', [
            'fiscalYears' => $fiscalYears,
            'categories' => $categories,
            'rates' => $rates,
            'selectedFiscalYearId' => $selectedFiscalYearId,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
        ]);

        FeeCategory::create($validated);

        return redirect()->route('admin.members.fee-categories.index');
    }

    public function storeRates(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'fiscal_year_id' => ['required', 'exists:fiscal_years,id'],
            'rates' => ['required', 'array'],
            'rates.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        foreach ($validated['rates'] as $categoryId => $amount) {
            if ($amount === null || $amount === '') {
                continue;
            }

            FeeCategoryRate::updateOrCreate(
                ['fee_category_id' => $categoryId, 'fiscal_year_id' => $validated['fiscal_year_id']],
                ['amount' => $amount]
            );
        }

        return redirect()->route('admin.members.fee-categories.index', ['fiscal_year_id' => $validated['fiscal_year_id']]);
    }
}
```

- [ ] **Step 4: Write the view**

`app/Modules/Members/resources/views/fee-categories/index.blade.php`:

```blade
@extends('layouts.admin')
@section('admin-title', __('members::admin.fee_categories.title'))
@section('admin-content')
<div class="ui-page">
    <div class="ui-page-header"><h1>{{ __('members::admin.fee_categories.title') }}</h1></div>

    <div class="ui-card">
        <div class="ui-card-header"><h2>{{ __('members::admin.fee_categories.new_category') }}</h2></div>
        <div class="ui-card-body">
            <form method="POST" action="{{ route('admin.members.fee-categories.store') }}" class="ui-row">
                @csrf
                <div class="ui-field min-w-[16rem]">
                    <input type="text" name="name" placeholder="{{ __('members::admin.fee_categories.name') }}" class="ui-input">
                </div>
                <button type="submit" class="ui-btn ui-btn-primary">{{ __('members::admin.common.save') }}</button>
            </form>
        </div>
    </div>

    <div class="ui-card">
        <div class="ui-card-header">
            <h2>{{ __('members::admin.fee_categories.rates_for') }}</h2>
        </div>
        <div class="ui-card-body">
            <form method="GET" action="{{ route('admin.members.fee-categories.index') }}" class="ui-row">
                <div class="ui-field min-w-[12rem]">
                    <select name="fiscal_year_id" class="ui-select" onchange="this.form.submit()">
                        @foreach($fiscalYears as $fiscalYear)
                            <option value="{{ $fiscalYear->id }}" @selected($selectedFiscalYearId === $fiscalYear->id)>{{ $fiscalYear->name }}</option>
                        @endforeach
                    </select>
                </div>
            </form>

            @if($fiscalYears->isNotEmpty())
                <form method="POST" action="{{ route('admin.members.fee-categories.rates.store') }}" class="ui-stack mt-4">
                    @csrf
                    <input type="hidden" name="fiscal_year_id" value="{{ $selectedFiscalYearId }}">
                    <div class="ui-table-wrap">
                        <table class="ui-table">
                            <thead>
                                <tr>
                                    <th>{{ __('members::admin.fee_categories.name') }}</th>
                                    <th>{{ __('members::admin.fee_categories.amount') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($categories as $category)
                                    <tr>
                                        <td>{{ $category->name }}</td>
                                        <td>
                                            <input type="number" step="0.01" min="0" name="rates[{{ $category->id }}]" value="{{ $rates[$category->id]->amount ?? '' }}" class="ui-input">
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <button type="submit" class="ui-btn ui-btn-primary">{{ __('members::admin.common.save') }}</button>
                </form>
            @endif
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 5: Add translations**

Add to `app/Modules/Members/resources/lang/de/admin.php`:

```php
    'fee_categories' => [
        'title' => 'Beitragskategorien',
        'new_category' => 'Neue Kategorie',
        'name' => 'Bezeichnung',
        'amount' => 'Betrag (€)',
        'rates_for' => 'Beitragssätze pro Fiskaljahr',
    ],
```

Add to `app/Modules/Members/resources/lang/en/admin.php`:

```php
    'fee_categories' => [
        'title' => 'Fee categories',
        'new_category' => 'New category',
        'name' => 'Name',
        'amount' => 'Amount (€)',
        'rates_for' => 'Rates per fiscal year',
    ],
```

- [ ] **Step 6: Run test to verify it passes**

Run: `wsl php artisan test --filter=FeeCategoryManagementTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Modules/Members/Http/Controllers/FeeCategoryController.php app/Modules/Members/resources tests/Feature/Modules/Members/FeeCategoryManagementTest.php
git commit -m "Add fee category and per-fiscal-year rate management"
```

---

### Task 15: `MemberDueController` — bulk generation

**Files:**
- Create: `app/Modules/Members/Http/Controllers/MemberDueController.php`
- Create: `app/Modules/Members/resources/views/dues/index.blade.php`
- Modify: `app/Modules/Members/resources/lang/de/admin.php`, `.../en/admin.php`
- Test: `tests/Feature/Modules/Members/MemberDueGenerationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Members;

use App\Models\User;
use App\Modules\Members\Models\FeeCategory;
use App\Modules\Members\Models\FeeCategoryRate;
use App\Modules\Members\Models\FiscalYear;
use App\Modules\Members\Models\Member;
use App\Modules\Members\Models\MemberDue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberDueGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['status' => 'admin']);
    }

    #[Test]
    public function generating_dues_creates_one_per_active_member_using_last_years_category(): void
    {
        $category = FeeCategory::create(['name' => 'Vollmitglied']);
        $lastYear = FiscalYear::create(['name' => '2025/26', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31']);
        $newYear = FiscalYear::create(['name' => '2026/27', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31']);
        FeeCategoryRate::create(['fee_category_id' => $category->id, 'fiscal_year_id' => $newYear->id, 'amount' => 130]);

        $activeMember = Member::factory()->create();
        MemberDue::create(['member_id' => $activeMember->id, 'fiscal_year_id' => $lastYear->id, 'fee_category_id' => $category->id, 'amount' => 120]);

        $departedMember = Member::factory()->create(['left_at' => '2026-01-01']);
        MemberDue::create(['member_id' => $departedMember->id, 'fiscal_year_id' => $lastYear->id, 'fee_category_id' => $category->id, 'amount' => 120]);

        $this->actingAs($this->admin())->post('/admin/members/dues/generate', [
            'fiscal_year_id' => $newYear->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('member_dues', [
            'member_id' => $activeMember->id,
            'fiscal_year_id' => $newYear->id,
            'fee_category_id' => $category->id,
            'amount' => 130,
        ]);
        $this->assertDatabaseMissing('member_dues', [
            'member_id' => $departedMember->id,
            'fiscal_year_id' => $newYear->id,
        ]);
    }

    #[Test]
    public function generating_dues_twice_does_not_create_duplicates(): void
    {
        $category = FeeCategory::create(['name' => 'Vollmitglied']);
        $lastYear = FiscalYear::create(['name' => '2025/26', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31']);
        $newYear = FiscalYear::create(['name' => '2026/27', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31']);
        FeeCategoryRate::create(['fee_category_id' => $category->id, 'fiscal_year_id' => $newYear->id, 'amount' => 130]);

        $member = Member::factory()->create();
        MemberDue::create(['member_id' => $member->id, 'fiscal_year_id' => $lastYear->id, 'fee_category_id' => $category->id, 'amount' => 120]);

        $this->actingAs($this->admin())->post('/admin/members/dues/generate', ['fiscal_year_id' => $newYear->id]);
        $this->actingAs($this->admin())->post('/admin/members/dues/generate', ['fiscal_year_id' => $newYear->id]);

        $this->assertSame(1, MemberDue::where('fiscal_year_id', $newYear->id)->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl php artisan test --filter=MemberDueGenerationTest`
Expected: FAIL — `MemberDueController` doesn't exist.

- [ ] **Step 3: Write the controller**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Members\Http\Controllers;

use App\Modules\Members\Models\FeeCategoryRate;
use App\Modules\Members\Models\FiscalYear;
use App\Modules\Members\Models\Member;
use App\Modules\Members\Models\MemberDue;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MemberDueController
{
    public function index(Request $request): View
    {
        $fiscalYears = FiscalYear::query()->orderBy('starts_on')->get();
        $selectedFiscalYearId = (int) $request->query('fiscal_year_id', $fiscalYears->last()->id ?? 0);

        $dues = MemberDue::query()
            ->with(['member', 'feeCategory', 'payments'])
            ->where('fiscal_year_id', $selectedFiscalYearId)
            ->get();

        return view('members::dues.index', [
            'fiscalYears' => $fiscalYears,
            'selectedFiscalYearId' => $selectedFiscalYearId,
            'dues' => $dues,
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'fiscal_year_id' => ['required', 'exists:fiscal_years,id'],
        ]);
        $fiscalYearId = (int) $validated['fiscal_year_id'];

        $existingMemberIds = MemberDue::where('fiscal_year_id', $fiscalYearId)->pluck('member_id');

        $created = 0;

        Member::query()
            ->active()
            ->whereNotIn('id', $existingMemberIds)
            ->each(function (Member $member) use ($fiscalYearId, &$created) {
                // "Last year's category" assumes fiscal years are created in chronological
                // order, so the highest fiscal_year_id is the most recent one.
                $lastDue = $member->dues()->latest('fiscal_year_id')->first();

                if (! $lastDue) {
                    return;
                }

                $rate = FeeCategoryRate::where('fee_category_id', $lastDue->fee_category_id)
                    ->where('fiscal_year_id', $fiscalYearId)
                    ->first();

                if (! $rate) {
                    return;
                }

                MemberDue::create([
                    'member_id' => $member->id,
                    'fiscal_year_id' => $fiscalYearId,
                    'fee_category_id' => $lastDue->fee_category_id,
                    'amount' => $rate->amount,
                ]);

                $created++;
            });

        return redirect()->route('admin.members.dues.index', ['fiscal_year_id' => $fiscalYearId])
            ->with('status', __('members::admin.dues.generated', ['count' => $created]));
    }
}
```

- [ ] **Step 4: Write the view**

`app/Modules/Members/resources/views/dues/index.blade.php`:

```blade
@extends('layouts.admin')
@section('admin-title', __('members::admin.dues.title'))
@section('admin-content')
<div class="ui-page">
    <div class="ui-page-header"><h1>{{ __('members::admin.dues.title') }}</h1></div>

    @if(session('status'))
        <div class="ui-card"><div class="ui-card-body">{{ session('status') }}</div></div>
    @endif

    <div class="ui-card">
        <div class="ui-card-body ui-stack">
            <form method="GET" action="{{ route('admin.members.dues.index') }}" class="ui-row">
                <div class="ui-field min-w-[12rem]">
                    <select name="fiscal_year_id" class="ui-select" onchange="this.form.submit()">
                        @foreach($fiscalYears as $fiscalYear)
                            <option value="{{ $fiscalYear->id }}" @selected($selectedFiscalYearId === $fiscalYear->id)>{{ $fiscalYear->name }}</option>
                        @endforeach
                    </select>
                </div>
            </form>
            <form method="POST" action="{{ route('admin.members.dues.generate') }}">
                @csrf
                <input type="hidden" name="fiscal_year_id" value="{{ $selectedFiscalYearId }}">
                <button type="submit" class="ui-btn ui-btn-primary">{{ __('members::admin.dues.generate') }}</button>
            </form>
        </div>
    </div>

    <div class="ui-card">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th>{{ __('members::admin.members.name') }}</th>
                        <th>{{ __('members::admin.fee_categories.title') }}</th>
                        <th>{{ __('members::admin.fee_categories.amount') }}</th>
                        <th>{{ __('members::admin.dues.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($dues as $due)
                        <tr>
                            <td>{{ $due->member->fullName() }}</td>
                            <td>{{ $due->feeCategory->name }}</td>
                            <td>{{ number_format((float) $due->amount, 2) }} €</td>
                            <td>
                                <span class="ui-badge {{ $due->payments->isNotEmpty() ? 'ui-badge-success' : 'ui-badge-info' }}">
                                    {{ $due->payments->isNotEmpty() ? __('members::admin.dues.status_paid') : __('members::admin.dues.status_open') }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 5: Add translations**

Add to `app/Modules/Members/resources/lang/de/admin.php`:

```php
    'dues' => [
        'title' => 'Beiträge',
        'generate' => 'Beiträge für dieses Fiskaljahr generieren',
        'generated' => ':count Beitrag/Beiträge erzeugt.',
        'status' => 'Status',
        'status_paid' => 'Bezahlt',
        'status_open' => 'Offen',
    ],
```

Add to `app/Modules/Members/resources/lang/en/admin.php`:

```php
    'dues' => [
        'title' => 'Dues',
        'generate' => 'Generate dues for this fiscal year',
        'generated' => ':count due(s) created.',
        'status' => 'Status',
        'status_paid' => 'Paid',
        'status_open' => 'Open',
    ],
```

- [ ] **Step 6: Run test to verify it passes**

Run: `wsl php artisan test --filter=MemberDueGenerationTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Modules/Members/Http/Controllers/MemberDueController.php app/Modules/Members/resources tests/Feature/Modules/Members/MemberDueGenerationTest.php
git commit -m "Add bulk member due generation per fiscal year"
```

---

### Task 16: `PaymentController`

**Files:**
- Create: `app/Modules/Members/Http/Controllers/PaymentController.php`
- Create: `app/Modules/Members/resources/views/payments/create.blade.php`
- Modify: `app/Modules/Members/resources/lang/de/admin.php`, `.../en/admin.php`
- Test: `tests/Feature/Modules/Members/PaymentManagementTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Members;

use App\Models\User;
use App\Modules\Members\Models\FeeCategory;
use App\Modules\Members\Models\FiscalYear;
use App\Modules\Members\Models\Member;
use App\Modules\Members\Models\MemberDue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['status' => 'admin']);
    }

    #[Test]
    public function admin_can_record_a_payment_covering_multiple_fiscal_years(): void
    {
        $member = Member::factory()->create();
        $category = FeeCategory::create(['name' => 'Vollmitglied']);
        $fy1 = FiscalYear::create(['name' => '2025/26', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31']);
        $fy2 = FiscalYear::create(['name' => '2026/27', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31']);
        $due1 = MemberDue::create(['member_id' => $member->id, 'fiscal_year_id' => $fy1->id, 'fee_category_id' => $category->id, 'amount' => 120]);
        $due2 = MemberDue::create(['member_id' => $member->id, 'fiscal_year_id' => $fy2->id, 'fee_category_id' => $category->id, 'amount' => 130]);

        $this->actingAs($this->admin())->post("/admin/members/{$member->id}/payments", [
            'amount' => 250,
            'paid_at' => '2025-09-10',
            'member_due_ids' => [$due1->id, $due2->id],
        ])->assertRedirect(route('admin.members.edit', $member));

        $this->assertTrue($due1->fresh()->isPaid());
        $this->assertTrue($due2->fresh()->isPaid());
        $this->assertSame('2027-08-31', $member->fresh()->paidUntil()->format('Y-m-d'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl php artisan test --filter=PaymentManagementTest`
Expected: FAIL — `PaymentController` doesn't exist.

- [ ] **Step 3: Write the controller**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Members\Http\Controllers;

use App\Modules\Members\Models\Member;
use App\Modules\Members\Models\MemberDue;
use App\Modules\Members\Models\Payment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PaymentController
{
    public function create(Member $member): View
    {
        $openDues = $member->dues()
            ->whereDoesntHave('payments')
            ->with(['fiscalYear', 'feeCategory'])
            ->get();

        return view('members::payments.create', ['member' => $member, 'openDues' => $openDues]);
    }

    public function store(Request $request, Member $member): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
            'member_due_ids' => ['required', 'array', 'min:1'],
            'member_due_ids.*' => ['integer', 'exists:member_dues,id'],
        ]);

        $payment = Payment::create([
            'member_id' => $member->id,
            'amount' => $validated['amount'],
            'paid_at' => $validated['paid_at'],
            'note' => $validated['note'] ?? null,
        ]);

        $payment->dues()->attach($validated['member_due_ids']);

        return redirect()->route('admin.members.edit', $member)
            ->with('status', __('members::admin.payments.created'));
    }
}
```

- [ ] **Step 4: Write the view**

`app/Modules/Members/resources/views/payments/create.blade.php`:

```blade
@extends('layouts.admin')
@section('admin-title', __('members::admin.payments.new_payment'))
@section('admin-content')
<div class="ui-page">
    <div class="ui-page-header"><h1>{{ __('members::admin.payments.new_payment') }} — {{ $member->fullName() }}</h1></div>

    <form method="POST" action="{{ route('admin.members.payments.store', $member) }}" class="ui-form-shell">
        @csrf
        <div class="ui-card">
            <div class="ui-card-body ui-stack">
                <div class="ui-grid-3 ui-form-panel">
                    <div class="ui-field">
                        <label class="ui-label" for="pf-amount">{{ __('members::admin.payments.amount') }}</label>
                        <input id="pf-amount" type="number" step="0.01" min="0.01" name="amount" class="ui-input">
                    </div>
                    <div class="ui-field">
                        <label class="ui-label" for="pf-paid-at">{{ __('members::admin.payments.paid_at') }}</label>
                        <input id="pf-paid-at" type="date" name="paid_at" value="{{ now()->toDateString() }}" class="ui-input">
                    </div>
                    <div class="ui-field">
                        <label class="ui-label" for="pf-note">{{ __('members::admin.payments.note') }}</label>
                        <input id="pf-note" type="text" name="note" class="ui-input">
                    </div>
                </div>

                <p class="ui-section-label">{{ __('members::admin.payments.covers') }}</p>
                @foreach($openDues as $due)
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="member_due_ids[]" value="{{ $due->id }}">
                        {{ $due->fiscalYear->name }} — {{ $due->feeCategory->name }} ({{ number_format((float) $due->amount, 2) }} €)
                    </label>
                @endforeach
            </div>
        </div>
        <div class="ui-form-actions">
            <a href="{{ route('admin.members.edit', $member) }}" class="ui-btn ui-btn-ghost">{{ __('members::admin.common.cancel') }}</a>
            <button type="submit" class="ui-btn ui-btn-primary">{{ __('members::admin.common.save') }}</button>
        </div>
    </form>
</div>
@endsection
```

- [ ] **Step 5: Add translations**

Add to `app/Modules/Members/resources/lang/de/admin.php` (extend the existing `payments` array):

```php
    'payments' => [
        'new_payment' => 'Zahlung erfassen',
        'created' => 'Zahlung erfasst.',
        'amount' => 'Betrag (€)',
        'paid_at' => 'Zahlungsdatum',
        'note' => 'Notiz',
        'covers' => 'Deckt folgende Beiträge ab',
    ],
```

Add to `app/Modules/Members/resources/lang/en/admin.php` (extend the existing `payments` array):

```php
    'payments' => [
        'new_payment' => 'Record payment',
        'created' => 'Payment recorded.',
        'amount' => 'Amount (€)',
        'paid_at' => 'Payment date',
        'note' => 'Note',
        'covers' => 'Covers the following dues',
    ],
```

- [ ] **Step 6: Run test to verify it passes**

Run: `wsl php artisan test --filter=PaymentManagementTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Modules/Members/Http/Controllers/PaymentController.php app/Modules/Members/resources tests/Feature/Modules/Members/PaymentManagementTest.php
git commit -m "Add payment recording covering one or more fiscal years"
```

---

### Task 17: `MemberExportController` — CSV export

**Files:**
- Create: `app/Modules/Members/Http/Controllers/MemberExportController.php`
- Test: `tests/Feature/Modules/Members/MemberExportTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Members;

use App\Models\User;
use App\Modules\Members\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberExportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['status' => 'admin']);
    }

    #[Test]
    public function export_streams_a_csv_with_all_members(): void
    {
        Member::factory()->create(['firstname' => 'Erika', 'lastname' => 'Musterfrau', 'email' => 'erika@example.com']);

        $response = $this->actingAs($this->admin())->get('/admin/members/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Erika', $content);
        $this->assertStringContainsString('Musterfrau', $content);
        $this->assertStringContainsString('erika@example.com', $content);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl php artisan test --filter=MemberExportTest`
Expected: FAIL — `MemberExportController` doesn't exist.

- [ ] **Step 3: Write the controller**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Members\Http\Controllers;

use App\Modules\Members\Models\Member;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MemberExportController
{
    public function export(): StreamedResponse
    {
        $members = Member::query()->orderBy('lastname')->orderBy('firstname')->get();

        return response()->streamDownload(function () use ($members) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Vorname', 'Nachname', 'E-Mail', 'Eingetreten', 'Ausgetreten']);

            foreach ($members as $member) {
                fputcsv($handle, [
                    $member->firstname,
                    $member->lastname,
                    $member->email,
                    optional($member->joined_at)->format('Y-m-d'),
                    optional($member->left_at)->format('Y-m-d'),
                ]);
            }

            fclose($handle);
        }, 'mitglieder.csv', ['Content-Type' => 'text/csv']);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `wsl php artisan test --filter=MemberExportTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Members/Http/Controllers/MemberExportController.php tests/Feature/Modules/Members/MemberExportTest.php
git commit -m "Add member CSV export"
```

---

### Task 18: `DashboardController`

**Files:**
- Create: `app/Modules/Members/Http/Controllers/DashboardController.php`
- Create: `app/Modules/Members/resources/views/dashboard.blade.php`
- Modify: `app/Modules/Members/resources/lang/de/admin.php`, `.../en/admin.php`
- Test: `tests/Feature/Modules/Members/DashboardTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Members;

use App\Models\User;
use App\Modules\Members\Models\FeeCategory;
use App\Modules\Members\Models\FiscalYear;
use App\Modules\Members\Models\Member;
use App\Modules\Members\Models\MemberDue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['status' => 'admin']);
    }

    #[Test]
    public function dashboard_shows_active_member_count_and_open_dues_total(): void
    {
        Member::factory()->create();
        Member::factory()->create(['left_at' => '2025-01-01']);

        $category = FeeCategory::create(['name' => 'Vollmitglied']);
        $fiscalYear = FiscalYear::create(['name' => '2025/26', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31']);
        MemberDue::create(['member_id' => Member::query()->active()->first()->id, 'fiscal_year_id' => $fiscalYear->id, 'fee_category_id' => $category->id, 'amount' => 120]);

        $this->actingAs($this->admin())->get('/admin/members/dashboard')
            ->assertOk()
            ->assertSee('1') // active member count
            ->assertSee('120');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `wsl php artisan test --filter=DashboardTest`
Expected: FAIL — `DashboardController` doesn't exist.

- [ ] **Step 3: Write the controller**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Members\Http\Controllers;

use App\Modules\Members\Models\FiscalYear;
use App\Modules\Members\Models\Member;
use App\Modules\Members\Models\MemberDue;
use Illuminate\Contracts\View\View;

class DashboardController
{
    public function index(): View
    {
        $activeCount = Member::query()->active()->count();

        $currentFiscalYear = FiscalYear::query()->orderByDesc('ends_on')->first();

        $openDuesQuery = MemberDue::query()->whereDoesntHave('payments');
        if ($currentFiscalYear) {
            $openDuesQuery->where('fiscal_year_id', $currentFiscalYear->id);
        }
        $openDues = $openDuesQuery->get();

        $categoryCounts = $currentFiscalYear
            ? MemberDue::query()
                ->where('fiscal_year_id', $currentFiscalYear->id)
                ->with('feeCategory')
                ->get()
                ->groupBy(fn (MemberDue $due) => $due->feeCategory->name)
                ->map->count()
            : collect();

        return view('members::dashboard', [
            'activeCount' => $activeCount,
            'openDuesCount' => $openDues->count(),
            'openDuesSum' => $openDues->sum('amount'),
            'categoryCounts' => $categoryCounts,
            'currentFiscalYear' => $currentFiscalYear,
        ]);
    }
}
```

- [ ] **Step 4: Write the view**

`app/Modules/Members/resources/views/dashboard.blade.php`:

```blade
@extends('layouts.admin')
@section('admin-title', __('members::admin.dashboard.title'))
@section('admin-content')
<div class="ui-page">
    <div class="ui-page-header"><h1>{{ __('members::admin.dashboard.title') }}</h1></div>

    <div class="ui-grid-3">
        <div class="ui-card">
            <div class="ui-card-body">
                <p class="ui-kpi-meta">{{ __('members::admin.dashboard.active_members') }}</p>
                <p class="text-3xl font-bold">{{ $activeCount }}</p>
            </div>
        </div>
        <div class="ui-card">
            <div class="ui-card-body">
                <p class="ui-kpi-meta">{{ __('members::admin.dashboard.open_dues') }}</p>
                <p class="text-3xl font-bold">{{ $openDuesCount }}</p>
                <p class="ui-kpi-meta">{{ number_format((float) $openDuesSum, 2) }} €</p>
            </div>
        </div>
        <div class="ui-card">
            <div class="ui-card-body">
                <p class="ui-kpi-meta">{{ __('members::admin.dashboard.by_category') }}</p>
                @forelse($categoryCounts as $categoryName => $count)
                    <p>{{ $categoryName }}: {{ $count }}</p>
                @empty
                    <p class="ui-kpi-meta">—</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 5: Add translations**

Add to `app/Modules/Members/resources/lang/de/admin.php`:

```php
    'dashboard' => [
        'title' => 'Übersicht',
        'active_members' => 'Aktive Mitglieder',
        'open_dues' => 'Offene Beiträge',
        'by_category' => 'Mitglieder pro Kategorie',
    ],
```

Add to `app/Modules/Members/resources/lang/en/admin.php`:

```php
    'dashboard' => [
        'title' => 'Overview',
        'active_members' => 'Active members',
        'open_dues' => 'Open dues',
        'by_category' => 'Members per category',
    ],
```

- [ ] **Step 6: Run test to verify it passes**

Run: `wsl php artisan test --filter=DashboardTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Modules/Members/Http/Controllers/DashboardController.php app/Modules/Members/resources tests/Feature/Modules/Members/DashboardTest.php
git commit -m "Add Members dashboard with summary tiles"
```

---

### Task 19: Full verification

**Files:** none (verification only)

- [ ] **Step 1: Run the isolated Members testsuite**

Run: `wsl php artisan test --testsuite=Members`
Expected: PASS, all Members unit + feature tests, completing well under the full suite's runtime.

- [ ] **Step 2: Run the entire project test suite**

Run: `wsl php artisan test`
Expected: PASS — no regressions in the existing booking system tests (in particular `tests/Feature/Admin/*` and the sidebar-rendering tests, since `admin-sidebar.blade.php` was modified in Task 11).

- [ ] **Step 3: Manually smoke-test in the browser**

Start the app (`wsl php artisan serve` or the project's existing dev workflow), log in as a `status=admin` user, and click through: `/admin/members/dashboard` → create a member → create a fiscal year → create a fee category with a rate → generate dues → record a payment → export CSV. Confirm the sidebar shows "Mitglieder" and that a non-admin user gets a 403 on `/admin/members`.

- [ ] **Step 4: Commit any fixes found during smoke-testing**

If the manual walkthrough surfaces issues, fix them and commit each fix separately with a descriptive message (do not bundle unrelated fixes into one commit).
