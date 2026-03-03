<?php

namespace Laravel\Xelentwatch\Hooks;

use Carbon\Carbon;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\ExecutionStage;
use Laravel\Xelentwatch\State\CommandState;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;

/**
 * @internal
 */
final class CommandLifecycleIsLongerThanHandler
{
    /**
     * @param  Core<CommandState>  $xelentwatch
     */
    public function __construct(
        private Core $xelentwatch,
    ) {
        //
    }

    public function __invoke(Carbon $startedAt, InputInterface $input, int $status): void
    {
        try {
            $this->xelentwatch->stage(ExecutionStage::End);
        } catch (Throwable $e) {
            $this->xelentwatch->report($e, handled: true);
        }

        try {
            $this->xelentwatch->command($input, $status);
        } catch (Throwable $e) {
            $this->xelentwatch->report($e, handled: true);
        }

        $this->xelentwatch->finishExecution();
    }
}
