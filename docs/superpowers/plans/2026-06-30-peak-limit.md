# Peak-Zeit-Buchungslimit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Buchungen während konfigurierbarer Stoßzeiten zählen gegen `max_active_bookings`; außerhalb gilt kein Limit.

**Architecture:** Ein neuer `PeakLimitService` liest die Peak-Konfiguration aus `bs_options` und prüft ob eine Zeit in ein Peak-Fenster fällt. Der `SquareValidator` nutzt diesen Service um die Buchungsprüfung zu verzweigen. Admin-Einstellungen steuern die globale Konfiguration; pro Platz gibt es ein `peak_limit_enabled` Meta-Flag.

**Tech Stack:** Laravel 11, PHP 8.2, PHPUnit, Blade, MySQL (`bs_options`, `bs_squares_meta`, `bs_reservations`)

---

## File Map

| Datei | Aktion | Zweck |
|-------|--------|-------|
| `config/booking.php` | Modify | Peak-Limit Defaults hinzufügen |
| `lang/de/booking.php` | Modify | `peak_limit_reached` Fehlermeldung |
| `app/Services/PeakLimitService.php` | **Create** | Peak-Fenster lesen + `isPeakTime()` |
| `app/Http/Controllers/Admin/OptionController.php` | Modify | 5 Peak-Keys in MAP + Validation |
| `resources/views/admin/config/edit.blade.php` | Modify | Stoßzeiten-Sektion im Admin-Formular |
| `app/Http/Controllers/Admin/SquareController.php` | Modify | `peak_limit_enabled` in buildPayload/toForm/defaults |
| `resources/views/admin/squares/_form.blade.php` | Modify | Checkbox Stoßzeiten-Limit |
| `app/Services/SquareValidator.php` | Modify | PeakLimitService injizieren + Logik verzweigen |
| `tests/Unit/Services/PeakLimitServiceTest.php` | **Create** | Unit-Tests für PeakLimitService |
| `tests/Unit/Services/SquareValidatorTest.php` | Modify | Tests für Peak-Limit-Prüfung |

---

## Task 1: Config-Defaults und Fehlermeldung

**Files:**
- Modify: `config/booking.php`
- Modify: `lang/de/booking.php`

- [ ] **Schritt 1: Peak-Defaults in config/booking.php einfügen**

Datei öffnen und den bestehenden Inhalt durch folgenden ersetzen:

```php
<?php

declare(strict_types=1);

return [
    'name' => env('BOOKING_NAME', env('APP_NAME', 'Tennis-Booking')),
    'logo_path' => env('BOOKING_LOGO_PATH', 'imgs-client/layout/client-logo.jpg'),
    'logo_width' => (int) env('BOOKING_LOGO_WIDTH', 112),
    'logo_height' => (int) env('BOOKING_LOGO_HEIGHT', 108),
    'square_names' => [
        '1' => env('BOOKING_SQUARE_1_NAME', 'Platz1'),
        '2' => env('BOOKING_SQUARE_2_NAME', 'Platz2'),
        '3' => env('BOOKING_SQUARE_3_NAME', 'Platz3'),
    ],
    'peak_limit' => [
        'window_1_start' => '08:00',
        'window_1_end'   => '12:00',
        'window_2_start' => '17:00',
        'window_2_end'   => '21:00',
    ],
];
```

- [ ] **Schritt 2: Fehlermeldung in lang/de/booking.php einfügen**

Im `messages`-Array (nach `max_active_bookings_reached`) folgende Zeile ergänzen:

```php
'peak_limit_reached' => 'Du hast das Buchungslimit für Stoßzeiten erreicht.',
```

- [ ] **Schritt 3: Committen**

```bash
git add config/booking.php lang/de/booking.php
git commit -m "feat(peak-limit): Config-Defaults und Fehlermeldung"
```

---

## Task 2: PeakLimitService

**Files:**
- Create: `app/Services/PeakLimitService.php`
- Create: `tests/Unit/Services/PeakLimitServiceTest.php`

- [ ] **Schritt 1: Failing Test schreiben**

