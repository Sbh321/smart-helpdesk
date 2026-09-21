<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Controllers;

use App\Models\User;
use App\Modules\Integrations\Actions\CreateApiClient;
use App\Modules\Integrations\Actions\RevokeApiClient;
use App\Modules\Integrations\Domain\ScopeMap;
use App\Modules\Integrations\Http\Requests\StoreApiClientRequest;
use App\Modules\Integrations\Http\Resources\ApiClientResource;
use App\Modules\Integrations\Http\Resources\ApiScopeResource;
use App\Modules\Integrations\Http\Resources\NewApiClientResource;
use App\Modules\Integrations\Models\ApiClient;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Settings → Developer → API clients (docs/07-api/authentication.md §3).
 */
#[Group('API clients')]
final class ApiClientController
{
    /**
     * List the workspace's API clients, active ones first, newest first.
     */
    public function index(): AnonymousResourceCollection
    {
        $clients = ApiClient::query()
            ->orderBy('revoked')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return ApiClientResource::collection($clients);
    }

    /**
     * The scopes a client can be given, with a description each.
     */
    public function scopes(): AnonymousResourceCollection
    {
        return ApiScopeResource::collection(ScopeMap::definitions());
    }

    /**
     * Create an API client.
     *
     * The response carries `client_secret`, which is shown only this once.
     */
    #[Response(status: 201, type: NewApiClientResource::class)]
    public function store(StoreApiClientRequest $request, CreateApiClient $create): JsonResponse
    {
        $actor = $request->user();
        /** @var list<string> $scopes */
        $scopes = array_values((array) $request->validated('scopes'));

        $client = $create((string) $request->validated('name'), $scopes, $actor instanceof User ? $actor : null);

        return (new NewApiClientResource($client))->response()->setStatusCode(201);
    }

    /**
     * Revoke an API client.
     *
     * Its tokens stop working immediately; the client cannot be restored.
     */
    public function revoke(ApiClient $apiClient, RevokeApiClient $revoke): ApiClientResource
    {
        return new ApiClientResource($revoke($apiClient));
    }
}
