<?php

namespace Laravel\Xelentwatch\Hooks;

use Closure;
use Illuminate\Http\Request;
use Laravel\Xelentwatch\Compatibility;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\ExecutionStage;
use Laravel\Xelentwatch\Facades\Xelentwatch;
use Laravel\Xelentwatch\State\RequestState;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * @internal
 */
final class GlobalMiddleware
{
    private bool $hasHandledRequest = false;

    private bool $hasTerminated = false;

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
        if ($this->hasHandledRequest) {
            return $next($request);
        }

        $this->hasHandledRequest = true;

        try {
            $this->xelentwatch->configureRequestSampling();
        } catch (Throwable $e) {
            Xelentwatch::unrecoverableExceptionOccurred($e);
        }

        try {
            $this->xelentwatch->captureRequestPreview($request);
        } catch (Throwable $e) {
            $this->xelentwatch->report($e, handled: true);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($this->hasTerminated || Compatibility::$terminatingEventExists) {
            return;
        }

        $this->hasTerminated = true;

        try {
            $this->xelentwatch->stage(ExecutionStage::Terminating);
        } catch (Throwable $e) {
            $this->xelentwatch->report($e, handled: true);
        }
    }
}