Neue Datei `tests/Unit/Services/PeakLimitServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Option;
use App\Services\PeakLimitService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PeakLimitServiceTest extends TestCase
{
    use RefreshDatabase;

    private PeakLimitService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PeakLimitService;
    }

    #[Test]
    public function is_disabled_by_default(): void
    {
        $this->assertFalse($this->service->isEnabled());
    }

    #[Test]
    public function is_enabled_when_option_set(): void
    {
        Option::create(['key' => 'peak_limit.enabled', 'value' => '1', 'locale' => null]);

        $this->assertTrue($this->service->isEnabled());
    }

    #[Test]
    public function morning_window_is_peak(): void
    {
        $this->assertTrue($this->service->isPeakTime(Carbon::today()->setTime(8, 0)));
        $this->assertTrue($this->service->isPeakTime(Carbon::today()->setTime(11, 30)));
    }

    #[Test]
    public function morning_window_end_is_not_peak(): void
    {
        $this->assertFalse($this->service->isPeakTime(Carbon::today()->setTime(12, 0)));
    }

    #[Test]
    public function evening_window_is_peak(): void
    {
        $this->assertTrue($this->service->isPeakTime(Carbon::today()->setTime(17, 0)));
        $this->assertTrue($this->service->isPeakTime(Carbon::today()->setTime(20, 0)));
    }

    #[Test]
    public function evening_window_end_is_not_peak(): void
    {
        $this->assertFalse($this->service->isPeakTime(Carbon::today()->setTime(21, 0)));
    }

    #[Test]
    public function midday_off_peak_is_not_peak(): void
    {
        $this->assertFalse($this->service->isPeakTime(Carbon::today()->setTime(13, 0)));
    }

    #[Test]
    public function custom_windows_from_options_are_respected(): void
    {
        Option::create(['key' => 'peak_limit.window_1_start', 'value' => '09:00', 'locale' => null]);
        Option::create(['key' => 'peak_limit.window_1_end',   'value' => '11:00', 'locale' => null]);

        $this->assertTrue($this->service->isPeakTime(Carbon::today()->setTime(10, 0)));
        $this->assertFalse($this->service->isPeakTime(Carbon::today()->setTime(8, 0)));
    }
}
```

- [ ] **Schritt 2: Test ausführen — muss FEHLSCHLAGEN**

```bash
php artisan test tests/Unit/Services/PeakLimitServiceTest.php
```

Erwartetes Ergebnis: `Error: Class "App\Services\PeakLimitService" not found`

- [ ] **Schritt 3: PeakLimitService implementieren**

Neue Datei `app/Services/PeakLimitService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Option;
use Carbon\Carbon;

final class PeakLimitService
{
    public function isEnabled(): bool
    {
        return Option::getValue('peak_limit.enabled', '0') === '1';
    }

    /** @return list<array{start: string, end: string}> */
    public function windows(): array
    {
        return [
            [
                'start' => Option::getValue('peak_limit.window_1_start', config('booking.peak_limit.window_1_start', '08:00')),
                'end'   => Option::getValue('peak_limit.window_1_end',   config('booking.peak_limit.window_1_end',   '12:00')),
            ],
            [
                'start' => Option::getValue('peak_limit.window_2_start', config('booking.peak_limit.window_2_start', '17:00')),
                'end'   => Option::getValue('peak_limit.window_2_end',   config('booking.peak_limit.window_2_end',   '21:00')),
            ],
        ];
    }

    public function isPeakTime(Carbon $dateStart): bool
    {
        $hhmm = $dateStart->format('H:i');
        foreach ($this->windows() as $window) {
            if ($hhmm >= $window['start'] && $hhmm < $window['end']) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Schritt 4: Tests ausführen — müssen BESTEHEN**

```bash
php artisan test tests/Unit/Services/PeakLimitServiceTest.php
```

Erwartetes Ergebnis: alle Tests grün.

- [ ] **Schritt 5: Committen**

```bash
git add app/Services/PeakLimitService.php tests/Unit/Services/PeakLimitServiceTest.php
git commit -m "feat(peak-limit): PeakLimitService mit Tests"
```

---

## Task 3: SquareValidator — Peak-Logik

**Files:**
- Modify: `app/Services/SquareValidator.php`
- Modify: `tests/Unit/Services/SquareValidatorTest.php`

- [ ] **Schritt 1: Failing Tests schreiben**

Am Ende von `tests/Unit/Services/SquareValidatorTest.php` folgende Tests ergänzen (nach dem letzten `#[Test]`-Block, vor der schließenden `}`):

