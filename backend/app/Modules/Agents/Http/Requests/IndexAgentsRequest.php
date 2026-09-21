<?php

declare(strict_types=1);

namespace App\Modules\Agents\Http\Requests;

use App\Support\Http\Requests\ListRequest;

final class IndexAgentsRequest extends ListRequest
{
    protected function sortable(): array
    {
        return ['created_at', 'capacity', 'availability'];
    }

    protected function defaultSort(): string
    {
        return 'created_at';
    }

    protected function filterRules(): array
    {
        return [
            'availability' => ['in:available,away,offline'],
            'team_id' => ['uuid'],
            'skill_id' => ['uuid'],
        ];
    }
}
