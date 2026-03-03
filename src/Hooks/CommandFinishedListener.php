<?php

namespace Laravel\Xelentwatch\Hooks;

use Illuminate\Console\Events\CommandFinished;
use Laravel\Xelentwatch\Compatibility;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\ExecutionStage;
use Laravel\Xelentwatch\State\CommandState;
use Throwable;

/**
 * @internal
 */
final class CommandFinishedListener
{
    /**
     * @param  Core<CommandState>  $xelentwatch
     */
    public function __construct(
        private Core $xelentwatch,
    ) {
        //
    }

    public function __invoke(CommandFinished $event): void
    {
        try {
            if ($this->xelentwatch->capturingCommandNamed($event->command) && ! Compatibility::$terminatingEventExists) {
                $this->xelentwatch->stage(ExecutionStage::Terminating);
            }
        } catch (Throwable $e) {
            $this->xelentwatch->report($e, handled: true);
        }
    }
}
