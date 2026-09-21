<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Controllers;

use App\Models\User;
use App\Modules\Automation\Actions\OverrideTicketPriority;
use App\Modules\Automation\Http\Requests\OverridePriorityRequest;
use App\Modules\Tickets\Domain\Priority;
use App\Modules\Tickets\Http\Resources\TicketResource;
use App\Modules\Tickets\Models\Ticket;
use Dedoc\Scramble\Attributes\Group;

#[Group('Automation')]
final class TicketPriorityController
{
    public function store(OverridePriorityRequest $request, Ticket $ticket, OverrideTicketPriority $override): TicketResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->validated();

        return new TicketResource($override(
            $ticket,
            $data['level'] === null ? null : Priority::from($data['level']),
            $data['reason'] ?? null,
            $actor,
        )->load(['contact', 'organization', 'category', 'tags']));
    }
}
