<?php

declare(strict_types=1);

namespace App\Modules\Automation\Support;

use App\Modules\Automation\Contracts\PriorityStrategy;
use App\Modules\Automation\Domain\Priority\PrioritySettings;
use LogicException;

final class PriorityStrategyFactory
{
    public function forSettings(PrioritySettings $settings): PriorityStrategy
    {
        $configured = config('helpdesk.strategies.priority');
        if (! is_string($configured) || ! is_subclass_of($configured, PriorityStrategy::class)) {
            throw new LogicException('The configured priority strategy must implement PriorityStrategy.');
        }

        /** @var class-string<PriorityStrategy> $configured */
        return app()->makeWith($configured, ['settings' => $settings]);
    }
}
