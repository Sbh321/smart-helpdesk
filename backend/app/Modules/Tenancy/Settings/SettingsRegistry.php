<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Settings;

use InvalidArgumentException;

/**
 * The sections that exist. A singleton filled by the module service providers.
 */
final class SettingsRegistry
{
    /** @var array<string, SettingsSection> */
    private array $sections = [];

    public function register(SettingsSection $section): void
    {
        $this->sections[$section->key()] = $section;
        ksort($this->sections);
    }

    public function has(string $key): bool
    {
        return isset($this->sections[$key]);
    }

    public function get(string $key): SettingsSection
    {
        return $this->sections[$key] ?? throw new InvalidArgumentException("Unknown settings section [{$key}].");
    }

    /** @return array<string, SettingsSection> */
    public function all(): array
    {
        return $this->sections;
    }

    /**
     * Splits `automation.priority.baseline.weights` into its section and the path inside it.
     * The longest registered prefix wins.
     *
     * @return array{SettingsSection, string|null}
     */
    public function locate(string $key): array
    {
        $parts = explode('.', $key);
        for ($length = count($parts); $length > 0; $length--) {
            $candidate = implode('.', array_slice($parts, 0, $length));
            if (isset($this->sections[$candidate])) {
                $rest = implode('.', array_slice($parts, $length));

                return [$this->sections[$candidate], $rest === '' ? null : $rest];
            }
        }

        throw new InvalidArgumentException("No settings section owns [{$key}].");
    }
}
