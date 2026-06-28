# Admin-Bereich Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the full admin backend (users, events, bookings, config) plus admin calendar rights (cancel-any, see-data/see-past gating, book-for-member), gated granularly via the existing `User::can()` model.

**Architecture:** Dedicated `/admin` area (`App\Http\Controllers\Admin\*`, own layout) with per-section `can:`-middleware made consistent with `User::can()` via a `Gate::before` hook. Admin calendar rights are integrated into the existing frontend calendar/booking flow.

**Tech Stack:** Laravel 11, PHP 8.4, Blade, PHPUnit (sqlite `:memory:`), real `booking_local` schema (see `docs/superpowers/specs/2026-06-26-admin-area-design.md` and memory `real-db-schema` / `booking-permission-model`).

**Run tests:** `wsl bash -c "cd /mnt/c/development/bookingnew && php artisan test --filter='<name>'"` (PHP only via WSL).

---

## File Structure

- Create `app/Providers/` change in `AppServiceProvider.php` — `Gate::before` hook.
- Modify `app/Models/User.php` — add `PRIVILEGES` constant + meta-write helpers.
- Create `app/Http/Controllers/Admin/DashboardController.php`
- Create `app/Http/Controllers/Admin/UserController.php`
- Create `app/Http/Controllers/Admin/EventController.php`
- Create `app/Http/Controllers/Admin/BookingController.php`
- Create `app/Http/Controllers/Admin/OptionController.php`
- Create `resources/views/layouts/admin.blade.php` + `resources/views/admin/**`
- Modify `routes/web.php` — `/admin` group.
- Modify `resources/views/layouts/app.blade.php` — admin nav link (`@can('admin.see-menu')`).
- Modify `app/Http/Controllers/BookingController.php` — cancel-any + book-for-member.
- Modify `resources/views/calendar/index.blade.php` — see-data/see-past name gating.
- Tests under `tests/Feature/Admin/**` and additions to existing feature tests.

---

## Phase 1 — Foundation (access control, layout, dashboard)

### Task 1: Gate::before delegates to User::can()

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Admin/AccessControlTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\UserMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function gate_allows_admin_everything(): void
    {
        $admin = User::factory()->create(['status' => 'admin']);
        $this->assertTrue(Gate::forUser($admin)->allows('admin.user'));
    }

    #[Test]
    public function gate_respects_assist_allow_flags(): void
    {
        $assist = User::factory()->create(['status' => 'assist']);
        UserMeta::create(['uid' => $assist->uid, 'key' => 'allow.admin.user', 'value' => 'true']);

        $this->assertTrue(Gate::forUser($assist)->allows('admin.user'));
        $this->assertFalse(Gate::forUser($assist)->allows('admin.event'));
    }

    #[Test]
    public function gate_denies_regular_member(): void
    {
        $user = User::factory()->create(['status' => 'enabled']);
        $this->assertFalse(Gate::forUser($user)->allows('admin.user'));
    }
}
```

- [ ] **Step 2: Run, verify fail** — `--filter=AccessControlTest` → FAIL (gate returns false for admin, no hook).

- [ ] **Step 3: Implement** — in `AppServiceProvider::boot()`:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

// inside boot():
Gate::before(function (User $user, string $ability): ?bool {
    return $user->can($ability) ? true : null;
});
```

Note: `User::can()` already exists. `null` (not `false`) lets future real policies still allow.

- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit** — `feat(admin): delegate Gate checks to User::can()`

---

### Task 2: PRIVILEGES constant on User

**Files:**
- Modify: `app/Models/User.php`
- Test: `tests/Unit/Models/UserModelTest.php` (add)

- [ ] **Step 1: Add test**

```php
#[Test]
public function privileges_constant_lists_known_abilities(): void
{
    $this->assertContains('admin.user', \App\Models\User::PRIVILEGES);
    $this->assertContains('calendar.see-data', \App\Models\User::PRIVILEGES);
    $this->assertContains('admin.see-menu', \App\Models\User::PRIVILEGES);
}
```

- [ ] **Step 2: Run, verify fail** (undefined constant).

- [ ] **Step 3: Implement** — add to `User`:

```php
/** All assignable privileges (ported from ZF2 User::$privileges). */
public const PRIVILEGES = [
    'admin.user', 'admin.booking', 'admin.event', 'admin.config', 'admin.see-menu',
    'calendar.see-past', 'calendar.see-data',
    'calendar.create-single-bookings', 'calendar.cancel-single-bookings', 'calendar.delete-single-bookings',
    'calendar.create-subscription-bookings', 'calendar.cancel-subscription-bookings', 'calendar.delete-subscription-bookings',
];
```

- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit** — `feat(user): add PRIVILEGES constant`

---

### Task 3: User privilege/profile meta helpers

**Files:**
- Modify: `app/Models/User.php`
- Test: `tests/Unit/Models/UserModelTest.php` (add)

- [ ] **Step 1: Add tests**

```php
#[Test]
public function sync_privileges_writes_and_removes_allow_meta(): void
{
    $user = User::factory()->create(['status' => 'assist']);
    $user->syncPrivileges(['admin.user', 'calendar.see-data']);

    $this->assertTrue($user->can('admin.user'));
    $this->assertTrue($user->can('calendar.see-data'));

    $user->syncPrivileges(['admin.user']); // remove see-data
    $this->assertTrue($user->can('admin.user'));
    $this->assertFalse($user->fresh()->can('calendar.see-data'));
}

#[Test]
public function set_meta_upserts_value(): void
{
    $user = User::factory()->create();
    $user->setMeta('firstname', 'Max');
    $this->assertEquals('Max', $user->getMeta('firstname'));
    $user->setMeta('firstname', 'Karl');
    $this->assertEquals('Karl', $user->fresh()->getMeta('firstname'));
    $this->assertEquals(1, $user->meta()->where('key', 'firstname')->count());
}
```

