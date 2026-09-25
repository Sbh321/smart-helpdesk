<?php

declare(strict_types=1);

namespace App\Modules\Platform\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A workspace sent a receipt; a platform admin should check it (ADR-0025 §3). */
final class PaymentToReview extends Notification
{
    public function __construct(private readonly string $paymentId, private readonly string $workspaceName) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Payment to review: {$this->workspaceName}")
            ->line("{$this->workspaceName} sent a payment with its receipt.")
            ->action('Review the payment', sprintf('https://%s/platform/payments?payment=%s', config('helpdesk.hosts.admin'), $this->paymentId));
    }
}
