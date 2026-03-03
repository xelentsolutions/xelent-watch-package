<?php

namespace Laravel\Xelentwatch\Hooks;

use Illuminate\Http\Client\Factory;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\State\CommandState;
use Laravel\Xelentwatch\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class HttpClientFactoryResolvedHandler
{
    /**
     * @param  Core<RequestState|CommandState>  $xelentwatch
     */
    public function __construct(
        private Core $xelentwatch,
    ) {
        //
    }

    public function __invoke(Factory $factory): void
    {
        try {
            /**
             * @see \Laravel\Xelentwatch\Records\OutgoingRequest
             */
            $factory->globalMiddleware($this->xelentwatch->guzzleMiddleware());
        } catch (Throwable $e) {
            $this->xelentwatch->report($e, handled: true);
        }
    }
}
