<?php

namespace Laravel\Xelentwatch\Hooks;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Queue\Events\JobPopping;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\State\CommandState;
use Throwable;

/**
 * @internal
 */
final class WorkerLifecycleListener
{
    /**
     * @param  Core<CommandState>  $xelentwatch
     */
    public function __construct(
        private Core $xelentwatch,
    ) {
        //
    }

    public function __invoke(Looping|JobPopping|JobProcessing|WorkerStopping|CommandFinished $event): void
    {
        try {
            match ($event::class) {
                Looping::class, WorkerStopping::class => $this->xelentwatch->finishExecution()->waitForExecution(),
                CommandFinished::class => $event->command === 'queue:work' && $this->xelentwatch->finishExecution()->waitForExecution(),
                JobPopping::class => $this->xelentwatch->prepareForNextJob(),
                JobProcessing::class => $this->xelentwatch->prepareForJob($event->job),
            };
        } catch (Throwable $e) {
            $this->xelentwatch->report($e, handled: true);
        }
    }
}
