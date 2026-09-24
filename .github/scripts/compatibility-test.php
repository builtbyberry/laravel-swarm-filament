<?php

declare(strict_types=1);

namespace FilamentCompatibility;

require __DIR__.'/compatibility.php';
require __DIR__.'/../../vendor/autoload.php';

function package(string $name, string $version, string $ref): array
{
    $repository = FILAMENT_REPOSITORIES[$name] ?? $name;

    return [
        'name' => $name, 'version' => $version,
        'source' => ['type' => 'git', 'url' => "https://github.com/{$repository}.git", 'reference' => $ref],
        'dist' => ['type' => 'zip', 'url' => "https://api.github.com/repos/{$repository}/zipball/{$ref}", 'reference' => $ref],
    ];
}

function rejects(callable $callback, string $label): void
{
    try {
        $callback();
    } catch (\RuntimeException) {
        return;
    }
    throw new \RuntimeException("Guard accepted negative control: {$label}");
}

$root = readJson($argv[1] ?? __DIR__.'/../../composer.json');
$controls = 0;
foreach (LANES as $lane) {
    $native = str_starts_with($lane, 'native1-');
    $adoption = str_starts_with($lane, 'adoption-') || $native;
    $candidate = ['name' => CORE, 'require' => ['laravel/ai' => $native ? '^1.0' : '^0.11.2']];
    $candidateRef = $native ? NATIVE_CANDIDATE_REF : CANDIDATE_REF;
    $minimum = in_array($lane, ['lowest', 'adoption-minimum', 'native1-minimum'], true);
    $set = packages([
        package(CORE, $native ? '0.27.0' : ($adoption ? '0.26.0' : 'v0.25.0'), $adoption ? $candidateRef : PUBLISHED_REF),
        package('laravel/ai', $native ? 'v1.0.0' : ($adoption ? 'v0.11.2' : 'v0.10.0'), $native ? NATIVE_AI_MINIMUM_REF : ($adoption ? AI_MINIMUM_REF : str_repeat('a', 40))),
        package('laravel/framework', 'v13.16.0', str_repeat('b', 40)),
        package('livewire/livewire', $minimum ? 'v4.0.0' : 'v4.1.0', str_repeat('c', 40)),
        package('filament/filament', 'v5.0.0', str_repeat('f', 40)),
        package('filament/support', 'v5.0.0', str_repeat('e', 40)),
    ]);
    $set['filament/support']['require']['livewire/livewire'] = $minimum ? '^4.0' : '^4.1';
    verify($set, $set, $lane);
    $prepared = prepare($root, $lane, $candidate);
    check($lane !== 'lowest' || $prepared === $root, 'Lowest lane must keep original constraints.');
    check(! $adoption || $prepared['repositories'][0]['package']['source']['reference'] === $candidateRef, 'Candidate must be immutable.');
    check(! $adoption || $prepared['require-dev']['laravel/ai'] === ($native ? ($minimum ? '1.0.0' : '^1.0') : ($minimum ? '0.11.2' : '^0.11.2')), 'Wrong AI lane pin.');
    check($lane !== 'published-0.25' || $prepared['require'][CORE] === '0.25.0', 'Published lane must stay pinned.');

    // Mutate the Composer evidence shape, both independently and in agreement.
    foreach (array_keys($set) as $name) {
        foreach (['version', 'source', 'dist'] as $field) {
            $bad = $set;
            unset($bad[$name][$field]);
            rejects(fn () => verify($set, $bad, $lane), "missing installed {$field}");
            rejects(fn () => verify($bad, $set, $lane), "missing locked {$field}");
            $controls += 2;
        }
        foreach (['dev-main', 'v99.0.0'] as $version) {
            if ($name === 'laravel/ai' && ! $adoption && $version === 'v99.0.0') {
                continue; // Legacy lanes retain their own transitive AI constraints.
            }
            $bad = $set;
            $bad[$name]['version'] = $version;
            rejects(fn () => verify($bad, $bad, $lane), "incorrect {$name} version");
            $controls++;
        }
        $bad = $set;
        $bad[$name]['source']['url'] = 'https://github.com/example/fork.git';
        rejects(fn () => verify($bad, $bad, $lane), 'fork source');
        $bad = $set;
        $bad[$name]['dist']['url'] = 'https://example.com/patched.zip';
        rejects(fn () => verify($bad, $bad, $lane), 'replaced archive');
        $bad = $set;
        $bad[$name]['dist']['reference'] = str_repeat('d', 40);
        rejects(fn () => verify($bad, $bad, $lane), 'archive ref mismatch');
        $bad = $set;
        $bad[$name]['source']['reference'] = 'main';
        $bad[$name]['dist']['reference'] = 'main';
        $bad[$name]['dist']['url'] = 'https://api.github.com/repos/'.(FILAMENT_REPOSITORIES[$name] ?? $name).'/zipball/main';
        rejects(fn () => verify($bad, $bad, $lane), 'moving source ref');
        $bad = $set;
        $bad[$name]['source']['type'] = 'svn';
        rejects(fn () => verify($bad, $bad, $lane), 'wrong source type');
        $bad = $set;
        $bad[$name]['dist'] = ['type' => 'path', 'url' => '/tmp/local-package', 'reference' => str_repeat('a', 40)];
        rejects(fn () => verify($bad, $bad, $lane), 'path distribution');
        foreach (['version', 'source', 'dist'] as $field) {
            $bad = $set;
            $replacement = package($name, 'v5.1.0', str_repeat('9', 40));
            $bad[$name][$field] = $replacement[$field];
            rejects(fn () => verify($set, $bad, $lane), 'installed field mismatch');
            rejects(fn () => verify($bad, $set, $lane), 'locked field mismatch');
            $controls += 2;
        }
        $controls += 6;
    }
    foreach ($native ? ['0.10.3', '0.11.0', '0.11.1', '0.11.2'] : ['0.10.3', '0.11.0', '0.11.1'] as $oldAi) {
        if ($adoption) {
            $bad = $set;
            $bad['laravel/ai']['version'] = $oldAi;
            rejects(fn () => verify($bad, $bad, $lane), 'old AI');
            $controls++;
        }
    }
    if ($adoption) {
        $bad = $set;
        $bad[CORE] = package(CORE, $native ? '0.26.0' : '0.27.0', $native ? CANDIDATE_REF : NATIVE_CANDIDATE_REF);
        $bad['laravel/ai'] = package('laravel/ai', $native ? 'v0.11.2' : 'v1.0.0', $native ? AI_MINIMUM_REF : NATIVE_AI_MINIMUM_REF);
        rejects(fn () => verify($bad, $bad, $lane), 'wrong coherent adoption generation');
        rejects(fn () => prepare($root, $lane, ['name' => CORE, 'require' => ['laravel/ai' => $native ? '^0.11.2' : '^1.0']]), 'wrong generation manifest');
        $controls += 2;
    }
    $bad = $set;
    $bad['filament/support']['version'] = 'v5.1.0';
    rejects(fn () => verify($bad, $bad, $lane), 'Filament self.version mismatch');
    $bad = $set;
    $bad['filament/support']['require']['livewire/livewire'] = '^4.99';
    rejects(fn () => verify($bad, $bad, $lane), 'unsatisfied actual Livewire contract');
    rejects(fn () => verify($set, $bad, $lane), 'lock/install contract mismatch');
    $controls += 3;
    $differentInstalled = $set;
    $differentInstalled['laravel/framework'] = package('laravel/framework', 'v13.17.0', str_repeat('e', 40));
    rejects(fn () => verify($set, $differentInstalled, $lane), 'valid installed package differs from lock');
    $controls++;
    foreach ([CORE, 'laravel/ai'] as $name) {
        if (($name === CORE && $lane === 'lowest') || ($name === 'laravel/ai' && ! in_array($lane, ['adoption-minimum', 'native1-minimum'], true))) {
            continue;
        }
        $bad = $set;
        $bad[$name] = package($name, $set[$name]['version'], str_repeat('d', 40));
        rejects(fn () => verify($bad, $bad, $lane), 'wrong pinned commit in otherwise consistent evidence');
        $controls++;
    }
}
rejects(fn () => prepare($root, 'adoption-current', ['name' => 'example/fork', 'require' => ['laravel/ai' => '^0.11.2']]), 'wrong manifest identity');
rejects(fn () => prepare($root, 'adoption-current', ['name' => CORE, 'require' => ['laravel/ai' => '^0.10']]), 'wrong manifest contract');
$badRoot = $root;
$badRoot['require'][CORE] = '^0.26';
rejects(fn () => prepare($badRoot, 'adoption-current', ['name' => CORE, 'require' => ['laravel/ai' => '^0.11.2']]), 'dropped older ranges');
rejects(fn () => verify([], [], 'unknown'), 'unknown lane');
echo count(LANES).' positive lanes and '.($controls + 4)." negative dependency controls passed.\n";
