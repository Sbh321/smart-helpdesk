<?php

declare(strict_types=1);

namespace App\Modules\Automation\Support;

use App\Modules\Automation\Contracts\DuplicateStrategy;
use App\Modules\Automation\Domain\Duplicates\DuplicateResult;
use App\Modules\Automation\Domain\Duplicates\DuplicateSettings;
use App\Modules\Automation\Domain\Duplicates\TicketText;
use App\Modules\Tenancy\Settings\Settings;

/**
 * The `DuplicateStrategy` binding: the configured strategy with the current workspace's settings,
 * rebuilt when the workspace or its settings version changes (see WorkspacePriorityStrategy).
 */
final class WorkspaceDuplicateStrategy implements DuplicateStrategy
{
    private ?string $builtFor = null;

    private ?DuplicateStrategy $inner = null;

    public function __construct(private readonly DuplicateStrategyFactory $factory, private readonly Settings $settings) {}

    public function find(TicketText $ticket, array $candidates): DuplicateResult
    {
        $key = (tenant()?->getTenantKey() ?? 'central').':'.$this->settings->version();
        if ($this->inner === null || $this->builtFor !== $key) {
            $this->inner = $this->factory->forSettings(
                DuplicateSettings::fromArray((array) $this->settings->get('automation.duplicates.baseline')),
            );
            $this->builtFor = $key;
        }

        return $this->inner->find($ticket, $candidates);
    }
}
