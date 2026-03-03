<?php

namespace Laravel\Xelentwatch\Support;

/**
 * Centralized Logger for Xelentwatch Telemetry Agent
 * 
 * Provides structured logging for the Laravel telemetry agent:
 * - agent.log: General agent activity (startup, flush, control commands)
 * - ingest.log: Ingest operations (sending data to TCP server)
 * - error.log: Errors and exceptions
 */
class AgentLogger
{
    private string $logDir;
    private string $agentLogFile;
    private string $ingestLogFile;
    private string $errorLogFile;
    private bool $enabled;
    private string $logLevel;
    private int $maxFileSize;
    private int $maxFiles;

    // Log levels
    public const LEVEL_DEBUG = 'debug';
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';
    public const LEVEL_CRITICAL = 'critical';

    // Log contexts
    public const CONTEXT_AGENT = 'agent';
    public const CONTEXT_INGEST = 'ingest';
    public const CONTEXT_ERROR = 'error';

    private static ?AgentLogger $instance = null;

    /**
     * Get singleton instance
     */
    public static function getInstance(array $config = []): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    public function __construct(array $config = [])
    {
        $this->logDir = $config['log_dir'] ?? $this->getDefaultLogDir();
        $this->enabled = $config['enabled'] ?? true;
        $this->logLevel = $config['level'] ?? self::LEVEL_INFO;
        $this->maxFileSize = $config['max_file_size'] ?? 10 * 1024 * 1024; // 10MB
        $this->maxFiles = $config['max_files'] ?? 5;

        // Ensure log directory exists
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }

