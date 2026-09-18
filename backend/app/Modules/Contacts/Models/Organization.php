<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Models;

use App\Modules\Contacts\Concerns\HasTags;
use App\Modules\Contacts\Enums\OrganizationTier;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $domain
 * @property OrganizationTier $tier
 * @property array<string, mixed> $external_ids
 * @property array<string, mixed> $metadata
 * @property CarbonImmutable $created_at
 */
#[UseFactory(OrganizationFactory::class)]
final class Organization extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use HasTags;
    use HasUuids;

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = [
        'tier' => 'standard',
        'external_ids' => '{}',
        'metadata' => '{}',
    ];

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    protected function casts(): array
    {
        return [
            'tier' => OrganizationTier::class,
            'external_ids' => 'array',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
