<?php

namespace Laravel\Xelentwatch;

enum QueryConnectionType: string
{
    case Read = 'read';
    case Write = 'write';
    case Unknown = 'unknown';
}