        // Set up log file paths
        $this->agentLogFile = $this->logDir . '/agent.log';
        $this->ingestLogFile = $this->logDir . '/ingest.log';
        $this->errorLogFile = $this->logDir . '/error.log';
    }

    /**
     * Get default log directory based on Laravel structure
     */
    private function getDefaultLogDir(): string
    {
        // Check for Laravel's storage_path helper
        if (\function_exists('storage_path')) {
            return storage_path('logs/xelentwatch');
        }

        // Fallback to a reasonable default
        return dirname(__DIR__, 3) . '/logs/agent';
    }

    /**
     * Log agent activity
     */
    public function agent(string $message, array $context = [], string $level = self::LEVEL_INFO): void
    {
        $this->log(self::CONTEXT_AGENT, $level, $message, $context);
    }

    /**
     * Log ingest operations
     */
    public function ingest(string $message, array $context = [], string $level = self::LEVEL_INFO): void
    {
        $this->log(self::CONTEXT_INGEST, $level, $message, $context);
    }

    /**
     * Log errors
     */
    public function error(string $message, array $context = [], string $level = self::LEVEL_ERROR): void
    {
        $this->log(self::CONTEXT_ERROR, $level, $message, $context);
    }

    /**
     * Log debug message
     */
    public function debug(string $context, string $message, array $data = []): void
    {
        $this->log($context, self::LEVEL_DEBUG, $message, $data);
    }

    /**
     * Log info message
     */
    public function info(string $context, string $message, array $data = []): void
    {
        $this->log($context, self::LEVEL_INFO, $message, $data);
    }

    /**
     * Log warning message
     */
    public function warning(string $context, string $message, array $data = []): void
    {
        $this->log($context, self::LEVEL_WARNING, $message, $data);
    }

    /**
     * Log critical message
     */
    public function critical(string $context, string $message, array $data = []): void
    {
        $this->log($context, self::LEVEL_CRITICAL, $message, $data);
    }

    /**
     * Main logging method
     */
    public function log(string $context, string $level, string $message, array $data = []): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!$this->shouldLog($level)) {
            return;
        }

        $logFile = $this->getLogFile($context);
        $timestamp = date('Y-m-d H:i:s.v');
        $formattedMessage = $this->formatMessage($timestamp, $level, $context, $message, $data);

        $this->writeToFile($logFile, $formattedMessage);
    }

    /**
     * Check if message should be logged based on level
     */
    private function shouldLog(string $level): bool
    {
        $levels = [
            self::LEVEL_DEBUG => 0,
            self::LEVEL_INFO => 1,
            self::LEVEL_WARNING => 2,
            self::LEVEL_ERROR => 3,
            self::LEVEL_CRITICAL => 4,
        ];

        $currentLevel = $levels[$this->logLevel] ?? 1;
        $messageLevel = $levels[$level] ?? 1;

        return $messageLevel >= $currentLevel;
    }

    /**
     * Get log file path for context
     */
    private function getLogFile(string $context): string
    {
        return match ($context) {
            self::CONTEXT_AGENT => $this->agentLogFile,
            self::CONTEXT_INGEST => $this->ingestLogFile,
            self::CONTEXT_ERROR => $this->errorLogFile,
            default => $this->agentLogFile,
        };
    }

    /**
     * Format log message
     */
    private function formatMessage(string $timestamp, string $level, string $context, string $message, array $data): string
    {
        $levelUpper = strtoupper($level);
        $contextUpper = strtoupper($context);

        $formatted = "[{$timestamp}] [{$levelUpper}] [{$contextUpper}] {$message}";

        if (!empty($data)) {
            $formatted .= ' | ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $formatted . PHP_EOL;
    }

    /**
     * Write to log file with rotation
     */
    private function writeToFile(string $file, string $message): void
    {
        // Check if rotation is needed
        if (file_exists($file) && filesize($file) > $this->maxFileSize) {
            $this->rotateLog($file);
        }

        file_put_contents($file, $message, FILE_APPEND | LOCK_EX);
    }

    /**
     * Rotate log file
     */
    private function rotateLog(string $file): void
    {
        // Delete oldest file if exists
        $oldestFile = $file . '.' . $this->maxFiles;
        if (file_exists($oldestFile)) {
            unlink($oldestFile);
        }

        // Rotate existing files
        for ($i = $this->maxFiles - 1; $i >= 1; $i--) {
            $oldFile = $file . '.' . $i;
            $newFile = $file . '.' . ($i + 1);
            if (file_exists($oldFile)) {
                rename($oldFile, $newFile);
            }
        }

        // Rename current file to .1
        rename($file, $file . '.1');
    }

    /**
     * Log agent startup
     */
    public function agentStartup(string $appName, string $environment, array $config = []): void
    {
        $this->agent('Telemetry agent initialized', [
            'app_name' => $appName,
            'environment' => $environment,
            'php_version' => PHP_VERSION,
            'config' => array_merge($config, ['token' => '***hidden***']),
        ], self::LEVEL_INFO);
    }

    /**
     * Log agent shutdown
     */
    public function agentShutdown(string $reason = 'normal'): void
    {
        $this->agent('Telemetry agent shutting down', [
            'reason' => $reason,
        ], self::LEVEL_INFO);
    }

    /**
     * Log buffer flush
     */
    public function bufferFlush(int $eventsCount, float $duration, bool $success = true): void
    {
        $this->ingest('Buffer flushed', [
            'events_count' => $eventsCount,
            'duration_ms' => round($duration * 1000, 2),
            'success' => $success,
        ], $success ? self::LEVEL_DEBUG : self::LEVEL_WARNING);
    }

    /**
     * Log TCP connection event
     */
    public function tcpConnection(string $event, bool $success = true, ?string $error = null): void
    {
        $data = [
            'event' => $event,
            'success' => $success,
        ];

        if ($error) {
            $data['error'] = $error;
        }

        $this->ingest("TCP connection {$event}", $data, $success ? self::LEVEL_INFO : self::LEVEL_ERROR);
    }

    /**
     * Log telemetry control command
     */
    public function controlCommand(string $command, bool $success = true, ?string $message = null): void
    {
        $data = [
            'command' => $command,
            'success' => $success,
        ];

        if ($message) {
            $data['message'] = $message;
        }

        $this->agent("Control command: {$command}", $data, $success ? self::LEVEL_INFO : self::LEVEL_WARNING);
    }

    /**
     * Log exception
     */
    public function exception(\Throwable $exception, array $context = []): void
    {
        $this->error('Exception occurred', array_merge([
            'exception_class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ], $context), self::LEVEL_ERROR);
    }

    /**
     * Log event recorded
     */
    public function eventRecorded(string $eventType, string $eventHash = null): void
    {
        $data = ['event_type' => $eventType];

        if ($eventHash) {
            $data['event_hash'] = $eventHash;
        }

        $this->debug(self::CONTEXT_AGENT, 'Event recorded', $data);
    }

    /**
     * Log sampling decision
     */
    public function samplingDecision(string $eventType, bool $sampled, float $rate): void
    {
        $this->debug(self::CONTEXT_AGENT, 'Sampling decision', [
            'event_type' => $eventType,
            'sampled' => $sampled,
            'rate' => $rate,
        ]);
    }

    /**
     * Get log directory
     */
    public function getLogDir(): string
    {
        return $this->logDir;
    }

    /**
     * Enable/disable logging
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * Set log level
     */
    public function setLogLevel(string $level): void
    {
        $this->logLevel = $level;
    }
}
