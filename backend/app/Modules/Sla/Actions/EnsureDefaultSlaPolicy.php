<?php

declare(strict_types=1);

namespace App\Modules\Sla\Actions;

use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Tenancy\Settings\Settings;

/**
 * The one runtime seed of the default 24x7 policy and its P1-P4 targets (docs/04-domain/sla.md).
 * Idempotent; must run inside the tenant. The schema migration keeps its own inline copy because a
 * migration must not change when application code does.
 */
final readonly class EnsureDefaultSlaPolicy
{
    /** Minutes as [first response, resolution] per priority level. */
    public const array DEFAULT_TARGETS = [
        'P1' => [30, 240],
        'P2' => [60, 480],
        'P3' => [240, 1440],
        'P4' => [480, 4320],
    ];

    public function __construct(private Settings $settings) {}

    public function __invoke(): SlaPolicy
    {
        $policy = SlaPolicy::query()->firstOrCreate(
            ['is_default' => true],
            ['name' => 'Default', 'applies_to_tier' => null, 'warning_fraction' => (float) $this->settings->get('sla.warning_fraction', 0.75), 'calendar_id' => null, 'version' => 1],
        );

        foreach (self::DEFAULT_TARGETS as $priority => [$response, $resolution]) {
            $policy->targets()->firstOrCreate(
                ['priority_level' => $priority],
                ['first_response_minutes' => $response, 'resolution_minutes' => $resolution],
            );
        }

        return $policy;
    }
}
