<?php

declare(strict_types=1);

/*
 * Broadcasting (docs/03-architecture/realtime.md). `reverb` when the Compose profile `realtime` runs the
 * Reverb server, `log` otherwise; the SPA then keeps polling. The app publishes to Reverb over the
 * internal network (REVERB_HOST=reverb, http); browsers connect through the proxy (runtime config.json).
 */

return [

    'default' => env('BROADCAST_CONNECTION', 'log'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST', 'reverb'),
                'port' => env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            'client_options' => [
                // Publishing is on the internal network; a stopped Reverb must fail fast, not hold a worker.
                'timeout' => 3,
                'connect_timeout' => 2,
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
