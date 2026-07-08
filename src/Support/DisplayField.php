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
 *   never leaks ciphertext, whatever an upstream read does.
 *
 * Never throws.
 */
final class DisplayField
{
    /**
     * Core's persisted-cipher sentinel prefix. Inlined as a literal (with a test
     * pinning it) rather than importing the `@internal` SwarmPersistenceCipher —
     * a companion never couples to core internals (records 629/632).
     */
    private const SEALED_PREFIX = 'sw0:';

    public function __construct(
        public readonly ?string $value,
        public readonly bool $available,
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

        $value = is_string($rawValue) ? $rawValue : ($rawValue === null ? null : self::stringify($rawValue));

        // Never render ciphertext: a still-sealed value is unavailable regardless
        // of the flag the upstream read set.
        if ($value !== null && str_starts_with($value, self::SEALED_PREFIX)) {
            return new self(null, false);
        }

        return new self($value, $available);
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
            default => (string) json_encode($value),
        };
    }
}
