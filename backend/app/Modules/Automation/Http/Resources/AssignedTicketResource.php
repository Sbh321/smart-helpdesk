<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Resources;

use App\Modules\Tickets\Http\Resources\TicketResource;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Answer of `/assign` and `/auto-assign`: the ticket as everywhere else, plus the assignment that
 * was just recorded with its ranking explanation (docs/07-api/conventions.md).
 *
 * @mixin Ticket
 */
final class AssignedTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var TicketAssignment $assignment */
        $assignment = $this->resource->getRelation('latestAssignment');

        return [
            ...(new TicketResource($this->resource))->toArray($request),
            'assignment' => new TicketAssignmentResource($assignment),
        ];
    }
}