```php
    #[Test]
    public function peak_limit_blocks_booking_during_peak_when_limit_reached(): void
    {
        Option::create(['key' => 'peak_limit.enabled', 'value' => '1', 'locale' => null]);

        $square = Square::factory()->create(['max_active_bookings' => 1, 'time_block_bookable_max' => 0, 'range_book' => 0]);
        $square->setMeta('peak_limit_enabled', '1');

        $user = User::factory()->create(['status' => 'enabled']);

        // Bestehende Peak-Buchung anlegen (10:00, morgen)
        $existingBooking = Booking::factory()->create(['uid' => $user->uid, 'sid' => $square->sid, 'status' => 'single']);
        $reservation = Reservation::factory()->create([
            'bid' => $existingBooking->bid,
            'date' => Carbon::tomorrow()->toDateString(),
            'time_start' => '10:00:00',
            'time_end' => '11:00:00',
        ]);

        // Neue Buchung ebenfalls Peak (10:00, übermorgen)
        $result = $this->validator->validate(
            $square, $user, 2,
            Carbon::now()->addDays(2)->setTime(10, 0),
            Carbon::now()->addDays(2)->setTime(11, 0),
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Stoßzeiten', $result->getError());
    }

    #[Test]
    public function peak_limit_allows_off_peak_booking_when_peak_limit_reached(): void
    {
        Option::create(['key' => 'peak_limit.enabled', 'value' => '1', 'locale' => null]);

        $square = Square::factory()->create(['max_active_bookings' => 1, 'time_block_bookable_max' => 0, 'range_book' => 0]);
        $square->setMeta('peak_limit_enabled', '1');

        $user = User::factory()->create(['status' => 'enabled']);

        // Bestehende Peak-Buchung anlegen
        $existingBooking = Booking::factory()->create(['uid' => $user->uid, 'sid' => $square->sid, 'status' => 'single']);
        Reservation::factory()->create([
            'bid' => $existingBooking->bid,
            'date' => Carbon::tomorrow()->toDateString(),
            'time_start' => '10:00:00',
            'time_end' => '11:00:00',
        ]);

        // Neue Buchung Off-Peak (13:00) → soll erlaubt sein
        $result = $this->validator->validate(
            $square, $user, 2,
            Carbon::now()->addDays(2)->setTime(13, 0),
            Carbon::now()->addDays(2)->setTime(14, 0),
        );

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function peak_limit_inactive_square_uses_global_limit_as_before(): void
    {
        Option::create(['key' => 'peak_limit.enabled', 'value' => '1', 'locale' => null]);

        // peak_limit_enabled NICHT gesetzt auf diesem Platz
        $square = Square::factory()->create(['max_active_bookings' => 1, 'time_block_bookable_max' => 0, 'range_book' => 0]);

        $user = User::factory()->create(['status' => 'enabled']);

        // Eine beliebige aktive Buchung (Off-Peak)
        $existingBooking = Booking::factory()->create(['uid' => $user->uid, 'sid' => $square->sid, 'status' => 'single']);
        Reservation::factory()->create([
            'bid' => $existingBooking->bid,
            'date' => Carbon::tomorrow()->toDateString(),
            'time_start' => '13:00:00',
            'time_end' => '14:00:00',
        ]);

        // Neue Off-Peak Buchung → globales Limit greift, soll geblockt sein
        $result = $this->validator->validate(
            $square, $user, 2,
            Carbon::now()->addDays(2)->setTime(13, 0),
            Carbon::now()->addDays(2)->setTime(14, 0),
        );

        $this->assertFalse($result->isValid());
    }
```

