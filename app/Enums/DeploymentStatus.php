<?php

namespace App\Enums;

/**
 * Ported from reference/src/enums.ts DeploymentStatus.
 */
enum DeploymentStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
}
