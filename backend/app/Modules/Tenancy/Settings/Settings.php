<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Settings;

use App\Modules\Audit\Audit;
use App\Modules\Tenancy\Exceptions\SettingsInvalid;
use App\Modules\Tenancy\Models\TenantSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Workspace settings (docs/03-architecture/configuration.md): code defaults with the tenant's
 * overrides merged over them, cached per tenant, versioned on every write.
 *
 * Outside a tenant (console, central routes) every read answers the defaults and version 0.
 */
final readonly class Settings
{
    private const string CACHE_KEY = 'settings';

    public function __construct(private SettingsRegistry $registry) {}

    /** `get('automation.priority.baseline.weights')`, `get('tickets.reopen_window_days')`. */
    public function get(string $key, mixed $default = null): mixed
    {
        [$section, $path] = $this->registry->locate($key);
        $values = $this->section($section->key());

        return $path === null ? $values : Arr::get($values, $path, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function section(string $key): array
    {
        $section = $this->registry->get($key);

        return array_replace_recursive($section->defaults(), (array) Arr::get($this->stored()['data'], $this->slot($key), []));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return array_map(fn (SettingsSection $section): array => $this->section($section->key()), $this->registry->all());
    }

    public function version(): int
    {
        return $this->stored()['version'];
    }

    /**
     * Merges the submitted values into a section, validates the result and stores it.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> the section after the write
     */
    public function update(string $key, array $input): array
    {
        $section = $this->registry->get($key);

        $values = DB::transaction(function () use ($section, $key, $input): array {
            $row = TenantSetting::query()->lockForUpdate()->first() ?? new TenantSetting;
            $before = array_replace_recursive($section->defaults(), (array) Arr::get($row->data, $this->slot($key), []));

            $unknown = array_diff(array_keys(Arr::dot($input)), array_keys(Arr::dot($section->defaults())));
            if ($unknown !== []) {
                throw new SettingsInvalid($key, array_fill_keys($unknown, 'This setting does not exist.'));
            }

            $after = array_replace_recursive($before, $input);
            Validator::make($after, $section->rules())->validate();
            $broken = $section->check($after);
            if ($broken !== []) {
                throw new SettingsInvalid($key, $broken);
            }

            if ($section instanceof StoresOutsideSettings) {
                $section->persist($after);
            } else {
                $data = $row->data;
                Arr::set($data, $this->slot($key), $after);
                $row->data = $data;
            }
            $row->version++;
            $row->save();

            Audit::record('settings.updated', $row, [
                'section' => $key,
                'old' => $before,
                'new' => $after,
                'version' => $row->version,
            ]);

            return $after;
        });

        Cache::forget(self::CACHE_KEY);

        return $values;
    }

    /**
     * Inside a tenant the cache is tagged per tenant by the tenancy bootstrapper, so one key is enough.
     *
     * @return array{version: int, data: array<string, mixed>}
     */
    private function stored(): array
    {
        if (tenant() === null) {
            return ['version' => 0, 'data' => []];
        }

        /** @var array{version: int, data: array<string, mixed>} */
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $row = TenantSetting::query()->first();

            return ['version' => $row->version ?? 0, 'data' => $row->data ?? []];
        });
    }

    /** Section keys contain dots; one flat slot per section keeps `automation` from nesting. */
    private function slot(string $key): string
    {
        return str_replace('.', '_', $key);
    }
}
