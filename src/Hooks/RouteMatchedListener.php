<?php

namespace Laravel\Xelentwatch\Hooks;

use Illuminate\Routing\Events\RouteMatched;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class RouteMatchedListener
{
    /**
     * @param  Core<RequestState>  $xelentwatch
     */
    public function __construct(
        private Core $xelentwatch,
    ) {
        //
    }

    public function __invoke(RouteMatched $event): void
    {
        try {
            $this->xelentwatch->attachMiddlewareToRoute($event->route);
        } catch (Throwable $e) {
            $this->xelentwatch->report($e, handled: true);
        }
    }
}
