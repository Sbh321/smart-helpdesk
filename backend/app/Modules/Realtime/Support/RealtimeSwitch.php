<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Support;

use App\Modules\Tenancy\Settings\Settings;

/**
 * Whether anything is broadcast: a WebSocket broadcaster is configured (BROADCAST_CONNECTION=reverb
 * with the Compose profile `realtime`) and the current workspace has `features.realtime` on. With
 * `log` or `null` nothing is queued at all and the SPA keeps polling.
 */
final readonly class RealtimeSwitch
{
    public function __construct(private Settings $settings) {}

    public function serverEnabled(): bool
    {
        return ! in_array(config('broadcasting.default'), ['log', 'null', null], true);
    }

    public function enabled(): bool
    {
        return $this->serverEnabled()
            && tenancy()->initialized
            && (bool) $this->settings->get('features.realtime', false);
    }
}
