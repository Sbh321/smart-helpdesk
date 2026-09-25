<?php

declare(strict_types=1);

namespace App\Modules\Platform\Listeners;

use App\Modules\Billing\Events\PaymentSubmitted;
use App\Modules\Platform\Models\PlatformUser;
use App\Modules\Platform\Notifications\PaymentToReview;
use Illuminate\Support\Facades\Notification;

/** Tells every active platform admin that a receipt waits for review. */
final class NotifyAdminsOfPayment
{
    public function handle(PaymentSubmitted $event): void
    {
        Notification::send(
            PlatformUser::query()->active()->get(),
            new PaymentToReview($event->paymentId, $event->workspaceName),
        );
    }
}
