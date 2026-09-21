<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\TenantSettingFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The one settings row of a workspace: overrides of the code defaults, per section.
 * Read and written only through `Settings\Settings`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property array<string, mixed> $data
 * @property int $version
 */
#[UseFactory(TenantSettingFactory::class)]
final class TenantSetting extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TenantSettingFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['data' => '{}', 'version' => 0];

    protected function casts(): array
    {
        return ['data' => 'array', 'version' => 'integer'];
    }
}
