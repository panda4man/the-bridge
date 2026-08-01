<?php

namespace App\Services;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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

    /**
     * Verbatim from reference/src/routes/settings.ts:29, em dash included.
     */
    public const TEST_MESSAGE = ':satellite: The Bridge test notification — connection OK.';

    /**
     * Send the Settings screen's "test notification".
     *
     * Unlike notify(), this one THROWS on failure — the operator pressed a
     * button whose entire purpose is to tell them whether the URL works, so
     * swallowing the error is the one thing it must not do. The reference
     * swallows it and then reports the failure through its success flash
     * channel (settings.ts:31-33), which the plan lists as a defect to fix
     * rather than carry forward; the caller turns this exception into a
     * danger notification.
     *
     * @throws RequestException|ConnectionException
     */
    public static function sendTest(string $url): void
    {
        Http::post($url, ['text' => self::TEST_MESSAGE])->throw();
    }

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

        // Shared with Phase 4's deployments table — see
        // Deployment::durationText(), which is where this formatting moved so
        // the two renderings cannot drift apart.
        $durationText = $deployment->durationText() ?? '';

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
