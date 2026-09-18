<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\Organization;
use App\Modules\Contacts\Models\Tag;
use App\Modules\Identity\Models\Invitation;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\PersonalAccessToken;
use App\Modules\Identity\Models\Role;
use App\Modules\Platform\Models\PlatformUser;
use App\Modules\Reporting\Models\EntityChange;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantCounter;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Skill;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketAssignment;
use App\Modules\Tickets\Models\TicketComment;
use App\Modules\Tickets\Models\TicketEvent;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * The model side of the tenant registry (docs/03-architecture/tenancy.md §Model rules).
 *
 * `App\Modules\Tenancy\Support\TenantTables` lists the tables; this class lists the Eloquent
 * classes that sit on them, so the reflection test in tests/Isolation can prove that every model
 * is classified and that its classification matches the schema. Adding a model without adding it
 * to exactly one of these lists fails `tests/Isolation/ModelReflectionTest.php`.
 *
 * It lives in the test suite rather than in `app/` because nothing in production needs it, and a
 * list that only tests read cannot drift into runtime behaviour.
 */
final class TenantModelInventory
{
    /**
     * Primary tenant-scoped models: `BelongsToTenant`, `tenant_id NOT NULL`, global scope.
     *
     * @var list<class-string<Model>>
     */
    public const PRIMARY = [
        Category::class,
        Contact::class,
        EntityChange::class,
        Invitation::class,
        Organization::class,
        Skill::class,
        Tag::class,
        Ticket::class,
        TicketAssignment::class,
        TicketComment::class,
        TicketEvent::class,
        User::class,
    ];

    /**
     * Secondary models: scoped through their primary parent (`BelongsToPrimaryModel`) but still
     * carrying `tenant_id`. The first ones (ticket comments, events, timers) arrive in M2.
     *
     * @var list<class-string<Model>>
     */
    public const SECONDARY = [];

    /**
     * Tenant-scoped models with a nullable `tenant_id` (platform-level rows have none).
     *
     * @var list<class-string<Model>>
     */
    public const NULLABLE = [
        AuditLog::class,
        Role::class,
    ];

    /**
     * Credential models read before tenancy is initialised: they carry `tenant_id`, are never
     * globally scoped, and are excluded from row-level security (docs/08-database/tenancy.md).
     *
     * @var list<class-string<Model>>
     */
    public const CREDENTIALS = [
        PersonalAccessToken::class,
    ];

    /**
     * Control-plane models. `Domain` and `TenantCounter` have a `tenant_id` column but describe a
     * tenant rather than belonging to one, so they are never scoped and never get a policy.
     *
     * @var list<class-string<Model>>
     */
    public const CENTRAL = [
        // The permission catalogue is the same for every workspace; roles decide who gets what.
        Permission::class,
        Tenant::class,
        Domain::class,
        TenantCounter::class,
        PlatformUser::class,
    ];

    /**
     * Tables that legitimately carry `tenant_id` while staying out of `TenantTables`: control-plane
     * rows keyed by tenant, and the pre-authentication credential tables.
     *
     * @var list<string>
     */
    public const UNSCOPED_TABLES_WITH_TENANT_ID = [
        'domains',
        'tenant_counters',
        'password_reset_tokens',
    ];

    /**
     * Known gaps between a table and the registry, each with the task that closes it. M1-10 may not
     * edit application code, so the assertions below step over these and over nothing else: the
     * entry disappears by itself once the owning task registers the table, and any *other* table
     * left out still fails.
     *
     * Empty: `invitations` was registered in `TenantTables::PRIMARY` when M1-08 landed.
     *
     * @var list<string>
     */
    public const AWAITING_REGISTRATION = [];

    /**
     * Unique indexes on tenant tables that do not include `tenant_id`, each with why it is not a
     * cross-tenant uniqueness rule. Everything else must lead with `tenant_id`.
     *
     * Empty: `entity_changes_version_unique` now leads with `tenant_id`.
     *
     * @var list<string>
     */
    public const UNIQUE_INDEX_EXCEPTIONS = [];

    /**
     * Every classified model, mapped to the names of the lists it appears in. More than one entry
     * means the model was classified twice, which the reflection test rejects.
     *
     * @return array<class-string<Model>, list<string>>
     */
    public static function classified(): array
    {
        $map = [];

        foreach (self::lists() as $list => $models) {
            foreach ($models as $model) {
                $map[$model][] = $list;
            }
        }

        return $map;
    }

    /**
     * @return array<string, list<class-string<Model>>>
     */
    public static function lists(): array
    {
        return [
            'PRIMARY' => self::PRIMARY,
            'SECONDARY' => self::SECONDARY,
            'NULLABLE' => self::NULLABLE,
            'CREDENTIALS' => self::CREDENTIALS,
            'CENTRAL' => self::CENTRAL,
        ];
    }

    /**
     * Models whose rows belong to one tenant, whatever mechanism does the scoping.
     *
     * @return list<class-string<Model>>
     */
    public static function tenantScoped(): array
    {
        return [...self::PRIMARY, ...self::SECONDARY, ...self::NULLABLE];
    }

    /**
     * Every concrete Eloquent model under `app/`, found on disk rather than from a list, so a new
     * model is discovered wherever it is put.
     *
     * @return list<class-string<Model>>
     */
    public static function discover(): array
    {
        $models = [];

        foreach (Finder::create()->files()->in(base_path('app'))->name('*.php') as $file) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $models[] = $class;
        }

        sort($models);

        return $models;
    }

    /**
     * @param  class-string<Model>  $model
     */
    public static function tableOf(string $model): string
    {
        return (new $model)->getTable();
    }

    /**
     * Primary models that can be seeded, keyed by class basename for readable dataset names. A
     * model without `HasFactory` cannot take part in the data-isolation cases; the reflection and
     * schema cases still cover it.
     *
     * @return array<string, class-string<Model>>
     */
    public static function seedablePrimary(): array
    {
        $models = array_values(array_filter(
            self::PRIMARY,
            fn (string $model): bool => method_exists($model, 'factory'),
        ));

        return array_combine(array_map(class_basename(...), $models), $models);
    }
}
