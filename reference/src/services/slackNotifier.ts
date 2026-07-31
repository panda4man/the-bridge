import * as SettingsModel from '../models/settings.js';
import type { DeploymentRecord } from '../types.js';

export async function notifyDeployment(dep: DeploymentRecord): Promise<void> {
  const url = SettingsModel.get('slack_webhook_url');
  if (!url) return;

  const isSuccess = dep.status === 'success';
  const emoji = isSuccess ? ':white_check_mark:' : ':x:';
  const color = isSuccess ? '#2eb886' : '#cc0000';

  let durationText = '';
  if (dep.started_at && dep.finished_at) {
    const secs = Math.round(
      (new Date(dep.finished_at).getTime() - new Date(dep.started_at).getTime()) / 1000
    );
    durationText = secs >= 60
      ? `${Math.floor(secs / 60)}m ${secs % 60}s`
      : `${secs}s`;
  }

  const logTail = dep.log
    ? dep.log.split('\n').slice(-20).join('\n').trim()
    : '';

  const blocks: unknown[] = [
    {
      type: 'section',
      text: {
        type: 'mrkdwn',
        text: `${emoji} *${dep.app_name ?? 'App'}* deployment *${dep.status}*${durationText ? ` (${durationText})` : ''}`,
      },
    },
  ];

  if (!isSuccess && logTail) {
    blocks.push({
      type: 'section',
      text: { type: 'mrkdwn', text: `\`\`\`${logTail}\`\`\`` },
    });
  }

  const baseUrl = process.env.APP_URL ?? 'http://localhost:3000';
  blocks.push({
    type: 'context',
    elements: [{ type: 'mrkdwn', text: `<${baseUrl}/deployments/${dep.id}|View deployment log>` }],
  });

  await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ attachments: [{ color, blocks }] }),
  });
}
