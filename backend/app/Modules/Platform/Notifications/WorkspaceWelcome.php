<?php

declare(strict_types=1);

namespace App\Modules\Platform\Notifications;

use App\Modules\Mail\Support\MailIdentity;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the owner once a sign-up has created the workspace: where to sign in, the address customers
 * write to, and the first things to set up (the same steps as the dashboard's "Get started" panel).
 */
final class WorkspaceWelcome extends Notification
{
    public function __construct(
        private readonly string $slug,
        private readonly string $workspaceName,
        private readonly int $trialDays,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = sprintf('https://%s/%s', config('helpdesk.hosts.app'), $this->slug);
        $intake = MailIdentity::intakeAddressOf($this->slug);

        return (new MailMessage)
            ->subject("{$this->workspaceName} is ready on Smart Helpdesk")
            ->line("Your workspace {$this->workspaceName} is ready, on a {$this->trialDays}-day free trial.")
            ->line("Your team signs in at {$app}.")
            ->line("Your customers can email {$intake}: every message becomes a ticket, and replies to ticket emails are added to the ticket. You can also forward your existing support mailbox to this address.")
            ->line('To get started: invite your team, give agents their skills and teams, check the SLA targets and business hours, and add your logo. The Get started panel on the dashboard links to each step.')
            ->action('Sign in to '.$this->workspaceName, "{$app}/login");
    }
}
