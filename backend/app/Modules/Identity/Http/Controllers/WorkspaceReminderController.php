<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\WorkspaceReminderRequest;
use App\Modules\Identity\Jobs\SendWorkspaceReminder;
use Illuminate\Http\JsonResponse;

final class WorkspaceReminderController
{
    /**
     * Email me my workspace.
     *
     * Sends the address a list of the workspaces it can sign in to, with a sign-in link for each. The
     * answer is the same whether or not the address has an account, and the lookup runs on the queue,
     * so neither the answer nor its timing tells a caller anything about the address.
     *
     * @unauthenticated
     *
     * @response 202 array{data: array{status: 'sent'}}
     */
    public function __invoke(WorkspaceReminderRequest $request): JsonResponse
    {
        SendWorkspaceReminder::dispatch((string) $request->validated('email'));

        return new JsonResponse(['data' => ['status' => 'sent']], 202);
    }
}
