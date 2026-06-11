<?php

return [
    'enabled' => env('XELENTWATCH_ENABLED', true),
    'token' => env('XELENTWATCH_TOKEN'),
    'deployment' => env('XELENTWATCH_DEPLOY'),
    'server' => env('XELENTWATCH_SERVER', (string) gethostname()),
    'project_name' => env('XELENTWATCH_PROJECT_NAME', env('APP_NAME', 'default')),
    'environment' => env('XELENTWATCH_ENVIRONMENT', 'production'),
    'capture_exception_source_code' => env('XELENTWATCH_CAPTURE_EXCEPTION_SOURCE_CODE', true),
    'capture_request_payload' => env('XELENTWATCH_CAPTURE_REQUEST_PAYLOAD', false),
    'redact_payload_fields' => explode(',', env('XELENTWATCH_REDACT_PAYLOAD_FIELDS', '_token,password,password_confirmation')),
    'redact_headers' => explode(',', env('XELENTWATCH_REDACT_HEADERS', 'Authorization,Cookie,Proxy-Authorization,X-XSRF-TOKEN')),

    'agent' => [
        // For Linux/Production:
        'host' => '/tmp/xelentwatch.sock',

        // For Windows/Laragon (Comment out the above and use this if needed):
        // 'host' => '127.0.0.1:9000', 

        'token' => env('XELENTWATCH_TOKEN'),
    ],

    'sampling' => [
        'requests' => env('XELENTWATCH_REQUEST_SAMPLE_RATE', 1.0),
        'commands' => env('XELENTWATCH_COMMAND_SAMPLE_RATE', 1.0),
        'exceptions' => env('XELENTWATCH_EXCEPTION_SAMPLE_RATE', 1.0),
        'scheduled_tasks' => env('XELENTWATCH_SCHEDULED_TASK_SAMPLE_RATE', 1.0),
    ],

    'filtering' => [
        'ignore_cache_events' => env('XELENTWATCH_IGNORE_CACHE_EVENTS', false),
        'ignore_mail' => env('XELENTWATCH_IGNORE_MAIL', false),
        'ignore_notifications' => env('XELENTWATCH_IGNORE_NOTIFICATIONS', false),
        'ignore_outgoing_requests' => env('XELENTWATCH_IGNORE_OUTGOING_REQUESTS', false),
        'ignore_queries' => env('XELENTWATCH_IGNORE_QUERIES', false),
        'log_level' => env('XELENTWATCH_LOG_LEVEL', env('LOG_LEVEL', 'debug')),
        'ignore_commands' => ['list', 'help'],
        'slow_query_threshold_ms' => env('XELENTWATCH_SLOW_QUERY_THRESHOLD_MS', 500),

    ],

    'ingest' => [
        'uri' => env('XELENTWATCH_INGEST_URI', '127.0.0.1:2407'),
        'timeout' => env('XELENTWATCH_INGEST_TIMEOUT', 0.5),
        'connection_timeout' => env('XELENTWATCH_INGEST_CONNECTION_TIMEOUT', 0.5),
        'event_buffer' => env('XELENTWATCH_INGEST_EVENT_BUFFER', 500),
    ],

    'logging' => [
        'enabled' => env('XELENTWATCH_LOGGING_ENABLED', true),
        'level' => env('XELENTWATCH_LOGGING_LEVEL', 'info'),
        'log_dir' => env('XELENTWATCH_LOG_DIR', storage_path('logs/xelentwatch')),
        'max_file_size' => env('XELENTWATCH_LOG_MAX_FILE_SIZE', 10 * 1024 * 1024), // 10MB
        'max_files' => env('XELENTWATCH_LOG_MAX_FILES', 5),
    ],
];
