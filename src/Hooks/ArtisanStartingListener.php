<?php

namespace Laravel\Xelentwatch\Hooks;

use Illuminate\Console\Events\ArtisanStarting;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\State\CommandState;
use Throwable;

/**
 * @internal
 */
final class ArtisanStartingListener
{
    /**
     * @param  Core<CommandState>  $xelentwatch
     */
    public function __construct(
        private Core $xelentwatch,
    ) {
        //
    }

    public function __invoke(ArtisanStarting $event): void
    {
        try {
            $this->xelentwatch->captureArtisan($event->artisan);
        } catch (Throwable $e) {
            $this->xelentwatch->report($e, handled: true);
        }
    }
}
