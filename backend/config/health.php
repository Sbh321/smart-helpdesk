<?php

declare(strict_types=1);

use Spatie\Health\Notifications\CheckFailedNotification;
use Spatie\Health\Notifications\Notifiable;
use Spatie\Health\ResultStores\CacheHealthResultStore;

// spatie/laravel-health (docs/11-operations/observability.md). Checks are registered in App\Support\Health\HealthChecks.

return [

    // Valkey-backed so results written by the scheduler container are visible to the app container.
    'result_stores' => [
        CacheHealthResultStore::class => [
            'store' => env('HEALTH_CACHE_STORE', 'redis'),
        ],
    ],

    'notifications' => [
        'enabled' => env('HEALTH_NOTIFY_MAIL') !== null && env('HEALTH_NOTIFY_MAIL') !== '',

        'notifications' => [
            CheckFailedNotification::class => ['mail'],
        ],

        'notifiable' => Notifiable::class,

        'throttle_notifications_for_minutes' => 60,
        'throttle_notifications_key' => 'health:latestNotificationSentAt:',

        'only_on_failure' => true,

        'mail' => [
            'to' => env('HEALTH_NOTIFY_MAIL', ''),

            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'noreply@shp.localhost'),
                'name' => env('MAIL_FROM_NAME', 'Smart Helpdesk'),
            ],
        ],

        'slack' => [
            'webhook_url' => '',
            'channel' => null,
            'username' => null,
            'icon' => null,
        ],
    ],

    'oh_dear_endpoint' => [
        'enabled' => false,
        'always_send_fresh_results' => true,
        'secret' => null,
        'url' => '/oh-dear-health-check-results',
    ],

    'horizon' => [
        'heartbeat_url' => null,
    ],

    'schedule' => [
        'heartbeat_url' => null,
    ],

    'theme' => 'light',

    'silence_health_queue_job' => true,

    'json_results_failure_status' => 503,

    // Not used: /v1/health is protected by App\Support\Http\Middleware\RequireHealthToken.
    'secret_token' => null,
];
