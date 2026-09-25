<?php

declare(strict_types=1);

namespace App\Modules\Platform\Notifications;

use App\Modules\Platform\Models\WorkspaceSignup;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/** The link that turns a sign-up into a workspace (ADR-0025 §8). */
final class VerifySignup extends Notification
{
    public function __construct(#[SensitiveParameter] private readonly string $token, private readonly string $workspaceName) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = sprintf('https://%s/signup/verify?token=%s', config('helpdesk.hosts.app'), $this->token);

        return (new MailMessage)
            ->subject("Confirm your email to create {$this->workspaceName}")
            ->line("Confirm this address to create the {$this->workspaceName} workspace on Smart Helpdesk, with a free trial.")
            ->action('Confirm and create the workspace', $url)
            ->line('The link works for '.WorkspaceSignup::HOURS.' hours. If you did not sign up, ignore this email: nothing is created without it.');
    }
}
