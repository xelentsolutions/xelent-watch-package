<?php

namespace Laravel\Xelentwatch;

use Countable;

use function array_shift;
use function count;

/**
 * @internal
 */
final class RecordsBuffer implements Countable
{
    /**
     * @var list<array<mixed>>
     */
    private array $records = [];

    public bool $full = false;

    public function __construct(private int $length)
    {
        error_log("[BUFFER] RecordsBuffer initialized with length={$this->length}");
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function write(array $record): void
    {
        error_log("[BUFFER] write() called, buffer count=" . $this->count() . "/{$this->length}, full=" . ($this->full ? 'yes' : 'no'));

        if ($this->full) {
            error_log("[BUFFER] Buffer is full, removing oldest record");
            array_shift($this->records);
        }

        $this->records[] = $record;

        $this->full = $this->count() >= $this->length;

        error_log("[BUFFER] After write, count=" . $this->count() . ", full=" . ($this->full ? 'yes' : 'no'));
    }

    public function count(): int
    {
        return count($this->records);
    }

    public function pull(string $tokenHash, string $projectId = 'default', string $environment = 'production'): Payload
    {
        error_log("[BUFFER] pull() called, records count=" . $this->count());

        if ($this->records === []) {
            error_log("[BUFFER] Records buffer is empty, returning empty payload");
            return Payload::json([], $tokenHash, $projectId, $environment);
        }

        $records = $this->records;

        error_log("[BUFFER] Pulling " . count($records) . " records, flushing buffer");
        $this->flush();

        return Payload::json($records, $tokenHash, $projectId, $environment);
    }

    public function flush(): void
    {
        $this->records = [];
        $this->full = false;
    }
}
