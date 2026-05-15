<?php

namespace App\Enums;

enum AppStatus: string
{
    case Idle = 'idle';
    case Deploying = 'deploying';
    case Success = 'success';
    case Failed = 'failed';
}
