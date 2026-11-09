<?php

declare(strict_types=1);

namespace Tests\Browser;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\Test;
use Tests\Browser\Support\ComparesDesignScreenshots;
use Tests\DuskTestCase;

class DesignVisualRegressionTest extends DuskTestCase
{
    use ComparesDesignScreenshots;
    use DatabaseMigrations;

    #[Test]
    public function shared_controls_match_the_desktop_reference(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->resizeReferenceViewport($browser, 1440, 857)->visit('/__design')->waitFor('[data-testid="design-preview"]');
            $this->assertSame('rgb(181, 68, 26)', $browser->script("return getComputedStyle(document.querySelector('.tc-button--primary')).backgroundColor;")[0]);
            $this->assertSame('rgb(154, 43, 23)', $browser->script("return getComputedStyle(document.querySelector('.tc-error')).color;")[0]);
            $this->assertScreenshotMatchesBaseline($browser, 'design-controls-desktop');
        });
    }

    #[Test]
    public function shared_controls_have_phone_targets_and_no_horizontal_overflow(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->resizeReferenceViewport($browser, 390, 857)->visit('/__design')->waitFor('[data-testid="design-preview"]');
            $this->assertTrue((bool) $browser->script("return document.documentElement.scrollWidth <= innerWidth && [...document.querySelectorAll('.tc-button, .tc-input')].filter(e => e.getClientRects().length).every(e => e.getBoundingClientRect().height >= 52);")[0]);
            $this->assertScreenshotMatchesBaseline($browser, 'design-controls-mobile');
        });
    }

    #[Test]
    public function dialog_opens_and_escape_returns_keyboard_focus(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->resizeReferenceViewport($browser, 390, 701)->visit('/__design')->waitFor('[data-testid="preview-open"]')
                ->click('[data-testid="preview-open"]')->waitFor('[data-testid="preview-dialog"]');
            $this->assertScreenshotMatchesBaseline($browser, 'design-dialog-mobile');
            $browser->keys('[data-testid="preview-dialog"] button', '{escape}')
                ->waitUntil("document.activeElement.matches('[data-testid=preview-open]')");
            $this->assertFalse((bool) $browser->script("return document.querySelector('[data-testid=preview-dialog]').open;")[0]);
        });
    }
}
