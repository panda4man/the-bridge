import { vi } from 'vitest';
import * as SettingsModel from '../../src/models/settings.js';

global.fetch = vi.fn();

test('notifyDeployment does nothing when no slack_webhook_url set', async () => {
  (global.fetch as ReturnType<typeof vi.fn>).mockClear();
  const { notifyDeployment } = await import('../../src/services/slackNotifier.js');
  await notifyDeployment({ id: 1, app_id: 1, status: 'success', log: null, started_at: null, finished_at: null, commit_sha: null, commit_message: null, rollback_sha: null, created_at: '', updated_at: '', app_name: 'Test' } as any);
  expect(global.fetch).not.toHaveBeenCalled();
});

test('notifyDeployment POSTs to Slack when url is configured', async () => {
  (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce({ ok: true });
  SettingsModel.set('slack_webhook_url', 'https://hooks.slack.com/test');
  const { notifyDeployment } = await import('../../src/services/slackNotifier.js');
  await notifyDeployment({ id: 2, app_id: 1, status: 'failed', log: 'error line\n', started_at: '2026-01-01T00:00:00Z', finished_at: '2026-01-01T00:01:00Z', commit_sha: null, commit_message: null, rollback_sha: null, created_at: '', updated_at: '', app_name: 'MyApp' } as any);
  expect(global.fetch).toHaveBeenCalledWith('https://hooks.slack.com/test', expect.objectContaining({ method: 'POST' }));
});
