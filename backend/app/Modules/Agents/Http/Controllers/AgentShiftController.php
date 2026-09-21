<?php

declare(strict_types=1);

namespace App\Modules\Agents\Http\Controllers;

use App\Modules\Agents\Actions\ReplaceAgentShifts;
use App\Modules\Agents\Http\Requests\ReplaceAgentShiftsRequest;
use App\Modules\Agents\Http\Resources\AgentShiftResource;
use App\Modules\Agents\Models\AgentProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AgentShiftController
{
    public function index(Request $request, AgentProfile $agent): AnonymousResourceCollection
    {
        abort_unless($request->user()?->can('shifts.manage') === true || $request->user()?->id === $agent->user_id, 403);

        return AgentShiftResource::collection($agent->shifts()->orderBy('date')->orderBy('weekday')->orderBy('starts_at')->get());
    }

    public function update(
        ReplaceAgentShiftsRequest $request,
        AgentProfile $agent,
        ReplaceAgentShifts $replace,
    ): AnonymousResourceCollection {
        /** @var list<array{weekday:?int,date:?string,starts_at:string,ends_at:string,is_off:bool}> $shifts */
        $shifts = $request->validated('shifts');

        return AgentShiftResource::collection($replace($agent, $shifts));
    }
}
