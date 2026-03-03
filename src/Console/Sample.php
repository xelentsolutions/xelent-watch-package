<?php

namespace Laravel\Xelentwatch\Console;

use Closure;
use Illuminate\Console\Scheduling\Event;
use Laravel\Xelentwatch\Core;

use function app;

final class Sample
{
    public static function rate(float $rate): Closure
    {
        return static fn (Event $event) => app(Core::class)->sampleScheduledTask($event, $rate);
    }

    public static function always(): Closure
    {
        return self::rate(1.0);
    }

    public static function never(): Closure
    {
        return self::rate(0.0);
    }
}
