<?php

declare(strict_types=1);

use App\Modules\Tenancy\Bootstrappers\RlsTenancyBootstrapper;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\UuidV7Generator;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;

/*
 * stancl/tenancy in single-database mode (ADR-0006, docs/03-architecture/tenancy.md).
 * Tenants are resolved from the session or API client, never from the host (ADR-0021),
 * so no identification middleware from the package is used and central_domains is empty.
 */
return [
    'tenant_model' => Tenant::class,
    'id_generator' => UuidV7Generator::class,
    'domain_model' => Domain::class,

    'central_domains' => [],

    /*
     * DatabaseTenancyBootstrapper stays off until a tenant is placed on a dedicated database.
     * RedisTenancyBootstrapper is off: the only Redis connection also carries the queues, and
     * prefixing it per tenant would hide jobs from the workers. Direct Redis keys include the
     * tenant id explicitly.
     */
    'bootstrappers' => [
        RlsTenancyBootstrapper::class,
        CacheTenancyBootstrapper::class,
        FilesystemTenancyBootstrapper::class,
        QueueTenancyBootstrapper::class,
    ],

    'database' => [
        'central_connection' => env('DB_CONNECTION', 'pgsql'),
        'template_tenant_connection' => null,
        'prefix' => 'tenant',
        'suffix' => '',
        'managers' => [],
    ],

    'cache' => [
        // Every cache call inside a tenant is tagged tenant{id}; the store must support tags (Valkey, array).
        'tag_base' => 'tenant',
    ],

    'filesystem' => [
        // Object keys live under tenants/{id}/ on both S3 disks (the presign disk must match the storage disk).
        'suffix_base' => 'tenants/',
        'disks' => ['s3', 's3-presign', 'local'],
        'root_override' => [
            'local' => '%storage_path%/app/private/tenants/%tenant%',
        ],
        // storage_path() stays global: logs, framework caches and compiled views are not per tenant.
        'suffix_storage_path' => false,
        'asset_helper_tenancy' => false,
    ],

    'redis' => [
        'prefix_base' => 'tenant',
        'prefixed_connections' => [],
    ],

    'features' => [],

    // The package's asset route is not needed (asset_helper_tenancy is off).
    'routes' => false,

    'migration_parameters' => [
        '--force' => true,
        '--path' => [database_path('migrations/tenant')],
        '--realpath' => true,
    ],

    'seeder_parameters' => [
        '--class' => 'DatabaseSeeder',
    ],
];