- [ ] **Step 2: Run, verify fail.**

- [ ] **Step 3: Implement** on `User`:

```php
/** Upsert a single meta value (bs_users_meta key/value). */
public function setMeta(string $key, ?string $value): void
{
    if ($value === null) {
        $this->meta()->where('key', $key)->delete();
        return;
    }
    $row = $this->meta()->where('key', $key)->first();
    if ($row) {
        $row->update(['value' => $value]);
    } else {
        $this->meta()->create(['key' => $key, 'value' => $value]);
    }
}

/** Replace the set of granted privileges (assist allow.* flags). */
public function syncPrivileges(array $privileges): void
{
    foreach (self::PRIVILEGES as $priv) {
        $this->setMeta('allow.' . $priv, in_array($priv, $privileges, true) ? 'true' : null);
    }
}

/** Currently granted privilege slugs (from allow.* meta). */
public function grantedPrivileges(): array
{
    return $this->meta()
        ->where('key', 'like', 'allow.%')
        ->where('value', 'true')
        ->pluck('key')
        ->map(fn (string $k) => substr($k, strlen('allow.')))
        ->all();
}
```

- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit** — `feat(user): privilege & meta sync helpers`

---

### Task 4: Admin layout + dashboard + nav + route group

**Files:**
- Create: `app/Http/Controllers/Admin/DashboardController.php`
- Create: `resources/views/layouts/admin.blade.php`, `resources/views/admin/dashboard.blade.php`
- Modify: `routes/web.php`, `resources/views/layouts/app.blade.php`
- Test: `tests/Feature/Admin/AccessControlTest.php` (add)

- [ ] **Step 1: Add tests**

```php
#[Test]
public function admin_can_open_dashboard(): void
{
    $admin = User::factory()->create(['status' => 'admin']);
    $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Administration');
}

#[Test]
public function regular_member_is_forbidden_from_dashboard(): void
{
    $user = User::factory()->create(['status' => 'enabled']);
    $this->actingAs($user)->get('/admin')->assertForbidden();
}

#[Test]
public function guest_dashboard_redirects_to_login(): void
{
    $this->get('/admin')->assertRedirect('/login');
}
```

- [ ] **Step 2: Run, verify fail** (404/no route).

- [ ] **Step 3a: DashboardController**

```php
<?php
declare(strict_types=1);
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function index(): View
    {
        return view('admin.dashboard');
    }
}
```

- [ ] **Step 3b: routes/web.php** — add after existing auth group:

```php
use App\Http\Controllers\Admin;

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function (): void {
    Route::middleware('can:admin.see-menu')->get('/', [Admin\DashboardController::class, 'index'])->name('dashboard');

    Route::middleware('can:admin.user')->group(function (): void {
        Route::resource('users', Admin\UserController::class)->except(['show']);
        Route::post('users/{user}/password', [Admin\UserController::class, 'password'])->name('users.password');
    });
    Route::middleware('can:admin.event')->group(function (): void {
        Route::resource('events', Admin\EventController::class)->except(['show']);
    });
    Route::middleware('can:admin.booking')->group(function (): void {
        Route::get('bookings', [Admin\BookingController::class, 'index'])->name('bookings.index');
        Route::get('bookings/{booking}', [Admin\BookingController::class, 'show'])->name('bookings.show');
        Route::delete('bookings/{booking}', [Admin\BookingController::class, 'destroy'])->name('bookings.destroy');
    });
    Route::middleware('can:admin.config')->group(function (): void {
        Route::get('config', [Admin\OptionController::class, 'edit'])->name('config.edit');
        Route::put('config', [Admin\OptionController::class, 'update'])->name('config.update');
    });
});
```

Note: resource routes for users/events reference controllers built in later tasks. To keep the suite green between tasks, add the route blocks for `users`/`events`/`bookings`/`config` only in their respective tasks. For Task 4, add ONLY the dashboard route + group; append the others in Tasks 5/8/10/12.

- [ ] **Step 3c: layouts/admin.blade.php** — minimal shell extending app layout or standalone. Standalone:

```blade
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Administration – @yield('admin-title', 'Übersicht')</title>
    <link rel="stylesheet" href="{{ asset('css/booking.css') }}">
</head>
<body>
<div class="page-shell">
    <header class="top-header"><div class="brand-title">Administration</div>
        <nav class="admin-nav">
            <a href="{{ route('admin.dashboard') }}" class="default-button">Übersicht</a>
            @can('admin.user')<a href="{{ route('admin.users.index') }}" class="default-button">Benutzer</a>@endcan
            @can('admin.event')<a href="{{ route('admin.events.index') }}" class="default-button">Veranstaltungen</a>@endcan
            @can('admin.booking')<a href="{{ route('admin.bookings.index') }}" class="default-button">Buchungen</a>@endcan
            @can('admin.config')<a href="{{ route('admin.config.edit') }}" class="default-button">Konfiguration</a>@endcan
            <a href="{{ route('calendar.index') }}" class="default-button">Zum Kalender</a>
        </nav>
    </header>
    @if(session('success'))<div class="success-message">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="error-message">{{ $errors->first() }}</div>@endif
    <main>@yield('admin-content')</main>
</div>
</body>
</html>
```

Note: `@can('admin.users.index')` route names are referenced before those routes exist. Guard the nav links with `@if(Route::has('admin.users.index'))` OR add nav links incrementally per task. Simplest: in Task 4 the nav contains only Übersicht + Kalender; add each section's link in its task.

- [ ] **Step 3d: admin/dashboard.blade.php**

