<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your workspaces" (M5-02): the name and sign-in link of each workspace the address belongs to, and
 * nothing else about the accounts.
 */
final class WorkspaceReminder extends Notification
{
    /**
     * @param  list<array{name: string, slug: string}>  $workspaces
     */
    public function __construct(public readonly array $workspaces) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Your Smart Helpdesk workspaces')
            ->line(count($this->workspaces) === 1
                ? 'Your email address can sign in to this workspace:'
                : 'Your email address can sign in to these workspaces:');

        foreach ($this->workspaces as $workspace) {
            $message->line(sprintf('%s: %s', $workspace['name'], $this->signInUrl($workspace['slug'])));
        }

        if (count($this->workspaces) === 1) {
            $message->action('Sign in to '.$this->workspaces[0]['name'], $this->signInUrl($this->workspaces[0]['slug']));
        }

        return $message->line('If you did not ask for this, you can ignore this email.');
    }

    private function signInUrl(string $slug): string
    {
        return sprintf('https://%s/%s/login', config('helpdesk.hosts.app'), $slug);
    }
}
