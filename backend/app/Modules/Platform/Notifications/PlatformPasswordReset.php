<?php

declare(strict_types=1);

namespace App\Modules\Platform\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

final class PlatformPasswordReset extends Notification
{
    public function __construct(#[SensitiveParameter] private readonly string $token, private readonly string $email) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = sprintf('https://%s/platform/reset-password?%s', config('helpdesk.hosts.admin'), http_build_query(['token' => $this->token, 'email' => $this->email]));

        return (new MailMessage)
            ->subject('Reset your Smart Helpdesk platform password')
            ->line('Someone asked to reset the password of this platform admin account.')
            ->action('Choose a new password', $url)
            ->line('The link works once, for 60 minutes. If it was not you, ignore this email: your password stays as it is.');
    }
}
