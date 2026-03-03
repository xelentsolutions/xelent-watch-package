<?php

namespace Laravel\Xelentwatch\Hooks;

use Illuminate\Log\Context\Repository;
use Laravel\Xelentwatch\Core;
use Laravel\Xelentwatch\State\CommandState;
use Laravel\Xelentwatch\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class ContextDehydratingHandler
{
    /**
     * @param  Core<RequestState|CommandState>  $xelentwatch
     */
    public function __construct(
        private Core $xelentwatch,
    ) {
        //
    }

    public function __invoke(Repository $context): void
    {
        try {
            if (($context->getHidden('xelentwatch_user_id') ?? '') === '') {
                $context->addHidden('xelentwatch_user_id', $this->xelentwatch->executionState->user->resolvedUserId());
            }
        } catch (Throwable $e) {
            $this->xelentwatch->report($e);
        }
    }
}
