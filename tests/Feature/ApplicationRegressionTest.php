<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BookingValidationException;
use App\Models\Booking;
use App\Models\Option;
use App\Models\Reservation;
use App\Models\Square;
use App\Models\User;
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Sanctum\Sanctum;
use Modules\Api\Mcp\Tools\UpdateBookingTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationRegressionTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2030-11-09 14:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function limits(): array
    {
        return [['time_block_bookable_max', 3600, false], ['max_active_bookings', 1, false], ['max_active_bookings', 1, true]];
    }

    #[Test]
    #[DataProvider('limits')]
    public function editing_players_at_the_limit_preserves_usage_but_new_bookings_are_rejected(string $limit, int $value, bool $peak): void
    {
        $user = User::factory()->create();
        $square = Square::factory()->create([$limit => $value]);
        if ($peak) {
            Option::updateOrCreate(['key' => 'peak_limit.enabled', 'locale' => null], ['value' => '1']);
            $square->meta()->create(['key' => 'peak_limit_enabled', 'value' => '1']);
        }
        $service = app(BookingService::class);
        $booking = $service->createSingle($user, $square, 2, Carbon::parse('2030-11-10 10:00'), Carbon::parse('2030-11-10 11:00'));
        $service->updateSinglePlayers($booking, $user, 2, [2 => 'Replacement player']);
        $this->assertSame(['Replacement player'], $booking->fresh()->player_names);
        $this->expectException(BookingValidationException::class);
        $service->createSingle($user, $square, 2, Carbon::parse('2030-11-10 11:00'), Carbon::parse('2030-11-10 12:00'));
    }

    public static function slotTimes(): array
    {
        return [['10:00:00', '11:00:00', false], ['13:00:00', '14:00:00', false], ['13:00:00', '15:00:00', true], ['15:00:00', '16:00:00', true]];
    }

    #[Test]
    #[DataProvider('slotTimes')]
    public function only_ongoing_and_future_slots_consume_the_active_limit(string $start, string $end, bool $blocked): void
    {
        $user = User::factory()->create();
        $square = Square::factory()->create(['max_active_bookings' => 1]);
        $booking = Booking::factory()->create(['uid' => $user->uid, 'sid' => $square->sid, 'status' => 'single', 'visibility' => 'public']);
        Reservation::factory()->create(['bid' => $booking->bid, 'date' => '2030-11-09', 'time_start' => $start, 'time_end' => $end]);
        if ($blocked) {
            $this->expectException(BookingValidationException::class);
        }
        $new = app(BookingService::class)->createSingle($user, $square, 2, Carbon::parse('2030-11-09 17:00'), Carbon::parse('2030-11-09 18:00'));
        $this->assertNotNull($new->bid);
    }

    public static function entryPoints(): array
    {
        return [['web'], ['rest'], ['mcp']];
    }

    #[Test]
    #[DataProvider('entryPoints')]
    public function irregular_dates_survive_partner_edits_across_interfaces(string $entry): void
    {
        $admin = User::factory()->create(['status' => 'admin']);
        Sanctum::actingAs($admin);
        $square = Square::factory()->create();
        $data = ['booked_for' => $admin->alias, 'sid' => $square->sid, 'quantity' => 2, 'mitspieler' => 'Partner', 'repeat_type' => 'custom', 'date' => '2030-11-10', 'date_end' => '2030-11-25', 'time_start' => '10:00', 'time_end' => '11:00'];
        $dates = ['2030-11-10', '2030-11-17', '2030-11-25'];
        $booking = app(BookingService::class)->createSeries($data, ['uid' => $admin->uid, 'ownerName' => null], array_map(fn ($date) => Carbon::parse($date.' 10:00'), $dates), 3600);
        if ($entry === 'web') {
            $this->get('/admin/bookings/'.$booking->bid.'/edit')->assertOk()->assertViewHas('repeatType', 'custom');
            $this->put('/admin/bookings/'.$booking->bid, $data)->assertRedirect()->assertSessionHasNoErrors();
        } elseif ($entry === 'rest') {
            $this->putJson('/api/v1/bookings/'.$booking->bid, $data)->assertOk();
        } else {
            app(UpdateBookingTool::class)->handle(new McpRequest(['booking_id' => $booking->bid, ...$data]));
        }
        $this->assertSame($dates, $booking->reservations()->orderBy('date')->pluck('date')->all());
        $this->assertSame('subscription', $booking->fresh()->status);
        $data['date_end'] = '2030-11-17';
        $this->expectException(BookingValidationException::class);
        app(BookingService::class)->buildOccurrenceTimeline($data, $booking);
    }

    #[Test]
    public function custom_recurrence_cannot_silently_create_a_single_booking(): void
    {
        $this->expectException(BookingValidationException::class);
        app(BookingService::class)->buildOccurrenceTimeline(['date' => '2030-11-10', 'date_end' => '2030-11-25', 'time_start' => '10:00', 'time_end' => '11:00', 'repeat_type' => 'custom']);
    }

    public static function irregularScopes(): array
    {
        return [
            ['single_occurrence', '2030-11-17', ['2030-11-17'], ['2030-11-10', '2030-11-25']],
            ['this_and_following', '2030-11-25', ['2030-11-17', '2030-11-25'], ['2030-11-10']],
        ];
    }

    #[Test]
    #[DataProvider('irregularScopes')]
    public function scoped_irregular_edits_preserve_every_unselected_date(string $scope, string $end, array $edited, array $untouched): void
    {
        $user = User::factory()->create();
        $square = Square::factory()->create();
        $service = app(BookingService::class);
        $data = ['sid' => $square->sid, 'quantity' => 2, 'mitspieler' => 'Partner', 'repeat_type' => 'custom', 'date' => '2030-11-17', 'date_end' => $end, 'time_start' => '10:00', 'time_end' => '11:00', 'occurrence' => '2030-11-17', 'edit_scope' => $scope];
        $owner = ['uid' => $user->uid, 'ownerName' => null];
        $booking = $service->createSeries($data, $owner, array_map(fn ($date) => Carbon::parse($date.' 10:00'), ['2030-11-10', '2030-11-17', '2030-11-25']), 3600);
        [, , $starts, $duration] = $service->buildOccurrenceTimeline($data, $booking);
        $updated = $service->updateSeries($booking, $data, $owner, $starts, $duration, '2030-11-17', $scope);
        $this->assertSame($edited, $updated->reservations()->orderBy('date')->pluck('date')->all());
        $this->assertSame($untouched, $booking->reservations()->orderBy('date')->pluck('date')->all());
        $this->assertSame(3, Reservation::count());
    }
}
