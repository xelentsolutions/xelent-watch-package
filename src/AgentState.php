<?php

namespace Laravel\Xelentwatch;

use Illuminate\Support\Facades\File;

use function file_exists;
use function file_get_contents;
use function json_decode;
use function storage_path;
use function time;

/**
 * Agent state manager for client-side telemetry control.
 *
 * This class manages the telemetry collection state, allowing
 * pause/resume/stop functionality from the Laravel application.
 *
 * @internal
 */
final class AgentState
{
    private const STATE_FILE = 'xelentwatch/agent-state.json';

    private const STATUS_RUNNING = 'running';
    private const STATUS_STOPPED = 'stopped';
    private const STATUS_PAUSED = 'paused';

    private static ?array $cachedState = null;
    private static ?float $cacheTime = null;
    private static float $cacheTTL = 1.0; // Cache for 1 second to avoid repeated file reads

    /**
     * Check if telemetry collection is enabled.
     */
    public static function isEnabled(): bool
    {
        $state = self::loadState();

        // Check if stopped
        if ($state['status'] === self::STATUS_STOPPED) {
            return false;
        }

        // Check if paused
        if ($state['status'] === self::STATUS_PAUSED) {
            // Check if auto-resume is due
            if ($state['pause_duration'] && $state['paused_at']) {
                $pausedAt = strtotime($state['paused_at']);
                $resumeAt = $pausedAt + $state['pause_duration'];

                if (time() >= $resumeAt) {
                    // Auto-resume - return true
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * Check if telemetry collection is paused.
     */
    public static function isPaused(): bool
    {
        $state = self::loadState();
        return $state['status'] === self::STATUS_PAUSED;
    }

    /**
     * Check if telemetry collection is stopped.
     */
    public static function isStopped(): bool
    {
        $state = self::loadState();
        return $state['status'] === self::STATUS_STOPPED;
    }

    /**
     * Get the current status.
     */
    public static function getStatus(): string
    {
        return self::loadState()['status'];
    }

    /**
     * Get the current state.
     */
    public static function getState(): array
    {
        return self::loadState();
    }

    /**
     * Get the reason for current state.
     */
    public static function getReason(): ?string
    {
        return self::loadState()['reason'] ?? null;
    }

    /**
     * Load the current state from file.
     */
    private static function loadState(): array
    {
        // Use cached state if still valid
        $now = microtime(true);
        if (self::$cachedState !== null && self::$cacheTime !== null && ($now - self::$cacheTime) < self::$cacheTTL) {
            return self::$cachedState;
        }

        $stateFile = self::getStateFilePath();

        if (!file_exists($stateFile)) {
            // Default state is running
            $defaultState = [
                'status' => self::STATUS_RUNNING,
                'paused_at' => null,
                'pause_duration' => null,
                'stopped_at' => null,
                'started_at' => null,
                'reason' => 'Initial state',
                'updated_at' => null,
            ];

            self::$cachedState = $defaultState;
            self::$cacheTime = $now;
            return $defaultState;
        }

        $content = file_get_contents($stateFile);
        $state = json_decode($content, true);

        if (!$state) {
            $defaultState = [
                'status' => self::STATUS_RUNNING,
                'paused_at' => null,
                'pause_duration' => null,
                'stopped_at' => null,
                'started_at' => null,
                'reason' => 'Invalid state file',
                'updated_at' => null,
            ];

            self::$cachedState = $defaultState;
            self::$cacheTime = $now;
            return $defaultState;
        }

        self::$cachedState = $state;
        self::$cacheTime = $now;
        return $state;
    }

    /**
     * Clear the state cache.
     */
    public static function clearCache(): void
    {
        self::$cachedState = null;
        self::$cacheTime = null;
    }

    /**
     * Get the state file path.
     */
    private static function getStateFilePath(): string
    {
        // Use storage_path() if available (Laravel context)
        if (function_exists('storage_path')) {
            return storage_path('framework/' . self::STATE_FILE);
        }

        // Fallback for non-Laravel context
        return sys_get_temp_dir() . '/xelentwatch-agent-state.json';
    }
}
