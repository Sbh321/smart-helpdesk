<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Media\Models\MediaFolder;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MediaFolder> */
final class MediaFolderFactory extends Factory
{
    use ForTenant;

    protected $model = MediaFolder::class;

    public function definition(): array
    {
        return ['parent_id' => null, 'name' => fake()->unique()->words(2, true), 'system_key' => null];
    }
}
