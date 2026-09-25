<?php

declare(strict_types=1);

namespace App\Modules\Billing\Notifications;

/** Links in billing emails. */
final class BillingLinks
{
    /** The workspace's Billing settings page. */
    public static function page(string $workspace): string
    {
        return sprintf('https://%s/%s/settings/billing', config('helpdesk.hosts.app'), $workspace);
    }
}