Außerdem `use App\Models\Option;` am Anfang der Datei ergänzen (falls noch nicht vorhanden).

- [ ] **Schritt 2: Tests ausführen — müssen FEHLSCHLAGEN**

```bash
php artisan test tests/Unit/Services/SquareValidatorTest.php --filter peak
```

Erwartetes Ergebnis: Fehler weil `SquareValidator` noch kein `PeakLimitService` kennt.

- [ ] **Schritt 3: SquareValidator anpassen**

In `app/Services/SquareValidator.php`:

**3a — Constructor ergänzen** (nach `final class SquareValidator`):

```php
    public function __construct(
        private readonly PeakLimitService $peakLimitService = new PeakLimitService,
    ) {}
```

**3b — `use`-Import ergänzen** (oben bei den anderen `use`-Statements):

```php
use App\Services\PeakLimitService;
```

**3c — Block bei Zeile 84 ersetzen** (aktuell):

```php
        if ((int) $square->max_active_bookings > 0
            && ! $shortTermOverrideActive
            && $this->getActiveFutureBookingCount($user) >= (int) $square->max_active_bookings) {
            return ValidationResult::fail(__('booking.messages.max_active_bookings_reached'));
        }
```

Ersetzen durch:

```php
        if ((int) $square->max_active_bookings > 0 && ! $shortTermOverrideActive) {
            $peakActive = $this->peakLimitService->isEnabled()
                && $square->getMeta('peak_limit_enabled') === '1';

            if ($peakActive) {
                if ($this->peakLimitService->isPeakTime($dateStart)) {
                    $count = $this->getPeakActiveFutureBookingCount(
                        $user,
                        $this->peakLimitService->windows(),
                    );
                    if ($count >= (int) $square->max_active_bookings) {
                        return ValidationResult::fail(__('booking.messages.peak_limit_reached'));
                    }
                }
            } elseif ($this->getActiveFutureBookingCount($user) >= (int) $square->max_active_bookings) {
                return ValidationResult::fail(__('booking.messages.max_active_bookings_reached'));
            }
        }
```

**3d — Neue Methode `getPeakActiveFutureBookingCount` ergänzen** (nach `getActiveFutureBookingCount`):

```php
    /** @param list<array{start: string, end: string}> $windows */
    private function getPeakActiveFutureBookingCount(User $user, array $windows): int
    {
        return Reservation::query()
            ->join('bs_bookings', 'bs_bookings.bid', '=', 'bs_reservations.bid')
            ->where('bs_bookings.uid', $user->uid)
            ->whereIn('bs_bookings.status', Booking::ACTIVE_STATUSES)
            ->where('bs_reservations.date', '>=', Carbon::today()->toDateString())
            ->where(function ($query) use ($windows): void {
                foreach ($windows as $window) {
                    $query->orWhere(function ($q) use ($window): void {
                        $q->where('bs_reservations.time_start', '>=', $window['start'].':00')
                          ->where('bs_reservations.time_start', '<',  $window['end'].':00');
                    });
                }
            })
            ->count();
    }
```

- [ ] **Schritt 4: Tests ausführen — müssen BESTEHEN**

```bash
php artisan test tests/Unit/Services/SquareValidatorTest.php
```

Erwartetes Ergebnis: alle Tests grün (inklusive bestehende).

- [ ] **Schritt 5: Committen**

```bash
git add app/Services/SquareValidator.php tests/Unit/Services/SquareValidatorTest.php
git commit -m "feat(peak-limit): SquareValidator prüft Stoßzeiten-Limit"
```

---

## Task 4: Admin-Einstellungen

**Files:**
- Modify: `app/Http/Controllers/Admin/OptionController.php`
- Modify: `resources/views/admin/config/edit.blade.php`

- [ ] **Schritt 1: OptionController — MAP und Validation erweitern**

In `app/Http/Controllers/Admin/OptionController.php`:

**4a — MAP ergänzen** (nach `'maintenance' => 'service.maintenance',`):

