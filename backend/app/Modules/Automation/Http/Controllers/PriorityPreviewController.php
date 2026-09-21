<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Controllers;

use App\Modules\Automation\Domain\Exceptions\InvalidStrategySettings;
use App\Modules\Automation\Domain\Priority\CustomerTier;
use App\Modules\Automation\Domain\Priority\PriorityInput;
use App\Modules\Automation\Domain\Priority\PrioritySettings;
use App\Modules\Automation\Http\Requests\PreviewPriorityRequest;
use App\Modules\Automation\Http\Resources\PriorityPreviewResource;
use App\Modules\Automation\Support\PriorityStrategyFactory;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

#[Group('Automation')]
final class PriorityPreviewController
{
    public function store(PreviewPriorityRequest $request, PriorityStrategyFactory $factory): AnonymousResourceCollection
    {
        $data = $request->validated();
        try {
            $settings = PrioritySettings::fromArray($data);
        } catch (InvalidStrategySettings $exception) {
            throw ValidationException::withMessages(['weights' => $exception->getMessage()]);
        }
        $strategy = $factory->forSettings($settings);
        $results = [];
        foreach ($data['samples'] as $sample) {
            $result = $strategy->score(new PriorityInput(
                (int) $sample['impact'],
                (int) $sample['urgency'],
                CustomerTier::from($sample['tier']),
                (float) $sample['hours_waited'],
            ));
            $results[] = $result;
        }

        return PriorityPreviewResource::collection($results);
    }
}
