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
        // The single layout serves the SPA on the platform domain itself, so links point there.
        'app' => env('APP_HOST', (env('HOST_LAYOUT', 'split') === 'single' ? '' : 'app.').env('PLATFORM_DOMAIN', 'shp.localhost')),
        'api' => env('API_HOST', 'api.'.env('PLATFORM_DOMAIN', 'shp.localhost')),
        'admin' => env('ADMIN_HOST', 'admin.'.env('PLATFORM_DOMAIN', 'shp.localhost')),
        'monitor' => env('MONITOR_HOST', 'monitor.'.env('PLATFORM_DOMAIN', 'shp.localhost')),
        'docs' => env('DOCS_HOST', 'docs.'.env('PLATFORM_DOMAIN', 'shp.localhost')),
        // The platform documentation for platform super admins (ADR-0024, M5-06).
        'platform_docs' => env('PLATFORM_DOCS_HOST', 'platform-docs.'.env('PLATFORM_DOMAIN', 'shp.localhost')),
        'files' => env('FILES_HOST', 'files.'.env('PLATFORM_DOMAIN', 'shp.localhost')),
        'mail' => env('MAIL_DOMAIN', env('PLATFORM_DOMAIN', 'shp.localhost')),
    ],

    // Outbound mail identity and the DNS records shown in Settings → Email (docs/04-domain/email.md, M3-18).
    // The addresses use hosts.mail as their domain; the DKIM values are printed by infra/scripts/mail-init.sh.
    'mail' => [
        'product_name' => env('MAIL_PRODUCT_NAME', 'Smart Helpdesk'),
        'mx_host' => env('MAIL_HOSTNAME', 'mail.'.env('MAIL_DOMAIN', env('PLATFORM_DOMAIN', 'shp.localhost'))),
        'spf_include' => env('MAIL_SPF_INCLUDE'),
        'dmarc_policy' => env('MAIL_DMARC_POLICY', 'none'),
        'dkim_selector' => env('MAIL_DKIM_SELECTOR'),
        'dkim_public_key' => env('MAIL_DKIM_PUBLIC_KEY'),
        // Files of a public reply travel with its mail up to this many bytes in total (before base64,
        // which adds a third): SES and Brevo refuse messages above 10 MB. Files that do not fit are named.
        'attachments_max_bytes' => (int) env('MAIL_ATTACHMENTS_MAX_BYTES', 7 * 1024 * 1024),

        // Inbound email (docs/04-domain/email.md §Inbound pipeline, M3-19): `mail:fetch-inbound` reads the
        // catch-all `inbound@` mailbox of the bundled server over IMAP every minute. Off by default, because
        // the mail server runs only in the `mail` profile.
        'inbound' => [
            'enabled' => (bool) env('MAIL_INBOUND_ENABLED', false),
            'host' => env('MAIL_INBOUND_HOST', 'mail'),
            'port' => (int) env('MAIL_INBOUND_PORT', 143),
            // none | ssl | tls (STARTTLS). The bundled server's IMAP listener is internal and plain.
            'encryption' => env('MAIL_INBOUND_ENCRYPTION', 'none'),
            'validate_cert' => (bool) env('MAIL_INBOUND_VALIDATE_CERT', true),
            'username' => env('MAIL_INBOUND_USERNAME', 'inbound@'.env('MAIL_DOMAIN', env('PLATFORM_DOMAIN', 'shp.localhost'))),
            'password' => env('MAIL_INBOUND_PASSWORD'),
            // Read in this order; Stalwart files mail its spam filter doubts under "Junk Mail".
            'folders' => array_values(array_filter(array_map('trim', explode(',', (string) env('MAIL_INBOUND_FOLDERS', 'INBOX,Junk Mail'))))),
            'processed_folder' => 'Processed',
            'failed_folder' => 'Failed',
            // Messages per run; the rest waits for the next minute.
            'batch_size' => 50,
            // A larger message is logged as rejected (too_large) without its body.
            'max_message_bytes' => 30 * 1024 * 1024,
            // Tickets created from email: impact 1 (single user) and urgency 2 until an agent triages them.
            'default_impact' => 1,
            'default_urgency' => 2,
        ],
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
        // The platform pass (ADR-0024): a host-only cookie on the platform-docs and monitor hosts, set from
        // a single-use hand-off link from the console and ended by console sign-out.
        'pass_cookie' => env('PLATFORM_PASS_COOKIE', 'shp_platform_pass'),
        'pass_minutes' => (int) env('PLATFORM_PASS_MINUTES', 480),
        'pass_handoff_seconds' => 60,
        // Self sign-up throttles (ADR-0025 §8). 0 turns a limit off: local development and rehearsed
        // demos do; production keeps the defaults.
        'signup_limits' => [
            'per_ip_hour' => (int) env('SIGNUP_LIMIT_PER_IP_HOUR', 5),
            'per_email_hour' => (int) env('SIGNUP_LIMIT_PER_EMAIL_HOUR', 3),
            'address_per_minute' => (int) env('SIGNUP_LIMIT_ADDRESS_PER_MINUTE', 30),
            'verify_per_minute' => (int) env('SIGNUP_LIMIT_VERIFY_PER_MINUTE', 10),
        ],
    ],

    // On-prem single-tenant mode: every request runs in this tenant (slug).
    'single_tenant' => env('TENANCY_SINGLE_TENANT'),

    'reserved_slugs' => [
        'app', 'api', 'admin', 'monitor', 'docs', 'platform-docs', 'files', 'mail', 'www', 'login', 'logout', 'invite',
        'reset-password', 'select-workspace', 'assets', 'static', 'platform', 'health', 'status',
        // The public sign-up pages on the app host (ADR-0025 §8).
        'signup',
    ],

    // Platform-level: which implementation serves each algorithm contract (ADR-0023). Not a tenant setting.
    // Class names are strings because the classes are created by roadmap tasks M1-18 to M1-21.
    'strategies' => [
        'priority' => 'App\\Modules\\Automation\\Strategies\\Baseline\\BasicWeightedPriority',
        'assignment' => 'App\\Modules\\Automation\\Strategies\\Baseline\\LeastLoadedAgent',
        'duplicates' => 'App\\Modules\\Automation\\Strategies\\Baseline\\JaccardDuplicates',
        'sla' => 'App\\Modules\\Sla\\Strategies\\Baseline\\SimpleSlaTimer',
    ],

    // Algorithm experiments (docs/05-algorithms/evaluation-methodology.md). `experiment:run --strategy=<name>`
    // looks the strategy up here, so a replacement is measured on the same datasets before it is bound above.
    // E6 needs a database and only ever runs against a *_test database.
    'experiments' => [
        'path' => env('EXPERIMENTS_PATH', dirname(__DIR__, 2).'/experiments'),
        'dataset' => 'v1',
        'database' => env('EXPERIMENTS_DATABASE', env('TEST_DATABASE', 'helpdesk_test')),
        'strategies' => [
            'priority' => ['baseline' => 'App\\Modules\\Automation\\Strategies\\Baseline\\BasicWeightedPriority'],
            'assignment' => ['baseline' => 'App\\Modules\\Automation\\Strategies\\Baseline\\LeastLoadedAgent'],
            'duplicates' => ['baseline' => 'App\\Modules\\Automation\\Strategies\\Baseline\\JaccardDuplicates'],
            'sla' => ['baseline' => 'App\\Modules\\Sla\\Strategies\\Baseline\\SimpleSlaTimer'],
        ],
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
    'sla' => ['warning_fraction' => 0.75, 'first_response_applies_to_agent_created' => true],
    'shifts' => ['enforce' => false],
    // `realtime`: the workspace default follows the deployment (REALTIME_ENABLED, set with the Compose
    // profile `realtime`); a workspace may turn it off. The SPA connects only when config.json has
    // realtime.enabled as well (docs/03-architecture/realtime.md).
    'features' => ['realtime' => (bool) env('REALTIME_ENABLED', false), 'exports' => true],

    // Outbound webhooks (docs/07-api/webhooks.md).
    'webhooks' => [
        'timeout_seconds' => 10,
        'connect_timeout_seconds' => 5,
        // Ports a production webhook URL may use; the scheme must be https.
        'allowed_ports' => [443, 8443],
        // Development only: host names (e.g. the Compose `webhook-echo` service) that skip the https,
        // port and private-address checks. Ignored when APP_ENV=production. Never list IP ranges here.
        'dev_allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('WEBHOOK_DEV_ALLOWED_HOSTS', ''))))),
        'jitter' => 0.2,
        'auto_disable_after' => 20,
        'max_manual_retries' => 5,
        'retention_days' => 30,
        'max_payload_bytes' => 256 * 1024,
        'response_excerpt_bytes' => 1024,
    ],

    // Demo dataset (roadmap/11-demo-dataset.md, M3-13): `demo:reset` drops and rebuilds only the `acme` and
    // `globex` workspaces. In production both commands refuse unless DEMO_INSTANCE=true says this
    // instance exists to be demonstrated (the public demo deployment); then `--force` is still required.
    'demo' => [
        'instance' => (bool) env('DEMO_INSTANCE', false),
        // Password of every seeded account; set DEMO_PASSWORD on a public demo instance.
        'password' => env('DEMO_PASSWORD', 'password'),
        'seed' => 2026,
        // Closed tickets of days −90 to −30 before the 120 live ones (more history, slower reset).
        'history_tickets' => (int) env('DEMO_HISTORY_TICKETS', 180),
        // Development only: the secret of the seeded webhook subscription to the Compose webhook-echo
        // service, which verifies signatures with the same value (infra/compose/tools.yaml).
        'webhook_secret' => env('DEMO_WEBHOOK_SECRET', 'ZGVtby13ZWJob29rLXNlY3JldC1kZXYtb25seS0wMDE='),
    ],

    'media' => [
        // Storage disk; null follows FILESYSTEM_DISK (s3 in dev/production, local under test).
        'disk' => env('MEDIA_DISK'),
        // Disk that signs browser URLs; null means s3-presign for the s3 disk, else the storage disk.
        'presign_disk' => env('MEDIA_PRESIGN_DISK'),
        // Images above this many pixels get no variants (decoding costs ~4 bytes per pixel).
        'variant_max_pixels' => 40_000_000,
        // At most 25 MiB: the media_items size CHECK constraint is the hard ceiling.
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
