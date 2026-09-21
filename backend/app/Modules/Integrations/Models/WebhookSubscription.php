<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Models;

use App\Models\User;
use App\Modules\Integrations\Casts\PostgresTextArray;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\WebhookSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A workspace's webhook endpoint (docs/07-api/webhooks.md §Subscription model). The secret is
 * stored with the `encrypted` cast, is hidden from serialisation and is returned by the API only
 * once, when the subscription is created or its secret rotated.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $url
 * @property list<string> $events
 * @property string $secret
 * @property string|null $previous_secret
 * @property CarbonImmutable|null $previous_secret_expires_at
 * @property string $api_version
 * @property bool $is_active
 * @property int $consecutive_failures
 * @property CarbonImmutable|null $disabled_at
 * @property string|null $disabled_reason
 * @property string|null $created_by_user_id
 * @property CarbonImmutable|null $last_delivery_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[UseFactory(WebhookSubscriptionFactory::class)]
final class WebhookSubscription extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<WebhookSubscriptionFactory> */
    use HasFactory;

    use HasUuids;

    public const DISABLED_MANUALLY = 'manual';

    public const DISABLED_FAILURES = 'consecutive_failures';

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['secret', 'previous_secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'events' => PostgresTextArray::class,
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'previous_secret_expires_at' => 'immutable_datetime',
            'is_active' => 'bool',
            'consecutive_failures' => 'integer',
            'disabled_at' => 'immutable_datetime',
            'last_delivery_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'subscription_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Active subscriptions of the current workspace that listen to `$eventType`.
     *
     * @param  Builder<self>  $query
     */
    public function scopeListeningTo(Builder $query, string $eventType): void
    {
        $query->where('is_active', true)->whereRaw('? = ANY (events)', [$eventType]);
    }

    /**
     * Secrets that sign a delivery at `$now`: the current one, plus the previous one for 24 hours
     * after a rotation.
     *
     * @return list<string>
     */
    public function signingSecrets(CarbonImmutable $now): array
    {
        $secrets = [$this->secret];
        if ($this->previous_secret !== null && $this->previous_secret_expires_at?->greaterThan($now)) {
            $secrets[] = $this->previous_secret;
        }

        return $secrets;
    }
}
