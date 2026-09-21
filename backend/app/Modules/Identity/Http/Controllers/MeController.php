<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Http\Requests\UpdatePreferencesRequest;
use App\Modules\Identity\Http\Resources\MeResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Authentication')]
final class MeController
{
    /**
     * The signed-in user, their workspace and permissions.
     */
    public function show(Request $request): MeResource
    {
        /** @var User $user */
        $user = $request->user();

        return new MeResource($user->load('agentProfile'));
    }

    /**
     * Update interface preferences (theme, density).
     */
    public function updatePreferences(UpdatePreferencesRequest $request): MeResource
    {
        /** @var User $user */
        $user = $request->user();

        $user->forceFill(['preferences' => [...$user->preferences ?? [], ...$request->validated()]])->save();

        return new MeResource($user->load('agentProfile'));
    }
}
