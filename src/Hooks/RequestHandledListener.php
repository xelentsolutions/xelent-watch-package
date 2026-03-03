<?php

namespace Laravel\Xelentwatch\Hooks;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\ExecutionStage;
use Laravel\Xelentwatch\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class RequestHandledListener
{
    /**
     * @param  Core<RequestState>  $xelentwatch
     */
    public function __construct(
        private Core $xelentwatch,
    ) {
        //
    }

    public function __invoke(RequestHandled $event): void
    {
        try {
            $this->xelentwatch->stage(ExecutionStage::Sending);
        } catch (Throwable $e) {
            $this->xelentwatch->report($e, handled: true);
        }
    }
}
