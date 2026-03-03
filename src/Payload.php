<?php

namespace Laravel\Xelentwatch;

use RuntimeException;

use function in_array;
use function json_encode;
use function strlen;

/**
 * @internal
 */
final class Payload
{
    public const PAYLOAD_VERSION = 'v1';

    private bool $pulled = false;

    /**
     * @param  'TEXT'|'JSON'  $type
     */
    public function __construct(
        private string $type,
        private string $payload,
        private string $tokenHash,
        private string $projectId = 'default',
        private string $environment = 'production',
    ) {
        //
    }

    public static function text(string $payload, string $tokenHash): self
    {
        return new self('TEXT', $payload, $tokenHash);
    }

    /**
     * @param  list<array<string, mixed>>  $payload
     */
    public static function json(array $payload, string $tokenHash, string $projectId = 'default', string $environment = 'production'): self
    {
        error_log("[PAYLOAD] Creating JSON payload with " . count($payload) . " records, projectId=$projectId, env=$environment");

        // Enrich each record with project_id and environment
        $enrichedPayload = array_map(function ($record) use ($projectId, $environment) {
            $record['project_name'] = $projectId;
            $record['environment'] = $environment;
            return $record;
        }, $payload);

        $json = json_encode($enrichedPayload, flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
        error_log("[PAYLOAD] JSON payload created: " . strlen($json) . " bytes");

        return new self(
            'JSON',
            $json,
            $tokenHash,
            $projectId,
            $environment
        );
    }

    public function pull(): string
    {
        if ($this->pulled) {
            throw new RuntimeException('Payload has already been read');
        }

        $this->pulled = true;
        $payload = $this->payload;

        $this->payload = '';

        $version = self::PAYLOAD_VERSION;
        $tokenHash = $this->tokenHash;

        // Format: length:version:tokenHash:jsonPayload
        // If tokenHash is empty, format is: length:version::jsonPayload
        $length = strlen($version) + 1 + strlen($tokenHash) + 1 + strlen($payload);

        return $length . ':' . $version . ':' . $tokenHash . ':' . $payload;
    }

    public function rawPayload(): string
    {
        return $this->payload;
    }

    public function isEmpty(): bool
    {
        return match ($this->type) {
            'JSON' => in_array($this->payload, ['[]', '{}', '""', 'null'], true),
            'TEXT' => $this->payload === '',
        };
    }
}
