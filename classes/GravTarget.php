<?php

namespace Grav\Plugin\Console;

/**
 * Value object describing which Grav version(s) a generated plugin/theme
 * should target. Centralises every version-dependent value (compatibility,
 * dependency floors, PHP floor) so the scaffolding templates never hardcode
 * a version — change it here and every template follows.
 */
final class GravTarget
{
    public const GRAV_17 = '1.7';
    public const GRAV_20 = '2.0';
    public const BOTH    = 'both';

    /** Non-interactive default when no --grav flag is supplied. */
    public const DEFAULT = self::GRAV_20;

    /**
     * @param string   $id             Canonical id: '1.7' | '2.0' | 'both'
     * @param string   $label          Human label for the wizard summary
     * @param string[] $compatibility  Values for the blueprint `compatibility.grav` list
     * @param string   $gravDep        Minimum Grav for the `dependencies` block (no `>=`)
     * @param string   $phpMin         Minimum PHP for composer.json (no `>=`)
     * @param bool     $supportsApi    Whether the API/Admin-Next template is offered
     */
    private function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly array $compatibility,
        public readonly string $gravDep,
        public readonly string $phpMin,
        public readonly bool $supportsApi,
    ) {
    }

    /**
     * Build a target from a user-supplied string. Accepts the canonical ids
     * plus a few friendly aliases ('2', '20', '1.7.0', 'legacy', 'all').
     *
     * @throws \InvalidArgumentException on an unknown value
     */
    public static function fromString(?string $value): self
    {
        $key = strtolower(trim((string) $value));

        $key = match ($key) {
            '', self::GRAV_20, '2', '20', '2.0.0' => self::GRAV_20,
            self::GRAV_17, '1.7.0', '17', 'legacy' => self::GRAV_17,
            self::BOTH, 'all', '1.7+2.0'           => self::BOTH,
            default => throw new \InvalidArgumentException(
                "Unknown Grav target \"{$value}\". Use one of: " . implode(', ', array_keys(self::choices()))
            ),
        };

        return match ($key) {
            self::GRAV_20 => new self(self::GRAV_20, 'Grav 2.0', ['2.0'], '2.0.0', '8.3.0', true),
            self::GRAV_17 => new self(self::GRAV_17, 'Grav 1.7', ['1.7'], '1.7.0', '7.3.6', false),
            self::BOTH    => new self(self::BOTH, 'Grav 1.7 + 2.0', ['1.7', '2.0'], '1.7.0', '8.0.0', false),
        };
    }

    /**
     * Wizard/flag choices: id => human description.
     *
     * @return array<string, string>
     */
    public static function choices(): array
    {
        return [
            self::GRAV_20 => 'Grav 2.0 only (modern, PHP 8.3+)',
            self::BOTH    => 'Grav 1.7 and 2.0 (maximum compatibility)',
            self::GRAV_17 => 'Grav 1.7 only (legacy)',
        ];
    }

    /** True when 2.0 is one of the targets. */
    public function is20(): bool
    {
        return in_array('2.0', $this->compatibility, true);
    }

    /** True when 1.7 is one of the targets (drives the legacy autoload note). */
    public function is17(): bool
    {
        return in_array('1.7', $this->compatibility, true);
    }

    /**
     * Flatten to the array injected into the Twig context as `component.target`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'label'         => $this->label,
            'compatibility' => $this->compatibility,
            'grav_dep'      => $this->gravDep,
            'php_min'       => $this->phpMin,
            'is20'          => $this->is20(),
            'is17'          => $this->is17(),
            'supports_api'  => $this->supportsApi,
        ];
    }
}
