<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\DatabaseNotification;

/**
 * An in-app notification of one user. Rows are written by `Channels\TenantDatabaseChannel` only.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $type
 * @property string $notifiable_id
 * @property array<string, mixed> $data
 * @property string $notification_key
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable $created_at
 */
#[UseFactory(NotificationFactory::class)]
final class Notification extends DatabaseNotification
{
    use BelongsToTenant;

    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    protected $table = 'notifications';

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
