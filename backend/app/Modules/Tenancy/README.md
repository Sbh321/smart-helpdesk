# Tenancy module

Implements [docs/03-architecture/tenancy.md](../../../../docs/03-architecture/tenancy.md) on top of
stancl/tenancy in single-database mode. Tenants come from the session or the API token, never from
the host or a client-supplied value ([ADR-0021](../../../../docs/adr/0021-host-layout-and-tenant-resolution.md)).

## Writing a tenant-scoped model

```php
final class Ticket extends Model
{
    use BelongsToTenant;   // App\Modules\Tenancy\Concerns\BelongsToTenant
}
```

The trait scopes every query to the current tenant, fills `tenant_id` on create, and refuses to
change it afterwards. Then, in the migration:

```php
Schema::create('tickets', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
    // every unique index starts with tenant_id
    $table->unique(['tenant_id', 'number']);
});
TenantTables::protectTenantId('tickets');   // BEFORE UPDATE trigger
```

Finally add the table to `TenantTables::PRIMARY` (or `NULLABLE`). That list drives the isolation
suite and the row-level-security migration, so a table left out of it fails those tests.

Rules that the isolation suite checks:

- Secondary tables (comments, events, timers) also carry `tenant_id`, even though it is derivable.
- Never query with `withoutTenancy()` outside the Platform module.
- Factories either run inside tenancy or use an explicit `forTenant($tenant)` state.
- Cross-tenant identifiers answer 404, never 403.

## Running code for a tenant

```php
tenancy()->initialize($tenant);   // sets app.current_tenant, cache tag, storage prefix, permission team
tenancy()->end();

tenancy()->run($tenant, fn () => …);          // one tenant
tenancy()->runForMultiple($tenants, fn () => …);
```

`php artisan tenants:run <command> --tenants=<id>` runs a console command per tenant. Queued jobs
carry the tenant id in the payload, so the worker restores the same context automatically.

## Files

| Path | Purpose |
|---|---|
| `Models/` | `Tenant`, `Domain`, `TenantCounter` (control plane) |
| `Concerns/BelongsToTenant.php` | model trait described above |
| `Bootstrappers/RlsTenancyBootstrapper.php` | `app.current_tenant`, `app.request_id`, `app.actor_type`, `app.actor_id` (read by the change-capture trigger), permission team, log context |
| `Http/Middleware/` | `ResolveTenantFromPrincipal`, `InitializeTenancyFromWorkspace`, `EnsureTenantActive`, `EnsureTenantMembership`, `EnsureCentralContext` |
| `Support/TenantResolver.php` | trusted tenant sources (single-tenant config, session, bearer token) |
| `Support/TenantTables.php` | registry of tenant tables and the immutability trigger |
| `Rules/WorkspaceSlug.php` | slug format and reserved names |
