<?php

namespace Laravel\Xelentwatch\Hooks;

use Illuminate\Console\Events\ScheduledTaskStarting;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\State\CommandState;
use Throwable;

/**
 * @internal
 */
final class ScheduledTaskStartingListener
{
    /**
     * @param  Core<CommandState>  $xelentwatch
     */
    public function __construct(
        private Core $xelentwatch,
    ) {
        //
    }

    public function __invoke(ScheduledTaskStarting $event): void
    {
        try {
            $this->xelentwatch->prepareForNextScheduledTask($event->task);
        } catch (Throwable $e) {
            $this->xelentwatch->report($e, handled: true);
        }
    }
}
