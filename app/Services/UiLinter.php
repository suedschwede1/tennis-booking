<?php

declare(strict_types=1);

namespace App\Services;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class UiLinter
{
    /** @param string|list<string> $viewsPath
     * @param  list<array<string, mixed>>  $rules
     */
    public function __construct(
        private readonly string|array $viewsPath,
        private readonly array $rules,
        private readonly ?string $projectRoot = null,
    ) {}

    /** @return list<string> */
    public function files(): array
    {
        $files = [];
        foreach ((array) $this->viewsPath as $root) {
            if (! is_dir($root)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
                if ($file instanceof SplFileInfo && $file->isFile()
                    && (str_ends_with($file->getFilename(), '.blade.php') || $file->getExtension() === 'css')) {
                    $files[] = $file->getPathname();
                }
            }
        }
        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /** @return list<array{rule: string, file: string, line: int, message: string, source: string, fingerprint: string}> */
    public function run(): array
    {
        $findings = [];
        foreach ($this->files() as $file) {
            $relative = $this->relativePath($file);
            $source = file_get_contents($file);
            if ($source === false) {
                throw new RuntimeException('Cannot read UI source: '.$relative);
            }
            // Ignore comments without changing offsets or line numbers.
            $content = preg_replace_callback('/{{--.*?--}}|<!--.*?-->|\/\*.*?\*\//s',
                static fn (array $m): string => preg_replace('/[^\n]/', ' ', $m[0]) ?? '', $source) ?? '';
            foreach ($this->rules as $rule) {
                if ($this->excluded($relative, $rule['exclude'] ?? [])) {
                    continue;
                }
                if (in_array($rule['id'], ['CLR-01', 'CLR-03'], true) && $relative === 'resources/css/design-tokens.css') {
                    continue;
                }
                preg_match_all($rule['pattern'], $content, $matches, PREG_OFFSET_CAPTURE);
                foreach ($matches[0] as [$match, $offset]) {
                    if (in_array($rule['id'], ['CLR-01', 'CLR-03'], true)
                        && ! $this->isColorContext($content, $offset, $file)) {
                        continue;
                    }
                    $line = substr_count(substr($content, 0, $offset), "\n") + 1;
                    $start = strrpos(substr($source, 0, $offset), "\n");
                    $start = $start === false ? 0 : $start + 1;
                    $end = strpos($source, "\n", $offset + strlen($match));
                    $snippet = trim((string) preg_replace('/\s+/', ' ', substr($source, $start, ($end === false ? strlen($source) : $end) - $start)));
                    $findings[] = [
                        'rule' => $rule['id'], 'file' => $relative, 'line' => $line,
                        'message' => $rule['warn'], 'source' => $snippet,
                        'fingerprint' => hash('sha256', $relative."\0".$rule['id']."\0".$snippet."\0".$match),
                    ];
                }
            }
        }

        return $findings;
    }

    private function relativePath(string $file): string
    {
        $root = $this->projectRoot ?? (is_string($this->viewsPath) ? $this->viewsPath : '');

        return ltrim(substr(str_replace('\\', '/', $file), strlen(rtrim(str_replace('\\', '/', $root), '/'))), '/');
    }

    private function isColorContext(string $content, int $offset, string $file): bool
    {
        // CSS selectors and url(#fragment) are identifiers, not colors.
        $prefix = substr($content, 0, $offset);
        if (preg_match('/url\(\s*[\x27\"]?[^)]*$/i', $prefix)) {
            return false;
        }
        if (str_ends_with($file, '.css')) {
            return (bool) preg_match('/(?:[;{]\s*|^)\s*[\w-]+\s*:[^;{}]*$/s', $prefix);
        }
        if (preg_match('/<style\b[^>]*>(?:(?!<\/style>).)*$/is', $prefix)) {
            return (bool) preg_match('/(?:[;{]\s*|^)\s*[\w-]+\s*:[^;{}]*$/s', $prefix);
        }
        // Quoted style/class/SVG attributes (also Alpine-bound), including
        // JavaScript expressions in those presentation attributes.
        preg_match_all('/(?:^|\s)(?:[:@\w-]*class|[:@\w-]*style|fill|stroke|color|stop-color|flood-color|lighting-color)\s*=\s*([\x27\"])(.*?)\1/s', $content, $attributes, PREG_OFFSET_CAPTURE);
        foreach ($attributes[2] as [$value, $start]) {
            if ($offset >= $start && $offset < $start + strlen($value)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $excludes */
    private function excluded(string $path, array $excludes): bool
    {
        foreach ($excludes as $exclude) {
            if (str_contains('/'.$path, $exclude)) {
                return true;
            }
        }

        return false;
    }
}
