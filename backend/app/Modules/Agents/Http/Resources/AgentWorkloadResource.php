<?php

declare(strict_types=1);

namespace App\Modules\Agents\Http\Resources;

use App\Modules\Agents\Support\AgentWorkloadView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Live workload of one Agent (`Contracts\DirectoryUsage::workload()`).
 *
 * @mixin AgentWorkloadView
 */
final class AgentWorkloadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'active_ticket_count' => $this->activeTicketCount,
            'capacity' => $this->capacity,
            'load' => $this->load,
            'by_priority' => $this->byPriority,
        ];
    }
}
