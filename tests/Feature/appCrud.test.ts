import request from 'supertest';
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'fs';
import { tmpdir } from 'os';
import { join } from 'path';
import { vi } from 'vitest';
import * as AppModel from '../../src/models/app.js';
import { getDb } from '../../src/db.js';

vi.mock('../../src/services/gitService.js', () => ({
  default: vi.fn().mockImplementation(() => ({
    clone: vi.fn().mockResolvedValue('Cloning into...'),
    pull: vi.fn().mockResolvedValue('Already up to date.'),
  })),
}));

async function makeApp() {
  return await import('../../src/app.js').then(m => m.default);
}

test('GET / returns 200 with apps list', async () => {
  const app = await makeApp();
  const res = await request(app).get('/');
  expect(res.status).toBe(200);
  expect(res.text).toContain('Apps');
});

test('GET /apps/create returns 200', async () => {
  const app = await makeApp();
  const res = await request(app).get('/apps/create');
  expect(res.status).toBe(200);
  expect(res.text).toContain('New App');
});

test('POST /apps creates app and redirects', async () => {
  const app = await makeApp();
  const res = await request(app)
    .post('/apps')
    .type('form')
    .send({ name: 'My App', repo_url: 'https://github.com/x/y.git', branch: 'main', path: 'my-app' });
  expect(res.status).toBe(302);
  expect(res.headers.location).toBe('/');
  const record = getDb().prepare('SELECT * FROM apps WHERE name = ?').get('My App');
  expect(record).toBeTruthy();
});

test('POST /apps validates required fields', async () => {
  const app = await makeApp();
  const res = await request(app).post('/apps').type('form').send({});
  expect(res.status).toBe(422);
  expect(res.text).toContain('Name');
});

test('GET /apps/:id returns app detail', async () => {
  const app = await makeApp();
  const rec = AppModel.create({ name: 'Detail', repo_url: 'r', branch: 'main', path: '/d' });
  const res = await request(app).get(`/apps/${rec.id}`);
  expect(res.status).toBe(200);
  expect(res.text).toContain('Detail');
});

test('GET /apps/:id/edit returns edit form', async () => {
  const app = await makeApp();
  const rec = AppModel.create({ name: 'EditMe', repo_url: 'r', branch: 'main', path: '/e' });
  const res = await request(app).get(`/apps/${rec.id}/edit`);
  expect(res.status).toBe(200);
  expect(res.text).toContain('EditMe');
});

test('PUT /apps/:id updates app and redirects', async () => {
  const app = await makeApp();
  const rec = AppModel.create({ name: 'Old', repo_url: 'r', branch: 'main', path: '/o' });
  const res = await request(app)
    .post(`/apps/${rec.id}`)
    .type('form')
    .send({ _method: 'PUT', name: 'Updated', repo_url: 'r', branch: 'develop', path: '/o' });
  expect(res.status).toBe(302);
  expect(AppModel.findById(rec.id)!.name).toBe('Updated');
});

test('DELETE /apps/:id deletes app and redirects', async () => {
  const app = await makeApp();
  const rec = AppModel.create({ name: 'Delete Me', repo_url: 'r', branch: 'main', path: '/del' });
  const res = await request(app)
    .post(`/apps/${rec.id}`)
    .type('form')
    .send({ _method: 'DELETE' });
  expect(res.status).toBe(302);
  expect(AppModel.findById(rec.id)).toBeNull();
});

test('POST /apps with skip_clone imports existing git repo', async () => {
  const app = await makeApp();
  const reposPath = process.env.REPOS_PATH || tmpdir();
  const relPath = `import-${Date.now()}`;
  const fullPath = join(reposPath, relPath);
  mkdirSync(join(fullPath, '.git'), { recursive: true });

  const res = await request(app)
    .post('/apps')
    .type('form')
    .send({ name: 'Imported', repo_url: 'r', branch: 'main', path: relPath, skip_clone: '1' });
  expect(res.status).toBe(302);
  expect(getDb().prepare('SELECT * FROM apps WHERE name = ?').get('Imported')).toBeTruthy();
  rmSync(fullPath, { recursive: true, force: true });
});

test('POST /apps with skip_clone fails if directory does not exist', async () => {
  const app = await makeApp();
  const res = await request(app)
    .post('/apps')
    .type('form')
    .send({ name: 'Missing', repo_url: 'r', branch: 'main', path: 'nonexistent-99xyz', skip_clone: '1' });
  expect(res.status).toBe(422);
  expect(getDb().prepare('SELECT * FROM apps WHERE name = ?').get('Missing')).toBeFalsy();
});

test('POST /apps copies .env.example to .env when .env is absent', async () => {
  const app = await makeApp();
  const reposPath = process.env.REPOS_PATH || tmpdir();
  const relPath = `env-example-${Date.now()}`;
  const fullPath = join(reposPath, relPath);
  mkdirSync(join(fullPath, '.git'), { recursive: true });
  writeFileSync(join(fullPath, '.env.example'), 'APP_KEY=example\n');

  const res = await request(app)
    .post('/apps')
    .type('form')
    .send({ name: 'EnvCopy', repo_url: 'r', branch: 'main', path: relPath, skip_clone: '1' });
  expect(res.status).toBe(302);
  expect(existsSync(join(fullPath, '.env'))).toBe(true);
  expect(readFileSync(join(fullPath, '.env'), 'utf-8')).toBe('APP_KEY=example\n');
  rmSync(fullPath, { recursive: true, force: true });
});

test('POST /apps does not overwrite existing .env when .env.example present', async () => {
  const app = await makeApp();
  const reposPath = process.env.REPOS_PATH || tmpdir();
  const relPath = `env-no-overwrite-${Date.now()}`;
  const fullPath = join(reposPath, relPath);
  mkdirSync(join(fullPath, '.git'), { recursive: true });
  writeFileSync(join(fullPath, '.env.example'), 'APP_KEY=example\n');
  writeFileSync(join(fullPath, '.env'), 'APP_KEY=real\n');

  const res = await request(app)
    .post('/apps')
    .type('form')
    .send({ name: 'EnvKeep', repo_url: 'r', branch: 'main', path: relPath, skip_clone: '1' });
  expect(res.status).toBe(302);
  expect(readFileSync(join(fullPath, '.env'), 'utf-8')).toBe('APP_KEY=real\n');
  rmSync(fullPath, { recursive: true, force: true });
});
