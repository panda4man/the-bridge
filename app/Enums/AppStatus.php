<?php

namespace App\Enums;

/**
 * Ported from reference/src/enums.ts AppStatus.
 */
enum AppStatus: string
{
    case Idle = 'idle';
    case Deploying = 'deploying';
    case Success = 'success';
    case Failed = 'failed';
}
