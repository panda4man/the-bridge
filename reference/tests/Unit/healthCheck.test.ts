import * as AppModel from '../../src/models/app.js';
import * as HealthCheckModel from '../../src/models/healthCheck.js';

test('create and findLatest for app', () => {
  const app = AppModel.create({ name: 'HC', repo_url: 'r', branch: 'main', path: '/hc' });
  HealthCheckModel.record(app.id, 'up', 200, 120);
  const latest = HealthCheckModel.findLatest(app.id);
  expect(latest).not.toBeNull();
  expect(latest!.status).toBe('up');
  expect(latest!.http_status_code).toBe(200);
  expect(latest!.response_time_ms).toBe(120);
});

test('listRecent returns up to 20 results newest-first', () => {
  const app = AppModel.create({ name: 'HC2', repo_url: 'r', branch: 'main', path: '/hc2' });
  for (let i = 0; i < 25; i++) HealthCheckModel.record(app.id, 'up', 200, i * 10);
  const results = HealthCheckModel.listRecent(app.id);
  expect(results.length).toBe(20);
});

test('consecutiveFailures counts only trailing failures', () => {
  const app = AppModel.create({ name: 'HC3', repo_url: 'r', branch: 'main', path: '/hc3' });
  HealthCheckModel.record(app.id, 'up', 200, 50);
  HealthCheckModel.record(app.id, 'down', 0, 0);
  HealthCheckModel.record(app.id, 'down', 0, 0);
  expect(HealthCheckModel.consecutiveFailures(app.id)).toBe(2);
});
