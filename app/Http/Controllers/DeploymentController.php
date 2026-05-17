<?php

namespace App\Http\Controllers;

use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use Illuminate\Http\Request;

class DeploymentController extends Controller
{
    public function reset(Deployment $deployment)
    {
        if (in_array($deployment->status, [DeploymentStatus::Running, DeploymentStatus::Pending], strict: true)) {
            $deployment->update(['status' => DeploymentStatus::Failed, 'finished_at' => now()]);
            $deployment->app->update(['status' => AppStatus::Failed]);
        }

        return redirect()->route('deployments.show', $deployment)->with('success', 'Deployment reset to failed.');
    }

    public function show(Deployment $deployment)
    {
        return view('deployments.show', compact('deployment'));
    }

    public function stream(Request $request, Deployment $deployment)
    {
        $terminal      = [DeploymentStatus::Success, DeploymentStatus::Failed];
        $initialOffset = (int) $request->header('Last-Event-ID', 0);

        return response()->stream(function () use ($deployment, $terminal, $initialOffset) {
            ob_implicit_flush(true);
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            ignore_user_abort(true);
            set_time_limit(0);

            $offset        = $initialOffset;
            $lastHeartbeat = time();

            try {
                while (true) {
                    if (connection_aborted()) {
                        break;
                    }

                    $deployment->refresh();
                    $log = $deployment->log ?? '';
                    $new = substr($log, $offset);

                    if ($new !== '') {
                        $offset = strlen($log);
                        echo "id: {$offset}\n";
                        echo 'data: ' . json_encode(['text' => $new]) . "\n\n";
                        ob_flush();
                        flush();
                        $lastHeartbeat = time();
                    }

                    if (in_array($deployment->status, $terminal, strict: true)) {
                        echo 'data: ' . json_encode(['done' => true, 'status' => $deployment->status->value]) . "\n\n";
                        ob_flush();
                        flush();
                        break;
                    }

                    if (time() - $lastHeartbeat >= 5) {
                        echo ": heartbeat\n\n";
                        ob_flush();
                        flush();
                        $lastHeartbeat = time();
                    }

                    usleep(500000);
                }
            } catch (\Throwable) {
                // Stream already started — swallow exception to avoid "headers already sent" error
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
