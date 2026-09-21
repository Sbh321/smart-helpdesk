<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Bootstrappers;

use App\Support\Auth\ApiClientPrincipal;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Context;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Puts the current tenant and actor into the PostgreSQL session
 * (docs/08-database/tenancy.md §Setting lifecycle).
 *
 * `app.current_tenant` drives the row-level security policies (M3-07); `app.actor_type`,
 * `app.actor_id` and `app.request_id` are what the change-capture trigger `record_entity_change()`
 * stores with every captured row (ADR-0022 §1). The actor is the authenticated user when one is
 * already resolved and `system` otherwise, which is what jobs, console commands and the scheduler
 * write. The spatie permission team follows the tenant, and the log context gets `tenant_id`.
 *
 * Session-level settings (`set_config(…, false)`), set on initialise and cleared on end, rather
 * than `SET LOCAL`: requests and jobs do not run in one transaction each, and no connection pooler
 * sits between PHP and PostgreSQL. What can go wrong with session settings is handled here:
 * - a reconnect starts an empty session, so the tenant is applied again (`reapply()`);
 * - a rollback also rolls back a `set_config` made inside the transaction, so the current state is
 *   applied again after every rollback (`reapplyAfterRollback()`);
 * - inside a failed transaction no statement runs; the write is skipped and the rollback that must
 *   follow applies it;
 * - a queue worker clears the session before every central job (`clearSession()`), so a long-lived
 *   Horizon connection never carries the previous job's tenant.
 */
final class RlsTenancyBootstrapper implements TenancyBootstrapper
{
    /**
     * Guards consulted for the actor, in order, with the actor type each one records; only an
     * already-resolved principal counts. The `api` guard only ever holds API clients.
     */
    private const GUARDS = ['api' => ApiClientPrincipal::ACTOR_TYPE, 'sanctum' => 'user', 'web' => 'user'];

    /** In the order apply() binds them. */
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
     * A rollback (or rollback to a savepoint) undoes every `set_config` made since the transaction
     * or savepoint began, so the connection could be left on a tenant that has since ended, or on
     * none while one is current. Writes the current state again.
     */
    public function reapplyAfterRollback(Connection $connection): void
    {
        if ($connection->getName() === $this->db->getDefaultConnection()) {
            $this->apply($connection);
        }
    }

    /**
     * Clears the settings on the default connection whatever this process believes the context
     * is; queue workers call it before every central job (TenancyServiceProvider).
     */
    public function clearSession(): void
    {
        if ($this->tenantId === null) {
            $this->apply($this->db->connection());
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
            // '' rather than RESET: one round trip, and the policies and the trigger read '' as unset.
            $values = array_fill(0, count(self::SETTINGS), '');
        } else {
            [$actorType, $actorId] = $this->appliedActor = $this->actor();
            $values = [$this->tenantId, (string) Context::get('request_id', ''), $actorType, $actorId];
        }

        try {
            // set_config(..., false) = SET for the session; bound parameters instead of string SQL.
            $connection->select(
                'SELECT '.implode(', ', array_map(fn (string $setting): string => "set_config('{$setting}', ?, false)", self::SETTINGS)),
                $values,
            );
        } catch (QueryException $exception) {
            // 25P02: the transaction already failed. Its rollback runs reapplyAfterRollback(), and
            // until then the connection runs no statement at all.
            if ($exception->getCode() !== '25P02') {
                throw $exception;
            }
        }
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
