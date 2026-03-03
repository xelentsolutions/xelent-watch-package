<?php

namespace Laravel\Xelentwatch\Hooks;

use Illuminate\Routing\Events\ResponsePrepared;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\ExecutionStage;
use Laravel\Xelentwatch\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class ResponsePreparedListener
{
    /**
     * @param  Core<RequestState>  $xelentwatch
     */
    public function __construct(
        private Core $xelentwatch,
    ) {
        //
    }

    public function __invoke(ResponsePrepared $event): void
    {
        try {
            if ($this->xelentwatch->executionStageIs(ExecutionStage::Render)) {
                $this->xelentwatch->stage(ExecutionStage::AfterMiddleware);
            }
        } catch (Throwable $e) {
            $this->xelentwatch->report($e, handled: true);
        }
    }
}
