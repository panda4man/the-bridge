import request from 'supertest';
import { vi } from 'vitest';
import * as SettingsModel from '../../src/models/settings.js';

vi.mock('../../src/services/gitService.js', () => ({
  default: vi.fn().mockImplementation(() => ({ clone: vi.fn(), pull: vi.fn() })),
}));

test('GET /settings returns 200', async () => {
  const app = await import('../../src/app.js').then(m => m.default);
  const res = await request(app).get('/settings');
  expect(res.status).toBe(200);
  expect(res.text).toContain('Slack');
});

test('POST /settings saves slack_webhook_url and redirects', async () => {
  const app = await import('../../src/app.js').then(m => m.default);
  const res = await request(app)
    .post('/settings')
    .type('form')
    .send({ slack_webhook_url: 'https://hooks.slack.com/test123' });
  expect(res.status).toBe(302);
  expect(SettingsModel.get('slack_webhook_url')).toBe('https://hooks.slack.com/test123');
});
