<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Models;

use App\Modules\Contacts\Concerns\HasTags;
use App\Modules\Contacts\Enums\OrganizationTier;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A requester (docs/04-domain/contacts.md). Email is unique per workspace, case-insensitively.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $organization_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property array<string, mixed> $external_ids
 * @property array<string, mixed> $metadata
 * @property CarbonImmutable|null $last_ticket_at
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable $created_at
 */
#[UseFactory(ContactFactory::class)]
final class Contact extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    use HasTags;
    use HasUuids;

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = [
        'external_ids' => '{}',
        'metadata' => '{}',
    ];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The tier that applies to this contact's tickets.
     */
    public function tier(): OrganizationTier
    {
        $organization = $this->organization;

        return $organization === null ? OrganizationTier::Standard : $organization->tier;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public static function findByEmail(string $email): ?self
    {
        return self::query()->whereRaw('lower(email) = lower(?)', [$email])->first();
    }

    protected function casts(): array
    {
        return [
            'external_ids' => 'array',
            'metadata' => 'array',
            'last_ticket_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
