<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Settings\Sections;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Settings\SettingsSection;
use App\Modules\Tenancy\Settings\StoresOutsideSettings;
use LogicException;

/**
 * Workspace name and time zone. Both are columns of `tenants`, because tenancy reads them before any
 * settings exist (`tenant('timezone')`).
 */
final class GeneralSection implements SettingsSection, StoresOutsideSettings
{
    public function key(): string
    {
        return 'general';
    }

    public function defaults(): array
    {
        $tenant = tenant();

        return [
            'name' => $tenant instanceof Tenant ? $tenant->name : '',
            'timezone' => $tenant instanceof Tenant ? $tenant->timezone : 'UTC',
        ];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'timezone' => ['required', 'string', 'timezone:all'],
        ];
    }

    public function check(array $values): array
    {
        return [];
    }

    public function persist(array $values): void
    {
        $tenant = tenant();
        if (! $tenant instanceof Tenant) {
            throw new LogicException('General settings can only be saved inside a workspace.');
        }

        $tenant->update(['name' => trim((string) $values['name']), 'timezone' => (string) $values['timezone']]);
    }
}
