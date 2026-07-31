import { vi } from 'vitest';
import * as AppModel from '../../src/models/app.js';
import * as HealthCheckModel from '../../src/models/healthCheck.js';

global.fetch = vi.fn();

test('runHealthChecks records "up" for 200 response', async () => {
  (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce({ ok: true, status: 200 });
  AppModel.create({ name: 'P', repo_url: 'r', branch: 'main', path: '/p', health_url: 'http://localhost:9999' } as any);
  const { runHealthChecks } = await import('../../src/services/healthPoller.js');
  await runHealthChecks();
  const apps = AppModel.list();
  const latest = HealthCheckModel.findLatest(apps[0].id);
  expect(latest?.status).toBe('up');
});

test('runHealthChecks records "down" when fetch throws', async () => {
  (global.fetch as ReturnType<typeof vi.fn>).mockRejectedValueOnce(new Error('ECONNREFUSED'));
  const app = AppModel.create({ name: 'P2', repo_url: 'r', branch: 'main', path: '/p2', health_url: 'http://localhost:9998' } as any);
  const { runHealthChecks } = await import('../../src/services/healthPoller.js');
  await runHealthChecks();
  const latest = HealthCheckModel.findLatest(app.id);
  expect(latest?.status).toBe('down');
});

test('runHealthChecks skips apps without health_url', async () => {
  (global.fetch as ReturnType<typeof vi.fn>).mockClear();
  AppModel.create({ name: 'Skip', repo_url: 'r', branch: 'main', path: '/skip' });
  const { runHealthChecks } = await import('../../src/services/healthPoller.js');
  await runHealthChecks();
  expect(global.fetch).not.toHaveBeenCalled();
});
