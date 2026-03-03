<?php

namespace Laravel\Xelentwatch\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\Facades\Xelentwatch;
use Laravel\Xelentwatch\State\RequestState;
use Throwable;

final class Sample
{
    /**
     * @param  Core<RequestState>  $xelentwatch
     */
    public function __construct(private Core $xelentwatch)
    {
        //
    }

    public static function rate(float $rate): string
    {
        $rate = (string) $rate;

        if ($rate === '0') {
            $rate = '0.0';
        }

        return self::class.':'.$rate;
    }

    public static function always(): string
    {
        return self::class.':1.0';
    }

    public static function never(): string
    {
        return self::class.':0.0';
    }

    public function handle(Request $request, Closure $next, float $rate): mixed
    {
        try {
            $this->xelentwatch->sample($rate);
        } catch (Throwable $e) {
            Xelentwatch::unrecoverableExceptionOccurred($e);
        }

        return $next($request);
    }
}
