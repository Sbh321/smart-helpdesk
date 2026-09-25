<?php

declare(strict_types=1);

namespace App\Modules\Billing\Notifications;

use App\Modules\Billing\Models\SubscriptionPayment;
use App\Modules\Billing\Support\Money;
use App\Support\Mail\PlatformSender;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PaymentRejected extends Notification
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

        return (new MailMessage)
            ->from($sender->address, $sender->name)
            ->subject("We could not accept a payment for {$tenant->name}")
            ->line('The payment of '.Money::format($this->payment->amount_minor, $this->payment->currency).' sent on '.$this->payment->created_at?->format('j F Y').' was not accepted.')
            ->line('Reason: '.$this->payment->rejection_reason)
            ->line('Nothing has changed on your subscription. You can send the payment again with a clearer receipt or the right amount.')
            ->action('Open billing', BillingLinks::page((string) $tenant->slug));
    }
}
