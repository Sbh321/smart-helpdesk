<?php

declare(strict_types=1);

namespace App\Modules\Platform\Notifications;

use App\Modules\Platform\Models\PlatformInvitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

final class PlatformAdminInvitation extends Notification
{
    public function __construct(#[SensitiveParameter] private readonly string $token, private readonly string $inviterName) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = sprintf('https://%s/platform/accept-invitation?token=%s', config('helpdesk.hosts.admin'), $this->token);

        return (new MailMessage)
            ->subject('You are invited to run Smart Helpdesk as a platform admin')
            ->line("{$this->inviterName} invited you to the Smart Helpdesk platform console, where workspaces, plans and payments are managed.")
            ->action('Accept the invitation', $url)
            ->line('The invitation is valid for '.PlatformInvitation::HOURS.' hours.');
    }
}
