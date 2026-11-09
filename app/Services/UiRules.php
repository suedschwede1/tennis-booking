<?php

declare(strict_types=1);

namespace App\Services;

/** Source checks complement, but cannot replace, a rendered design review. */
final class UiRules
{
    /** @return list<array{id: string, pattern: string, warn: string, exclude: list<string>}> */
    public static function all(): array
    {
        $rules = [
            ['CLR-03', '/(?<![\w-])(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color)\(\s*(?:[-+.\d]|(?:srgb(?:-linear)?|display-p3|a98-rgb|prophoto-rgb|rec2020|xyz(?:-d(?:50|65))?)\b)[^;{}]*?\)/i', 'Use a named design token instead of a literal color function.'],
            ['CLR-01', '/(?<![\w-])#[0-9a-f]{3,8}\b/i', 'Use a named design token instead of a literal color.'],
            ['CLR-02', '/\b(?:bg|text|border|ring|accent|fill|stroke)-(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-[0-9]{2,3}\b/', 'Use the TC Bewegung color tokens, including semantic status colors.'],
            ['TYP-01', '/\btext-\[(?:[0-9]|10)px\]|font-size\s*:\s*(?:[0-9]|10)px\b/i', 'Text must be at least 11px; body text is 15px and mobile inputs are 17px.'],
            ['TYP-02', '/Red[ +]Hat[ +](?:Display|Text)|\bfont-(?:serif|mono)\b/i', 'Use Barlow for body text and Barlow Condensed for display text.'],
            ['BTN-01', '/<button\b[^>]*\b(?:h|size)-(?:[1-9]|10)\b[^>]*>/is', 'Touch controls need at least 44px; use ui.button (52px on phones).'],
            ['INP-01', '/<(?:input|select|textarea)\b[^>]*\brounded-full\b[^>]*>/is', 'Inputs use radius-md on desktop and radius-lg on phones.'],
            ['STY-01', '/(?<![:\w-])style\s*=\s*(?:"[^"]*"|\x27[^\x27]*\x27)/is', 'Move presentation to token-based CSS; document technical exceptions explicitly.'],
            ['MOT-01', '/\b(?:animate-(?:spin|pulse|bounce|ping)|transition-all|bg-gradient-to-\w+)\b|(?<!repeating-)linear-gradient\s*\(/i', 'Use flat colors and short color/modal transitions with reduced-motion support.'],
        ];

        return array_map(static fn (array $rule): array => [
            'id' => $rule[0], 'pattern' => $rule[1], 'warn' => $rule[2],
            'exclude' => ['/emails/'],
        ], $rules);
    }
}
