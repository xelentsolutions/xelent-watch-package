<?php

namespace Laravel\Xelentwatch\Hooks;

use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\State\CommandState;
use Laravel\Xelentwatch\State\RequestState;
use Throwable;

use function str_repeat;

/**
 * @internal
 */
final class ReportableHandler
{
    public ?string $reservedMemory;

    /**
     * @param  Core<RequestState|CommandState>  $xelentwatch
     */
    public function __construct(
        private Core $xelentwatch,
    ) {
        $this->reservedMemory = str_repeat('n', 32768);
    }

    public function __invoke(Throwable $e): void
    {
        if (HandleExceptions::$reservedMemory === null) {
            $this->reservedMemory = null;
        }

        if ($this->xelentwatch->executionState->source === 'schedule') {
            return;
        }

        $this->xelentwatch->report($e);
    }
}
