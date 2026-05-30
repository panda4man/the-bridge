import request from 'supertest';
import { createHmac, randomBytes } from 'crypto';
import { vi } from 'vitest';
import * as AppModel from '../../src/models/app.js';
import { getDb } from '../../src/db.js';

vi.mock('../../src/services/gitService.js', () => ({
  default: vi.fn().mockImplementation(() => ({
    clone: vi.fn().mockResolvedValue(''),
    pull: vi.fn().mockResolvedValue(''),
  })),
}));

vi.mock('../../src/queue.js', () => ({
  enqueueDeployJob: vi.fn(),
}));

function signPayload(secret: string, body: string): string {
  return 'sha256=' + createHmac('sha256', secret).update(body).digest('hex');
}

test('POST /apps/:id/webhook with valid signature queues deploy and returns 204', async () => {
  const app = await import('../../src/app.js').then(m => m.default);
  const secret = randomBytes(20).toString('hex');
  const rec = AppModel.create({ name: 'WH', repo_url: 'r', branch: 'main', path: '/wh' });
  getDb().prepare('UPDATE apps SET webhook_secret = ? WHERE id = ?').run(secret, rec.id);

  const body = JSON.stringify({ ref: 'refs/heads/main' });
  const sig = signPayload(secret, body);

  const res = await request(app)
    .post(`/apps/${rec.id}/webhook`)
    .set('Content-Type', 'application/json')
    .set('X-Hub-Signature-256', sig)
    .send(body);

  expect(res.status).toBe(204);
});

test('POST /apps/:id/webhook with invalid signature returns 401', async () => {
  const app = await import('../../src/app.js').then(m => m.default);
  const secret = randomBytes(20).toString('hex');
  const rec = AppModel.create({ name: 'WH2', repo_url: 'r', branch: 'main', path: '/wh2' });
  getDb().prepare('UPDATE apps SET webhook_secret = ? WHERE id = ?').run(secret, rec.id);

  const res = await request(app)
    .post(`/apps/${rec.id}/webhook`)
    .set('Content-Type', 'application/json')
    .set('X-Hub-Signature-256', 'sha256=badsignature')
    .send(JSON.stringify({ ref: 'refs/heads/main' }));

  expect(res.status).toBe(401);
});

test('POST /apps/:id/webhook with branch mismatch returns 200 no-op', async () => {
  const app = await import('../../src/app.js').then(m => m.default);
  const secret = randomBytes(20).toString('hex');
  const rec = AppModel.create({ name: 'WH3', repo_url: 'r', branch: 'main', path: '/wh3' });
  getDb().prepare('UPDATE apps SET webhook_secret = ? WHERE id = ?').run(secret, rec.id);

  const body = JSON.stringify({ ref: 'refs/heads/other-branch' });
  const sig = signPayload(secret, body);

  const res = await request(app)
    .post(`/apps/${rec.id}/webhook`)
    .set('Content-Type', 'application/json')
    .set('X-Hub-Signature-256', sig)
    .send(body);

  expect(res.status).toBe(200);
  expect(res.body.skipped).toBe(true);
});

test('POST /apps/:id/webhook returns 400 when no secret configured', async () => {
  const app = await import('../../src/app.js').then(m => m.default);
  const rec = AppModel.create({ name: 'WH4', repo_url: 'r', branch: 'main', path: '/wh4' });

  const res = await request(app)
    .post(`/apps/${rec.id}/webhook`)
    .set('Content-Type', 'application/json')
    .send(JSON.stringify({ ref: 'refs/heads/main' }));

  expect(res.status).toBe(400);
});
