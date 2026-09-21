<?php

declare(strict_types=1);

namespace App\Modules\Automation\Support;

use App\Modules\Automation\Contracts\PriorityStrategy;
use App\Modules\Automation\Domain\Priority\PriorityInput;
use App\Modules\Automation\Domain\Priority\PriorityResult;
use App\Modules\Automation\Domain\Priority\PrioritySettings;
use App\Modules\Tenancy\Settings\Settings;

/**
 * What the container hands out for `PriorityStrategy`: the configured strategy, built with the
 * settings of the workspace that is current when `score()` runs. An action is often resolved once and
 * then used for several workspaces (the hourly ageing command), so the settings cannot be fixed at
 * resolution time. The inner strategy is rebuilt only when the workspace or its settings version changes.
 */
final class WorkspacePriorityStrategy implements PriorityStrategy
{
    private ?string $builtFor = null;

    private ?PriorityStrategy $inner = null;

    public function __construct(private readonly PriorityStrategyFactory $factory, private readonly Settings $settings) {}

    public function score(PriorityInput $input): PriorityResult
    {
        return $this->current()->score($input);
    }

    private function current(): PriorityStrategy
    {
        $key = (tenant()?->getTenantKey() ?? 'central').':'.$this->settings->version();
        if ($this->inner === null || $this->builtFor !== $key) {
            $this->inner = $this->factory->forSettings(
                PrioritySettings::fromArray((array) $this->settings->get('automation.priority.baseline')),
            );
            $this->builtFor = $key;
        }

        return $this->inner;
    }
}
