import { rmSync } from 'fs';
import { tmpdir } from 'os';
import { join } from 'path';
import GitService from '../../src/services/gitService.js';

const TEST_REPO = 'https://github.com/octocat/Hello-World.git';
const TEST_BRANCH = 'master';

test('clone() returns output string on success', async () => {
  const dir = join(tmpdir(), `bridge-clone-${Date.now()}`);
  const svc = new GitService();
  const output = await svc.clone(TEST_REPO, dir, TEST_BRANCH);
  expect(typeof output).toBe('string');
  rmSync(dir, { recursive: true, force: true });
}, 60000);

test('clone() throws on invalid repo', async () => {
  const dir = join(tmpdir(), `bridge-fail-${Date.now()}`);
  const svc = new GitService();
  await expect(
    svc.clone('https://github.com/nonexistent99xyz/nonexistent99xyz.git', dir, 'main')
  ).rejects.toThrow();
}, 30000);

test('pull() returns output string for existing repo', async () => {
  const dir = join(tmpdir(), `bridge-pull-${Date.now()}`);
  const svc = new GitService();
  await svc.clone(TEST_REPO, dir, TEST_BRANCH);
  const output = await svc.pull(dir, TEST_BRANCH);
  expect(typeof output).toBe('string');
  rmSync(dir, { recursive: true, force: true });
}, 120000);
