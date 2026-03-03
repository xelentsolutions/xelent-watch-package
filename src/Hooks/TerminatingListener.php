<?php

namespace Laravel\Xelentwatch\Hooks;

use Illuminate\Foundation\Events\Terminating;
use Laravel\Xelentwatch\Compatibility;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\ExecutionStage;
use Laravel\Xelentwatch\State\CommandState;
use Laravel\Xelentwatch\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class TerminatingListener
{
    /**
     * @param  Core<RequestState|CommandState>  $xelentwatch
     */
    public function __construct(
        private Core $xelentwatch,
    ) {
        //
    }

    public function __invoke(Terminating $event): void
    {
        if (! Compatibility::$terminatingEventExists) {
            return;
        }

        try {
            $this->xelentwatch->stage(ExecutionStage::Terminating);
        } catch (Throwable $e) {
            $this->xelentwatch->report($e, handled: true);
        }
    }
}
