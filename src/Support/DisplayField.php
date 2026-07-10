<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament\Support;

/**
 * The companion's single rendering rule for a display-decrypted field.
 *
 * The v0.19 display contracts already degrade per row: each sealed field comes
 * back as a `value` plus a companion `<field>_available` flag (null + false when
 * it could not be decrypted). This value object turns that pair into something a
 * Filament infolist/table can render, applying one invariant everywhere:
 *
 * - an **unavailable** field renders as a marked placeholder, never the raw value;
 * - as defense in depth, a value that still looks like `sw0:` ciphertext is
 *   treated as unavailable even if the row flagged it available — the companion
 *   never leaks ciphertext, whatever an upstream read does. This holds at ANY
 *   nesting depth: a structured value (e.g. `['input' => ['q' => 'sw0:…']]`)
 *   would json-encode past a top-level prefix check, so each string leaf is
 *   masked in the DECODED structure BEFORE it is encoded — a sealed leaf never
 *   renders while its plaintext siblings stay available (a partial mask). Only a
 *   bare scalar that is itself sealed degrades the whole field to unavailable.
 *
 * This is the SINGLE sealed-value chokepoint the companion routes rendering
 * through; it mirrors the leaf-mask in {@see StreamTimelinePresenter::scrub()}
 * and the recursive scan in {@see MemorySnapshotPresenter}.
 *
 * Never throws.
 */
final readonly class DisplayField
{
    /**
     * Core's persisted-cipher sentinel prefix. Inlined as a literal (with a test
     * pinning it) rather than importing the `@internal` SwarmPersistenceCipher —
     * a companion never couples to core internals (records 629/632).
     */
    private const SEALED_PREFIX = 'sw0:';

    /**
     * Placeholder swapped in for a sealed string leaf inside a structured value.
     * Chosen so it never itself contains {@see SEALED_PREFIX} — masking must not
     * reintroduce the very sentinel it removes.
     */
    private const MASKED_LEAF = 'unavailable';

    public function __construct(
        public ?string $value,
        public bool $available,
    ) {}

    /**
     * Build from a display-contract row's `<field>` / `<field>_available` pair.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row, string $field): self
    {
        $rawValue = $row[$field] ?? null;
        $available = (bool) ($row["{$field}_available"] ?? true);

        if ($rawValue === null) {
            return new self(null, $available);
        }

        // Base case — a bare scalar string. A value that still looks like `sw0:`
        // ciphertext is unavailable regardless of the flag the upstream read set;
        // the whole field degrades because there is no sibling to preserve.
        if (is_string($rawValue)) {
            if (str_starts_with($rawValue, self::SEALED_PREFIX)) {
                return new self(null, false);
            }

            return new self($rawValue, $available);
        }

        // Structured / non-string scalar. Sealed string leaves are masked inside
        // the decoded structure BEFORE json_encode (partial mask), so a nested
        // `sw0:` value never renders while plaintext siblings survive.
        return new self(self::stringify($rawValue), $available);
    }

    public function isAvailable(): bool
    {
        return $this->available && $this->value !== null;
    }

    /**
     * The value to render, or the placeholder when the field is unavailable.
     */
    public function display(string $placeholder = 'unavailable'): string
    {
        return $this->isAvailable() ? (string) $this->value : $placeholder;
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            is_array($value) => (string) json_encode(self::maskSealedLeaves($value)),
            default => (string) json_encode($value),
        };
    }

    /**
     * Recursively mask any string leaf that still looks like `sw0:` ciphertext,
     * BEFORE the structure is json-encoded — so no sealed value renders at any
     * nesting depth while plaintext siblings are left intact (partial mask). This
     * is the structured-value arm of the single companion leak rule; it mirrors
     * {@see StreamTimelinePresenter::scrub()} for shape/naming consistency.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function maskSealedLeaves(array $value): array
    {
        return array_map(static function (mixed $item): mixed {
            if (is_array($item)) {
                return self::maskSealedLeaves($item);
            }

            if (is_string($item) && str_starts_with($item, self::SEALED_PREFIX)) {
                return self::MASKED_LEAF;
            }

            return $item;
        }, $value);
    }
}