```blade
@extends('layouts.admin')
@section('admin-title', 'Übersicht')
@section('admin-content')
    <h1>Administration</h1>
    <ul>
        @can('admin.user')<li><a href="{{ route('admin.users.index') }}">Benutzerverwaltung</a></li>@endcan
        @can('admin.event')<li><a href="{{ route('admin.events.index') }}">Veranstaltungen</a></li>@endcan
        @can('admin.booking')<li><a href="{{ route('admin.bookings.index') }}">Buchungen</a></li>@endcan
        @can('admin.config')<li><a href="{{ route('admin.config.edit') }}">Konfiguration</a></li>@endcan
    </ul>
@endsection
```

Note: same incremental-link caveat — only render links whose routes exist yet. Use `@if(Route::has(...))` wrappers to keep dashboard valid across tasks.

- [ ] **Step 3e: app.blade.php nav** — add inside the authenticated actions:

```blade
@can('admin.see-menu')
    <a href="{{ route('admin.dashboard') }}" class="default-button">Administration</a>
@endcan
```

- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit** — `feat(admin): dashboard, layout, nav, route group`

---

## Phase 2 — UserController (Task 5–7)

### Task 5: User list + access

**Files:**
- Create: `app/Http/Controllers/Admin/UserController.php`
- Create: `resources/views/admin/users/index.blade.php`
- Modify: `routes/web.php` (add users routes), admin nav (add Benutzer link)
- Test: `tests/Feature/Admin/UserManagementTest.php`

- [ ] **Step 1: Test**

```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\UserMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User { return User::factory()->create(['status' => 'admin']); }

    #[Test]
    public function index_lists_non_deleted_users(): void
    {
        $active  = User::factory()->create(['alias' => 'Aktiv Mitglied', 'status' => 'enabled']);
        $deleted = User::factory()->create(['alias' => 'Geloescht', 'status' => 'deleted']);

        $this->actingAs($this->admin())->get('/admin/users')
            ->assertOk()->assertSee('Aktiv Mitglied')->assertDontSee('Geloescht');
    }

    #[Test]
    public function assist_without_flag_is_forbidden(): void
    {
        $assist = User::factory()->create(['status' => 'assist']);
        $this->actingAs($assist)->get('/admin/users')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run, verify fail.**

- [ ] **Step 3a: UserController index**

```php
<?php
declare(strict_types=1);
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

final class UserController extends Controller
{
    public function index(): View
    {
        $users = User::where('status', '!=', 'deleted')->orderBy('alias')->get();
        return view('admin.users.index', compact('users'));
    }
}
```

- [ ] **Step 3b: routes** — uncomment/add the `users` resource block (Task 4 Step 3b).
- [ ] **Step 3c: admin/users/index.blade.php**

```blade
@extends('layouts.admin')
@section('admin-title', 'Benutzer')
@section('admin-content')
    <h1>Benutzer</h1>
    <a href="{{ route('admin.users.create') }}" class="default-button">Neuer Benutzer</a>
    <table class="booking-grid"><thead><tr><th>Name</th><th>E-Mail</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @foreach($users as $u)
        <tr><td>{{ $u->alias }}</td><td>{{ $u->email }}</td><td>{{ $u->status }}</td>
            <td><a href="{{ route('admin.users.edit', $u) }}">Bearbeiten</a></td></tr>
    @endforeach
    </tbody></table>
@endsection
```

Note: `User::getRouteKeyName()` defaults to `uid` (primary key) — route-model binding works on `uid`. Confirm/add `public function getRouteKeyName(): string { return 'uid'; }` to User if binding fails.

- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit** — `feat(admin): user list`

---

### Task 6: Create + store user (with password, profile meta, privileges)

**Files:**
- Modify: `app/Http/Controllers/Admin/UserController.php`
- Create: `resources/views/admin/users/create.blade.php`, `resources/views/admin/users/_form.blade.php`
- Test: `tests/Feature/Admin/UserManagementTest.php` (add)

- [ ] **Step 1: Tests**

```php
#[Test]
public function admin_can_create_user_with_password_profile_and_privileges(): void
{
    $this->actingAs($this->admin())->post('/admin/users', [
        'alias' => 'Neu Mitglied', 'email' => 'neu@example.com', 'status' => 'assist',
        'password' => 'geheim123', 'firstname' => 'Neu', 'phone' => '+43123',
        'privileges' => ['admin.user', 'calendar.see-data'],
    ])->assertRedirect(route('admin.users.index'));

    $user = User::where('email', 'neu@example.com')->firstOrFail();
    $this->assertSame('assist', $user->status);
    $this->assertTrue(Hash::check('geheim123', $user->pw));
    $this->assertEquals('Neu', $user->getMeta('firstname'));
    $this->assertTrue($user->can('admin.user'));
    $this->assertTrue($user->can('calendar.see-data'));
}

#[Test]
public function create_validates_unique_email_and_required_fields(): void
{
    User::factory()->create(['email' => 'dup@example.com']);
    $this->actingAs($this->admin())->post('/admin/users', [
        'alias' => '', 'email' => 'dup@example.com', 'status' => 'enabled',
    ])->assertSessionHasErrors(['alias', 'email']);
}
```

- [ ] **Step 2: Run, verify fail.**

- [ ] **Step 3a: controller create/store**

```php
public function create(): View
{
    return view('admin.users.create', ['privileges' => User::PRIVILEGES]);
}

