<?php

declare(strict_types=1);

namespace App\Modules\Automation\Domain\Assignment;

/**
 * Why an agent cannot take a ticket; stable codes stored in the explanation.
 */
enum ExclusionReason: string
{
    case Inactive = 'inactive';
    case NotAvailable = 'not_available';
    case OffShift = 'off_shift';
    case MissingSkill = 'missing_skill';
    case NotInTeam = 'not_in_team';
    case AtCapacity = 'at_capacity';
}
