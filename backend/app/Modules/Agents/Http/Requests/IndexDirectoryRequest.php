<?php

declare(strict_types=1);

namespace App\Modules\Agents\Http\Requests;

use App\Support\Http\Requests\ListRequest;

final class IndexDirectoryRequest extends ListRequest
{
    protected function sortable(): array
    {
        return ['name', 'created_at'];
    }

    protected function defaultSort(): string
    {
        return 'name';
    }

    protected function filterRules(): array
    {
        return [];
    }
}
