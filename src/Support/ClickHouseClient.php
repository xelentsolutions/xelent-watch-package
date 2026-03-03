<?php

namespace Laravel\Xelentwatch\Support;

use RuntimeException;
use Throwable;

use function curl_close;
use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_init;
use function curl_setopt_array;
use function json_encode;

// For DateTime conversion
use function sprintf;

/**
 * @internal
 */
final class ClickHouseClient
{
    private string $url;
    private ?string $username;
    private ?string $password;
    private int $timeout;

    public function __construct(
        string $url,
        ?string $username = 'default',
        ?string $password = null,
        int $timeout = 30
    ) {
        // Remove trailing slash from URL
        $this->url = rtrim($url, '/');
        $this->username = $username;
        $this->password = $password;
        $this->timeout = $timeout;
    }

    /**
     * Insert a batch of events into ClickHouse
     *
     * @param  array<mixed>  $events  Array of event records
     * @param  string  $database  Database name (default: default)
     * @param  string  $table  Table name (default: telemetry)
     * @return void
     */
    public function insertBatch(array $events, string $database = 'default', string $table = 'telemetry'): void
    {
        if (empty($events)) {
            return;
        }

        // Format events as JSON EachRow format for ClickHouse
        $formattedData = $this->formatEventsForClickHouse($events);

        // Include table name in the INSERT statement, not as URL parameter
        $sql = "INSERT INTO {$database}.{$table} FORMAT JSONEachRow";

        $this->sendRequest("POST", "/?database={$database}", $formattedData, $sql);
    }

    /**
     * Execute a query on ClickHouse
     *
     * @param  string  $query  SQL query
     * @param  string  $database  Database name (default: default)
     * @return string  Response body
     */
    public function query(string $query, string $database = 'default'): string
    {
        return $this->sendRequest("POST", "/?database={$database}", '', $query);
    }

    /**
     * Format events for ClickHouse JSONEachRow format
     *
     * @param  array<mixed>  $events
     * @return string
     */
    private function formatEventsForClickHouse(array $events): string
    {
        $rows = [];

        foreach ($events as $event) {
            // Convert event to JSON row format
            $jsonRow = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            // Fix ClickHouse parsing issues with numbers like ".5" instead of "0.5"
            $jsonRow = $this->fixFloatDecimals($jsonRow);
            // Convert Unix timestamp floats to ClickHouse-compatible format
            $jsonRow = $this->convertTimestamp($jsonRow);
            $rows[] = $jsonRow;
        }

        // Join with newlines for JSONEachRow format
        return implode("\n", $rows);
    }

    /**
     * Fix float decimal formatting in JSON by ensuring leading zeros
     * ClickHouse requires numbers to start with a digit (0-9), not a decimal point
     *
     * @param  string  $json
     * @return string
     */
    private function fixFloatDecimals(string $json): string
    {
        // Match numbers that start with a decimal point (like ".5") with NO digits before
        // This prevents matching timestamps like "1770016289.608517"
        // The pattern uses negative lookbehind to ensure no digits precede the decimal
        return preg_replace('/(?<!\d)\.(\d+)/', '0.${1}', $json);
    }

    /**
     * Convert Unix timestamp floats to ClickHouse DateTime64-compatible string format
     * ClickHouse DateTime64 expects: 'YYYY-MM-DD HH:MM:SS.ffffff' format
     *
     * @param  string  $json
     * @return string
     */
    private function convertTimestamp(string $json): string
    {
        // Match "timestamp":<number> where number is a Unix timestamp with fractional seconds
        // Pattern: "timestamp": followed by digits.digits (Unix timestamp with microseconds)
        return preg_replace_callback(
            '/"timestamp":(\d+\.\d+)/',
            function ($matches) {
                $timestamp = (float) $matches[1];
                // Convert to DateTime with microseconds
                $datetime = \DateTime::createFromFormat('U.u', sprintf('%.6f', $timestamp));
                if ($datetime !== false) {
                    // Format as ClickHouse-compatible string: 'YYYY-MM-DD HH:MM:SS.ffffff'
                    return '"timestamp":"' . $datetime->format('Y-m-d H:i:s.u') . '"';
                }
                // Fallback: use integer timestamp if conversion fails
                return '"timestamp":' . (int) $timestamp;
            },
            $json
        );
    }

    /**
     * Send HTTP request to ClickHouse
     *
     * @param  string  $method  HTTP method
     * @param  string  $path  Request path
     * @param  string  $body  Request body
     * @param  string  $sql  SQL statement (for INSERT)
     * @return string  Response body
     */
    private function sendRequest(
        string $method,
        string $path,
        string $body,
        string $sql = ''
    ): string {
        $ch = curl_init();

        try {
            $url = $this->url . $path;

            // For INSERT/SELECT statements, add the query to the URL
            if (!empty($sql)) {
                $url .= "&query=" . urlencode($sql);
            }

            $options = [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_POST => ($method === 'POST'),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
            ];

            // Add authentication if configured
            if ($this->username !== null && $this->password !== null) {
                $options[CURLOPT_USERPWD] = $this->username . ':' . $this->password;
            }

            // Add body for POST requests
            if ($method === 'POST' && $body !== '') {
                $options[CURLOPT_POSTFIELDS] = $body;
            }

            curl_setopt_array($ch, $options);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($response === false) {
                throw new RuntimeException('CURL error: ' . curl_error($ch));
            }

            if ($httpCode >= 400) {
                throw new RuntimeException("ClickHouse error (HTTP {$httpCode}): {$response}");
            }

            return $response;
        } finally {
            curl_close($ch);
        }
    }

    /**
     * Check if ClickHouse is reachable
     *
     * @return bool
     */
    public function ping(): bool
    {
        try {
            $ch = curl_init();

            $options = [
                CURLOPT_URL => $this->url . '/ping',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 5,
            ];

            if ($this->username !== null && $this->password !== null) {
                $options[CURLOPT_USERPWD] = $this->username . ':' . $this->password;
            }

            curl_setopt_array($ch, $options);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close($ch);

            return $httpCode === 200;
        } catch (Throwable) {
            return false;
        }
    }
}
