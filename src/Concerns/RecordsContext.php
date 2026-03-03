<?php

namespace Laravel\Xelentwatch\Concerns;

use Illuminate\Support\Facades\Context;
use Laravel\Xelentwatch\Compatibility;
use Laravel\Xelentwatch\Facades\Xelentwatch;
use Laravel\Xelentwatch\Types\Str;
use Throwable;

use function json_encode;

/**
 * @internal
 */
trait RecordsContext
{
    private function serializedContext(): string
    {
        if (! Compatibility::$contextExists) {
            return '';
        }

        try {
            return Str::text(json_encode((object) Context::all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        } catch (Throwable $e) {
            Xelentwatch::unrecoverableExceptionOccurred($e);

            return '{"_xelentwatch_error":"Failed to serialize context"}';
        }
    }
}
