<?php

return [
    'presentation_timezone' => env('APP_PRESENTATION_TIMEZONE', 'America/Santiago'),
    'clock_drift_minutes' => (int) env('ATTENDANCE_CLOCK_DRIFT_MINUTES', 15),
    'allowed_event_types' => ['ENTRY', 'BREAK_START', 'BREAK_END', 'EXIT'],
];
