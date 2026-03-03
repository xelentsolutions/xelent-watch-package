<?php

namespace Laravel\Xelentwatch\Hooks;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Events\Terminating;
use Illuminate\Foundation\Http\Kernel;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\Facades\Xelentwatch;
use Laravel\Xelentwatch\Http\Middleware\Sample;
use Laravel\Xelentwatch\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class HttpKernelResolvedHandler
{
    /**
     * @param  Core<RequestState>  $xelentwatch
     */
    public function __construct(
        private Core $xelentwatch,
    ) {
        //
    }

    public function __invoke(KernelContract $kernel, Application $app): void
    {
        if (! $kernel instanceof Kernel) {
            return;
        }

        try {
            /**
             * @see \Laravel\Xelentwatch\ExecutionStage::End
             * @see \Laravel\Xelentwatch\Records\Request
             * @see \Laravel\Xelentwatch\Core::finishExecution()
             */
            $kernel->whenRequestLifecycleIsLongerThan(-1, new RequestLifecycleIsLongerThanHandler($this->xelentwatch));
        } catch (Throwable $e) {
            Xelentwatch::unrecoverableExceptionOccurred($e);
        }

        try {
            /**
             * @see \Laravel\Xelentwatch\ExecutionStage::Terminating
             */
            $kernel->prependMiddleware(GlobalMiddleware::class);
        } catch (Throwable $e) {
            $this->xelentwatch->report($e, handled: true);
        }

        try {
            $kernel->prependToMiddlewarePriority(Sample::class);
        } catch (Throwable $e) {
            $this->xelentwatch->report($e);
        }
    }
}
