<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use App\Support\Mail\PlatformSender;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class UserInvitation extends Notification
{
    public function __construct(
        private readonly string $token,
        private readonly string $workspace,
        private readonly string $workspaceName,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = sprintf(
            'https://%s/%s/accept-invitation?token=%s',
            config('helpdesk.hosts.app'),
            $this->workspace,
            $this->token,
        );

        $sender = PlatformSender::onBehalfOf($this->workspaceName);

        return (new MailMessage)
            ->from($sender->address, $sender->name)
            ->subject("You have been invited to {$this->workspaceName} on Smart Helpdesk")
            ->line("You can now join the {$this->workspaceName} workspace.")
            ->action('Accept the invitation', $url)
            ->line('The invitation is valid for 48 hours.');
    }
}
