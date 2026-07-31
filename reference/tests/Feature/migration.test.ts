import { getDb } from '../../src/db.js';

test('apps table has correct columns', () => {
  const db = getDb();
  const cols = (db.prepare("PRAGMA table_info(apps)").all() as { name: string }[]).map(c => c.name);
  expect(cols).toContain('id');
  expect(cols).toContain('name');
  expect(cols).toContain('repo_url');
  expect(cols).toContain('branch');
  expect(cols).toContain('path');
  expect(cols).toContain('status');
  expect(cols).toContain('created_at');
  expect(cols).toContain('updated_at');
});

test('deployments table has correct columns', () => {
  const db = getDb();
  const cols = (db.prepare("PRAGMA table_info(deployments)").all() as { name: string }[]).map(c => c.name);
  expect(cols).toContain('id');
  expect(cols).toContain('app_id');
  expect(cols).toContain('status');
  expect(cols).toContain('log');
  expect(cols).toContain('started_at');
  expect(cols).toContain('finished_at');
  expect(cols).toContain('created_at');
  expect(cols).toContain('updated_at');
});

test('jobs table has correct columns', () => {
  const db = getDb();
  const cols = (db.prepare("PRAGMA table_info(jobs)").all() as { name: string }[]).map(c => c.name);
  expect(cols).toContain('id');
  expect(cols).toContain('queue');
  expect(cols).toContain('payload');
  expect(cols).toContain('attempts');
  expect(cols).toContain('reserved_at');
  expect(cols).toContain('available_at');
  expect(cols).toContain('created_at');
});
