<?php

namespace Laravel\Xelentwatch\Hooks;

use Illuminate\Auth\Events\Logout;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class LogoutListener
{
    /**
     * @param  Core<RequestState>  $xelentwatch
     */
    public function __construct(
        private Core $xelentwatch,
    ) {
        //
    }

    public function __invoke(Logout $event): void
    {
        try {
            if ($event->user !== null) {
                $this->xelentwatch->remember($event->user);
            }
        } catch (Throwable $e) {
            $this->xelentwatch->report($e, handled: true);
        }
    }
}
