<?php

return [
    /*
    |--------------------------------------------------------------------------
    | MDVR Server Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the JTT808/JTT1078 MDVR protocol server
    |
    */

    // Main TCP Server
    'server' => [
        'host' => env('MDVR_SERVER_HOST', '0.0.0.0'),
        'port' => env('MDVR_SERVER_PORT', 8808),
    ],

    // Attachment Server (for video/image uploads)
    'attachment_server' => [
        'host' => env('MDVR_ATTACHMENT_HOST', '0.0.0.0'),
        'port' => env('MDVR_ATTACHMENT_PORT', 8809),
    ],

    // Heartbeat configuration
    'heartbeat' => [
        'interval' => env('MDVR_HEARTBEAT_INTERVAL', 30), // seconds
        'timeout_multiplier' => 3, // disconnect if no heartbeat in interval * multiplier
    ],

    // Storage path for attachments (videos, images)
    'storage_path' => storage_path('app/mdvr'),

    // Protocol settings
    'protocol' => [
        'version' => 1, // JTT808-2019
        'start_delimiter' => 0x7E,
        'escape_char' => 0x7D,
    ],

    // Logging
    'logging' => [
        'enabled' => env('MDVR_LOGGING', true),
        'channel' => env('MDVR_LOG_CHANNEL', 'stack'),
    ],
];
