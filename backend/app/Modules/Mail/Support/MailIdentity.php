<?php

declare(strict_types=1);

namespace App\Modules\Mail\Support;

/**
 * How a workspace appears in mail (docs/04-domain/email.md §Addresses and identities).
 *
 * Contacts receive mail from `"<sender name>" <support+<slug>@<mail domain>>`; the same plus-address
 * is the workspace's intake address, so a reply that loses its `Reply-To` still reaches the workspace.
 * The sender name is the workspace's setting (`email.sender_name`) or "<Workspace> Support".
 */
final readonly class MailIdentity
{
    public function __construct(
        public string $domain,
        public string $workspaceSlug,
        public string $workspaceName,
        public ?string $senderNameSetting,
    ) {}

    /** "<Workspace> Support", unless the workspace name already says so ("Acme Support"). */
    public function defaultSenderName(): string
    {
        $name = trim($this->workspaceName);

        return preg_match('/\bsupport$/i', $name) === 1 ? $name : trim("{$name} Support");
    }

    public function senderName(): string
    {
        $name = trim((string) $this->senderNameSetting);

        return $name === '' ? $this->defaultSenderName() : $name;
    }

    public function senderAddress(): string
    {
        return $this->intakeAddress();
    }

    public function intakeAddress(): string
    {
        return self::intakeAddressOf($this->workspaceSlug, $this->domain);
    }

    /**
     * The intake address of a workspace without its settings, for mail sent outside it (the welcome
     * mail after sign-up); the domain defaults to the platform's mail host.
     */
    public static function intakeAddressOf(string $slug, ?string $domain = null): string
    {
        return sprintf('support+%s@%s', $slug, $domain ?? (string) config('helpdesk.hosts.mail'));
    }

    /** The Reply-To of ticket mail with a placeholder for the ticket id. */
    public function replyToPattern(): string
    {
        return sprintf('ticket+<ticket-id>@%s', $this->domain);
    }
}
