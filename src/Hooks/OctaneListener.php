<?php

namespace Laravel\Xelentwatch\Hooks;

use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\State\CommandState;
use Laravel\Xelentwatch\State\RequestState;
use Laravel\Octane\Events\RequestReceived;
use Throwable;

/**
 * @internal
 */
final class OctaneListener
{
    /**
     * @param  Core<RequestState|CommandState>  $xelentwatch
     */
    public function __construct(private Core $xelentwatch)
    {
        //
    }

    public function __invoke(RequestReceived $event): void // @phpstan-ignore class.notFound
    {
        try {
            $this->xelentwatch->prepareForNextRequest();
        } catch (Throwable $e) {
            $this->xelentwatch->report($e);
        }
    }
}
