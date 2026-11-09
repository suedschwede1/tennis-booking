<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use Laravel\Dusk\Browser;
use PHPUnit\Framework\Assert;

trait ComparesDesignScreenshots
{
    private function resizeReferenceViewport(Browser $browser, int $width, int $height): Browser
    {
        // Lock the CSS viewport, not the OS-dependent outer window decoration.
        // These sizes are explicit fixture values; never derive them from a PNG.
        $browser->driver->executeCustomCommand('/session/:sessionId/goog/cdp/execute', 'POST', [
            'cmd' => 'Emulation.setDeviceMetricsOverride',
            'params' => ['width' => $width, 'height' => $height, 'deviceScaleFactor' => 1, 'mobile' => false],
        ]);
        $browser->driver->executeCustomCommand('/session/:sessionId/goog/cdp/execute', 'POST', [
            'cmd' => 'Emulation.setLocaleOverride',
            'params' => ['locale' => 'en_GB'],
        ]);

        return $browser;
    }

    private function assertScreenshotMatchesBaseline(Browser $browser, string $name, float $allowedMismatchRatio = 0.002): void
    {
        $browser->waitUsing(10, 100, fn (): bool => (bool) ($browser->script("return document.fonts.status === 'loaded' && Array.from(document.images).every(i => i.complete);")[0] ?? false));
        $browser->script("document.activeElement?.blur(); document.documentElement.style.scrollBehavior = 'auto';");
        $browser->pause(200)->screenshot($name);
        $actual = base_path('tests/Browser/screenshots/'.$name.'.png');
        $baseline = base_path('tests/Browser/baselines/'.$name.'.png');

        if (filter_var(env('UPDATE_DUSK_SNAPSHOTS', false), FILTER_VALIDATE_BOOL)) {
            Assert::assertFalse(filter_var(env('CI', false), FILTER_VALIDATE_BOOL), 'CI must compare references, never replace them.');
            if (! is_dir(dirname($baseline))) {
                mkdir(dirname($baseline), 0755, true);
            }
            Assert::assertTrue(copy($actual, $baseline));

            return;
        }
        Assert::assertFileExists($baseline, 'Missing reviewed baseline: '.$name);
        Assert::assertTrue(extension_loaded('gd'), 'Install PHP gd: a skipped comparison is not a successful design check.');
        [$width, $height] = getimagesize($actual);
        Assert::assertSame([$width, $height], array_slice(getimagesize($baseline), 0, 2), 'Screenshot dimensions changed: '.$name);
        $actualImage = imagecreatefrompng($actual);
        $baselineImage = imagecreatefrompng($baseline);
        $changed = 0;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $a = imagecolorat($actualImage, $x, $y);
                $b = imagecolorat($baselineImage, $x, $y);
                $distance = abs((($a >> 16) & 255) - (($b >> 16) & 255))
                    + abs((($a >> 8) & 255) - (($b >> 8) & 255)) + abs(($a & 255) - ($b & 255));
                $changed += $distance > 24 ? 1 : 0;
            }
        }
        imagedestroy($actualImage);
        imagedestroy($baselineImage);
        Assert::assertLessThanOrEqual($allowedMismatchRatio, $changed / ($width * $height), 'Visual regression: '.$name);
    }
}
