<?php

namespace Laravel\Xelentwatch\Hooks;

use Illuminate\Contracts\Foundation\Application;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\ExecutionStage;
use Laravel\Xelentwatch\State\CommandState;
use Throwable;

/**
 * @internal
 */
final class CommandBootedHandler
{
    /**
     * @param  Core<CommandState>  $xelentwatch
     */
    public function __construct(
        private Core $xelentwatch,
    ) {
        //
    }

    public function __invoke(Application $app): void
    {
        try {
            $this->xelentwatch->stage(ExecutionStage::Action);
        } catch (Throwable $e) {
            $this->xelentwatch->report($e, handled: true);
        }
    }
}
