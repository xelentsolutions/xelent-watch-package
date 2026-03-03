# Xelentwatch Client Package

Self-hosted telemetry client for Laravel applications. This package sends telemetry data to the Xelentwatch TCP Server.

## Installation

### Via Composer

```bash
composer require xelent/xelentwatch
```

### Manual Installation

Add the package to your `composer.json`:

```json
{
  "require": {
    "xelent/xelentwatch": "^1.0"
  }
}
```

Then run:

```bash
composer update
```

## Configuration

### Publish Configuration

```bash
php artisan vendor:publish --tag=xelentwatch-config
```

### Environment Variables

Add these to your `.env` file:

```env
XELENTWATCH_ENABLED=true
XELENTWATCH_TOKEN=your-app-token-here
XELENTWATCH_PROJECT_NAME=your-app-name
XELENTWATCH_ENVIRONMENT=production
XELENTWATCH_INGEST_URI=127.0.0.1:2407
```

### Configuration File

The published configuration file (`config/xelentwatch.php`) contains all available options:

```php
return [
    'enabled' => env('XELENTWATCH_ENABLED', true),
    'token' => env('XELENTWATCH_TOKEN'),
    'project_name' => env('XELENTWATCH_PROJECT_NAME', env('APP_NAME', 'default')),
    'environment' => env('XELENTWATCH_ENVIRONMENT', 'production'),

    'sampling' => [
        'requests' => env('XELENTWATCH_REQUEST_SAMPLE_RATE', 1.0),
        'commands' => env('XELENTWATCH_COMMAND_SAMPLE_RATE', 1.0),
        'exceptions' => env('XELENTWATCH_EXCEPTION_SAMPLE_RATE', 1.0),
        'scheduled_tasks' => env('XELENTWATCH_SCHEDULED_TASK_SAMPLE_RATE', 1.0),
    ],

    'ingest' => [
        'uri' => env('XELENTWATCH_INGEST_URI', '127.0.0.1:2407'),
        'timeout' => env('XELENTWATCH_INGEST_TIMEOUT', 0.5),
        'connection_timeout' => env('XELENTWATCH_INGEST_CONNECTION_TIMEOUT', 0.5),
        'event_buffer' => env('XELENTWATCH_INGEST_EVENT_BUFFER', 500),
    ],
];
```

## Usage

### Automatic Telemetry

Once installed and configured, the package automatically captures:

- HTTP requests
- Console commands
- Exceptions
- Database queries
- Cache events
- Mail notifications
- Queue jobs
- Scheduled tasks

### Manual Telemetry Control

Use the Artisan commands to control telemetry:

```bash
# Start telemetry agent
php artisan xelentwatch:agent start

# Check agent status
php artisan xelentwatch:agent status

# Pause telemetry
php artisan xelentwatch:agent pause

# Resume telemetry
php artisan xelentwatch:agent resume

# Stop telemetry
php artisan xelentwatch:agent stop
```

### Using the Facade

```php
use Laravel\Xelentwatch\Facades\Xelentwatch;

// Check if telemetry is enabled
if (Xelentwatch::isEnabled()) {
    // Custom telemetry logic
}
```

## Sampling

Control the sampling rate for different event types:

```env
# Sample 100% of requests (default)
XELENTWATCH_REQUEST_SAMPLE_RATE=1.0

# Sample 10% of requests (high-traffic apps)
XELENTWATCH_REQUEST_SAMPLE_RATE=0.1

# Sample 100% of exceptions (always capture errors)
XELENTWATCH_EXCEPTION_SAMPLE_RATE=1.0
```

## Filtering

Ignore specific event types:

```env
XELENTWATCH_IGNORE_CACHE_EVENTS=false
XELENTWATCH_IGNORE_MAIL=false
XELENTWATCH_IGNORE_NOTIFICATIONS=false
XELENTWATCH_IGNORE_OUTGOING_REQUESTS=false
XELENTWATCH_IGNORE_QUERIES=false
```

## Logging

The package logs to `storage/logs/xelentwatch/`:

| File         | Description       |
| ------------ | ----------------- |
| `agent.log`  | Agent activity    |
| `ingest.log` | Ingest operations |
| `error.log`  | Errors            |

Configure logging:

```env
XELENTWATCH_LOGGING_ENABLED=true
XELENTWATCH_LOGGING_LEVEL=info
```

## Multi-App Setup

To use with multiple Laravel applications:

1. Install this package in each application
2. Configure unique tokens for each app
3. Point all apps to the same TCP server

```env
# App 1 (.env)
XELENTWATCH_TOKEN=app1-token-here
XELENTWATCH_PROJECT_NAME=app1
XELENTWATCH_INGEST_URI=127.0.0.1:2407

# App 2 (.env)
XELENTWATCH_TOKEN=app2-token-here
XELENTWATCH_PROJECT_NAME=app2
XELENTWATCH_INGEST_URI=127.0.0.1:2407
```

## Requirements

- PHP 8.1+
- Laravel 10.0+ or 11.0+
- ext-sockets
- ext-json

## License

MIT
