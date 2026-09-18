<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PasswordResetLink extends Notification
{
    public function __construct(
        private readonly string $token,
        private readonly string $workspace,
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
            'https://%s/%s/reset-password?token=%s&email=%s',
            config('helpdesk.hosts.app'),
            $this->workspace,
            $this->token,
            urlencode((string) $notifiable->email),
        );

        return (new MailMessage)
            ->subject('Reset your Smart Helpdesk password')
            ->line('We received a request to reset your password.')
            ->action('Choose a new password', $url)
            ->line('The link is valid for 60 minutes. If you did not ask for this, ignore this email.');
    }
}
