<?php

declare(strict_types=1);

namespace App\Modules\Sla\Support;

use App\Modules\Sla\Contracts\SlaStrategy;
use App\Support\Time\Clock;
use LogicException;

final readonly class SlaStrategyFactory
{
    public function __construct(private Clock $clock) {}

    public function forWarningFraction(float $warningFraction): SlaStrategy
    {
        $configured = config('helpdesk.strategies.sla');
        if (! is_string($configured) || ! is_subclass_of($configured, SlaStrategy::class)) {
            throw new LogicException('The configured SLA strategy must implement SlaStrategy.');
        }

        /** @var class-string<SlaStrategy> $configured */
        return app()->makeWith($configured, [
            'clock' => $this->clock,
            'warningFraction' => $warningFraction,
        ]);
    }
}
