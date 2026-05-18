import request from 'supertest';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'fs';
import { tmpdir } from 'os';
import { join } from 'path';
import * as AppModel from '../../src/models/app.js';

async function makeApp() {
  return await import('../../src/app.js').then(m => m.default);
}

function makeRepoWithEnv(initialContent: string) {
  const repoPath = mkdtempSync(join(tmpdir(), 'bridge-env-'));
  const envPath = join(repoPath, '.env');
  writeFileSync(envPath, initialContent, 'utf-8');
  return { repoPath, envPath };
}

test('POST /apps/:id/env rejects empty content and keeps existing file', async () => {
  const app = await makeApp();
  const { repoPath, envPath } = makeRepoWithEnv('EXISTING=1\n');
  const rec = AppModel.create({ name: 'EnvEmpty', repo_url: 'r', branch: 'main', path: repoPath });

  try {
    const res = await request(app)
      .post(`/apps/${rec.id}/env`)
      .set('Content-Type', 'application/json')
      .send({ content: '' });

    expect(res.status).toBe(400);
    expect(readFileSync(envPath, 'utf-8')).toBe('EXISTING=1\n');
  } finally {
    rmSync(repoPath, { recursive: true, force: true });
  }
});

test('POST /apps/:id/env writes non-empty content', async () => {
  const app = await makeApp();
  const { repoPath, envPath } = makeRepoWithEnv('OLD=1\n');
  const rec = AppModel.create({ name: 'EnvSave', repo_url: 'r', branch: 'main', path: repoPath });

  try {
    const res = await request(app)
      .post(`/apps/${rec.id}/env`)
      .set('Content-Type', 'application/json')
      .send({ content: 'NEW=1\n' });

    expect(res.status).toBe(200);
    expect(readFileSync(envPath, 'utf-8')).toBe('NEW=1\n');
  } finally {
    rmSync(repoPath, { recursive: true, force: true });
  }
});
