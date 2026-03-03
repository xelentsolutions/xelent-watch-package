<?php

namespace Laravel\Xelentwatch\Hooks;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\ExecutionStage;
use Laravel\Xelentwatch\State\RequestState;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * @internal
 */
final class RequestLifecycleIsLongerThanHandler
{
    /**
     * @param  Core<RequestState>  $xelentwatch
     */
    public function __construct(
        private Core $xelentwatch,
    ) {
        //
    }

    public function __invoke(Carbon $startedAt, Request $request, Response $response): void
    {
        try {
            $this->xelentwatch->stage(ExecutionStage::End);
        } catch (Throwable $e) {
            $this->xelentwatch->report($e, handled: true);
        }

        try {
            $this->xelentwatch->captureUser();
        } catch (Throwable $e) {
            $this->xelentwatch->report($e, handled: true);
        }

        try {
            $this->xelentwatch->request($request, $response);
        } catch (Throwable $e) {
            $this->xelentwatch->report($e, handled: true);
        }

        $this->xelentwatch->finishExecution();
    }
}
