<?php

namespace Laravel\Xelentwatch\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Laravel\Xelentwatch\GracefulCliOutputExceptionHandler;
use Laravel\Xelentwatch\Support\ClickHouseClient;
use SensitiveParameter;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

use function fclose;
use function fread;
use function fwrite;
use function stream_socket_server;
use function stream_socket_accept;
use function strlen;
use function substr;

/**
 * @internal
 */
#[AsCommand(name: 'xelentwatch:agent', description: 'Run the Xelentwatch agent.')]
final class AgentCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'xelentwatch:agent
        {--listen-on=127.0.0.1:2407 : TCP address to listen on}
        {--clickhouse-url= : ClickHouse HTTP URL}
        {--clickhouse-user= : ClickHouse username}
        {--clickhouse-password= : ClickHouse password}
        {--batch-size=50 : Number of events to batch before sending}
        {--batch-timeout=5 : Maximum seconds to wait before sending batch}
        {--auth-connection-timeout=}
        {--auth-timeout=}
        {--ingest-connection-timeout=}
        {--ingest-timeout=}
        {--server=}
        {--silent : Do not output any message}';

    /**
     * @var string
     */
    protected $description = 'Run the Xelentwatch agent.';

    public function __construct(
        #[SensitiveParameter] private ?string $token,
        private ?string $server,
        private ?string $ingestUri,
    ) {
        parent::__construct();
    }

    public function handle(Application $app): void
    {
        // Clear opcache to ensure latest code is loaded
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        try {
            $handler = $app->instance(
                ExceptionHandler::class,
                new GracefulCliOutputExceptionHandler($app->make(ExceptionHandler::class))
            );
        } catch (Throwable) {
            //
        }

        $listenOn = $this->option('listen-on') ?? '127.0.0.1:2407';
        $clickhouseUrl = $this->option('clickhouse-url') ?? $_SERVER['CLICKHOUSE_URL'] ?? 'http://localhost:8123';
        $clickhouseUser = $this->option('clickhouse-user') ?? $_SERVER['CLICKHOUSE_USER'] ?? 'default';
        $clickhousePassword = $this->option('clickhouse-password') ?? $_SERVER['CLICKHOUSE_PASSWORD'] ?? '';
        $batchSize = (int) ($this->option('batch-size') ?? 50);
        $batchTimeout = (float) ($this->option('batch-timeout') ?? 5.0);

        $this->info("Starting Xelentwatch agent...");
        $this->info("Listen on: {$listenOn}");
        $this->info("ClickHouse URL: {$clickhouseUrl}");
        $this->info("ClickHouse User: {$clickhouseUser}");
        $this->info("Batch size: {$batchSize}");
        $this->info("Batch timeout: {$batchTimeout}s");

        // Create ClickHouse client
        $clickhouse = new ClickHouseClient($clickhouseUrl, $clickhouseUser, $clickhousePassword);

        // Create TCP socket server
        $server = stream_socket_server("tcp://{$listenOn}", $errno, $errstr);
        if (!$server) {
            $this->error("Failed to create socket: {$errstr} ({$errno})");
            exit(1);
        }

        $this->info("Listening on tcp://{$listenOn}");

        // Batching state
        $batch = [];
        $lastBatchTime = microtime(true);

        while (true) {
            // Check for incoming connection with timeout
            $timeout = $batchTimeout - (microtime(true) - $lastBatchTime);
            if ($timeout < 0.1) {
                $timeout = 0.1;
            }

            $client = @stream_socket_accept($server, $timeout);

            if ($client !== false) {
                $clientName = stream_socket_get_name($client, true);
                $this->info("Connection from: {$clientName}");

                // Read the payload
                $payload = $this->readPayload($client);

                if ($payload !== null && $payload !== '') {
                    $this->info("Received payload: " . strlen($payload) . " bytes");

                    // Send acknowledgment
                    fwrite($client, '2:OK');

                    // Parse and add to batch
                    $events = $this->parsePayload($payload);
                    foreach ($events as $event) {
                        $batch[] = $event;
                    }

                    $this->info("Parsed " . count($events) . " events. Batch size: " . count($batch));
                } else {
                    $this->info("Empty payload received");
                }

                fclose($client);

                // Check if we should flush the batch
                if (count($batch) >= $batchSize || (microtime(true) - $lastBatchTime) >= $batchTimeout) {
                    if (!empty($batch)) {
                        $this->flushBatch($clickhouse, $batch);
                        $batch = [];
                        $lastBatchTime = microtime(true);
                    }
                }
            } else {
                // Timeout occurred - flush batch if we have events and time is up
                if (!empty($batch) && (microtime(true) - $lastBatchTime) >= $batchTimeout) {
                    $this->flushBatch($clickhouse, $batch);
                    $batch = [];
                    $lastBatchTime = microtime(true);
                }
            }
        }

        if (isset($handler)) {
            $handler->shuttingDown();
        }
    }

    /**
     * Read the full payload from the client socket
     */
    private function readPayload($client): string
    {
        $content = '';
        $attempts = 0;

        do {
            $chunk = fread($client, 8192);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $content .= $chunk;
            $attempts++;
        } while ($attempts < 100 && strlen($content) < 10 * 1024 * 1024); // Max 10MB

        return $content;
    }

    /**
     * Parse the payload into events
     * Format: length:version:tokenHash:jsonPayload
     */
    private function parsePayload(string $payload): array
    {
        $events = [];

        // Split by the format: length:version:tokenHash:
        $parts = explode(':', $payload, 4);
        if (count($parts) >= 4) {
            $jsonPayload = $parts[3] ?? '';

            $decoded = json_decode($jsonPayload, true);
            if (is_array($decoded)) {
                $events = $decoded;
            }
        }

        return $events;
    }

    /**
     * Flush the batch to ClickHouse
     */
    private function flushBatch(ClickHouseClient $clickhouse, array $batch): void
    {
        $this->info("Flushing " . count($batch) . " events to ClickHouse...");

        try {
            $clickhouse->insertBatch($batch);
            $this->info("Successfully inserted " . count($batch) . " events.");
        } catch (Throwable $e) {
            $this->error("Failed to insert batch: " . $e->getMessage());
        }
    }
}
