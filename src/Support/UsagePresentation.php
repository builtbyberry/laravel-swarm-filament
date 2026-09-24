<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Support;

/** Internal, non-persisted presentation of complete primary token counts. */
final class UsagePresentation
{
    /** @return array{generation: ?string, tokens: ?int, label: string} */
    public static function report(mixed $usage): array
    {
        if (! is_array($usage)) {
            return self::state(null, null);
        }

        $native = array_key_exists('input_tokens', $usage) || array_key_exists('output_tokens', $usage);
        $legacy = array_key_exists('prompt_tokens', $usage) || array_key_exists('completion_tokens', $usage);

        if ($native && $legacy) {
            return self::state('mixed', null);
        }

        if (! $native && ! $legacy) {
            return self::state(null, null);
        }

        $generation = $native ? 'native' : 'legacy';
        $input = $usage[$native ? 'input_tokens' : 'prompt_tokens'] ?? null;
        $output = $usage[$native ? 'output_tokens' : 'completion_tokens'] ?? null;
        $known = is_int($input) && $input >= 0 && is_int($output) && $output >= 0;

        return self::state($generation, $known && $input <= PHP_INT_MAX - $output ? $input + $output : null);
    }

    /**
     * Empty iterable means no runs; an empty report is an unknown contributor.
     *
     * @param  iterable<mixed>  $reports
     * @return array{generation: ?string, tokens: ?int, label: string}
     */
    public static function window(iterable $reports): array
    {
        $generation = null;
        $tokens = 0;
        foreach ($reports as $report) {
            $state = self::report($report);
            if ($state['generation'] !== null) {
                $generation = $generation === null || $generation === $state['generation']
                    ? $state['generation']
                    : 'mixed';
            }
            $tokens = $tokens !== null && $state['tokens'] !== null && $tokens <= PHP_INT_MAX - $state['tokens']
                ? $tokens + $state['tokens']
                : null;
        }

        return self::state($generation, $generation === 'mixed' ? null : $tokens);
    }

    /** @return array{generation: ?string, tokens: ?int, label: string} */
    private static function state(?string $generation, ?int $tokens): array
    {
        return [
            'generation' => $generation,
            'tokens' => $tokens,
            'label' => $tokens !== null ? number_format($tokens) : ($generation === 'mixed' ? 'Mixed or unavailable' : 'Unavailable'),
        ];
    }
}
