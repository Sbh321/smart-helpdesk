<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Notification> */
final class NotificationFactory extends Factory
{
    use ForTenant;

    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'id' => fn (): string => (string) Str::uuid7(),
            'type' => 'ticket_assigned',
            'notifiable_type' => (new User)->getMorphClass(),
            // The recipient is made in the notification's own workspace; outside any workspace it stays
            // null and the insert fails on the NOT NULL column, as every tenant factory must.
            'notifiable_id' => function (array $attributes): ?string {
                $tenantId = $attributes['tenant_id'] ?? tenant()?->getTenantKey();

                return is_string($tenantId)
                    ? (string) User::factory()->forTenant(Tenant::query()->findOrFail($tenantId))->create()->getKey()
                    : null;
            },
            'data' => ['kind' => 'ticket_assigned', 'ticket_id' => (string) Str::uuid7(), 'ticket_number' => 1, 'ticket_title' => 'Sample', 'summary' => 'A ticket was assigned to you'],
            'notification_key' => fn (): string => 'ticket_assigned:'.Str::uuid7(),
            'read_at' => null,
        ];
    }
}
