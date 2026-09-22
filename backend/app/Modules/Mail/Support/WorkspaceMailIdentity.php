<?php

declare(strict_types=1);

namespace App\Modules\Mail\Support;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Settings\Settings;
use LogicException;

/**
 * Resolves the mail identity of the current workspace. Call it where the tenant is known (a request,
 * an after-commit listener) and hand the result to queued notifications as primitives.
 */
final readonly class WorkspaceMailIdentity
{
    public function __construct(private Settings $settings) {}

    public function current(): MailIdentity
    {
        $tenant = tenant();
        if (! $tenant instanceof Tenant) {
            throw new LogicException('The mail identity belongs to a workspace; none is active.');
        }

        $name = $this->settings->get('email.sender_name');

        return new MailIdentity(
            (string) config('helpdesk.hosts.mail'),
            $tenant->slug,
            $tenant->name,
            is_string($name) ? $name : null,
        );
    }
}
