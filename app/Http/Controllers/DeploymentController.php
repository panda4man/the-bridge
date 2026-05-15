<?php

namespace App\Http\Controllers;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;

class DeploymentController extends Controller
{
    public function show(Deployment $deployment)
    {
        return view('deployments.show', compact('deployment'));
    }

    public function stream(Deployment $deployment)
    {
        $terminal = [DeploymentStatus::Success, DeploymentStatus::Failed];

        return response()->stream(function () use ($deployment, $terminal) {
            $offset = 0;

            while (true) {
                $deployment->refresh();
                $log = $deployment->log ?? '';
                $new = substr($log, $offset);

                if ($new !== '') {
                    echo 'data: ' . json_encode(['text' => $new]) . "\n\n";
                    ob_flush();
                    flush();
                    $offset = strlen($log);
                }

                if (in_array($deployment->status, $terminal)) {
                    echo 'data: ' . json_encode(['done' => true, 'status' => $deployment->status->value]) . "\n\n";
                    ob_flush();
                    flush();
                    break;
                }

                usleep(500000);
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
