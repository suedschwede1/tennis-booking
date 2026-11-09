<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\UiLinter;
use App\Services\UiRules;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UiLinterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ui-linter-'.bin2hex(random_bytes(8));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    private function write(string $path, string $source): void
    {
        if (! is_dir(dirname($this->root.'/'.$path))) {
            mkdir(dirname($this->root.'/'.$path), 0777, true);
        }
        file_put_contents($this->root.'/'.$path, $source);
    }

    private function findings(): array
    {
        return (new UiLinter($this->root, UiRules::all(), $this->root))->run();
    }

    #[Test]
    public function fragment_identifiers_selectors_and_script_strings_are_not_colors(): void
    {
        $this->write('page.blade.php', '<a href="#add">Add</a><section id="add"></section><svg><use href="#abc" /></svg><script>const id = "#fff";</script>');
        $this->write('page.css', '#add { color: var(--color-ink); fill: url(#fff); }');
        $this->assertSame([], $this->findings());
    }

    #[Test]
    public function color_functions_in_css_utilities_and_svg_are_rejected(): void
    {
        $this->write('page.css', '.card { color: rgb(255, 0, 0); background: oklch(50% .2 30); }');
        $this->write('page.blade.php', '<p class="text-[hsl(0,100%,50%)]">x</p><svg fill="#add"></svg>');
        $rules = array_count_values(array_column($this->findings(), 'rule'));
        $this->assertSame(3, $rules['CLR-03']);
        $this->assertSame(1, $rules['CLR-01']);
    }

    #[Test]
    public function named_tokens_and_valid_component_sizes_are_allowed(): void
    {
        $this->write('clean.blade.php', '<button class="bg-clay text-on-clay min-h-12 rounded-lg">Book</button><input class="rounded-lg text-[17px]">');
        $this->assertSame([], $this->findings());
    }

    #[Test]
    public function retired_and_arbitrary_colors_are_rejected_including_short_hex(): void
    {
        $this->write('colors.blade.php', '<p class="text-[#bf4316] bg-[#fff] border-blue-500">Text</p>');
        $this->assertSame(['CLR-01', 'CLR-01', 'CLR-02'], array_column($this->findings(), 'rule'));
    }

    #[Test]
    public function multiline_small_buttons_and_small_type_are_detected(): void
    {
        $this->write('controls.blade.php', "<button\n class=\"h-9\">Book</button>\n<p class=\"text-[10px]\">Text</p>");
        $findings = $this->findings();
        $this->assertContains('BTN-01', array_column($findings, 'rule'));
        $this->assertContains('TYP-01', array_column($findings, 'rule'));
        $this->assertSame(3, $findings[0]['line']);
    }

    #[Test]
    public function css_and_multiple_view_roots_are_scanned_but_other_files_are_not(): void
    {
        $this->write('resources/views/a.blade.php', '<p class="text-red-500">x</p>');
        $this->write('app/Modules/Members/resources/views/mail/index.blade.php', '<p class="text-blue-500">x</p>');
        $this->write('resources/css/sample.css', '.card { color: #0066cc; font-family: "Red Hat Text"; }');
        $this->write('resources/views/ignored.php', '#ff0000');
        $linter = new UiLinter([$this->root.'/resources', $this->root.'/app/Modules'], UiRules::all(), $this->root);
        $this->assertCount(3, $linter->files());
        $this->assertCount(4, $linter->run());
        $this->assertContains('app/Modules/Members/resources/views/mail/index.blade.php', array_column($linter->run(), 'file'));
    }

    #[Test]
    public function comments_and_email_templates_are_excluded_without_losing_line_numbers(): void
    {
        $this->write('emails/mail.blade.php', '<p style="color:#f00">Mail</p>');
        $this->write('page.blade.php', "{{-- #f00 --}}\n<!-- #0f0 -->\n<p class=\"text-[#00f]\">Text</p>");
        $this->assertCount(1, $this->findings());
        $this->assertSame(3, $this->findings()[0]['line']);
    }

    #[Test]
    public function inserting_lines_preserves_a_fingerprint_but_changing_the_violation_does_not(): void
    {
        $this->write('page.blade.php', '<p class="text-[#f00]">Text</p>');
        $original = $this->findings()[0]['fingerprint'];
        $this->write('page.blade.php', "\n\n".'<p class="text-[#f00]">Text</p>');
        $this->assertSame($original, $this->findings()[0]['fingerprint']);
        $this->write('page.blade.php', '<p class="text-[#0f0]">Text</p>');
        $this->assertNotSame($original, $this->findings()[0]['fingerprint']);
    }

    #[Test]
    public function canonical_palette_matches_the_documented_light_tokens(): void
    {
        $root = dirname(__DIR__, 3);
        $docs = file_get_contents($root.'/docs/DESIGN.md');
        $css = file_get_contents($root.'/resources/css/design-tokens.css');
        preg_match_all('/^\| `([\w-]+)` \| `(#\w{6})` \|/m', $docs, $tokens, PREG_SET_ORDER);
        $this->assertGreaterThan(20, count($tokens));
        foreach ($tokens as $token) {
            $this->assertStringContainsString('--color-'.$token[1].': '.$token[2].';', $css);
        }
        $expected = array_column($tokens, 2, 1);
        preg_match_all('/^\| `([\w-]+)` \/ `([\w-]+)` \| `(#\w{6})` \/ `(#\w{6})`/m', $docs, $pairs, PREG_SET_ORDER);
        $this->assertCount(2, $pairs);
        foreach ($pairs as $pair) {
            $expected[$pair[1]] = $pair[3];
            $expected[$pair[2]] = $pair[4];
        }
        preg_match_all('/--color-([\w-]+):\s*(#\w{6});/', $css, $actual, PREG_SET_ORDER);
        $actual = array_column($actual, 2, 1);
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual, 'Palette changes must also update the design contract.');
    }
}