```php
        // Stoßzeiten
        'peak_limit_enabled'  => 'peak_limit.enabled',
        'peak_limit_w1_start' => 'peak_limit.window_1_start',
        'peak_limit_w1_end'   => 'peak_limit.window_1_end',
        'peak_limit_w2_start' => 'peak_limit.window_2_start',
        'peak_limit_w2_end'   => 'peak_limit.window_2_end',
```

**4b — Validation in `update()` ergänzen** (nach `'maintenance' => ...`):

```php
            'peak_limit_enabled'  => ['nullable', 'in:0,1'],
            'peak_limit_w1_start' => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
            'peak_limit_w1_end'   => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
            'peak_limit_w2_start' => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
            'peak_limit_w2_end'   => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
```

- [ ] **Schritt 2: Admin-Config-View — Stoßzeiten-Sektion ergänzen**

In `resources/views/admin/config/edit.blade.php` vor dem schließenden `</form>`-Tag folgendes einfügen:

```blade
        {{-- Stoßzeiten --}}
        <div class="bg-white rounded-xl border border-[#e0ddd7] shadow-sm overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-[#f0ede6]">
                <h2 class="text-base font-semibold text-[#151515]" style="font-family: var(--font-display)">Stoßzeiten-Limit</h2>
            </div>
            <div class="px-6 py-5 flex flex-col gap-4">

                <label class="flex items-center gap-2 text-sm text-[#151515] cursor-pointer">
                    <input type="checkbox" name="peak_limit_enabled" value="1"
                        @checked(old('peak_limit_enabled', $values['peak_limit_enabled']) == '1')>
                    Stoßzeiten-Limit global aktivieren
                </label>
                <p class="text-xs text-[#6a6e73] -mt-2">Wenn aktiv, zählen nur Buchungen in Stoßzeiten gegen das Buchungslimit. Pro Platz muss das Limit zusätzlich aktiviert werden.</p>

                <div class="grid grid-cols-2 gap-4">
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">Fenster 1 von</label>
                        <input type="time" name="peak_limit_w1_start"
                            value="{{ old('peak_limit_w1_start', $values['peak_limit_w1_start']) }}"
                            class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">bis</label>
                        <input type="time" name="peak_limit_w1_end"
                            value="{{ old('peak_limit_w1_end', $values['peak_limit_w1_end']) }}"
                            class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">Fenster 2 von</label>
                        <input type="time" name="peak_limit_w2_start"
                            value="{{ old('peak_limit_w2_start', $values['peak_limit_w2_start']) }}"
                            class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-semibold uppercase tracking-wide text-[#6a6e73]">bis</label>
                        <input type="time" name="peak_limit_w2_end"
                            value="{{ old('peak_limit_w2_end', $values['peak_limit_w2_end']) }}"
                            class="w-full border border-[#d1cbc0] rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#bf4316] focus:border-transparent">
                    </div>
                </div>

            </div>
        </div>
```

- [ ] **Schritt 3: `$values` im Controller mit Defaults füllen**

In `OptionController::edit()` werden die Werte über das MAP automatisch aus `bs_options` geladen (leerer String als Default). Damit die Zeit-Felder beim ersten Aufruf sinnvolle Defaults zeigen, die Config-Defaults als Fallback setzen. In `edit()` nach dem `foreach`-Loop ergänzen:

```php
        // Peak-Limit: Config-Defaults wenn noch keine Option gespeichert
        $peakDefaults = [
            'peak_limit_w1_start' => config('booking.peak_limit.window_1_start', '08:00'),
            'peak_limit_w1_end'   => config('booking.peak_limit.window_1_end',   '12:00'),
            'peak_limit_w2_start' => config('booking.peak_limit.window_2_start', '17:00'),
            'peak_limit_w2_end'   => config('booking.peak_limit.window_2_end',   '21:00'),
        ];
        foreach ($peakDefaults as $field => $default) {
            if ($values[$field] === '') {
                $values[$field] = $default;
            }
        }
```

- [ ] **Schritt 4: Committen**

```bash
git add app/Http/Controllers/Admin/OptionController.php resources/views/admin/config/edit.blade.php
git commit -m "feat(peak-limit): Admin-Einstellungen für Stoßzeiten"
```

