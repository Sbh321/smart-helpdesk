<?php

declare(strict_types=1);

namespace App\Modules\Billing\Notifications;

use App\Modules\Billing\Enums\SubscriptionState;
use App\Modules\Billing\Support\SubscriptionStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Mail\PlatformSender;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** One of the reminders of `billing:remind` (ADR-0025 §6). */
final class SubscriptionReminder extends Notification
{
    public function __construct(
        private readonly string $key,
        private readonly Tenant $tenant,
        private readonly SubscriptionStatus $status,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = (string) $this->tenant->name;
        $what = $this->status->plan?->isTrial() === true ? 'free trial' : 'subscription';
        $endsOn = $this->status->endsAt?->format('j F Y') ?? '';
        $graceEndsOn = $this->status->graceEndsAt?->format('j F Y') ?? '';
        $sender = PlatformSender::onBehalfOf($name);
        $message = (new MailMessage)->from($sender->address, $sender->name);

        $message = match (true) {
            str_starts_with($this->key, 'ends-in-') => $message
                ->subject("{$name}: your {$what} ends on {$endsOn}")
                ->line("The {$what} of {$name} ends on {$endsOn}.")
                ->line('Renew it under Settings, Billing: choose a plan, pay, and upload the receipt. Everything keeps working while it is checked.'),
            $this->status->state === SubscriptionState::Grace => $message
                ->subject("{$name}: your {$what} has ended")
                ->line("The {$what} of {$name} ended on {$endsOn}. Everything keeps working until {$graceEndsOn}.")
                ->line("After {$graceEndsOn} the workspace becomes read-only until a payment is approved."),
            default => $message
                ->subject("{$name} is now read-only")
                ->line("{$name} is read-only because its {$what} ended and was not renewed. Your team can still read and export everything.")
                ->line('Renew it under Settings, Billing; the workspace opens again as soon as the payment is approved.'),
        };

        return $message->action('Open billing', BillingLinks::page((string) $this->tenant->slug));
    }
}
