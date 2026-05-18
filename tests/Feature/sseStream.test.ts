import request from 'supertest';
import * as AppModel from '../../src/models/app.js';
import * as DeploymentModel from '../../src/models/deployment.js';
import { DeploymentStatus } from '../../src/enums.js';

async function getApp() {
  const { default: app } = await import('../../src/app.js');
  return app;
}

test('GET /deployments/:id/stream returns text/event-stream content type', async () => {
  const app = await getApp();
  const rec = AppModel.create({ name: 'SSEApp', repo_url: 'r', branch: 'main', path: '/sse' });
  const dep = DeploymentModel.create({ app_id: rec.id, status: DeploymentStatus.Success, log: 'line1\n' });

  const res = await request(app).get(`/deployments/${dep.id}/stream`);
  expect(res.status).toBe(200);
  expect(res.headers['content-type']).toContain('text/event-stream');
});

test('GET /deployments/:id/stream emits done event for terminal deployment', async () => {
  const app = await getApp();
  const rec = AppModel.create({ name: 'SSEApp2', repo_url: 'r', branch: 'main', path: '/sse2' });
  const dep = DeploymentModel.create({ app_id: rec.id, status: DeploymentStatus.Success, log: 'build output\n' });

  const res = await request(app).get(`/deployments/${dep.id}/stream`);
  expect(res.text).toContain('"done":true');
  expect(res.text).toContain('build output');
});
