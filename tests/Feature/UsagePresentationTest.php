<?php

declare(strict_types=1);

use BuiltByBerry\LaravelSwarmFilament\Support\UsagePresentation;
use BuiltByBerry\LaravelSwarmFilament\Tests\Support\UsageRenderHarness;

test('usage presentation requires a complete nonnegative integer primary pair', function (mixed $usage, string $label, ?int $tokens) {
    expect(UsagePresentation::report($usage))->toMatchArray(['tokens' => $tokens, 'label' => $label]);
})->with(UsageRenderHarness::cases());

test('window unavailability is sticky and independent of report order', function () {
    $native = ['input_tokens' => 9, 'output_tokens' => 3];
    $legacy = ['prompt_tokens' => 4, 'completion_tokens' => 1];
    foreach ([[$native, $legacy, []], [$native, [], $legacy], [$legacy, $native, []], [$legacy, [], $native], [[], $native, $legacy], [[], $legacy, $native]] as $reports) {
        expect(UsagePresentation::window($reports))->toBe(['generation' => 'mixed', 'tokens' => null, 'label' => 'Mixed or unavailable']);
    }
    expect(UsagePresentation::window([])['tokens'])->toBe(0)
        ->and(UsagePresentation::window([[]])['tokens'])->toBeNull()
        ->and(UsagePresentation::window([$native, []]))->toBe(UsagePresentation::window([[], $native]));
});
