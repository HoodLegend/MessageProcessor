<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Device Access Control Configuration
    |--------------------------------------------------------------------------
    */

    // Path to the Java JAR file
    'jar_path' => env('DEVICE_ACCESS_JAR_PATH', storage_path('app/jars/device-access-control.jar')),

    // Path to the access control configuration file
    'config_path' => env('DEVICE_ACCESS_CONFIG_PATH', storage_path('app/config/access-control.conf')),

    // Java executable path
    'java_path' => env('JAVA_PATH', 'java'),

    // Cache timeout for access checks (in seconds)
    'cache_timeout' => env('DEVICE_ACCESS_CACHE_TIMEOUT', 300),

    // Access control mode: IP_ONLY, MAC_ONLY, IP_AND_MAC, IP_OR_MAC
    'default_mode' => env('DEVICE_ACCESS_MODE', 'IP_ONLY'),

    // Whether to allow access when the access control system fails
    // true = fail open (allow access), false = fail closed (deny access)
    'fail_open' => env('DEVICE_ACCESS_FAIL_OPEN', false),

    // Routes to skip access control
    'skip_routes' => [
        'login',
        'register',
        'password.request',
        'password.email',
        'password.reset',
        'password.update',
        'verification.*',
        'device-access.*',
        'health',
        'status'
    ],

    // Support contact information
    'support_email' => env('DEVICE_ACCESS_SUPPORT_EMAIL', 'support@yourcompany.com'),
    'support_phone' => env('DEVICE_ACCESS_SUPPORT_PHONE', '+1-555-0123'),

    // Enable/disable device access logging
    'logging_enabled' => env('DEVICE_ACCESS_LOGGING', true),

    // Log channel for device access events
    'log_channel' => env('DEVICE_ACCESS_LOG_CHANNEL', 'daily'),

    // Enable network scanning
    'network_scan_enabled' => env('DEVICE_ACCESS_NETWORK_SCAN', false),

    // Trusted proxy settings (for getting real client IP)
    'trusted_proxies' => env('DEVICE_ACCESS_TRUSTED_PROXIES', '*'),

    // Emergency bypass settings
    'emergency_bypass' => [
        'enabled' => env('DEVICE_ACCESS_EMERGENCY_BYPASS', false),
        'key' => env('DEVICE_ACCESS_EMERGENCY_KEY', null),
        'ips' => explode(',', env('DEVICE_ACCESS_EMERGENCY_IPS', '127.0.0.1')),
    ],

    // Rate limiting for access checks
    'rate_limit' => [
        'enabled' => env('DEVICE_ACCESS_RATE_LIMIT', true),
        'max_attempts' => env('DEVICE_ACCESS_MAX_ATTEMPTS', 10),
        'decay_minutes' => env('DEVICE_ACCESS_DECAY_MINUTES', 5),
    ],

    // Notification settings
    'notifications' => [
        'access_denied' => [
            'enabled' => env('DEVICE_ACCESS_NOTIFY_DENIED', false),
            'channels' => ['mail', 'slack'],
            'threshold' => 5, // Send notification after N denied attempts
        ],
        'new_device' => [
            'enabled' => env('DEVICE_ACCESS_NOTIFY_NEW_DEVICE', false),
            'channels' => ['mail'],
        ],
    ],
];
