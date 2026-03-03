<?php

namespace Laravel\Xelentwatch\Hooks;

use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\State\CommandState;
use Laravel\Xelentwatch\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class CreateQueuePayloadHandler
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
        try {
            return [
                ...$payload,
                'xelentwatch' => [
                    ...($payload['xelentwatch'] ?? []),  // @phpstan-ignore arrayUnpacking.nonIterable
                    'job_id' => $this->xelentwatch->uuid->make(),
                ],
            ];
        } catch (Throwable $e) {
            $this->xelentwatch->report($e);

            return $payload;
        }
    }
}
