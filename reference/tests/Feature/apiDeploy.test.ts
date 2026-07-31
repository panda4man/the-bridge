import request from 'supertest';
import { vi, afterEach } from 'vitest';
import * as AppModel from '../../src/models/app.js';
import * as DeploymentModel from '../../src/models/deployment.js';
import { getDb } from '../../src/db.js';
import { DeploymentStatus } from '../../src/enums.js';

vi.mock('../../src/jobs/deployApp.js', () => ({
  deployApp: vi.fn().mockResolvedValue(undefined),
}));

const TOKEN = 'test-bridge-token';

afterEach(() => {
  delete process.env.BRIDGE_API_TOKEN;
});

async function loadApp() {
  const { default: app } = await import('../../src/app.js');
  return app;
}

function makeApp() {
  return AppModel.create({ name: 'ApiApp', repo_url: 'r', branch: 'main', path: '/api-app' });
}

test('rejects requests with no Authorization header', async () => {
  process.env.BRIDGE_API_TOKEN = TOKEN;
  const app = await loadApp();
  const rec = makeApp();

  const res = await request(app).post(`/api/apps/${rec.id}/deploy`);
  expect(res.status).toBe(401);
});

test('rejects requests with the wrong token', async () => {
  process.env.BRIDGE_API_TOKEN = TOKEN;
  const app = await loadApp();
  const rec = makeApp();

  const res = await request(app)
    .post(`/api/apps/${rec.id}/deploy`)
    .set('Authorization', 'Bearer nope');
  expect(res.status).toBe(401);
});

test('returns 503 when no token is configured', async () => {
  delete process.env.BRIDGE_API_TOKEN;
  const app = await loadApp();

  const res = await request(app)
    .get('/api/apps')
    .set('Authorization', 'Bearer anything');
  expect(res.status).toBe(503);
});

test('POST /api/apps/:id/deploy creates a pending deployment and returns 202', async () => {
  process.env.BRIDGE_API_TOKEN = TOKEN;
  const app = await loadApp();
  const rec = makeApp();

  const res = await request(app)
    .post(`/api/apps/${rec.id}/deploy`)
    .set('Authorization', `Bearer ${TOKEN}`);

  expect(res.status).toBe(202);
  expect(res.body.deployment_id).toBeGreaterThan(0);
  expect(res.body.app_id).toBe(rec.id);
  expect(res.body.status).toBe(DeploymentStatus.Pending);

  const dep = getDb()
    .prepare("SELECT * FROM deployments WHERE app_id = ? AND status = 'pending'")
    .get(rec.id);
  expect(dep).toBeTruthy();
});

test('POST /api/apps/:id/deploy returns 404 for unknown app', async () => {
  process.env.BRIDGE_API_TOKEN = TOKEN;
  const app = await loadApp();

  const res = await request(app)
    .post('/api/apps/9999/deploy')
    .set('Authorization', `Bearer ${TOKEN}`);
  expect(res.status).toBe(404);
});

test('GET /api/apps lists apps', async () => {
  process.env.BRIDGE_API_TOKEN = TOKEN;
  const app = await loadApp();
  const rec = makeApp();

  const res = await request(app)
    .get('/api/apps')
    .set('Authorization', `Bearer ${TOKEN}`);

  expect(res.status).toBe(200);
  expect(Array.isArray(res.body)).toBe(true);
  expect(res.body.find((a: { id: number }) => a.id === rec.id)).toMatchObject({
    name: 'ApiApp',
    branch: 'main',
  });
});

test('GET /api/deployments/:id returns status JSON with log_length', async () => {
  process.env.BRIDGE_API_TOKEN = TOKEN;
  const app = await loadApp();
  const rec = makeApp();
  const dep = DeploymentModel.create({ app_id: rec.id, status: DeploymentStatus.Running });
  DeploymentModel.appendLog(dep.id, 'hello world');

  const res = await request(app)
    .get(`/api/deployments/${dep.id}`)
    .set('Authorization', `Bearer ${TOKEN}`);

  expect(res.status).toBe(200);
  expect(res.body.id).toBe(dep.id);
  expect(res.body.app_id).toBe(rec.id);
  expect(res.body.status).toBe(DeploymentStatus.Running);
  expect(res.body.log_length).toBe('hello world'.length);
});

test('GET /api/deployments/:id/log returns slice from offset with poll headers', async () => {
  process.env.BRIDGE_API_TOKEN = TOKEN;
  const app = await loadApp();
  const rec = makeApp();
  const dep = DeploymentModel.create({ app_id: rec.id, status: DeploymentStatus.Success });
  DeploymentModel.appendLog(dep.id, 'line one\nline two\n');

  const full = await request(app)
    .get(`/api/deployments/${dep.id}/log`)
    .set('Authorization', `Bearer ${TOKEN}`);

  expect(full.status).toBe(200);
  expect(full.text).toBe('line one\nline two\n');
  expect(full.headers['x-log-offset']).toBe(String('line one\nline two\n'.length));
  expect(full.headers['x-deploy-status']).toBe(DeploymentStatus.Success);
  expect(full.headers['x-deploy-done']).toBe('true');

  const tail = await request(app)
    .get(`/api/deployments/${dep.id}/log?offset=9`)
    .set('Authorization', `Bearer ${TOKEN}`);
  expect(tail.text).toBe('line two\n');
});

test('GET /api/deployments/:id returns 404 for unknown deployment', async () => {
  process.env.BRIDGE_API_TOKEN = TOKEN;
  const app = await loadApp();

  const res = await request(app)
    .get('/api/deployments/9999')
    .set('Authorization', `Bearer ${TOKEN}`);
  expect(res.status).toBe(404);
});
