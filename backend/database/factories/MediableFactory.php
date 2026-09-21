<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Media\Models\Mediable;
use App\Modules\Media\Models\MediaItem;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Mediable> */
final class MediableFactory extends Factory
{
    use ForTenant;

    protected $model = Mediable::class;

    public function definition(): array
    {
        return [
            // Outside tenancy and without forTenant() there is no tenant: the NOT NULL column then fails loudly.
            'media_item_id' => fn (array $attributes): ?string => ($tenantId = self::tenantOf($attributes)) === null
                ? null
                : MediaItem::factory()->ready()->state(['tenant_id' => $tenantId])->create()->id,
            'mediable_type' => 'ticket',
            'mediable_id' => (string) Str::uuid7(),
            'role' => 'attachment',
            'created_at' => now(),
        ];
    }

    /** @param array<string, mixed> $attributes */
    private static function tenantOf(array $attributes): ?string
    {
        $tenantId = $attributes['tenant_id'] ?? tenant()?->getTenantKey();

        return is_string($tenantId) ? $tenantId : null;
    }
}
