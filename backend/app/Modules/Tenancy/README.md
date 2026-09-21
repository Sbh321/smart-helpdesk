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
TenantTables::protectTenantId('tickets');         // BEFORE UPDATE trigger
TenantTables::enableRowLevelSecurity('tickets');  // ENABLE + FORCE + tenant_isolation policy
```

Finally add the table to `TenantTables::PRIMARY` (or `NULLABLE`). That list drives the isolation
suite and the row-level-security migration (M3-07), so a table left out of it, or one whose migration
does not call `enableRowLevelSecurity()`, fails those tests.

Rules that the isolation suite checks:

- Secondary tables (comments, events, timers) also carry `tenant_id`, even though it is derivable.
- Never query with `withoutTenancy()` outside the Platform module. It only removes the Eloquent scope:
  row-level security still limits the query to the current tenant, or to nothing outside one.
- Never read tenant rows across tenants, and never use the `pgsql_owner` connection at runtime (the
  policies are forced, so it would see nothing either). Loop over the tenants and enter each one.
- Factories either run inside tenancy or use an explicit `forTenant($tenant)` state.
- Cross-tenant identifiers answer 404, never 403.

## Running code for a tenant

```php
tenancy()->initialize($tenant);   // sets app.current_tenant, cache tag, storage prefix, permission team
tenancy()->end();                 // clears it

$tenant->run(fn () => …);         // one tenant; restores the previous context, also on an exception
tenancy()->central(fn () => …);   // step out of the tenant (global roles, platform rows)

foreach (Tenant::active()->cursor() as $tenant) {   // work across tenants: enter each one
    $tenant->run(fn () => …);
}
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
