<?php

declare(strict_types=1);

namespace App\Modules\Sla\Exceptions;

use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Tickets\Domain\Priority;
use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

/**
 * Rendered as 422 `sla_target_missing`: the selected policy has no target row for the ticket's
 * priority, so no deadline can be computed (docs/04-domain/sla.md).
 */
final class SlaTargetMissing extends DomainException
{
    public static function for(SlaPolicy $policy, Priority $priority): self
    {
        return new self(
            sprintf('The SLA policy "%s" has no target for priority %s.', $policy->name, $priority->value),
            ['policy_id' => $policy->id, 'priority_level' => $priority->value],
        );
    }

    public function code(): ErrorCode
    {
        return ErrorCode::SlaTargetMissing;
    }
}
