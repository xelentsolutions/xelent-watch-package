<?php

namespace Laravel\Xelentwatch\Console;

use Laravel\Xelentwatch\Support\PackageCollector;
use Laravel\Xelentwatch\Ingest;
use Illuminate\Console\Command;

class InventorySendCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'xelentwatch:inventory:send
        {--dry-run : Show what would be sent without actually sending}
        {--path= : Project path (defaults to base_path())}
        {--host= : Override the hostname sent to server}
        {--format=json : Output format (json, table)}';

    /**
     * The console command description.
     */
    protected $description = 'Send package inventory to the TCP server for CVE scanning';

    private PackageCollector $collector;

    public function __construct(PackageCollector $collector)
    {
        parent::__construct();
        $this->collector = $collector;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $projectPath = $this->option('path') ?: base_path();
        $dryRun = $this->option('dry-run');
        $hostOverride = $this->option('host');

        $this->info('Collecting package inventory...');

        // Collect packages
        $packages = $this->collector->collect($projectPath);

        if (empty($packages)) {
            $this->warn('No packages found.');
            return self::SUCCESS;
        }

        // Get statistics
        $stats = $this->collector->getStats($packages);

        $this->info("Found {$stats['total']} packages");

        if ($this->option('format') === 'table') {
            $this->displayTable($stats);
        }

        // Dry run - just show what would be sent
        if ($dryRun) {
            $this->warn("\nDry run mode - not sending to server");
            $this->displayPackageList($packages);
            return self::SUCCESS;
        }

        // Build inventory payload
        $payload = $this->buildPayload($packages);

        // Send to TCP server
        $this->info("\nSending inventory to TCP server...");

        try {
            $result = $this->sendToServer($payload);

            if ($result['success']) {
                $this->info("Inventory sent successfully!");
                $this->info("Server ID: {$result['data']['server_id']}");
                $this->info("Package count: {$result['data']['package_count']}");
                $this->info("Scan triggered: " . ($result['data']['scan_triggered'] ? 'Yes' : 'No'));
                return self::SUCCESS;
            } else {
                $this->error("Failed to send inventory: {$result['error']}");
                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Error sending inventory: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    /**
     * Build the inventory payload.
     */
    private function buildPayload(array $packages): array
    {
        $hostOverride = $this->option('host');

        return [
            'type' => 'inventory',
            'server_name' => config('app.name', 'unknown'),
            'host' => $hostOverride ?: gethostname(),
            'environment' => config('app.env', 'production'),
            'packages' => $packages,
            'collected_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Send inventory to the TCP server.
     */
    private function sendToServer(array $payload): array
    {
        // Get config values
        $host = config('xelentwatch.ingest.uri', '127.0.0.1:2407');
        $timeout = config('xelentwatch.ingest.timeout', 0.5);

        $parts = explode(':', $host);
        $ip = $parts[0];
        $port = $parts[1] ?? 2407;

        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if (!$socket) {
            return [
                'success' => false,
                'error' => 'Failed to create socket',
            ];
        }

        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, [
            'sec' => (int) $timeout,
            'usec' => (int) (($timeout - (int) $timeout) * 1000000),
        ]);

        if (!@socket_connect($socket, $ip, (int) $port)) {
            socket_close($socket);
            return [
                'success' => false,
                'error' => 'Failed to connect to TCP server',
            ];
        }

        // Send payload with token
        $token = config('xelentwatch.token', '');
        $message = json_encode(array_merge($payload, ['token' => $token])) . "\n";

        $sent = socket_write($socket, $message, strlen($message));

        if ($sent === false) {
            socket_close($socket);
            return [
                'success' => false,
                'error' => 'Failed to send data to server',
            ];
        }

        // Read response
        $response = '';
        while ($data = socket_read($socket, 4096, PHP_NORMAL_READ)) {
            $response .= $data;
            if (str_ends_with($data, "\n")) {
                break;
            }
        }

        socket_close($socket);

        $result = json_decode(trim($response), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Invalid response from server',
            ];
        }

        return $result;
    }

    /**
     * Display statistics in table format.
     */
    private function displayTable(array $stats): void
    {
        $this->newLine();
        $this->info('Package Statistics:');

        $rows = [
            ['Total Packages', $stats['total']],
            ['Dev Packages', $stats['dev_packages']],
        ];

        foreach ($stats['by_ecosystem'] as $ecosystem => $count) {
            $rows[] = ["Ecosystem: {$ecosystem}", $count];
        }

        $this->table(['Metric', 'Count'], $rows);
    }

    /**
     * Display a list of packages (for dry run).
     */
    private function displayPackageList(array $packages): void
    {
        $this->newLine();
        $this->info('Packages to send:');

        $rows = array_map(function ($package) {
            return [
                $package['name'],
                $package['version'],
                $package['ecosystem'],
                $package['is_dev'] ? 'Yes' : 'No',
                $package['license'] ?? '-',
            ];
        }, array_slice($packages, 0, 20)); // Show first 20

        $this->table(['Name', 'Version', 'Ecosystem', 'Dev', 'License'], $rows);

        if (count($packages) > 20) {
            $this->info('... and ' . (count($packages) - 20) . ' more packages');
        }
    }
}
