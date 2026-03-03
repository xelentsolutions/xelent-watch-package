<?php

namespace Laravel\Xelentwatch\Hooks;

use Closure;
use Illuminate\Http\Request;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\ExecutionStage;
use Laravel\Xelentwatch\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class RouteMiddleware
{
    /**
     * @param  Core<RequestState>  $xelentwatch
     */
    public function __construct(
        private Core $xelentwatch,
    ) {
        //
    }

    public function handle(Request $request, Closure $next): mixed
    {
        try {
            $this->xelentwatch->stage(ExecutionStage::Action);
        } catch (Throwable $e) {
            $this->xelentwatch->report($e, handled: true);
        }

        $response = $next($request);

        // If an exception occurs in the action phase, the usual
        // ResponsePrepared event is not fired. This fallback
        // ensures that we go to the AfterMiddleware stage.
        try {
            $this->xelentwatch->stage(ExecutionStage::AfterMiddleware);
        } catch (Throwable $e) {
            $this->xelentwatch->report($e, handled: true);
        }

        return $response;
    }
}
