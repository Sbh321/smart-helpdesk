<?php

declare(strict_types=1);

namespace App\Modules\Automation\Support;

use App\Modules\Automation\Contracts\DuplicateStrategy;
use App\Modules\Automation\Domain\Duplicates\DuplicateSettings;
use LogicException;

final class DuplicateStrategyFactory
{
    public function forSettings(DuplicateSettings $settings): DuplicateStrategy
    {
        $configured = config('helpdesk.strategies.duplicates');
        if (! is_string($configured) || ! is_subclass_of($configured, DuplicateStrategy::class)) {
            throw new LogicException('The configured duplicate strategy must implement DuplicateStrategy.');
        }

        /** @var class-string<DuplicateStrategy> $configured */
        return app()->makeWith($configured, ['settings' => $settings]);
    }
}
