<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\User;
use App\Modules\Members\Models\FeeCategory;
use App\Modules\Members\Models\FiscalYear;
use App\Modules\Members\Models\Member;
use App\Modules\Members\Models\MemberDue;
use App\Modules\Members\Models\Payment;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\Test;
use Tests\DuskTestCase;

class MembersManagementTest extends DuskTestCase
{
    use DatabaseMigrations;

    #[Test]
    public function a_payment_can_be_entered_and_voided_from_member_details_on_a_phone(): void
    {
        $admin = User::factory()->create(['status' => 'admin']);
        $member = Member::factory()->create();
        $year = FiscalYear::create(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $category = FeeCategory::create(['name' => 'Standard']);
        $due = MemberDue::create(['member_id' => $member->id, 'fiscal_year_id' => $year->id, 'fee_category_id' => $category->id, 'amount' => 120]);
        $this->browse(function (Browser $browser) use ($admin, $member): void {
            (new ChromeDevToolsDriver($browser->driver))->execute('Emulation.setDeviceMetricsOverride', ['width' => 390, 'height' => 844, 'deviceScaleFactor' => 1, 'mobile' => true]);
            $browser->loginAs($admin)->visit('/members/'.$member->id.'/edit')
                ->click('a[href="'.route('members.payments.create', $member).'"]')
                ->waitFor('#pf-amount')->type('amount', '120')
                ->check('member_due_ids[]')
                ->click('form[action="'.route('members.payments.store', $member).'"] button[type="submit"]')
                ->waitForLocation('/members/'.$member->id.'/edit');
            $payment = Payment::firstOrFail();
            $browser->type('reason', 'Browser review correction')
                ->click('form[action="'.route('members.payments.void', [$member, $payment]).'"] button[type="submit"]')
                ->waitForText(__('members::admin.payments.voided'));
        });
        $payment = Payment::firstOrFail();
        $this->assertNotNull($payment->voided_at);
        $this->assertFalse($due->fresh()->isPaid());
        // The disposable browser database must contain no reversals before migration rollback.
        $payment->delete();
    }

    #[Test]
    public function member_pages_fit_phone_tablet_and_desktop_and_payment_entry_is_reachable(): void
    {
        $admin = User::factory()->create(['status' => 'admin', 'alias' => 'Review Admin']);
        $member = Member::factory()->create(['firstname' => 'Review', 'lastname' => 'Example']);
        $output = env('MEMBERS_REVIEW_OUTPUT');
        $measurements = [];
        $this->browse(function (Browser $browser) use ($admin, $member, $output, &$measurements): void {
            $browser->loginAs($admin);
            $cdp = new ChromeDevToolsDriver($browser->driver);
            foreach ([1440, 768, 390] as $width) {
                $cdp->execute('Emulation.setDeviceMetricsOverride', ['width' => $width, 'height' => 844, 'deviceScaleFactor' => 1, 'mobile' => $width === 390]);
                foreach (['list' => '/members', 'edit' => '/members/'.$member->id.'/edit', 'dues' => '/members/dues'] as $page => $url) {
                    $browser->visit($url)->waitFor('main')->assertPresent('main');
                    $metrics = $browser->script('return {width: document.documentElement.clientWidth, documentWidth: document.documentElement.scrollWidth};')[0];
                    $measurements[$width][$page] = $metrics;
                    $this->assertLessThanOrEqual($metrics['width'] + 1, $metrics['documentWidth'], $page.' overflows at '.$width);
                    if ($output) {
                        $browser->driver->takeScreenshot($output.'/members-fixed-'.$page.'-'.$width.'.png');
                    }
                }
            }
            $browser->visit('/members/'.$member->id.'/edit')
                ->assertVisible('.members-menu-toggle')
                ->assertMissing('#members-navigation')
                ->click('.members-menu-toggle')
                ->assertVisible('#members-navigation')
                ->assertAttribute('.members-menu-toggle', 'aria-expanded', 'true')
                ->click('.members-menu-toggle')
                ->assertMissing('#members-navigation')
                ->click('a[href="'.route('members.payments.create', $member).'"]')
                ->waitFor('#pf-amount')
                ->assertPathIs('/members/'.$member->id.'/payments/create');
            $metrics = $browser->script('return {width: document.documentElement.clientWidth, documentWidth: document.documentElement.scrollWidth};')[0];
            $this->assertLessThanOrEqual($metrics['width'] + 1, $metrics['documentWidth']);
        });
        if ($output) {
            file_put_contents($output.'/members-fixed-browser-measurements.json', json_encode($measurements, JSON_PRETTY_PRINT));
        }
    }
}
