<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Tenancy\Models\TenantSetting;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TenantSetting> */
final class TenantSettingFactory extends Factory
{
    use ForTenant;

    protected $model = TenantSetting::class;

    public function definition(): array
    {
        return ['data' => [], 'version' => 0];
    }
}
