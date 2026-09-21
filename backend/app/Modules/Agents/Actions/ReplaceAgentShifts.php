<?php

declare(strict_types=1);

namespace App\Modules\Agents\Actions;

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\AgentShift;
use App\Modules\Audit\Audit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class ReplaceAgentShifts
{
    /**
     * @param  list<array{weekday:?int,date:?string,starts_at:string,ends_at:string,is_off:bool}>  $shifts
     * @return Collection<int, AgentShift>
     */
    public function __invoke(AgentProfile $agent, array $shifts): Collection
    {
        return DB::transaction(function () use ($agent, $shifts): Collection {
            $locked = AgentProfile::query()->lockForUpdate()->findOrFail($agent->id);
            $old = $locked->shifts()->orderBy('date')->orderBy('weekday')->get()->map->only([
                'weekday', 'date', 'starts_at', 'ends_at', 'is_off',
            ])->all();
            $locked->shifts()->delete();
            foreach ($shifts as $shift) {
                $locked->shifts()->create($shift);
            }
            Audit::record('agent.shifts_changed', $locked, ['old' => $old, 'new' => $shifts]);

            return $locked->shifts()->orderBy('date')->orderBy('weekday')->orderBy('starts_at')->get();
        });
    }
}