---

## Task 5: Square-Formular — Checkbox pro Platz

**Files:**
- Modify: `app/Http/Controllers/Admin/SquareController.php`
- Modify: `resources/views/admin/squares/_form.blade.php`

- [ ] **Schritt 1: SquareController anpassen**

In `app/Http/Controllers/Admin/SquareController.php`:

**5a — Import ergänzen** (oben bei den `use`-Statements):

```php
use App\Models\Option;
```

**5b — `toForm()` erweitern** (nach `'label_free' => ...`):

```php
            'peak_limit_enabled' => $square->getMeta('peak_limit_enabled') === '1',
```

**5c — `buildPayload()` Validation erweitern** (nach `'label_free' => ...`):

```php
            'peak_limit_enabled' => ['nullable', 'in:0,1'],
```

**5d — `buildPayload()` Meta erweitern** (nach `'label.free' => ...`):

```php
            'peak_limit_enabled' => $request->boolean('peak_limit_enabled') ? '1' : '0',
```

**5e — `defaults()` erweitern** (nach `'label_free' => ''`):

```php
            'peak_limit_enabled' => false,
```

**5f — `edit()` und `create()` — `$peakLimitGlobal` an View übergeben**

In `edit()`:
```php
    public function edit(Square $square): View
    {
        $square->load('meta');
        $peakLimitGlobal = Option::getValue('peak_limit.enabled', '0') === '1';

        return view('admin.squares.edit', [
            'square' => $square,
            'form' => $this->toForm($square),
            'peakLimitGlobal' => $peakLimitGlobal,
        ]);
    }
```

In `create()`:
```php
    public function create(): View
    {
        $peakLimitGlobal = Option::getValue('peak_limit.enabled', '0') === '1';

        return view('admin.squares.create', [
            'square' => null,
            'form' => $this->defaults(),
            'peakLimitGlobal' => $peakLimitGlobal,
        ]);
    }
```

- [ ] **Schritt 2: Square-Formular Checkbox ergänzen**

In `resources/views/admin/squares/_form.blade.php` in **Section 2 (Booking)**, nach der `allow_notes`-Checkbox ergänzen:

```blade
        @if($peakLimitGlobal ?? false)
        <label class="flex items-center gap-2 text-sm text-[#151515] cursor-pointer">
            <input type="checkbox" name="peak_limit_enabled" value="1"
                @checked(old('peak_limit_enabled', $form['peak_limit_enabled']))>
            {{ __('booking.admin.squares.peak_limit_enabled') }}
        </label>
        @endif
```

- [ ] **Schritt 3: Übersetzung ergänzen**

In `lang/de/booking.php` im `admin.squares`-Array ergänzen:

```php
'peak_limit_enabled' => 'Stoßzeiten-Limit für diesen Platz aktivieren',
```

- [ ] **Schritt 4: Committen**

```bash
git add app/Http/Controllers/Admin/SquareController.php resources/views/admin/squares/_form.blade.php lang/de/booking.php
git commit -m "feat(peak-limit): Checkbox pro Platz im Admin-Formular"
```

---

## Task 6: Integrations-Smoke-Test

- [ ] **Schritt 1: Alle Tests ausführen**

```bash
php artisan test
```

Erwartetes Ergebnis: alle bestehenden Tests grün, keine Regressionen.

- [ ] **Schritt 2: Manuell testen**

1. Admin → Einstellungen → Stoßzeiten-Limit aktivieren, Fenster speichern
2. Admin → Platz bearbeiten → Checkbox "Stoßzeiten-Limit" erscheint und ist speicherbar
3. Als Mitglied: Buchung in Stoßzeit wenn Limit erreicht → Fehlermeldung "Stoßzeiten"
4. Als Mitglied: Buchung außerhalb Stoßzeit wenn Peak-Limit erreicht → Buchung erlaubt

- [ ] **Schritt 3: Finalen Commit setzen**

```bash
git add -A
git commit -m "feat(peak-limit): Stoßzeiten-Buchungslimit vollständig implementiert"
```
