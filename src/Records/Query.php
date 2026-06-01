<?php

namespace Laravel\Xelentwatch\Records;

use Laravel\Xelentwatch\QueryConnectionType;

final class Query
{
    public function __construct(
        public string $sql,
        public readonly string $file,
        public readonly int $line,
        public readonly int $duration,
        public readonly string $connection,
        public readonly QueryConnectionType $connectionType,
        public readonly bool $has_explain = false,
        public readonly string $explain_output = '',
    ) {
        //
    }
}
