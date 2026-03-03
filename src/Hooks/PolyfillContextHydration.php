<?php

namespace Laravel\Xelentwatch\Hooks;

use Illuminate\Queue\Events\JobProcessing;
use Laravel\Xelentwatch\Compatibility;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\State\CommandState;
use Laravel\Xelentwatch\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class PolyfillContextHydration
{
    /**
     * @param  Core<RequestState|CommandState>  $xelentwatch
     */
    public function __construct(
        private Core $xelentwatch,
    ) {
        //
    }

    public function __invoke(JobProcessing $event): void
    {
        try {
            $xelentwatch = $event->job->payload()['xelentwatch'] ?? [];

            Compatibility::$context = [
                'xelentwatch_trace_id' => $xelentwatch['xelentwatch_trace_id'] ?? null,
                'xelentwatch_should_sample' => $xelentwatch['xelentwatch_should_sample'] ?? null,
                'xelentwatch_user_id' => $xelentwatch['xelentwatch_user_id'] ?? '',
            ];
        } catch (Throwable $e) {
            $this->xelentwatch->report($e);

            Compatibility::$context = [];
        }
    }
}
