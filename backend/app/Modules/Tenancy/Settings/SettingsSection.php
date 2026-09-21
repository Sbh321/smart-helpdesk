<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Settings;

/**
 * One section of the workspace settings (docs/03-architecture/configuration.md). The module that owns
 * the behaviour registers the section in its service provider, so Tenancy never imports that module.
 */
interface SettingsSection
{
    /** Dot key, also the URL segment: `branding`, `automation.priority`. */
    public function key(): string;

    /**
     * Code defaults; tenant overrides are merged over them, so a new key needs no data migration.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array;

    /**
     * Laravel rules for the merged section (defaults + stored + submitted).
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Rules that span fields (weights sum to 1, thresholds decrease). Field => message, empty when valid.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public function check(array $values): array;
}
