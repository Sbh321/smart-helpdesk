<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Support\Http\Requests\ListRequest;

final class IndexUsersRequest extends ListRequest
{
    protected function sortable(): array
    {
        return ['name', 'email', 'created_at', 'last_login_at'];
    }

    protected function defaultSort(): string
    {
        return 'name';
    }

    protected function filterRules(): array
    {
        return [
            'status' => ['in:active,invited,disabled'],
            'role' => ['string', 'max:60'],
        ];
    }
}
