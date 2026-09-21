<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Settings;

use Closure;

/**
 * A section described by data: defaults, rules and an optional cross-field check.
 */
final readonly class ArraySection implements SettingsSection
{
    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $rules
     * @param  (Closure(array<string, mixed>): array<string, string>)|null  $check
     */
    public function __construct(
        private string $key,
        private array $defaults,
        private array $rules,
        private ?Closure $check = null,
        private ?string $configKey = null,
    ) {}

    /**
     * Defaults come from `config('helpdesk.<key>')`, limited to the keys that have a rule, so config
     * entries that are not tenant settings (for example `tickets.default_categories`) stay out.
     * Config is read on every call: an operator's (or a test's) change applies without a rebuild.
     *
     * @param  array<string, mixed>  $rules
     * @param  (Closure(array<string, mixed>): array<string, string>)|null  $check
     */
    public static function fromConfig(string $key, array $rules, ?Closure $check = null): self
    {
        return new self($key, [], $rules, $check, "helpdesk.{$key}");
    }

    public function key(): string
    {
        return $this->key;
    }

    public function defaults(): array
    {
        if ($this->configKey === null) {
            return $this->defaults;
        }

        $top = array_unique(array_map(fn (string $rule): string => explode('.', $rule)[0], array_keys($this->rules)));

        return array_intersect_key((array) config($this->configKey, []), array_flip($top));
    }

    public function rules(): array
    {
        return $this->rules;
    }

    public function check(array $values): array
    {
        return $this->check === null ? [] : ($this->check)($values);
    }
}
