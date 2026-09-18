<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Controllers;

use App\Modules\Tickets\Http\Resources\CategoryResource;
use App\Modules\Tickets\Models\Category;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Tickets')]
final class CategoryController
{
    /**
     * List the workspace's active categories, in display order. Management arrives in M2-02.
     */
    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            Category::query()->active()->orderBy('sort_order')->orderBy('name')->get(),
        );
    }
}