public function store(Request $request): RedirectResponse
{
    $data = $request->validate([
        'alias'        => ['required', 'string', 'max:128'],
        'email'        => ['nullable', 'email', 'max:128', 'unique:bs_users,email'],
        'status'       => ['required', 'in:admin,assist,enabled,disabled'],
        'password'     => ['required', 'string', 'min:6'],
        'firstname'    => ['nullable', 'string', 'max:128'],
        'lastname'     => ['nullable', 'string', 'max:128'],
        'phone'        => ['nullable', 'string', 'max:64'],
        'privileges'   => ['array'],
        'privileges.*' => ['in:' . implode(',', User::PRIVILEGES)],
    ]);

    $user = User::create([
        'alias'   => $data['alias'],
        'email'   => $data['email'] ?? null,
        'status'  => $data['status'],
        'pw'      => Hash::make($data['password']),
        'created' => now(),
    ]);

    foreach (['firstname', 'lastname', 'phone'] as $field) {
        if (!empty($data[$field])) {
            $user->setMeta($field, $data[$field]);
        }
    }
    $user->syncPrivileges($data['privileges'] ?? []);

    return redirect()->route('admin.users.index')->with('success', 'Benutzer angelegt.');
}
```

- [ ] **Step 3b: views** — `create.blade.php` extends admin layout, includes `_form.blade.php` with fields: alias, email, status `<select>` (admin/assist/enabled/disabled), password, firstname, lastname, phone, and a checkbox per `$privileges` (`name="privileges[]" value="{{ $priv }}"`). Form posts to `route('admin.users.store')` with `@csrf`.

```blade
{{-- admin/users/_form.blade.php --}}
@csrf
<label>Name <input type="text" name="alias" value="{{ old('alias', $user->alias ?? '') }}"></label>
<label>E-Mail <input type="email" name="email" value="{{ old('email', $user->email ?? '') }}"></label>
<label>Status
  <select name="status">
    @foreach(['admin','assist','enabled','disabled'] as $s)
      <option value="{{ $s }}" @selected(old('status', $user->status ?? 'enabled') === $s)>{{ $s }}</option>
    @endforeach
  </select>
</label>
<label>Vorname <input type="text" name="firstname" value="{{ old('firstname', $profile['firstname'] ?? '') }}"></label>
<label>Nachname <input type="text" name="lastname" value="{{ old('lastname', $profile['lastname'] ?? '') }}"></label>
<label>Telefon <input type="text" name="phone" value="{{ old('phone', $profile['phone'] ?? '') }}"></label>
<fieldset><legend>Rechte (für assist)</legend>
  @foreach($privileges as $priv)
    <label><input type="checkbox" name="privileges[]" value="{{ $priv }}"
      @checked(in_array($priv, old('privileges', $granted ?? []), true))> {{ $priv }}</label>
  @endforeach
</fieldset>
```

```blade
{{-- admin/users/create.blade.php --}}
@extends('layouts.admin')
@section('admin-content')
<h1>Neuer Benutzer</h1>
<form method="POST" action="{{ route('admin.users.store') }}">@include('admin.users._form', ['privileges' => $privileges])
<button type="submit" class="default-button">Anlegen</button></form>
@endsection
```

- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit** — `feat(admin): create user`

---

### Task 7: Edit, update, password reset, soft-delete

**Files:**
- Modify: `app/Http/Controllers/Admin/UserController.php`
- Create: `resources/views/admin/users/edit.blade.php`
- Test: `tests/Feature/Admin/UserManagementTest.php` (add)

- [ ] **Step 1: Tests**

```php
#[Test]
public function admin_can_update_user_and_toggle_privileges(): void
{
    $u = User::factory()->create(['alias' => 'Alt', 'status' => 'assist']);
    $u->syncPrivileges(['admin.user']);

    $this->actingAs($this->admin())->put("/admin/users/{$u->uid}", [
        'alias' => 'Neu', 'email' => $u->email, 'status' => 'assist',
        'privileges' => ['calendar.see-data'],
    ])->assertRedirect(route('admin.users.index'));

    $u->refresh();
    $this->assertSame('Neu', $u->alias);
    $this->assertFalse($u->can('admin.user'));
    $this->assertTrue($u->can('calendar.see-data'));
}

#[Test]
public function admin_can_reset_password(): void
{
    $u = User::factory()->create();
    $this->actingAs($this->admin())->post("/admin/users/{$u->uid}/password", ['password' => 'neuespass1'])
        ->assertRedirect();
    $this->assertTrue(Hash::check('neuespass1', $u->fresh()->pw));
}

#[Test]
public function destroy_soft_deletes_user(): void
{
    $u = User::factory()->create(['status' => 'enabled']);
    $this->actingAs($this->admin())->delete("/admin/users/{$u->uid}")->assertRedirect(route('admin.users.index'));
    $this->assertSame('deleted', $u->fresh()->status);
}
```

- [ ] **Step 2: Run, verify fail.**

- [ ] **Step 3: Implement** controller methods:

```php
public function edit(User $user): View
{
    return view('admin.users.edit', [
        'user'       => $user,
        'privileges' => User::PRIVILEGES,
        'granted'    => $user->grantedPrivileges(),
        'profile'    => [
            'firstname' => $user->getMeta('firstname'),
            'lastname'  => $user->getMeta('lastname'),
            'phone'     => $user->getMeta('phone'),
        ],
    ]);
}

public function update(Request $request, User $user): RedirectResponse
{
    $data = $request->validate([
        'alias'        => ['required', 'string', 'max:128'],
        'email'        => ['nullable', 'email', 'max:128', 'unique:bs_users,email,' . $user->uid . ',uid'],
        'status'       => ['required', 'in:admin,assist,enabled,disabled'],
        'firstname'    => ['nullable', 'string', 'max:128'],
        'lastname'     => ['nullable', 'string', 'max:128'],
        'phone'        => ['nullable', 'string', 'max:64'],
        'privileges'   => ['array'],
        'privileges.*' => ['in:' . implode(',', User::PRIVILEGES)],
    ]);

    $user->update(['alias' => $data['alias'], 'email' => $data['email'] ?? null, 'status' => $data['status']]);
    foreach (['firstname', 'lastname', 'phone'] as $field) {
        $user->setMeta($field, $data[$field] ?? null);
    }
    $user->syncPrivileges($data['privileges'] ?? []);

    return redirect()->route('admin.users.index')->with('success', 'Benutzer aktualisiert.');
}

