<?php

namespace Laravel\Xelentwatch\Hooks;

use Illuminate\Routing\Events\PreparingResponse;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\ExecutionStage;
use Laravel\Xelentwatch\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class PreparingResponseListener
{
    /**
     * @param  Core<RequestState>  $xelentwatch
     */
    public function __construct(
        private Core $xelentwatch,
    ) {
        //
    }

    public function __invoke(PreparingResponse $event): void
    {
        try {
            if ($this->xelentwatch->executionStageIs(ExecutionStage::Action)) {
                $this->xelentwatch->stage(ExecutionStage::Render);
            }
        } catch (Throwable $e) {
            $this->xelentwatch->report($e, handled: true);
        }
    }
}
