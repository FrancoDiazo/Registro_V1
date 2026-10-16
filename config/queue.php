<?php

return [
    'default' => env('QUEUE_CONNECTION', 'database'),
    'connections' => [
        'sync' => [
            'driver' => 'sync',
        ],
        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'trabajos_cola'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 120),
            'after_commit' => true,
        ],
    ],
    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => env('DB_FAILED_JOBS_TABLE', 'trabajos_fallidos'),
    ],
];
