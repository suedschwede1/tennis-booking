<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PendingAccountStateTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function inactiveStates(): array
    {
        return [['disabled'], ['blocked'], ['deleted'], ['missing']];
    }

    private function pending(string $status): array
    {
        $user = User::factory()->create(['status' => $status === 'missing' ? 'enabled' : $status]);
        $pending = ['user_id' => $user->uid, 'ip' => '127.0.0.1', 'country_code' => 'AT', 'risk_flags' => [], 'code_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(5)->toIso8601String(), 'attempts' => 0, 'remember' => true, 'redirect_to' => '/calendar'];
        if ($status === 'missing') {
            $user->delete();
        }

        return $pending;
    }

    #[Test]
    #[DataProvider('inactiveStates')]
    public function a_pending_code_cannot_authenticate_an_ineligible_account(string $status): void
    {
        $this->withSession(['login.pending_verification' => $this->pending($status)])
            ->post('/login/verify', ['code' => '123456'])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull(session('login.pending_verification'));
        $this->assertSame(0, LoginHistory::where('status', 'success')->count());
    }

    #[Test]
    #[DataProvider('inactiveStates')]
    public function resending_to_an_ineligible_account_clears_the_challenge_without_mail(string $status): void
    {
        Mail::fake();
        $this->withSession(['login.pending_verification' => $this->pending($status)])
            ->post('/login/verify/resend')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull(session('login.pending_verification'));
        Mail::assertNothingSent();
    }

    public static function activeStates(): array
    {
        return [['enabled'], ['assist'], ['admin']];
    }

    #[Test]
    #[DataProvider('activeStates')]
    public function eligible_accounts_can_complete_verification(string $status): void
    {
        $pending = $this->pending($status);
        $this->withSession(['login.pending_verification' => $pending])->post('/login/verify', ['code' => '123456'])->assertRedirect('/calendar');
        $this->assertAuthenticatedAs(User::findOrFail($pending['user_id']));
        $this->assertNull(session('login.pending_verification'));
    }
}
