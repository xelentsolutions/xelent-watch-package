<?php

namespace Laravel\Xelentwatch\Hooks;

use Illuminate\Console\Events\CommandFinished;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\State\CommandState;

/**
 * @internal
 */
final class VaporWorkCommandFinishedListener
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
        $this->xelentwatch->finishExecution();
    }
}
