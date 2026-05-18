import request from 'supertest';
import { vi } from 'vitest';
import * as AppModel from '../../src/models/app.js';
import { getDb } from '../../src/db.js';

vi.mock('../../src/jobs/deployApp.js', () => ({
  deployApp: vi.fn().mockResolvedValue(undefined),
}));

test('POST /apps/:id/deploy creates pending deployment and queues job', async () => {
  const { default: app } = await import('../../src/app.js');

  const rec = AppModel.create({ name: 'DeployApp', repo_url: 'r', branch: 'main', path: '/deploy' });

  const res = await request(app).post(`/apps/${rec.id}/deploy`);
  expect(res.status).toBe(302);

  const dep = getDb().prepare("SELECT * FROM deployments WHERE app_id = ? AND status = 'pending'").get(rec.id);
  expect(dep).toBeTruthy();
});
