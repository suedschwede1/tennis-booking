<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Members;

use App\Mail\MemberBulkMail;
use App\Models\User;
use App\Modules\Members\Models\ClubRole;
use App\Modules\Members\Models\FeeCategory;
use App\Modules\Members\Models\FeeCategoryRate;
use App\Modules\Members\Models\FiscalYear;
use App\Modules\Members\Models\MailSend;
use App\Modules\Members\Models\Member;
use App\Modules\Members\Models\MemberChange;
use App\Modules\Members\Models\MemberDue;
use App\Modules\Members\Models\Payment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MemberWorkflowRegressionTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 9)->startOfDay());
        $this->actingAs(User::factory()->create(['status' => 'admin']));
    }

    private function setupDue(): array
    {
        $year = FiscalYear::create(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $category = FeeCategory::create(['name' => 'Standard']);
        FeeCategoryRate::create(['fee_category_id' => $category->id, 'fiscal_year_id' => $year->id, 'amount' => 120]);
        $member = Member::factory()->create(['fee_category_id' => $category->id, 'joined_at' => '2020-01-01']);
        $due = MemberDue::create(['member_id' => $member->id, 'fee_category_id' => $category->id, 'fiscal_year_id' => $year->id, 'amount' => 120]);

        return [$member, $due, $year, $category];
    }

    private function pay(Member $member, MemberDue $due): Payment
    {
        $this->post(route('members.payments.store', $member), ['amount' => 120, 'paid_at' => '2026-11-09', 'member_due_ids' => [$due->id]])->assertSessionHasNoErrors();

        return Payment::latest('id')->firstOrFail();
    }

    #[Test]
    public function generation_preview_and_execution_report_every_active_member_without_silent_skips(): void
    {
        [$existing, $due, $year, $category] = $this->setupDue();
        $new = Member::factory()->create(['fee_category_id' => $category->id, 'joined_at' => '2026-01-01']);
        Member::factory()->create(['fee_category_id' => null, 'joined_at' => '2026-01-01']);
        $noRate = FeeCategory::create(['name' => 'No rate']);
        Member::factory()->create(['fee_category_id' => $noRate->id, 'joined_at' => '2026-01-01']);
        $preview = $this->post('/members/dues/generate', ['fiscal_year_id' => $year->id, 'preview' => 1])->assertSessionHasNoErrors();
        $reasons = collect(session('generationReport'))->pluck('reason')->all();
        $this->assertEqualsCanonicalizing(['existing', 'ready', 'no_category', 'no_rate'], $reasons);
        $this->assertSame(1, MemberDue::count());
        $this->get($preview->headers->get('Location'))->assertOk()->assertSee($new->fullName());
        $this->post('/members/dues/generate', ['fiscal_year_id' => $year->id])->assertSessionHasNoErrors();
        $this->assertSame(2, MemberDue::count());
        $this->assertSame(1, collect(session('generationReport'))->where('reason', 'created')->count());
    }

    #[Test]
    public function voiding_a_payment_preserves_its_history_and_reopens_the_due_for_corrected_posting(): void
    {
        [$member, $due] = $this->setupDue();
        $payment = $this->pay($member, $due);
        $this->get(route('members.edit', $member))->assertOk()->assertSee(route('members.payments.void', [$member, $payment]), false);
        $this->post(route('members.payments.void', [$member, $payment]), ['reason' => 'Wrong account'])->assertSessionHasNoErrors();
        $this->assertNotNull($payment->fresh()->voided_at);
        $this->assertSame(auth()->id(), $payment->fresh()->voided_by_user_id);
        $this->assertSame('120.00', $payment->fresh()->amount);
        $this->assertFalse($due->fresh()->isPaid());
        $this->assertSame(1, $payment->dues()->count());
        $this->assertSame(2, MemberChange::where('member_id', $member->id)->count());
        $replacement = $this->pay($member, $due);
        $this->assertNotSame($payment->id, $replacement->id);
        $this->assertSame(1, $due->payments()->count());
        $this->post(route('members.payments.void', [$member, $payment]), ['reason' => 'Repeated click'])->assertSessionHasNoErrors();
        $this->assertTrue($due->fresh()->isPaid());
        $this->assertSame(3, MemberChange::where('member_id', $member->id)->count());
    }

    #[Test]
    public function payment_voiding_checks_owner_permission_and_reason(): void
    {
        [$member, $due] = $this->setupDue();
        $payment = $this->pay($member, $due);
        $other = Member::factory()->create();
        $this->post(route('members.payments.void', [$other, $payment]), ['reason' => 'Mismatch'])->assertNotFound();
        $this->post(route('members.payments.void', [$member, $payment]), [])->assertSessionHasErrors('reason');
        $this->actingAs(User::factory()->create(['status' => 'enabled']))->post(route('members.payments.void', [$member, $payment]), ['reason' => 'Forbidden'])->assertForbidden();
        $this->assertNull($payment->fresh()->voided_at);
    }

    #[Test]
    public function rest_role_replacement_and_explicit_clearing_are_audited_while_omitted_fields_survive(): void
    {
        Sanctum::actingAs(auth()->user());
        $member = Member::factory()->create(['joined_at' => '2020-01-01', 'left_at' => '2025-01-01']);
        $a = ClubRole::create(['name' => 'Role A']);
        $b = ClubRole::create(['name' => 'Role B']);
        $member->clubRoles()->attach($a);
        $data = ['member_number' => $member->member_number, 'firstname' => $member->firstname, 'lastname' => $member->lastname, 'joined_at' => '2020-01-01'];
        $this->putJson('/api/v1/members/'.$member->id, $data)->assertOk();
        $this->assertSame('2025-01-01', $member->fresh()->left_at->toDateString());
        $this->assertSame([$a->id], $member->clubRoles()->pluck('club_roles.id')->all());
        $this->putJson('/api/v1/members/'.$member->id, [...$data, 'club_roles' => [$b->id]])->assertOk();
        $this->assertStringContainsString('Role B', MemberChange::latest('id')->first()->after['club_roles']);
        $this->putJson('/api/v1/members/'.$member->id, [...$data, 'club_roles' => []])->assertOk();
        $this->assertSame(0, $member->clubRoles()->count());
    }

    #[Test]
    public function real_array_transport_marks_submission_but_a_queued_mail_is_not_claimed_delivered(): void
    {
        Member::factory()->create(['email' => 'review@example.test']);
        config(['mail.default' => 'array', 'queue.default' => 'sync']);
        $this->post('/members/mail/send', ['audience' => 'all', 'subject' => 'Review', 'body' => 'No network'])->assertSessionHasNoErrors();
        $send = MailSend::firstOrFail();
        $this->assertSame('submitted', $send->status);
        $this->assertNotNull($send->submitted_at);
        Mail::fake();
        $this->post('/members/mail/send', ['audience' => 'all', 'subject' => 'Queued', 'body' => 'No network'])->assertSessionHasNoErrors();
        $queued = MailSend::latest('id')->firstOrFail();
        $this->assertSame('queued', $queued->status);
        $this->assertNull($queued->submitted_at);
        (new MemberBulkMail('Queued', 'No network', $queued->id))->failed(new \RuntimeException('Transport failure'));
        $this->assertSame('failed', $queued->fresh()->status);
    }

    #[Test]
    public function immediate_mail_failure_is_reported_and_recorded(): void
    {
        Member::factory()->create(['email' => 'review@example.test']);
        Mail::shouldReceive('bcc')->once()->andThrow(new \RuntimeException('Review transport unavailable'));
        $this->post('/members/mail/send', ['audience' => 'all', 'subject' => 'Review', 'body' => 'No network'])->assertSessionHasErrors('audience');
        $this->assertSame('failed', MailSend::firstOrFail()->status);
    }

    #[Test]
    public function sender_names_are_validated_before_writing_legacy_options(): void
    {
        $this->postJson('/members/mail/sender', ['from_name' => 'Review 🎾', 'from_address' => 'review@example.test'])->assertUnprocessable()->assertJsonValidationErrors('from_name');
        $this->post('/members/mail/sender', ['from_name' => 'Tennisclub', 'from_address' => 'review@example.test'])->assertSessionHasNoErrors();
    }

    #[Test]
    public function reconciliation_is_dry_run_by_default_and_repairs_only_explicit_unpaid_zero_placeholders(): void
    {
        [$member, $due] = $this->setupDue();
        $due->update(['amount' => 0]);
        $this->artisan('members:reconcile-dues', ['--repair-zero' => (string) $due->id])->assertSuccessful();
        $this->assertSame('0.00', $due->fresh()->amount);
        $this->assertNull($due->fresh()->faellig_am);
        $this->artisan('members:reconcile-dues', ['--apply' => true])->assertSuccessful();
        $this->assertSame('0.00', $due->fresh()->amount);
        $this->assertNotNull($due->fresh()->faellig_am);
        $this->artisan('members:reconcile-dues', ['--apply' => true, '--repair-zero' => (string) $due->id])->assertSuccessful();
        $this->assertSame('120.00', $due->fresh()->amount);
        $this->pay($member, $due);
        DB::table('member_dues')->where('id', $due->id)->update(['amount' => 0, 'status' => 'ausstehend']);
        $this->artisan('members:reconcile-dues', ['--apply' => true, '--repair-zero' => (string) $due->id])
            ->expectsOutputToContain('amount/allocation mismatch')->assertSuccessful();
        $this->assertSame('0.00', $due->fresh()->amount);
        $this->assertSame('bezahlt', $due->fresh()->status);
    }

    #[Test]
    public function existing_anonymized_notes_and_paid_import_status_are_repaired_by_the_migration(): void
    {
        [$member, $due] = $this->setupDue();
        $payment = Payment::create(['member_id' => $member->id, 'amount' => 120, 'paid_at' => now()]);
        $due->payments()->attach($payment);
        DB::table('members')->where('id', $member->id)->update(['anonymized_at' => now(), 'notiz' => 'Legacy private marker']);
        DB::table('member_changes')->insert(['member_id' => $member->id, 'action' => 'updated', 'before' => json_encode(['notiz' => 'Legacy private marker']), 'after' => '{}', 'created_at' => now()]);
        Schema::table('payments', fn ($table) => $table->dropColumn(['voided_at', 'voided_by_user_id', 'void_reason']));
        Schema::table('mail_sends', fn ($table) => $table->dropColumn(['status', 'submitted_at']));
        (require database_path('migrations/2026_11_09_000002_complete_member_accounting_and_privacy.php'))->up();
        $this->assertNull($member->fresh()->notiz);
        $this->assertArrayNotHasKey('notiz', MemberChange::first()->before);
        $this->assertSame('bezahlt', $due->fresh()->status);
    }
}
