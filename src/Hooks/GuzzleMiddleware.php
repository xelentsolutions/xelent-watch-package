<?php

namespace Laravel\Xelentwatch\Hooks;

use GuzzleHttp\Promise\PromiseInterface;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\State\CommandState;
use Laravel\Xelentwatch\State\RequestState;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * @internal
 */
final class GuzzleMiddleware
{
    /**
     * @param  Core<RequestState|CommandState>  $xelentwatch
     */
    public function __construct(
        private Core $xelentwatch,
    ) {
        //
    }

    /**
     * TODO record the failed responses as well.
     */
    public function __invoke(callable $handler): callable
    {
        if ($this->xelentwatch->config['filtering']['ignore_outgoing_requests'] || $this->xelentwatch->paused()) {
            return $handler;
        }

        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            try {
                $startMicrotime = $this->xelentwatch->clock->microtime();
            } catch (Throwable $e) {
                $this->xelentwatch->report($e, handled: true);

                return $handler($request, $options);
            }

            return $handler($request, $options)->then(function (ResponseInterface $response) use ($request, $startMicrotime): ResponseInterface {
                try {
                    $endMicrotime = $this->xelentwatch->clock->microtime();

                    $this->xelentwatch->outgoingRequest(
                        $startMicrotime, $endMicrotime,
                        $request, $response,
                    );
                } catch (Throwable $e) {
                    $this->xelentwatch->report($e, handled: true);
                }

                return $response;
            });
        };
    }
}
