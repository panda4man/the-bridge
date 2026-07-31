import request from 'supertest';
import { vi } from 'vitest';
import * as AppModel from '../../src/models/app.js';

vi.mock('../../src/services/containerStatus.js', () => ({
  getContainerStatus: vi.fn().mockReturnValue([
    { name: 'myapp-web-1', service: 'web', state: 'running', status: 'Up 2 hours', ports: '0.0.0.0:3000->3000/tcp' },
  ]),
}));

vi.mock('../../src/services/gitService.js', () => ({
  default: vi.fn().mockImplementation(() => ({
    clone: vi.fn().mockResolvedValue(''),
    pull: vi.fn().mockResolvedValue(''),
  })),
}));

test('GET /apps/:id/containers returns container list', async () => {
  const app = await import('../../src/app.js').then(m => m.default);
  const rec = AppModel.create({ name: 'C', repo_url: 'r', branch: 'main', path: '/c' });
  const res = await request(app).get(`/apps/${rec.id}/containers`);
  expect(res.status).toBe(200);
  expect(res.body.containers).toHaveLength(1);
  expect(res.body.containers[0].state).toBe('running');
});

test('GET /apps/:id/containers returns 404 for unknown app', async () => {
  const app = await import('../../src/app.js').then(m => m.default);
  const res = await request(app).get('/apps/99999/containers');
  expect(res.status).toBe(404);
});
