<?php

declare(strict_types=1);

namespace App\Modules\Audit\Actions;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;
use App\Support\Auth\ApiClientPrincipal;
use App\Support\Time\Clock;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Writes one audit entry (docs/04-domain/audit.md §Audited actions).
 *
 * The actor, tenant, IP address, user agent and request id are taken from the current
 * request and tenancy context unless given explicitly (console commands, jobs).
 */
final readonly class RecordAuditLog
{
    public function __construct(
        private Clock $clock,
        private AuthFactory $auth,
        private Request $request,
    ) {}

    /**
     * @param  array<string, mixed>  $changes  `['field' => ['old' => …, 'new' => …]]` or free-form context
     */
    public function __invoke(
        string $action,
        ?Model $subject = null,
        array $changes = [],
        ?string $tenantId = null,
        ?ActorType $actorType = null,
        ?string $actorId = null,
    ): AuditLog {
        [$resolvedType, $resolvedId] = $actorType === null ? $this->currentActor() : [$actorType, $actorId];
        // Workers and commands have no client; their Request instance is synthetic.
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : $this->request;
        $userAgent = $request?->userAgent();

        return AuditLog::query()->create([
            'tenant_id' => $tenantId ?? $this->currentTenantId(),
            'actor_type' => $resolvedType,
            'actor_id' => $resolvedId,
            'action' => $action,
            'subject_type' => $subject === null ? null : Str::snake(class_basename($subject)),
            'subject_id' => $subject?->getKey(),
            'changes' => $changes,
            'ip_address' => $request?->ip(),
            'user_agent' => $userAgent === null || $userAgent === '' ? null : Str::limit($userAgent, 252),
            'request_id' => $this->request->attributes->get('request_id'),
            'created_at' => $this->clock->now(),
        ]);
    }

    /**
     * @return array{ActorType, string|null}
     */
    private function currentActor(): array
    {
        // Platform admins pass their actor explicitly; API clients are the `api` guard's principal.
        $user = $this->auth->guard()->user();

        return match (true) {
            $user === null => [ActorType::System, null],
            $user instanceof ApiClientPrincipal => [ActorType::ApiClient, $user->clientId()],
            default => [ActorType::User, (string) $user->getAuthIdentifier()],
        };
    }

    private function currentTenantId(): ?string
    {
        $tenant = function_exists('tenant') ? tenant() : null;

        return $tenant === null ? null : (string) $tenant->getTenantKey();
    }
}
