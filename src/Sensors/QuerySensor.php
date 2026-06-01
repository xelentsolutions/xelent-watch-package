<?php

namespace Laravel\Xelentwatch\Sensors;

use Illuminate\Database\Events\QueryExecuted;
use Laravel\Xelentwatch\Clock;
use Laravel\Xelentwatch\Compatibility;
use Laravel\Xelentwatch\Location;
use Laravel\Xelentwatch\QueryConnectionType;
use Laravel\Xelentwatch\Records\Query;
use Laravel\Xelentwatch\State\CommandState;
use Laravel\Xelentwatch\State\RequestState;
use Laravel\Xelentwatch\Types\Str;

use function hash;
use function in_array;
use function json_encode;
use function preg_replace;
use function round;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * @internal
 */
final class QuerySensor
{
    public function __construct(
        private RequestState|CommandState $executionState,
        private Clock $clock,
        private Location $location,
    ) {
        //
    }

    /**
     * @param  list<array{ file?: string, line?: int }>  $trace
     * @return array{0: Query, 1: callable(): array<mixed>}
     */
    public function __invoke(QueryExecuted $event, array $trace): array
    {
        $durationInMicroseconds = (int) round($event->time * 1000);

        [$file, $line] = $this->location->forQueryTrace($trace);

        $connectionType = Compatibility::$queryConnectionTypeCapturable && $event->readWriteType !== null
            ? QueryConnectionType::from($event->readWriteType)
            : QueryConnectionType::Unknown;

        [$hasExplain, $explainOutput] = $this->captureExplain($event, $durationInMicroseconds);

        return [
            $record = new Query(
                sql: $event->sql,
                file: $file ?? '',
                line: $line ?? 0,
                duration: $durationInMicroseconds,
                connection: $event->connectionName ?? '', // @phpstan-ignore nullCoalesce.property
                connectionType: $connectionType,
                has_explain: $hasExplain,
                explain_output: $explainOutput,
            ),
            function () use ($event, $record) {
                $this->executionState->queries++;

                return [
                    'v' => 1,
                    't' => 'query',
                    'timestamp' => $this->clock->microtime() - ($event->time / 1000),
                    'deploy' => $this->executionState->deploy,
                    'server' => $this->executionState->server,
                    '_group' => $this->hash($event, $record),
                    'trace_id' => $this->executionState->trace,
                    'execution_source' => $this->executionState->source,
                    'execution_id' => $this->executionState->id(),
                    'execution_preview' => $this->executionState->executionPreview(),
                    'execution_stage' => $this->executionState->stage,
                    'user' => $this->executionState->user->id(),
                    'sql' => Str::mediumText($record->sql),
                    'file' => Str::tinyText($record->file),
                    'line' => $record->line,
                    'duration' => $record->duration,
                    'connection' => Str::tinyText($record->connection),
                    'connection_type' => $record->connectionType === QueryConnectionType::Unknown ? '' : $record->connectionType->value,
                    'has_explain' => $record->has_explain ? 1 : 0,
                    'explain_output' => $record->explain_output,
                ];
            },
        ];
    }

    /**
     * Capture EXPLAIN output for queries that exceed the slow-query threshold.
     *
     * Only runs for SELECT statements to avoid mutating side-effects.
     * Returns [false, ''] when EXPLAIN is not captured.
     *
     * @return array{0: bool, 1: string}
     */
    private function captureExplain(QueryExecuted $event, int $durationInMicroseconds): array
    {
        $thresholdMs = (int) config('xelentwatch.filtering.slow_query_threshold_ms', 500);

        if ($durationInMicroseconds < $thresholdMs * 1000) {
            return [false, ''];
        }

        $normalised = strtolower(trim($event->sql));

        if (! str_starts_with($normalised, 'select')) {
            return [false, ''];
        }

        try {
            $results = $event->connection->select('EXPLAIN ' . $event->sql, $event->bindings);
            return [true, json_encode($results, JSON_UNESCAPED_UNICODE) ?: ''];
        } catch (\Throwable) {
            return [false, ''];
        }
    }

    private function hash(QueryExecuted $event, Query $record): string
    {
        if (! in_array($event->connection->getDriverName(), ['mariadb', 'mysql', 'pgsql', 'sqlite', 'sqlsrv', 'singlestore'], true)) {
            return hash('xxh128', "{$record->connection},{$record->sql}");
        }

        $sql = preg_replace('/in \([\d?\s,]+\)/', 'in (...?)', $record->sql) ?? $record->sql;

        if (str_contains($sql, 'insert')) {
            $sql = preg_replace('/values [(?,\s)]+/', 'values ...', $sql) ?? $sql;
        }

        return hash('xxh128', "{$record->connection},{$sql}");
    }
}
