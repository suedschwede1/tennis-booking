<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\UiLinter;
use App\Services\UiRules;
use Illuminate\Console\Command;
use JsonException;

final class LintUiCommand extends Command
{
    protected $signature = 'lint:ui
        {--summary : Show counts only}
        {--rule= : Filter a rule or category, e.g. CLR}
        {--strict : Report all legacy findings as errors}
        {--write-baseline : Create the initial legacy inventory; never overwrite it}
        {--prune-baseline : Remove resolved exceptions without accepting new ones}';

    protected $description = 'Enforce docs/DESIGN.md for Blade and application CSS';

    public function handle(): int
    {
        if ($this->option('write-baseline') && $this->option('prune-baseline')) {
            $this->error('Choose one baseline operation.');

            return self::FAILURE;
        }
        $rules = UiRules::all();
        if ($filter = $this->option('rule')) {
            $rules = array_values(array_filter($rules, static fn (array $r): bool => str_starts_with($r['id'], strtoupper((string) $filter))));
            if ($rules === [] || $this->option('write-baseline') || $this->option('prune-baseline')) {
                $this->error('Unknown rule, or a baseline operation combined with a rule filter.');

                return self::FAILURE;
            }
        }
        $roots = config('ui-design.roots');
        if (! is_array($roots)) {
            $this->error('Missing UI configuration. Run php artisan config:clear first.');

            return self::FAILURE;
        }
        $linter = new UiLinter($roots, $rules, base_path());
        if ($linter->files() === []) {
            $this->error('No UI sources found; check ui-design.roots.');

            return self::FAILURE;
        }
        $findings = $linter->run();
        $path = config('ui-design.baseline');
        $current = [];
        foreach ($findings as $finding) {
            $key = $finding['fingerprint'];
            $current[$key] ??= ['file' => $finding['file'], 'rule' => $finding['rule'], 'source' => $finding['source'], 'count' => 0];
            $current[$key]['count']++;
        }
        if ($this->option('write-baseline')) {
            if (file_exists($path) || array_filter($findings, fn (array $f): bool => $this->migrated($f['file']))) {
                $this->error('Refusing to overwrite a baseline or accept violations in migrated views.');

                return self::FAILURE;
            }
            $this->writeBaseline($path, $current);
            $this->info('Initial legacy inventory created. Review it before committing.');

            return self::SUCCESS;
        }
        try {
            if (! is_file($path)) {
                throw new JsonException('Missing baseline. Create and review the initial inventory.');
            }
            $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (($data['version'] ?? null) !== 1 || ! is_array($data['findings'] ?? null)) {
                throw new JsonException('Invalid baseline schema.');
            }
            $baseline = $data['findings'];
            foreach ($baseline as $entry) {
                if (! is_array($entry) || ! is_int($entry['count'] ?? null) || $entry['count'] < 1) {
                    throw new JsonException('Invalid baseline count.');
                }
            }
        } catch (JsonException $e) {
            $this->error('Cannot read UI baseline: '.$e->getMessage());

            return self::FAILURE;
        }
        if ($this->option('prune-baseline')) {
            $pruned = [];
            foreach ($baseline as $key => $entry) {
                if (isset($current[$key]) && ! $this->migrated($current[$key]['file'])) {
                    $pruned[$key] = $current[$key];
                    $pruned[$key]['count'] = min($entry['count'], $current[$key]['count']);
                }
            }
            $this->writeBaseline($path, $pruned);
            $baseline = $pruned;
        }
        $seen = [];
        $errors = 0;
        foreach ($findings as $finding) {
            $key = $finding['fingerprint'];
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            if (! $this->option('strict') && ! $this->migrated($finding['file']) && $seen[$key] <= ($baseline[$key]['count'] ?? 0)) {
                continue;
            }
            $errors++;
            if (! $this->option('summary')) {
                $this->error(sprintf('[%s] %s:%d %s', $finding['rule'], $finding['file'], $finding['line'], $finding['message']));
            }
        }
        $this->line(sprintf('%d blocking findings; %d total findings in %d files.', $errors, count($findings), count($linter->files())));

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function migrated(string $path): bool
    {
        foreach (config('ui-design.migrated', []) as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, array<string, mixed>> $findings */
    private function writeBaseline(string $path, array $findings): void
    {
        ksort($findings);
        file_put_contents($path, json_encode(['version' => 1, 'findings' => $findings], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
}
