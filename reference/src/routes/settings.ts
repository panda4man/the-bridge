import { Router, Request, Response } from 'express';
import * as SettingsModel from '../models/settings.js';

const router = Router();

router.get('/settings', (req: Request, res: Response) => {
  const slackWebhookUrl = SettingsModel.get('slack_webhook_url') ?? '';
  res.render('settings/index', { slackWebhookUrl });
});

router.post('/settings', async (req: Request, res: Response) => {
  const { slack_webhook_url, test_slack } = req.body as {
    slack_webhook_url?: string;
    test_slack?: string;
  };

  if (slack_webhook_url !== undefined) {
    const url = slack_webhook_url.trim();
    SettingsModel.set('slack_webhook_url', url);
  }

  if (test_slack) {
    const url = SettingsModel.get('slack_webhook_url');
    if (url) {
      try {
        await fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ text: ':satellite: The Bridge test notification — connection OK.' }),
        });
        req.flash('success', 'Test notification sent to Slack.');
      } catch {
        req.flash('success', 'Failed to reach Slack webhook URL.');
      }
    }
  } else {
    req.flash('success', 'Settings saved.');
  }

  res.redirect('/settings');
});

export default router;
