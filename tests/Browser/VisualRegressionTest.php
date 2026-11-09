<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Booking;
use App\Models\Event;
use App\Models\EventMeta;
use App\Models\Option;
use App\Models\Reservation;
use App\Models\Square;
use App\Models\SquareMeta;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Browser\Support\ComparesDesignScreenshots;
use Tests\DuskTestCase;

final class VisualRegressionTest extends DuskTestCase
{
    use ComparesDesignScreenshots;
    use DatabaseMigrations;

    private const CALENDAR_DATE = '2099-07-01';

    public static function applicationScreens(): array
    {
        return [
            'login desktop' => ['/login', 1440, 757, 'login-desktop'],
            'login phone' => ['/login', 390, 701, 'login-mobile'],
            'account desktop' => ['/my-account', 1440, 757, 'account-desktop'],
            'account phone' => ['/my-account', 390, 701, 'account-mobile'],
            'admin desktop' => ['/admin', 1440, 757, 'admin-desktop'],
            'admin phone' => ['/admin', 390, 701, 'admin-mobile'],
        ];
    }

    #[Test]
    #[DataProvider('applicationScreens')]
    public function application_pages_preserve_their_reviewed_layout(string $path, int $width, int $height, string $name): void
    {
        $member = $this->seedCalendarState();
        if ($path === '/admin') {
            $member->update(['status' => 'admin']);
        }
        $this->browse(function (Browser $browser) use ($path, $width, $height, $name, $member): void {
            $this->resizeReferenceViewport($browser, $width, $height);
            $path === '/login' ? $browser->logout() : $browser->loginAs($member);
            $browser->visit($path)->assertPathIs($path);
            if ($path === '/admin') {
                // Only the current-date label is masked; counters and content remain checked.
                $browser->script("document.querySelector('.ui-page-header p').textContent = 'Donnerstag, 5. November 2026';");
            }
            $this->assertScreenshotMatchesBaseline($browser, $name);
        });
    }

    public function test_calendar_desktop_visual_regression(): void
    {
        $this->seedCalendarState();

        $this->browse(function (Browser $browser): void {
            $this->resizeReferenceViewport($browser, 1440, 757)
                ->visit('/calendar?date='.self::CALENDAR_DATE)
                ->waitFor('#calendar-grid', 10)
                ->pause(300);

            $this->assertScreenshotMatchesBaseline($browser, 'calendar-desktop');
        });
    }

    public function test_calendar_mobile_visual_regression(): void
    {
        $this->seedCalendarState();

        $this->browse(function (Browser $browser): void {
            $this->resizeReferenceViewport($browser, 390, 701)
                ->visit('/calendar?date='.self::CALENDAR_DATE)
                ->waitFor('#calendar-grid', 10)
                ->pause(300);

            $this->assertScreenshotMatchesBaseline($browser, 'calendar-mobile');
        });
    }

    public function test_booking_modal_visual_regression(): void
    {
        $member = $this->seedCalendarState();

        $this->browse(function (Browser $browser) use ($member): void {
            $this->resizeReferenceViewport($browser, 1024, 625)
                ->loginAs($member)
                ->visit('/calendar?date='.self::CALENDAR_DATE)
                ->waitFor('#calendar-grid', 10)
                ->script("document.querySelector('.cc-free')?.click();");

            $browser->waitFor('[data-testid="booking-create-dialog"]', 10)
                ->pause(300);

            $this->assertScreenshotMatchesBaseline($browser, 'booking-modal');
        });
    }

    private function seedCalendarState(): User
    {
        // Branding must not depend on local club assets or installation overrides.
        Option::updateOrCreate(['key' => 'service.name', 'locale' => null], ['value' => 'Tennis Club']);
        Option::updateOrCreate(['key' => 'service.brand.logo_path', 'locale' => null], ['value' => 'missing-visual-logo.svg']);
        $squares = collect([1, 2, 3])->map(function (int $number): Square {
            $square = Square::factory()->create([
                'name' => (string) $number,
                'priority' => $number,
                'capacity' => 4,
                'capacity_heterogenic' => 1,
                'range_book' => 0,
                'time_block_bookable_max' => 0,
            ]);

            SquareMeta::create([
                'sid' => $square->sid,
                'key' => 'alias',
                'value' => match ($number) {
                    1 => 'Alpha Court',
                    2 => 'Beta Court',
                    default => 'Gamma Court',
                },
            ]);

            return $square;
        });

        $member = User::factory()->create([
            'alias' => 'Alex Example',
            'email' => 'alex@example.test',
            'status' => 'enabled',
        ]);
        $other = User::factory()->create([
            'alias' => 'Morgan Example',
            'status' => 'enabled',
        ]);

        $this->createReservation($member, $squares[0], self::CALENDAR_DATE, '11:00:00', '12:00:00', ['Helga']);
        $this->createReservation($other, $squares[1], self::CALENDAR_DATE, '09:00:00', '11:00:00', ['Alfred Lechner (17)']);
        $this->createReservation($member, $squares[2], self::CALENDAR_DATE, '17:00:00', '18:00:00', ['Helga und Sandra']);

        $event = Event::factory()->create([
            'sid' => null,
            'status' => 'enabled',
            'datetime_start' => Carbon::parse(self::CALENDAR_DATE.' 12:00:00'),
            'datetime_end' => Carbon::parse(self::CALENDAR_DATE.' 13:00:00'),
        ]);
        EventMeta::create([
            'eid' => $event->eid,
            'key' => 'name',
            'value' => 'TESTSPIEL',
        ]);

        return $member;
    }

    /** @param list<string> $playerNames */
    private function createReservation(User $user, Square $square, string $date, string $from, string $to, array $playerNames): void
    {
        $booking = Booking::factory()->create([
            'uid' => $user->uid,
            'sid' => $square->sid,
            'status' => 'single',
            'visibility' => 'public',
            'quantity' => count($playerNames) >= 3 ? 4 : 2,
        ]);

        Reservation::factory()->create([
            'bid' => $booking->bid,
            'date' => $date,
            'time_start' => $from,
            'time_end' => $to,
        ]);

        if ($playerNames !== []) {
            $booking->meta()->create([
                'key' => 'player-names',
                'value' => serialize(array_map(
                    static fn (string $name, int $index): array => [
                        'name' => 'sb-player-name-'.($index + 2),
                        'value' => $name,
                    ],
                    $playerNames,
                    array_keys($playerNames),
                )),
            ]);
        }
    }
}
