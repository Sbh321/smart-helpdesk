<?php

declare(strict_types=1);

/*
 * Smart Helpdesk defaults (docs/03-architecture/configuration.md).
 * Tenant settings override the "tenant-overridable" keys; everything else is platform configuration.
 */

return [

    // Hosts (ADR-0021). HOST_LAYOUT=split uses one host per function; single serves everything on one host.
    'platform_domain' => env('PLATFORM_DOMAIN', 'shp.localhost'),
    'host_layout' => env('HOST_LAYOUT', 'split'),
    'hosts' => [
        'app' => env('APP_HOST', 'app.'.env('PLATFORM_DOMAIN', 'shp.localhost')),
        'api' => env('API_HOST', 'api.'.env('PLATFORM_DOMAIN', 'shp.localhost')),
        'admin' => env('ADMIN_HOST', 'admin.'.env('PLATFORM_DOMAIN', 'shp.localhost')),
        'monitor' => env('MONITOR_HOST', 'monitor.'.env('PLATFORM_DOMAIN', 'shp.localhost')),
        'docs' => env('DOCS_HOST', 'docs.'.env('PLATFORM_DOMAIN', 'shp.localhost')),
        'files' => env('FILES_HOST', 'files.'.env('PLATFORM_DOMAIN', 'shp.localhost')),
        'mail' => env('MAIL_DOMAIN', env('PLATFORM_DOMAIN', 'shp.localhost')),
    ],

    // Release shown in logs and the health report (docs/11-operations/logs.md).
    'version' => env('APP_VERSION', 'dev'),

    // Dependency health report at /v1/health (docs/11-operations/observability.md).
    'health' => [
        'token' => env('HEALTH_TOKEN'),
        'queues' => ['default'],
    ],

    // Platform super admins on the admin host (ADR-0021).
    'platform' => [
        'session_cookie' => env('SESSION_PLATFORM_COOKIE', 'shp_platform_session'),
    ],

    // On-prem single-tenant mode: every request runs in this tenant (slug).
    'single_tenant' => env('TENANCY_SINGLE_TENANT'),

    'reserved_slugs' => [
        'app', 'api', 'admin', 'monitor', 'docs', 'files', 'mail', 'www', 'login', 'logout', 'invite',
        'reset-password', 'select-workspace', 'assets', 'static', 'platform', 'health', 'status',
    ],

    // Platform-level: which implementation serves each algorithm contract (ADR-0023). Not a tenant setting.
    // Class names are strings because the classes are created by roadmap tasks M1-18 to M1-21.
    'strategies' => [
        'priority' => 'App\\Modules\\Automation\\Strategies\\Baseline\\BasicWeightedPriority',
        'assignment' => 'App\\Modules\\Automation\\Strategies\\Baseline\\LeastLoadedAgent',
        'duplicates' => 'App\\Modules\\Automation\\Strategies\\Baseline\\JaccardDuplicates',
        'sla' => 'App\\Modules\\Sla\\Strategies\\Baseline\\SimpleSlaTimer',
    ],

    // Tenant-overridable settings, namespaced per strategy.
    'automation' => [
        'priority' => [
            'baseline' => [
                'weights' => ['impact' => 0.40, 'urgency' => 0.35, 'tier' => 0.15, 'age' => 0.10],
                'thresholds' => ['P1' => 75, 'P2' => 50, 'P3' => 25],
                'age_full_hours' => 72,
            ],
        ],
        'assignment' => ['enabled' => true],
        'duplicates' => [
            'baseline' => ['threshold' => 0.35, 'candidate_limit' => 50, 'window_days' => 30, 'max_suggestions' => 5],
        ],
    ],

    'tickets' => [
        'auto_close_days' => 7,
        'reopen_window_days' => 14,
        // Seeded into every new workspace by ProvisionTenant; editable later (M2-02).
        'default_categories' => ['General', 'Billing', 'Technical issue', 'Account and access', 'Feature request', 'Other'],
    ],
    'sla' => ['warning_fraction' => 0.75],
    'shifts' => ['enforce' => false],
    'features' => ['realtime' => false, 'exports' => true],

    'media' => [
        'max_file_bytes' => 25 * 1024 * 1024,
        'default_quota_bytes' => 5 * 1024 * 1024 * 1024,
        'allowed_mime' => [
            'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain', 'text/csv',
            'application/zip',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ],
    ],
];
