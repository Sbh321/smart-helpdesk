<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Support;

use App\Models\User;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Str;

/**
 * The private channels and who may join them (docs/03-architecture/realtime.md §Channels).
 *
 * The tenant in a channel name is only ever compared with the session's tenant, never used to pick
 * one: a user of workspace A asking for `tenants.B.…` is refused before anything is looked up.
 * Internal notes travel on their own channel, so a member without `comments.internal` never
 * receives even the id of one.
 */
final class Channels
{
    public static function tickets(string $tenantId): string
    {
        return "tenants.{$tenantId}.tickets";
    }

    public static function ticket(string $tenantId, string $ticketId): string
    {
        return "tenants.{$tenantId}.tickets.{$ticketId}";
    }

    public static function ticketInternal(string $tenantId, string $ticketId): string
    {
        return "tenants.{$tenantId}.tickets.{$ticketId}.internal";
    }

    public static function user(string $tenantId, string $userId): string
    {
        return "tenants.{$tenantId}.users.{$userId}";
    }

    /**
     * Registers the authorisation callbacks on the broadcaster that answers the request. Called per
     * request rather than at boot, so the callbacks always sit on the configured connection.
     */
    public static function registerOn(Broadcaster $broadcaster): void
    {
        $broadcaster->channel('tenants.{tenantId}.tickets',
            fn (mixed $user, string $tenantId): bool => self::member($user, $tenantId) && $user->can('tickets.view'));

        $broadcaster->channel('tenants.{tenantId}.tickets.{ticketId}',
            fn (mixed $user, string $tenantId, string $ticketId): bool => self::canViewTicket($user, $tenantId, $ticketId));

        $broadcaster->channel('tenants.{tenantId}.tickets.{ticketId}.internal',
            fn (mixed $user, string $tenantId, string $ticketId): bool => self::canViewTicket($user, $tenantId, $ticketId)
                && $user->can('comments.internal'));

        $broadcaster->channel('tenants.{tenantId}.users.{userId}',
            fn (mixed $user, string $tenantId, string $userId): bool => self::member($user, $tenantId) && $user->id === $userId);
    }

    /** @phpstan-assert-if-true User $user */
    private static function member(mixed $user, string $tenantId): bool
    {
        $current = tenant('id');

        return $user instanceof User
            && is_string($current)
            && $tenantId === $current
            && $user->tenant_id === $current
            && $user->is_active;
    }

    /** @phpstan-assert-if-true User $user */
    private static function canViewTicket(mixed $user, string $tenantId, string $ticketId): bool
    {
        return self::member($user, $tenantId)
            && $user->can('tickets.view')
            && Str::isUuid($ticketId)
            // Tenant-scoped model and row-level security: another workspace's ticket does not exist here.
            && Ticket::query()->whereKey($ticketId)->exists();
    }
}
