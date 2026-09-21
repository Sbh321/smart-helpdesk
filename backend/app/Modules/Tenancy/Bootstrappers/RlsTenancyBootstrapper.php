<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Bootstrappers;

use App\Support\Auth\ApiClientPrincipal;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Context;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Puts the current tenant and actor into the PostgreSQL session
 * (docs/08-database/tenancy.md §Setting lifecycle).
 *
 * `app.current_tenant` drives the RLS policies (M3); `app.actor_type`, `app.actor_id` and
 * `app.request_id` are what the change-capture trigger `record_entity_change()` stores with every
 * captured row (ADR-0022 §1). The actor is the authenticated user when one is already resolved and
 * `system` otherwise, which is what jobs, console commands and the scheduler write. Session-level
 * settings are safe because no connection pooler sits between PHP and PostgreSQL. The spatie
 * permission team follows the tenant, and the log context gets `tenant_id`.
 */
final class RlsTenancyBootstrapper implements TenancyBootstrapper
{
    /**
     * Guards consulted for the actor, in order, with the actor type each one records; only an
     * already-resolved principal counts. The `api` guard only ever holds API clients.
     */
    private const GUARDS = ['api' => ApiClientPrincipal::ACTOR_TYPE, 'sanctum' => 'user', 'web' => 'user'];

    private const SETTINGS = ['app.current_tenant', 'app.request_id', 'app.actor_type', 'app.actor_id'];

    private ?string $tenantId = null;

    /** @var array{string, string}|null the actor last written to the session */
    private ?array $appliedActor = null;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly PermissionRegistrar $permissions,
        private readonly AuthFactory $auth,
    ) {}

    public function bootstrap(Tenant $tenant): void
    {
        $this->tenantId = (string) $tenant->getTenantKey();

        $this->apply($this->db->connection());
        $this->permissions->setPermissionsTeamId($this->tenantId);
        Context::add('tenant_id', $this->tenantId);
    }

    public function revert(): void
    {
        $this->tenantId = null;

        $this->apply($this->db->connection());
        $this->permissions->setPermissionsTeamId(null);
        Context::forget('tenant_id');
    }

    /**
     * Re-applies the setting after a reconnect (a new PostgreSQL session starts empty).
     */
    public function reapply(Connection $connection): void
    {
        if ($this->tenantId !== null && $connection->getName() === $this->db->getDefaultConnection()) {
            $this->apply($connection);
        }
    }

    /**
     * Re-reads the actor, which authentication resolves after tenancy is initialised
     * (the `tenant` middleware group runs `auth:sanctum,api` after `ResolveTenantFromPrincipal`).
     * Called by `EnsureTenantMembership` for every authenticated tenant request, and by the
     * Reporting module on the `Authenticated` event (login inside the pre-authentication group).
     * Only writes when the actor changed.
     */
    public function refreshActor(): void
    {
        if ($this->tenantId !== null && $this->actor() !== $this->appliedActor) {
            $this->apply($this->db->connection());
        }
    }

    public function currentTenantId(): ?string
    {
        return $this->tenantId;
    }

    private function apply(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        if ($this->tenantId === null) {
            $this->appliedActor = null;

            foreach (self::SETTINGS as $setting) {
                $connection->statement("RESET {$setting}");
            }

            return;
        }

        [$actorType, $actorId] = $this->appliedActor = $this->actor();

        // set_config(..., false) = SET for the session; bound parameters instead of string SQL.
        $connection->select(
            "SELECT set_config('app.current_tenant', ?, false), set_config('app.request_id', ?, false),"
            ." set_config('app.actor_type', ?, false), set_config('app.actor_id', ?, false)",
            [$this->tenantId, (string) Context::get('request_id', ''), $actorType, $actorId],
        );
    }

    /**
     * The actor for captured changes: `api_client` for API clients (docs/07-api/authentication.md
     * §3), `user` for signed-in users, `system` otherwise. Resolving a user here would run a query inside tenancy
     * bootstrapping, so only a user the guard already holds counts; `refreshActor()` picks up the
     * one authentication resolves later.
     *
     * @return array{string, string}
     */
    private function actor(): array
    {
        foreach (self::GUARDS as $name => $type) {
            $guard = $this->auth->guard($name);

            if ($guard->hasUser()) {
                return [$type, (string) $guard->user()?->getAuthIdentifier()];
            }
        }

        // Queued jobs, console commands and the scheduler have no user.
        return ['system', ''];
    }
}
