<?php

declare(strict_types=1);

namespace App\Support\Mail;

/**
 * The sender of mail the platform sends to users on a workspace's behalf: invitations and ticket
 * notifications to agents (docs/04-domain/email.md §Addresses and identities). The address is the
 * platform's no-reply address (MAIL_FROM_ADDRESS), DKIM-signed for the mail domain; the display name
 * says which workspace it is about: "Acme via Smart Helpdesk".
 *
 * Mail to contacts uses the workspace's own sender instead (Mail\Support\WorkspaceMailIdentity).
 */
final readonly class PlatformSender
{
    public function __construct(public string $address, public string $name) {}

    public static function onBehalfOf(string $workspaceName): self
    {
        $product = (string) config('helpdesk.mail.product_name', 'Smart Helpdesk');
        $workspace = trim($workspaceName);

        return new self(
            (string) config('mail.from.address'),
            $workspace === '' ? $product : "{$workspace} via {$product}",
        );
    }
}
