<?php

/*
| 48HRS Dental Lab settings. Business settings the Super Admin can edit (SLA
| hours, emergency fee, invoice terms, lab profile) live in the database; these
| are deployment settings.
*/

return [
    // IANA zone that defines the lab's working day (due today, reports, dashboard periods, case-number year).
    'timezone' => env('LAB_TIMEZONE', 'UTC'),

    // Public URL of the React app (password-reset links). Must be https:// in production.
    'frontend_url' => rtrim((string) env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/'),

    'auth' => [
        // Absolute session length; activity never extends it (SESSION_LIFETIME is the idle limit).
        'session_minutes' => (int) env('SESSION_TTL_MINUTES', 480),
        // Per-account lockout after repeated wrong passwords (stored on the user row).
        'max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        'lock_minutes' => (int) env('LOGIN_LOCK_MINUTES', 15),
        // Sign-in attempts per client IP per 15 minutes (a whole clinic may share one IP).
        'rate_limit' => (int) env('AUTH_RATE_LIMIT', 100),
        // Password-reset requests per client IP per hour.
        'reset_rate_limit' => (int) env('PASSWORD_RESET_RATE_LIMIT', 10),
        // Every API request per client IP per minute, against scripted abuse.
        'api_rate_limit' => (int) env('API_RATE_LIMIT', 600),
    ],

    'uploads' => [
        // Case files, on a private disk (never under the web root).
        'root' => (static fn (?string $d) => $d ? (str_starts_with($d, '/') ? $d : base_path($d)) : storage_path('app/private/cases'))(env('UPLOAD_DIR')),
        'max_mb' => (int) env('MAX_UPLOAD_MB', 50),
    ],

    // Browser origins allowed to call the API with cookies besides the API's own origin (comma-separated).
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),
];
