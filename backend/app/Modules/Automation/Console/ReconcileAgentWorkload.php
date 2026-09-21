<?php

declare(strict_types=1);

namespace App\Modules\Automation\Console;

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Automation\Queries\AgentWorkload;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Telescope\Telescope;

/**
 * Nightly repair of `agent_profiles.active_ticket_count` against the live ticket count
 * (docs/04-domain/agents-and-teams.md: "maintained by events; verified nightly").
 */
final class ReconcileAgentWorkload extends Command
{
    protected $signature = 'agents:reconcile-workload';

    protected $description = 'Reconcile stored Agent active-ticket counters against Tickets';

    public function handle(AgentWorkload $workload): int
    {
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }

        $corrected = 0;
        foreach (Tenant::active()->cursor() as $tenant) {
            $corrected += (int) $tenant->run(fn (): int => DB::transaction(function () use ($workload): int {
                $fixed = 0;
                // Lock first, count second: no assignment can slip between the two.
                $agents = AgentProfile::query()->orderBy('id')->lockForUpdate()->get();
                $live = $workload->liveCounts();
                foreach ($agents as $agent) {
                    $actual = $live[$agent->id] ?? 0;
                    if ($agent->active_ticket_count !== $actual) {
                        $agent->active_ticket_count = $actual;
                        $agent->timestamps = false;
                        $agent->save();
                        $fixed++;
                    }
                }

                return $fixed;
            }));
        }

        $this->components->info("Reconciled {$corrected} Agent workload counters.");

        return self::SUCCESS;
    }
}
