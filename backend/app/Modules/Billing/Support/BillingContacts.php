<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Notifications\Notification;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * The people of a workspace who hear about its subscription: active users with `billing.manage`,
 * read inside the workspace's context so row-level security stays in force.
 */
final class BillingContacts
{
    public function notify(Tenant $tenant, Notification $notification): void
    {
        $tenant->run(function () use ($notification): void {
            try {
                $users = User::permission('billing.manage')->where('is_active', true)->get();
            } catch (PermissionDoesNotExist) {
                return;
            }
            foreach ($users as $user) {
                $user->notify($notification);
            }
        });
    }
}
