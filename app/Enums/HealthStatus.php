<?php

namespace App\Enums;

/**
 * Ported from reference/src/enums.ts HealthStatus.
 */
enum HealthStatus: string
{
    case Up = 'up';
    case Down = 'down';
    case Unknown = 'unknown';
}
