<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Http\Resources\SettingsSectionResource;
use App\Modules\Tenancy\Settings\SectionView;
use App\Modules\Tenancy\Settings\Settings;
use App\Modules\Tenancy\Settings\SettingsRegistry;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Settings')]
final class SettingsController
{
    public function __construct(private readonly Settings $settings, private readonly SettingsRegistry $registry) {}

    /** Every section with its effective values. */
    public function index(): AnonymousResourceCollection
    {
        $sections = [];
        foreach (array_keys($this->registry->all()) as $key) {
            $sections[] = $this->payload($key);
        }

        return SettingsSectionResource::collection($sections);
    }

    public function show(string $section): SettingsSectionResource
    {
        abort_unless($this->registry->has($section), 404);

        return new SettingsSectionResource($this->payload($section));
    }

    /**
     * Partial update: submitted keys are merged into the section, the result is validated as a whole.
     * 422 `validation_failed` for malformed values, 422 `settings_invalid` for values that do not fit
     * together (weights that do not sum to 1); both carry `errors` per field.
     */
    public function update(Request $request, string $section): SettingsSectionResource
    {
        abort_unless($this->registry->has($section), 404);

        $this->settings->update($section, (array) $request->json()->all());

        return new SettingsSectionResource($this->payload($section));
    }

    private function payload(string $key): SectionView
    {
        return new SectionView(
            $key,
            $this->settings->version(),
            $this->settings->section($key),
            $this->registry->get($key)->defaults(),
        );
    }
}
