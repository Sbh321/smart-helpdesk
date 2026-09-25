<?php

declare(strict_types=1);

namespace App\Modules\Billing\Notifications;

use App\Modules\Billing\Models\SubscriptionPayment;
use App\Modules\Billing\Support\Money;
use App\Support\Mail\PlatformSender;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PaymentApproved extends Notification
{
    public function __construct(private readonly SubscriptionPayment $payment) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tenant = $this->payment->tenant;
        $sender = PlatformSender::onBehalfOf((string) $tenant->name);
        $until = $this->payment->period_ends_at?->format('j F Y') ?? '';

        return (new MailMessage)
            ->from($sender->address, $sender->name)
            ->subject("Payment received: {$tenant->name} is on {$this->payment->plan->name} until {$until}")
            ->line('Your payment of '.Money::format($this->payment->amount_minor, $this->payment->currency).' was checked and approved.')
            ->line("{$tenant->name} is on the {$this->payment->plan->name} plan until {$until}.")
            ->action('See billing', BillingLinks::page((string) $tenant->slug));
    }
}
