import request from 'supertest';
import { vi } from 'vitest';
import * as AppModel from '../../src/models/app.js';
import * as DeploymentModel from '../../src/models/deployment.js';
import { DeploymentStatus } from '../../src/enums.js';

vi.mock('../../src/services/gitService.js', () => ({
  default: vi.fn().mockImplementation(() => ({
    clone: vi.fn().mockResolvedValue(''),
    pull: vi.fn().mockResolvedValue(''),
  })),
}));

vi.mock('../../src/queue.js', () => ({
  enqueueDeployJob: vi.fn(),
}));

test('POST /apps/:id/rollback creates deployment with rollback_sha and redirects', async () => {
  const app = await import('../../src/app.js').then(m => m.default);
  const rec = AppModel.create({ name: 'RB', repo_url: 'r', branch: 'main', path: '/rb' });
  const dep = DeploymentModel.create({
    app_id: rec.id,
    status: DeploymentStatus.Success,
    commit_sha: 'abc123def456abc123def456abc123def456abc1',
  });

  const res = await request(app)
    .post(`/apps/${rec.id}/rollback`)
    .type('form')
    .send({ deployment_id: dep.id });

  expect(res.status).toBe(302);
  const deployments = DeploymentModel.listForApp(rec.id);
  const rollback = deployments.find(d => d.rollback_sha === 'abc123def456abc123def456abc123def456abc1');
  expect(rollback).toBeTruthy();
});

test('POST /apps/:id/rollback returns 400 when deployment has no commit_sha', async () => {
  const app = await import('../../src/app.js').then(m => m.default);
  const rec = AppModel.create({ name: 'RB2', repo_url: 'r', branch: 'main', path: '/rb2' });
  const dep = DeploymentModel.create({ app_id: rec.id, status: DeploymentStatus.Success });

  const res = await request(app)
    .post(`/apps/${rec.id}/rollback`)
    .type('form')
    .send({ deployment_id: dep.id });

  expect(res.status).toBe(400);
});
