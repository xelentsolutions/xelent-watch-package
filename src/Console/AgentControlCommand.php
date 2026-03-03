<?php

namespace Laravel\Xelentwatch\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Attribute\AsCommand;

use function date;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function json_decode;
use function json_encode;
use function mkdir;
use function sleep;
use function storage_path;
use function strtotime;

/**
 * Client-side telemetry control command.
 *
 * This command allows pausing, resuming, and stopping telemetry collection
 * from the Laravel application side, similar to the original xelentwatch:agent.
 *
 * Usage:
 *   php artisan xelentwatch:control start   - Enable telemetry collection
 *   php artisan xelentwatch:control stop    - Disable telemetry collection
 *   php artisan xelentwatch:control pause   - Temporarily pause telemetry
 *   php artisan xelentwatch:control resume  - Resume paused telemetry
 *   php artisan xelentwatch:control status  - Check current status
 */
#[AsCommand(name: 'xelentwatch:control', description: 'Control telemetry collection (start/stop/pause/resume/status)')]
final class AgentControlCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'xelentwatch:control
        {action : Action to perform (start|stop|pause|resume|status)}
        {--duration= : Duration for pause in seconds (default: indefinite)}
        {--reason= : Reason for the action}';

    /**
     * @var string
     */
    protected $description = 'Control telemetry collection (start/stop/pause/resume/status)';

    private const STATE_FILE = 'xelentwatch/agent-state.json';

    private const STATUS_RUNNING = 'running';
    private const STATUS_STOPPED = 'stopped';
    private const STATUS_PAUSED = 'paused';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = $this->argument('action');

        if (!in_array($action, ['start', 'stop', 'pause', 'resume', 'status'], true)) {
            $this->error("Invalid action: {$action}");
            $this->info("Valid actions: start, stop, pause, resume, status");
            return self::FAILURE;
        }

        return match ($action) {
            'start' => $this->startTelemetry(),
            'stop' => $this->stopTelemetry(),
            'pause' => $this->pauseTelemetry(),
            'resume' => $this->resumeTelemetry(),
            'status' => $this->showStatus(),
        };
    }

    /**
     * Start telemetry collection.
     */
    private function startTelemetry(): int
    {
        $currentState = $this->loadState();

        if ($currentState['status'] === self::STATUS_RUNNING) {
            $this->info('Telemetry is already running.');
            return self::SUCCESS;
        }

        $this->saveState([
            'status' => self::STATUS_RUNNING,
            'paused_at' => null,
            'pause_duration' => null,
            'stopped_at' => null,
            'started_at' => date('Y-m-d H:i:s'),
            'reason' => $this->option('reason') ?? 'Manual start',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->info('✓ Telemetry collection STARTED');
        $this->line('  Events will be sent to the TCP server.');

        return self::SUCCESS;
    }

    /**
     * Stop telemetry collection.
     */
    private function stopTelemetry(): int
    {
        $currentState = $this->loadState();

        if ($currentState['status'] === self::STATUS_STOPPED) {
            $this->info('Telemetry is already stopped.');
            return self::SUCCESS;
        }

        $this->saveState([
            'status' => self::STATUS_STOPPED,
            'paused_at' => null,
            'pause_duration' => null,
            'stopped_at' => date('Y-m-d H:i:s'),
            'started_at' => $currentState['started_at'] ?? null,
            'reason' => $this->option('reason') ?? 'Manual stop',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->info('✓ Telemetry collection STOPPED');
        $this->line('  Events will NOT be sent to the TCP server.');
        $this->line('  Use "php artisan xelentwatch:control start" to resume.');

        return self::SUCCESS;
    }

    /**
     * Pause telemetry collection temporarily.
     */
    private function pauseTelemetry(): int
    {
        $currentState = $this->loadState();

        if ($currentState['status'] === self::STATUS_STOPPED) {
            $this->error('Cannot pause: Telemetry is stopped. Use "start" first.');
            return self::FAILURE;
        }

        if ($currentState['status'] === self::STATUS_PAUSED) {
            $this->info('Telemetry is already paused.');
            return self::SUCCESS;
        }

        $duration = $this->option('duration');
        $pauseDuration = $duration ? (int) $duration : null;

        $this->saveState([
            'status' => self::STATUS_PAUSED,
            'paused_at' => date('Y-m-d H:i:s'),
            'pause_duration' => $pauseDuration,
            'stopped_at' => null,
            'started_at' => $currentState['started_at'] ?? null,
            'reason' => $this->option('reason') ?? 'Manual pause',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->info('✓ Telemetry collection PAUSED');

        if ($pauseDuration) {
            $this->line("  Duration: {$pauseDuration} seconds");
            $this->line('  Will auto-resume at: ' . date('Y-m-d H:i:s', strtotime("+{$pauseDuration} seconds")));
        } else {
            $this->line('  Duration: Indefinite');
            $this->line('  Use "php artisan xelentwatch:control resume" to resume.');
        }

        return self::SUCCESS;
    }

    /**
     * Resume paused telemetry collection.
     */
    private function resumeTelemetry(): int
    {
        $currentState = $this->loadState();

        if ($currentState['status'] !== self::STATUS_PAUSED) {
            $this->error('Cannot resume: Telemetry is not paused.');
            $this->line("  Current status: {$currentState['status']}");
            return self::FAILURE;
        }

        $this->saveState([
            'status' => self::STATUS_RUNNING,
            'paused_at' => null,
            'pause_duration' => null,
            'stopped_at' => null,
            'started_at' => $currentState['started_at'] ?? date('Y-m-d H:i:s'),
            'reason' => $this->option('reason') ?? 'Manual resume',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->info('✓ Telemetry collection RESUMED');
        $this->line('  Events will be sent to the TCP server.');

        return self::SUCCESS;
    }

    /**
     * Show current telemetry status.
     */
    private function showStatus(): int
    {
        $state = $this->loadState();

        $this->newLine();
        $this->line('<info>=== Xelentwatch Telemetry Status ===</info>');
        $this->newLine();

        // Status with color
        $statusColor = match ($state['status']) {
            self::STATUS_RUNNING => 'info',
            self::STATUS_STOPPED => 'error',
            self::STATUS_PAUSED => 'comment',
            default => 'comment',
        };

        $statusIcon = match ($state['status']) {
            self::STATUS_RUNNING => '🟢',
            self::STATUS_STOPPED => '🔴',
            self::STATUS_PAUSED => '🟡',
            default => '⚪',
        };

        $this->line("  Status: {$statusIcon} <{$statusColor}>{$state['status']}</{$statusColor}>");

        if ($state['started_at']) {
            $this->line("  Started at: {$state['started_at']}");
        }

        if ($state['stopped_at']) {
            $this->line("  Stopped at: {$state['stopped_at']}");
        }

        if ($state['status'] === self::STATUS_PAUSED) {
            if ($state['paused_at']) {
                $this->line("  Paused at: {$state['paused_at']}");
            }

            if ($state['pause_duration']) {
                $resumeAt = strtotime($state['paused_at']) + $state['pause_duration'];
                $remaining = $resumeAt - time();

                if ($remaining > 0) {
                    $this->line("  Auto-resume in: {$remaining} seconds");
                    $this->line("  Auto-resume at: " . date('Y-m-d H:i:s', $resumeAt));
                } else {
                    $this->line("  Auto-resume: Overdue (should resume now)");
                }
            } else {
                $this->line("  Auto-resume: Disabled (manual resume required)");
            }
        }

        if ($state['reason']) {
            $this->line("  Last action reason: {$state['reason']}");
        }

        if ($state['updated_at']) {
            $this->line("  Last updated: {$state['updated_at']}");
        }

        // Check TCP server connectivity
        $this->newLine();
        $this->line('<info>=== TCP Server Connectivity ===</info>');

        $tcpHost = config('xelentwatch.ingest.uri', '127.0.0.1:2407');
        $this->line("  Configured host: {$tcpHost}");

        $socket = @fsockopen('127.0.0.1', 2407, $errno, $errstr, 2);
        if ($socket) {
            fclose($socket);
            $this->line('  Connection: <info>✓ Available</info>');
        } else {
            $this->line('  Connection: <error>✗ Not available</error>');
            $this->line('  Make sure the TCP server is running.');
        }

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Load the current state from file.
     */
    private function loadState(): array
    {
        $stateFile = $this->getStateFilePath();

        if (!file_exists($stateFile)) {
            // Default state is running
            return [
                'status' => self::STATUS_RUNNING,
                'paused_at' => null,
                'pause_duration' => null,
                'stopped_at' => null,
                'started_at' => null,
                'reason' => 'Initial state',
                'updated_at' => null,
            ];
        }

        $content = file_get_contents($stateFile);
        $state = json_decode($content, true);

        if (!$state) {
            return [
                'status' => self::STATUS_RUNNING,
                'paused_at' => null,
                'pause_duration' => null,
                'stopped_at' => null,
                'started_at' => null,
                'reason' => 'Invalid state file',
                'updated_at' => null,
            ];
        }

        // Check if auto-resume is due
        if ($state['status'] === self::STATUS_PAUSED && $state['pause_duration'] && $state['paused_at']) {
            $resumeAt = strtotime($state['paused_at']) + $state['pause_duration'];

            if (time() >= $resumeAt) {
                // Auto-resume
                $state['status'] = self::STATUS_RUNNING;
                $state['paused_at'] = null;
                $state['pause_duration'] = null;
                $state['reason'] = 'Auto-resumed after pause duration';
                $state['updated_at'] = date('Y-m-d H:i:s');
                $this->saveState($state);
            }
        }

        return $state;
    }

    /**
     * Save state to file.
     */
    private function saveState(array $state): void
    {
        $stateFile = $this->getStateFilePath();
        $stateDir = dirname($stateFile);

        if (!is_dir($stateDir)) {
            mkdir($stateDir, 0755, true);
        }

        file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
    }

    /**
     * Get the state file path.
     */
    private function getStateFilePath(): string
    {
        return storage_path('framework/' . self::STATE_FILE);
    }
}
