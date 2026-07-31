<?php

namespace App\Services;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * Ported from reference/src/services/slackNotifier.ts's notifyDeployment().
 *
 * The reference reads `deployment.app_name`, flattened onto the row by a SQL
 * JOIN (reference/src/models/deployment.ts:31-33). This port uses a
 * `belongsTo` relation instead, so there is no bare `app_name` attribute —
 * the app name comes from `$deployment->app?->name`, which either uses an
 * already-eager-loaded relation or lazy-loads it. Callers notifying many
 * deployments in a batch should `->with('app')` first to avoid N+1s; a
 * single post-deploy notification (the only real caller) does not need to.
 */
final class SlackNotifier
{
    private const LOG_TAIL_LINES = 20;

    public static function notify(Deployment $deployment): void
    {
        $url = Setting::getValue('slack_webhook_url');
        if (! $url) {
            return;
        }

        $appName = $deployment->app?->name ?? 'App';
        $isSuccess = $deployment->status === DeploymentStatus::Success;
        $emoji = $isSuccess ? ':white_check_mark:' : ':x:';
        $color = $isSuccess ? '#2eb886' : '#cc0000';
        $statusValue = $deployment->status instanceof DeploymentStatus
            ? $deployment->status->value
            : (string) $deployment->status;

        $durationText = '';
        if ($deployment->started_at && $deployment->finished_at) {
            $secs = (int) round($deployment->finished_at->getTimestamp() - $deployment->started_at->getTimestamp());
            $durationText = $secs >= 60
                ? sprintf('%dm %ds', intdiv($secs, 60), $secs % 60)
                : "{$secs}s";
        }

        $logTail = $deployment->log
            ? trim(implode("\n", array_slice(explode("\n", $deployment->log), -self::LOG_TAIL_LINES)))
            : '';

        $blocks = [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "{$emoji} *{$appName}* deployment *{$statusValue}*".($durationText !== '' ? " ({$durationText})" : ''),
                ],
            ],
        ];

        // Last 20 log lines on failure only.
        if (! $isSuccess && $logTail !== '') {
            $blocks[] = [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => "```{$logTail}```"],
            ];
        }

        $baseUrl = config('app.url') ?: 'http://localhost:3000';
        $blocks[] = [
            'type' => 'context',
            'elements' => [
                ['type' => 'mrkdwn', 'text' => "<{$baseUrl}/deployments/{$deployment->id}|View deployment log>"],
            ],
        ];

        Http::post($url, [
            'attachments' => [
                ['color' => $color, 'blocks' => $blocks],
            ],
        ]);
    }
}
