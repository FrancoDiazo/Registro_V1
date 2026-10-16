<?php

return [
    'default' => env('CACHE_STORE', 'database'),
    'stores' => [
        'array' => ['driver' => 'array', 'serialize' => false],
        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table' => env('DB_CACHE_TABLE', 'cache_aplicacion'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE', 'bloqueos_cache'),
        ],
    ],
    'prefix' => env('CACHE_PREFIX', 'asistencia_cache'),
];
