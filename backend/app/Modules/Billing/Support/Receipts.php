<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Models\SubscriptionPayment;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Media\Support\MediaStorage;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Receipts are media items of their workspace (ADR-0025 §4), so they are read inside that
 * workspace's context, one visit per workspace for a page of payments.
 */
final readonly class Receipts
{
    public function __construct(private MediaStorage $storage) {}

    /**
     * Sets the `receipt` relation (a media item or null) on each payment.
     *
     * @param  iterable<int, SubscriptionPayment>  $payments
     */
    public function attach(iterable $payments): void
    {
        $byTenant = [];
        foreach ($payments as $payment) {
            $payment->setRelation('receipt', null);
            if ($payment->receipt_media_id !== null) {
                $byTenant[$payment->tenant_id][] = $payment;
            }
        }
        foreach ($byTenant as $tenantId => $group) {
            $tenant = Tenant::query()->find($tenantId);
            if ($tenant === null) {
                continue;
            }
            $items = $tenant->run(fn () => MediaItem::query()
                ->whereIn('id', array_map(fn (SubscriptionPayment $payment): string => (string) $payment->receipt_media_id, $group))
                ->get()->keyBy('id'));
            foreach ($group as $payment) {
                $payment->setRelation('receipt', $items->get((string) $payment->receipt_media_id));
            }
        }
    }

    /** A short-lived URL that shows the receipt in the browser, or null when it is gone. */
    public function openUrl(SubscriptionPayment $payment): ?string
    {
        if ($payment->receipt_media_id === null) {
            return null;
        }

        return $payment->tenant->run(function () use ($payment): ?string {
            $item = MediaItem::query()->whereKey($payment->receipt_media_id)->where('state', 'ready')->first();

            return $item === null ? null : $this->storage->viewUrl($item->storage_key, $item->name, (string) $item->mime_type);
        });
    }
}