public function password(Request $request, User $user): RedirectResponse
{
    $request->validate(['password' => ['required', 'string', 'min:6']]);
    $user->update(['pw' => Hash::make($request->string('password')->value())]);
    return back()->with('success', 'Passwort zurückgesetzt.');
}

public function destroy(User $user): RedirectResponse
{
    $user->update(['status' => 'deleted']);
    return redirect()->route('admin.users.index')->with('success', 'Benutzer gelöscht.');
}
```

- [ ] **Step 3b: edit.blade.php** — like create, but `@method('PUT')`, action `route('admin.users.update', $user)`, prefilled via `$user`/`$profile`/`$granted`; plus a separate password-reset form posting to `route('admin.users.password', $user)`.

- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit** — `feat(admin): edit/update/password/soft-delete user`

---

## Phase 3 — EventController (Task 8–9)

### Task 8: Event list + create/store

**Files:**
- Create: `app/Http/Controllers/Admin/EventController.php`
- Create: `resources/views/admin/events/{index,create,_form}.blade.php`
- Modify: `routes/web.php` (events block), admin nav
- Test: `tests/Feature/Admin/EventManagementTest.php`

- [ ] **Step 1: Tests**

```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Admin;

use App\Models\Event;
use App\Models\Square;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventManagementTest extends TestCase
{
    use RefreshDatabase;
    private function admin(): User { return User::factory()->create(['status' => 'admin']); }

    #[Test]
    public function admin_can_create_event_for_a_square(): void
    {
        $square = Square::factory()->create();
        $this->actingAs($this->admin())->post('/admin/events', [
            'sid' => $square->sid, 'name' => 'Stadtmeisterschaft', 'status' => 'enabled',
            'datetime_start' => '2026-07-01 10:00', 'datetime_end' => '2026-07-01 18:00',
        ])->assertRedirect(route('admin.events.index'));

        $event = Event::firstOrFail();
        $this->assertSame((int) $square->sid, (int) $event->sid);
        $this->assertEquals('Stadtmeisterschaft', $event->meta()->where('key', 'name')->value('value'));
    }

    #[Test]
    public function event_create_allows_all_squares_when_sid_blank(): void
    {
        $this->actingAs($this->admin())->post('/admin/events', [
            'sid' => '', 'name' => 'Wartung', 'status' => 'enabled',
            'datetime_start' => '2026-07-01 10:00', 'datetime_end' => '2026-07-01 12:00',
        ])->assertRedirect();
        $this->assertNull(Event::firstOrFail()->sid);
    }
}
```

- [ ] **Step 2: Run, verify fail.**

- [ ] **Step 3: Implement** EventController (`index`, `create`, `store`):

```php
<?php
declare(strict_types=1);
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Square;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class EventController extends Controller
{
    public function index(): View
    {
        $events = Event::with('meta', 'square')->orderByDesc('datetime_start')->get();
        return view('admin.events.index', compact('events'));
    }

    public function create(): View
    {
        return view('admin.events.create', ['squares' => Square::orderBy('priority')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateEvent($request);
        $event = Event::create([
            'sid'            => $data['sid'] !== '' ? (int) $data['sid'] : null,
            'status'         => $data['status'],
            'datetime_start' => $data['datetime_start'],
            'datetime_end'   => $data['datetime_end'],
            'capacity'       => null,
        ]);
        if (!empty($data['name'])) {
            $event->meta()->create(['key' => 'name', 'value' => $data['name']]);
        }
        return redirect()->route('admin.events.index')->with('success', 'Veranstaltung angelegt.');
    }

    private function validateEvent(Request $request): array
    {
        return $request->validate([
            'sid'            => ['nullable'],
            'name'           => ['nullable', 'string', 'max:128'],
            'status'         => ['required', 'in:enabled,disabled'],
            'datetime_start' => ['required', 'date'],
            'datetime_end'   => ['required', 'date', 'after:datetime_start'],
        ]);
    }
}
```

Note: `sid` validation `nullable` accepts ''. The `exists` check is intentionally omitted to allow '' (all squares); if non-empty, the FK will simply reference a square — optionally harden later.

- [ ] **Step 3b: views** — index (table: name via `$event->meta->firstWhere('key','name')?->value`, square name or "Alle", start/end, edit/delete links); create with `_form` (sid `<select>` incl. blank "Alle Plätze", name, status, datetime-local inputs).

- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit** — `feat(admin): event list + create`

---

### Task 9: Event edit/update/delete

**Files:**
- Modify: `app/Http/Controllers/Admin/EventController.php`
- Create: `resources/views/admin/events/edit.blade.php`
- Test: `tests/Feature/Admin/EventManagementTest.php` (add)

- [ ] **Step 1: Tests**

```php
#[Test]
public function admin_can_update_event_name(): void
{
    $event = Event::factory()->create(['status' => 'enabled']);
    $event->meta()->create(['key' => 'name', 'value' => 'Alt']);
    $this->actingAs($this->admin())->put("/admin/events/{$event->eid}", [
        'sid' => $event->sid, 'name' => 'Neu', 'status' => 'enabled',
        'datetime_start' => '2026-07-01 10:00', 'datetime_end' => '2026-07-01 12:00',
    ])->assertRedirect(route('admin.events.index'));
    $this->assertEquals('Neu', $event->fresh()->meta()->where('key', 'name')->value('value'));
}

#[Test]
public function admin_can_delete_event(): void
{
    $event = Event::factory()->create();
    $this->actingAs($this->admin())->delete("/admin/events/{$event->eid}")->assertRedirect(route('admin.events.index'));
    $this->assertDatabaseMissing('bs_events', ['eid' => $event->eid]);
}
```

- [ ] **Step 2: Run, verify fail.**

- [ ] **Step 3: Implement** `edit`, `update`, `destroy`:

```php
public function edit(Event $event): View
{
    return view('admin.events.edit', [
        'event'   => $event,
        'squares' => Square::orderBy('priority')->get(),
        'name'    => $event->meta()->where('key', 'name')->value('value'),
    ]);
}

public function update(Request $request, Event $event): RedirectResponse
{
    $data = $this->validateEvent($request);
    $event->update([
        'sid'            => $data['sid'] !== '' ? (int) $data['sid'] : null,
        'status'         => $data['status'],
        'datetime_start' => $data['datetime_start'],
        'datetime_end'   => $data['datetime_end'],
    ]);
    $nameRow = $event->meta()->where('key', 'name')->first();
    if (!empty($data['name'])) {
        $nameRow ? $nameRow->update(['value' => $data['name']]) : $event->meta()->create(['key' => 'name', 'value' => $data['name']]);
    } elseif ($nameRow) {
        $nameRow->delete();
    }
    return redirect()->route('admin.events.index')->with('success', 'Veranstaltung aktualisiert.');
}

public function destroy(Event $event): RedirectResponse
{
    $event->meta()->delete();
    $event->delete();
    return redirect()->route('admin.events.index')->with('success', 'Veranstaltung gelöscht.');
}
```

Note: `Event` needs `getRouteKeyName()` returning `eid`. Add to Event model if binding fails: `public function getRouteKeyName(): string { return 'eid'; }`.

- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit** — `feat(admin): event edit/update/delete`

---

## Phase 4 — Admin BookingController (Task 10)

### Task 10: Bookings list + cancel

**Files:**
- Create: `app/Http/Controllers/Admin/BookingController.php`
- Create: `resources/views/admin/bookings/{index,show}.blade.php`
- Modify: `routes/web.php` (bookings block), admin nav, `app/Services/BookingService.php` (reuse `cancelSingle`)
- Test: `tests/Feature/Admin/AdminBookingTest.php`

- [ ] **Step 1: Tests**

```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Admin;

use App\Models\Booking;
use App\Models\Reservation;
use App\Models\Square;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminBookingTest extends TestCase
{
    use RefreshDatabase;
    private function admin(): User { return User::factory()->create(['status' => 'admin']); }

    #[Test]
    public function index_lists_active_bookings(): void
    {
        $owner = User::factory()->create(['alias' => 'Bucher Mitglied']);
        $b = Booking::factory()->create(['uid' => $owner->uid, 'status' => 'single']);
        Reservation::factory()->create(['bid' => $b->bid, 'date' => Carbon::today()->toDateString()]);

        $this->actingAs($this->admin())->get('/admin/bookings')
            ->assertOk()->assertSee('Bucher Mitglied');
    }

    #[Test]
    public function admin_can_cancel_any_booking(): void
    {
        $b = Booking::factory()->create(['status' => 'single']);
        $this->actingAs($this->admin())->delete("/admin/bookings/{$b->bid}")->assertRedirect();
        $this->assertSame('cancelled', $b->fresh()->status);
    }
}
```

- [ ] **Step 2: Run, verify fail.**

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Square;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class BookingController extends Controller
{
    public function __construct(private readonly BookingService $bookingService) {}

    public function index(Request $request): View
    {
        $query = Booking::with(['user', 'square', 'reservations'])
            ->whereIn('status', Booking::ACTIVE_STATUSES);

        if ($request->filled('sid'))  { $query->where('sid', (int) $request->input('sid')); }
        if ($request->filled('uid'))  { $query->where('uid', (int) $request->input('uid')); }

        $bookings = $query->orderByDesc('bid')->paginate(50);
        $squares  = Square::orderBy('priority')->get();
        return view('admin.bookings.index', compact('bookings', 'squares'));
    }

    public function show(Booking $booking): View
    {
        $booking->load(['user', 'square', 'reservations', 'meta']);
        return view('admin.bookings.show', compact('booking'));
    }

    public function destroy(Booking $booking): RedirectResponse
    {
        $this->bookingService->cancelSingle($booking);
        return redirect()->route('admin.bookings.index')->with('success', 'Buchung storniert.');
    }
}
```

- [ ] **Step 3b: views** — index table (alias, square display_name, first reservation date/time, status, links to show + a delete form). show: booking detail + reservations + cancel button.

- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit** — `feat(admin): bookings list + cancel`

---

## Phase 5 — OptionController (Task 11)

### Task 11: Curated config form

**Files:**
- Create: `app/Http/Controllers/Admin/OptionController.php`
- Create: `resources/views/admin/config/edit.blade.php`
- Modify: `routes/web.php` (config block), admin nav
- Test: `tests/Feature/Admin/ConfigTest.php`

- [ ] **Step 1: Tests**

```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Admin;

use App\Models\Option;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConfigTest extends TestCase
{
    use RefreshDatabase;
    private function admin(): User { return User::factory()->create(['status' => 'admin']); }

    #[Test]
    public function edit_shows_current_values(): void
    {
        Option::create(['key' => 'client.name.full', 'value' => 'TC Bewegung', 'locale' => null]);
        $this->actingAs($this->admin())->get('/admin/config')->assertOk()->assertSee('TC Bewegung');
    }

    #[Test]
    public function update_writes_default_locale_rows(): void
    {
        $this->actingAs($this->admin())->put('/admin/config', [
            'client_name_full' => 'Neuer Name',
            'contact_email'    => 'info@example.com',
            'calendar_days'    => '5',
            'registration'     => '1',
            'maintenance'      => '0',
        ])->assertRedirect();

        $this->assertSame('Neuer Name', Option::getValue('client.name.full'));
        $this->assertSame('5', Option::getValue('service.calendar.days'));
    }
}
```

- [ ] **Step 2: Run, verify fail.**

- [ ] **Step 3: Implement** — controller with an explicit field↔key map:

```php
<?php
declare(strict_types=1);
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Option;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class OptionController extends Controller
{
    /** form field => option key */
    private const MAP = [
        'client_name_full' => 'client.name.full',
        'contact_email'    => 'client.contact.email',
        'calendar_days'    => 'service.calendar.days',
        'registration'     => 'service.user.registration',
        'maintenance'      => 'service.maintenance',
    ];

    public function edit(): View
    {
        $values = [];
        foreach (self::MAP as $field => $key) {
            $values[$field] = Option::getValue($key, '');
        }
        return view('admin.config.edit', compact('values'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_name_full' => ['nullable', 'string', 'max:255'],
            'contact_email'    => ['nullable', 'email', 'max:128'],
            'calendar_days'    => ['nullable', 'integer', 'min:1', 'max:31'],
            'registration'     => ['nullable', 'in:0,1'],
            'maintenance'      => ['nullable', 'in:0,1'],
        ]);

        foreach (self::MAP as $field => $key) {
            if (!array_key_exists($field, $data) || $data[$field] === null) { continue; }
            $row = Option::where('key', $key)->whereNull('locale')->first();
            $row ? $row->update(['value' => (string) $data[$field]])
                 : Option::create(['key' => $key, 'value' => (string) $data[$field], 'locale' => null]);
        }
        return redirect()->route('admin.config.edit')->with('success', 'Konfiguration gespeichert.');
    }
}
```

- [ ] **Step 3b: edit.blade.php** — labelled inputs for the 5 fields (text, email, number, two selects 0/1), PUT to `route('admin.config.update')`.

- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit** — `feat(admin): curated config form`

---

## Phase 6 — Calendar admin integration (Task 12–14)

### Task 12: Cancel any booking from the calendar

**Files:**
- Modify: `app/Http/Controllers/BookingController.php` (`destroy`)
- Test: `tests/Feature/BookingControllerTest.php` (add)

- [ ] **Step 1: Tests**

```php
#[Test]
public function admin_can_cancel_another_users_booking_from_calendar(): void
{
    $owner = \App\Models\User::factory()->create();
    $admin = \App\Models\User::factory()->create(['status' => 'admin']);
    $booking = \App\Models\Booking::factory()->create(['uid' => $owner->uid, 'status' => 'single']);

    $this->actingAs($admin)->delete("/bookings/{$booking->bid}")->assertRedirect();
    $this->assertSame('cancelled', $booking->fresh()->status);
}

#[Test]
public function regular_member_still_cannot_cancel_others_booking(): void
{
    $owner = \App\Models\User::factory()->create();
    $other = \App\Models\User::factory()->create(['status' => 'enabled']);
    $booking = \App\Models\Booking::factory()->create(['uid' => $owner->uid]);

    $this->actingAs($other)->delete("/bookings/{$booking->bid}")->assertForbidden();
}
```

- [ ] **Step 2: Run, verify fail** (regular non-owner currently 403 — passes; admin currently 403 — fails).

- [ ] **Step 3: Implement** — change `BookingController@destroy`:

```php
public function destroy(Booking $booking): RedirectResponse
{
    $user = auth()->user();
    $privilege = $booking->isSubscription()
        ? 'calendar.cancel-subscription-bookings'
        : 'calendar.cancel-single-bookings';

    if ($booking->uid !== $user->uid && !$user->can($privilege)) {
        abort(403);
    }

    $this->bookingService->cancelSingle($booking);
    return redirect()->route('calendar.index')->with('success', 'Buchung storniert.');
}
```

- [ ] **Step 4: Run, verify pass** (full `BookingControllerTest`).
- [ ] **Step 5: Commit** — `feat(calendar): admins can cancel any booking`

---

### Task 13: see-data / see-past name gating in calendar

**Files:**
- Modify: `resources/views/calendar/index.blade.php` (occupied-cell name branch)
- Test: `tests/Feature/CalendarControllerTest.php` (add)

- [ ] **Step 1: Tests**

```php
#[Test]
public function regular_member_does_not_see_other_members_name(): void
{
    $owner  = User::factory()->create(['alias' => 'Fremd Mitglied']);
    $square = Square::factory()->create();
    $booking = Booking::factory()->create(['uid' => $owner->uid, 'sid' => $square->sid, 'status' => 'single']);
    Reservation::factory()->create(['bid' => $booking->bid, 'date' => Carbon::today()->toDateString(),
        'time_start' => '10:00:00', 'time_end' => '11:00:00']);

    $viewer = User::factory()->create(['status' => 'enabled']);
    $this->actingAs($viewer)->get('/calendar?date=' . Carbon::today()->toDateString())
        ->assertOk()->assertDontSee('Fremd Mitglied');
}

#[Test]
public function admin_with_see_data_sees_other_members_name(): void
{
    $owner  = User::factory()->create(['alias' => 'Fremd Mitglied']);
    $square = Square::factory()->create();
    $booking = Booking::factory()->create(['uid' => $owner->uid, 'sid' => $square->sid, 'status' => 'single']);
    Reservation::factory()->create(['bid' => $booking->bid, 'date' => Carbon::today()->toDateString(),
        'time_start' => '10:00:00', 'time_end' => '11:00:00']);

    $admin = User::factory()->create(['status' => 'admin']);
    $this->actingAs($admin)->get('/calendar?date=' . Carbon::today()->toDateString())
        ->assertOk()->assertSee('Fremd Mitglied');
}
```

Note: This replaces the current behavior where any logged-in user sees names. Update `calendar_shows_booking_owner_name_for_authenticated_user` to use an admin viewer (or own booking), since plain members no longer see foreign names.

- [ ] **Step 2: Run, verify fail** (regular member currently sees the name).

- [ ] **Step 3: Implement** — in the occupied (non-own) `else` branch of the cell logic, gate the name:

```blade
} else {
    $cellClass = 'cc-single-future';
    $canSeeData = auth()->check()
        && (auth()->user()->can('calendar.see-data')
            || $square->getMeta('public_names') === 'true');
    $primaryLabel = $canSeeData
        ? ($reservation->booking?->user?->name ?? 'Belegt')
        : '';
}
```

Also gate `$secondaryLabel` (player names) on the same `$canSeeData` (owner always sees own — keep the existing `$isOwn` branch showing names). For past slots with reservations, only render the name when `auth()->user()?->can('calendar.see-past')` (see-data implies admins).

- [ ] **Step 4: Run, verify pass** (full `CalendarControllerTest`).
- [ ] **Step 5: Commit** — `feat(calendar): gate name visibility behind see-data`

---

### Task 14: Book on behalf of a member

**Files:**
- Modify: `app/Http/Controllers/BookingController.php` (`create`, `store`)
- Modify: `resources/views/bookings/create.blade.php` (member select for privileged users)
- Test: `tests/Feature/BookingControllerTest.php` (add)

- [ ] **Step 1: Tests**

```php
#[Test]
public function admin_can_book_for_another_member(): void
{
    $admin  = \App\Models\User::factory()->create(['status' => 'admin']);
    $member = \App\Models\User::factory()->create(['status' => 'enabled']);
    $square = \App\Models\Square::factory()->create(['status' => 'enabled', 'time_block_bookable_max' => 0, 'range_book' => 0]);

    $this->actingAs($admin)->post('/bookings', [
        'sid' => $square->sid, 'date' => '2026-07-10', 'time_start' => '10:00', 'time_end' => '11:00',
        'quantity' => 2, 'for_uid' => $member->uid,
    ])->assertRedirect();

    $this->assertDatabaseHas('bs_bookings', ['uid' => $member->uid, 'sid' => $square->sid]);
}

#[Test]
public function regular_member_cannot_book_for_others(): void
{
    $member = \App\Models\User::factory()->create(['status' => 'enabled']);
    $victim = \App\Models\User::factory()->create();
    $square = \App\Models\Square::factory()->create(['status' => 'enabled', 'time_block_bookable_max' => 0, 'range_book' => 0]);

    $this->actingAs($member)->post('/bookings', [
        'sid' => $square->sid, 'date' => '2026-07-10', 'time_start' => '10:00', 'time_end' => '11:00',
        'quantity' => 2, 'for_uid' => $victim->uid,
    ])->assertRedirect();

    // for_uid ignored for non-privileged users → booking belongs to the actor
    $this->assertDatabaseHas('bs_bookings', ['uid' => $member->uid]);
    $this->assertDatabaseMissing('bs_bookings', ['uid' => $victim->uid]);
}
```

- [ ] **Step 2: Run, verify fail.**

- [ ] **Step 3: Implement** — in `BookingController@store`, resolve the booking owner:

```php
// after $data validation, add 'for_uid' => ['nullable','integer'] to the rules
$actor = auth()->user();
$owner = $actor;
if (!empty($data['for_uid']) && $actor->can('calendar.create-single-bookings')) {
    $candidate = \App\Models\User::where('uid', (int) $data['for_uid'])->where('status', '!=', 'deleted')->first();
    if ($candidate) { $owner = $candidate; }
}
// ... use $owner instead of auth()->user() in createSingle(...)
$this->bookingService->createSingle($owner, $square, (int) $data['quantity'], $dateStart, $dateEnd);
```

In `create()`, pass `$members = User::where('status','!=','deleted')->orderBy('alias')->get()` only when `auth()->user()->can('calendar.create-single-bookings')`, and render an optional `<select name="for_uid">` in the view (blank = self).

- [ ] **Step 4: Run, verify pass.**
- [ ] **Step 5: Commit** — `feat(calendar): admins can book on behalf of a member`

---

## Self-Review notes

- Spec coverage: access control (T1), see-menu/nav (T4), user CRUD+privileges+password+soft-delete (T5-7), events CRUD (T8-9), admin bookings list+cancel (T10), config (T11), cancel-any (T12), see-data/see-past gating (T13), book-for-member (T14). All spec sections covered.
- Route-name forward references in nav/dashboard are handled by adding each section's routes in its own task and guarding nav links (`@can` only renders when the user has the privilege; wrap `route()` links that may not exist yet with `@if(Route::has(...))` until all routes are added, or keep nav minimal until Phase 5 completes).
- Route-model binding: add `getRouteKeyName()` returning the PK to `User` (`uid`) and `Event` (`eid`) if not already present (Booking already has `bid`).
- Consistency: `User::can()`, `setMeta`, `syncPrivileges`, `grantedPrivileges`, `PRIVILEGES`, `Booking::ACTIVE_STATUSES`, `Square::getDisplayName`/`getMeta` are used consistently across tasks.
