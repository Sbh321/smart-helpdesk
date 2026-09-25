<?php

declare(strict_types=1);

namespace App\Modules\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A workspace sent a receipt for review; the Platform module tells the admins (ADR-0025 §3). */
final readonly class PaymentSubmitted
{
    use Dispatchable;

    public function __construct(public string $paymentId, public string $workspaceName) {}
}
