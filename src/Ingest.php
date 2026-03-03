<?php

namespace Laravel\Xelentwatch;

use Deprecated;
use Laravel\Xelentwatch\Contracts\Ingest as IngestContract;
use RuntimeException;

use function call_user_func;
use function Xelentwatch\fclose_safely;
use function Xelentwatch\fread_all;
use function Xelentwatch\fwrite_all;
use function Xelentwatch\stream_configure_read_timeout;

/**
 * @internal
 */
final class Ingest implements IngestContract
{
    private string $transmitTo;

    private bool $shouldDigestWhenBufferIsFull = true;

    /**
     * @param  (callable(string $address, float $timeout): resource)  $streamFactory
     */
    public function __construct(
        string $transmitTo,
        private float $connectionTimeout,
        private float $timeout,
        public $streamFactory,
        public RecordsBuffer $buffer,
        private string $tokenHash,
        private string $projectId = 'default',
        private string $environment = 'production',
    ) {
        // Detect socket type:
        // - Unix socket: starts with / (e.g., /tmp/xelentwatch.sock) -> unix://...
        // - TCP socket: host:port (e.g., 127.0.0.1:2407) -> tcp://...
        // - Already has protocol: tcp:// or unix:// -> use as-is
        $this->transmitTo = match (true) {
            str_starts_with($transmitTo, 'unix://') => $transmitTo,
            str_starts_with($transmitTo, 'tcp://') => $transmitTo,
            str_starts_with($transmitTo, '/') => "unix://{$transmitTo}",
            default => "tcp://{$transmitTo}",
        };

        error_log("[INGEST] Ingest initialized: transmitTo={$this->transmitTo}, projectId={$this->projectId}, env={$this->environment}");
    }

    public function write(array $record): void
    {
        // Check if telemetry is enabled via AgentState
        if (!AgentState::isEnabled()) {
            // Telemetry is paused or stopped - skip writing
            return;
        }

        $this->buffer->write($record);

        if ($this->shouldDigestWhenBufferIsFull && $this->buffer->full) {
            $this->digest();
        }
    }

    public function writeNow(array $record): void
    {
        // Check if telemetry is enabled via AgentState
        if (!AgentState::isEnabled()) {
            // Telemetry is paused or stopped - skip writing
            return;
        }

        $this->transmit(Payload::json([$record], $this->tokenHash, $this->projectId, $this->environment));
    }

    public function flush(): void
    {
        // Check if telemetry is enabled via AgentState
        if (!AgentState::isEnabled()) {
            // Telemetry is paused or stopped - just clear buffer without sending
            $this->buffer->flush();
            return;
        }

        $this->buffer->flush();
    }

    public function ping(): void
    {
        // Always allow ping regardless of state (for health checks)
        $this->transmit(Payload::text('PING', $this->tokenHash));
    }

    #[Deprecated('Use shouldDigestWhenBufferIsFull instead')]
    public function shouldDigest(bool $bool = true): void
    {
        $this->shouldDigestWhenBufferIsFull($bool);
    }

    public function shouldDigestWhenBufferIsFull(bool $bool = true): void
    {
        $this->shouldDigestWhenBufferIsFull = $bool;
    }

    public function digest(): void
    {
        // Check if telemetry is enabled via AgentState
        if (!AgentState::isEnabled()) {
            // Telemetry is paused or stopped - clear buffer without sending
            $this->buffer->flush();
            return;
        }

        $this->transmit($this->buffer->pull($this->tokenHash, $this->projectId, $this->environment));
    }

    private function transmit(Payload $payload): void
    {
        if ($payload->isEmpty()) {
            error_log("[INGEST] Payload is empty, skipping transmit");
            return;
        }

        error_log("[INGEST] Creating stream to {$this->transmitTo}");
        $stream = $this->createStream();

        try {
            $this->configureStreamTimeout($stream);

            $payloadStr = $payload->pull();
            error_log("[INGEST] Sending payload: " . strlen($payloadStr) . " bytes");
            $this->sendPayload($stream, $payloadStr);

            error_log("[INGEST] Waiting for acknowledgment...");
            $this->waitForAcknowledgment($stream);
            error_log("[INGEST] Acknowledgment received");
        } finally {
            fclose_safely($stream);
        }
    }

    /**
     * @return resource
     */
    private function createStream()
    {
        return call_user_func($this->streamFactory, $this->transmitTo, $this->connectionTimeout);
    }

    /**
     * @param  resource  $stream
     */
    private function configureStreamTimeout($stream): void
    {
        stream_configure_read_timeout($stream, $this->timeout);
    }

    /**
     * @param  resource  $stream
     */
    private function sendPayload($stream, string $payloadStr): void
    {
        fwrite_all($stream, $payloadStr);
    }

    /**
     * @param  resource  $stream
     */
    private function waitForAcknowledgment($stream): void
    {
        $response = fread_all($stream, 4);

        if ($response !== '2:OK') {
            throw new RuntimeException("Unexpected response from agent [{$response}]");
        }
    }
}
