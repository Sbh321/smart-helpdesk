<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Audit\Audit;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Platform\Support\SignupSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Platform-wide settings for super admins (ADR-0025): self sign-up and the grace period. */
final readonly class PlatformSettingsController
{
    public function __construct(private SignupSettings $signup, private BillingSettings $billing) {}

    /**
     * Show the platform settings.
     *
     * @response array{data: array{signup_enabled: bool, grace_days: int, payment_instructions: string}}
     */
    public function show(): JsonResponse
    {
        return new JsonResponse(['data' => $this->values()]);
    }

    /**
     * Change the platform settings.
     *
     * @response array{data: array{signup_enabled: bool, grace_days: int, payment_instructions: string}}
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'signup_enabled' => ['sometimes', 'boolean'],
            'grace_days' => ['sometimes', 'integer', 'between:0,60'],
            'payment_instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $before = $this->values();
        if (array_key_exists('signup_enabled', $data)) {
            $this->signup->setEnabled((bool) $data['signup_enabled']);
        }
        if (array_key_exists('grace_days', $data)) {
            $this->billing->setGraceDays((int) $data['grace_days']);
        }
        if (array_key_exists('payment_instructions', $data)) {
            $this->billing->setPaymentInstructions(trim((string) $data['payment_instructions']));
        }
        Audit::record('platform.settings_updated', null, ['before' => $before, 'after' => $this->values()], tenantId: null);

        return new JsonResponse(['data' => $this->values()]);
    }

    /** @return array{signup_enabled: bool, grace_days: int, payment_instructions: string} */
    private function values(): array
    {
        return [
            'signup_enabled' => $this->signup->enabled(),
            'grace_days' => $this->billing->graceDays(),
            'payment_instructions' => $this->billing->paymentInstructions(),
        ];
    }
}
