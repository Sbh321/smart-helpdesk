<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Support\Settings\PlatformSettings;

/**
 * Billing's platform settings (ADR-0025): the grace period after a subscription ends, and how to pay
 * (bank account, wallet id), shown to workspaces on their Billing page.
 */
final readonly class BillingSettings
{
    public const DEFAULT_GRACE_DAYS = 7;

    public function __construct(private PlatformSettings $settings) {}

    public function graceDays(): int
    {
        $value = $this->settings->get('billing', ['grace_days' => self::DEFAULT_GRACE_DAYS])['grace_days'];

        return is_numeric($value) ? max(0, (int) $value) : self::DEFAULT_GRACE_DAYS;
    }

    public function paymentInstructions(): string
    {
        $value = $this->settings->get('billing', ['payment_instructions' => ''])['payment_instructions'];

        return is_string($value) ? $value : '';
    }

    public function setGraceDays(int $days): void
    {
        $this->settings->put('billing', [...$this->settings->get('billing'), 'grace_days' => $days]);
    }

    public function setPaymentInstructions(string $text): void
    {
        $this->settings->put('billing', [...$this->settings->get('billing'), 'payment_instructions' => $text]);
    }
}
