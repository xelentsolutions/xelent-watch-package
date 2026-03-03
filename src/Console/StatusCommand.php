<?php

namespace Laravel\Xelentwatch\Console;

use Illuminate\Console\Command;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\State\CommandState;
use Laravel\Xelentwatch\State\RequestState;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'xelentwatch:status', description: 'Get the current status of the Xelentwatch agent.')]
final class StatusCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'xelentwatch:status';

    /**
     * @var string
     */
    protected $description = 'Get the current status of the Xelentwatch agent.';

    /**
     * @param  Core<RequestState|CommandState>  $xelentwatch
     */
    public function handle(Core $xelentwatch): int
    {
        if (! $xelentwatch->enabled()) {
            $this->components->error('Xelentwatch is disabled');

            return 1;
        }

        try {
            $xelentwatch->ingest->ping();

            $this->components->info('The Xelentwatch agent is running and accepting connections');

            return 0;
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return 1;
        }
    }
}
