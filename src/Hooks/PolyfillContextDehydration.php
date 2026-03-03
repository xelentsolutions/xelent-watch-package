<?php

namespace Laravel\Xelentwatch\Hooks;

use Laravel\Xelentwatch\Compatibility;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\State\CommandState;
use Laravel\Xelentwatch\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class PolyfillContextDehydration
{
    /**
     * @param  Core<RequestState|CommandState>  $xelentwatch
     */
    public function __construct(
        private Core $xelentwatch,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function __invoke(mixed $connection, mixed $queue, array $payload): array
    {
        $context = Compatibility::$context;

        try {
            if (($context['xelentwatch_user_id'] ?? '') === '') {
                $context['xelentwatch_user_id'] = $this->xelentwatch->executionState->user->resolvedUserId();
            }

            return [
                ...$payload,
                'xelentwatch' => [
                    ...($payload['xelentwatch'] ?? []), // @phpstan-ignore arrayUnpacking.nonIterable
                    'xelentwatch_trace_id' => $context['xelentwatch_trace_id'] ?? null,
                    'xelentwatch_should_sample' => $context['xelentwatch_should_sample'] ?? null,
                    'xelentwatch_user_id' => $context['xelentwatch_user_id'],
                ],
            ];
        } catch (Throwable $e) {
            $this->xelentwatch->report($e);

            return $payload;
        }
    }
}
