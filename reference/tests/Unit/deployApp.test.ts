import { mkdirSync, rmSync, writeFileSync, readFileSync, existsSync } from 'fs';
import { tmpdir } from 'os';
import { join } from 'path';
import { execSync } from 'child_process';
import * as AppModel from '../../src/models/app.js';
import * as DeploymentModel from '../../src/models/deployment.js';
import { AppStatus, DeploymentStatus } from '../../src/enums.js';
import { deployApp } from '../../src/jobs/deployApp.js';

const TEST_REPO = 'https://github.com/octocat/Hello-World.git';
const TEST_BRANCH = 'master';

function makeTestDir(prefix: string): string {
  const dir = join(tmpdir(), `${prefix}-${Date.now()}`);
  mkdirSync(dir, { recursive: true });
  execSync(`git clone --branch ${TEST_BRANCH} ${TEST_REPO} ${dir}`, { stdio: 'pipe' });
  return dir;
}

test('deployApp marks deployment success when all commands exit 0', async () => {
  const dir = makeTestDir('bridge-deploy');
  const app = AppModel.create({ name: 'Test', repo_url: TEST_REPO, branch: TEST_BRANCH, path: dir });
  const dep = DeploymentModel.create({ app_id: app.id, status: DeploymentStatus.Pending });

  const mockRunner = async (_subCmd: string, _workDir: string, onOutput: (chunk: string) => void): Promise<number> => {
    onOutput('mock compose output\n');
    return 0;
  };

  await deployApp(dep.id, { composeRunner: mockRunner });

  const updated = DeploymentModel.findById(dep.id);
  const updatedApp = AppModel.findById(app.id);

  expect(updated!.status).toBe(DeploymentStatus.Success);
  expect(updated!.log).toContain('mock compose output');
  expect(updated!.finished_at).not.toBeNull();
  expect(updatedApp!.status).toBe(AppStatus.Success);

  rmSync(dir, { recursive: true, force: true });
}, 120000);

test('deployApp marks deployment failed when compose exits non-zero', async () => {
  const dir = makeTestDir('bridge-fail');
  const app = AppModel.create({ name: 'Fail', repo_url: TEST_REPO, branch: TEST_BRANCH, path: dir });
  const dep = DeploymentModel.create({ app_id: app.id, status: DeploymentStatus.Pending });

  const mockRunner = async (_subCmd: string, _workDir: string, onOutput: (chunk: string) => void): Promise<number> => {
    onOutput('compose error output\n');
    return 1;
  };

  await deployApp(dep.id, { composeRunner: mockRunner });

  const updated = DeploymentModel.findById(dep.id);
  const updatedApp = AppModel.findById(app.id);

  expect(updated!.status).toBe(DeploymentStatus.Failed);
  expect(updatedApp!.status).toBe(AppStatus.Failed);

  rmSync(dir, { recursive: true, force: true });
}, 120000);

test('deployApp stores commit_sha after successful deploy', async () => {
  const dir = makeTestDir('bridge-sha');
  const app = AppModel.create({ name: 'SHA', repo_url: TEST_REPO, branch: TEST_BRANCH, path: dir });
  const dep = DeploymentModel.create({ app_id: app.id, status: DeploymentStatus.Pending });

  const mockRunner = async (_s: string, _w: string, _o: (c: string) => void) => 0;
  await deployApp(dep.id, { composeRunner: mockRunner });

  const updated = DeploymentModel.findById(dep.id);
  expect(updated!.commit_sha).toMatch(/^[0-9a-f]{40}$/);
  expect(updated!.commit_message).toBeTruthy();

  rmSync(dir, { recursive: true, force: true });
}, 120000);

test('deployApp with rollback_sha checks out that SHA instead of pulling', async () => {
  const dir = makeTestDir('bridge-rollback');
  const sha = execSync('git rev-parse HEAD', { cwd: dir }).toString().trim();

  const app = AppModel.create({ name: 'RB', repo_url: TEST_REPO, branch: TEST_BRANCH, path: dir });
  const dep = DeploymentModel.create({ app_id: app.id, status: DeploymentStatus.Pending, rollback_sha: sha });

  const mockRunner = async (_s: string, _w: string, _o: (c: string) => void) => 0;
  await deployApp(dep.id, { composeRunner: mockRunner });

  const updated = DeploymentModel.findById(dep.id);
  expect(updated!.status).toBe(DeploymentStatus.Success);
  expect(updated!.commit_sha).toBe(sha);

  rmSync(dir, { recursive: true, force: true });
}, 120000);

// --- Post-deploy steps tests (local git, injected runners) ---

/**
 * Creates a bare remote + cloned working copy so `git pull origin <branch>` works locally.
 * Writes the bare path to <work>/.bare_path for cleanup.
 * Returns the working copy path.
 */
function makeLocalGitDir(prefix: string): string {
  const ts = Date.now();
  const bare = join(tmpdir(), `${prefix}-bare-${ts}`);
  const seed = join(tmpdir(), `${prefix}-seed-${ts}`);
  const work = join(tmpdir(), `${prefix}-work-${ts}`);

  // Initialize bare remote
  mkdirSync(bare, { recursive: true });
  execSync('git init --bare', { cwd: bare, stdio: 'pipe' });

  // Seed repo to push initial commit
  mkdirSync(seed, { recursive: true });
  execSync('git init', { cwd: seed, stdio: 'pipe' });
  execSync('git config user.email "test@bridge.local"', { cwd: seed, stdio: 'pipe' });
  execSync('git config user.name "Bridge Test"', { cwd: seed, stdio: 'pipe' });
  writeFileSync(join(seed, 'README.md'), 'test\n', 'utf-8');
  execSync('git add .', { cwd: seed, stdio: 'pipe' });
  execSync('git commit -m "init"', { cwd: seed, stdio: 'pipe' });
  execSync(`git remote add origin ${bare}`, { cwd: seed, stdio: 'pipe' });
  // Try master first (older git defaults), fall back to main
  try {
    execSync('git push -u origin master', { cwd: seed, stdio: 'pipe' });
  } catch {
    execSync('git push -u origin main', { cwd: seed, stdio: 'pipe' });
  }
  rmSync(seed, { recursive: true, force: true });

  // Clone from bare
  execSync(`git clone ${bare} ${work}`, { stdio: 'pipe' });
  execSync('git config user.email "test@bridge.local"', { cwd: work, stdio: 'pipe' });
  execSync('git config user.name "Bridge Test"', { cwd: work, stdio: 'pipe' });

  // Store bare path for cleanup
  writeFileSync(join(work, '.bare_path'), bare, 'utf-8');
  return work;
}

function cleanupLocalGitDir(workDir: string): void {
  const barePath = join(workDir, '.bare_path');
  if (existsSync(barePath)) {
    const bare = readFileSync(barePath, 'utf-8').trim();
    rmSync(bare, { recursive: true, force: true });
  }
  rmSync(workDir, { recursive: true, force: true });
}

test('post-deploy: service not running causes pre-flight failure and no exec call', async () => {
  // docker is unavailable in test env → getContainerStatus returns [] → pre-flight fails
  const dir = makeLocalGitDir('bridge-pd-preflight');
  const branch = execSync('git rev-parse --abbrev-ref HEAD', { cwd: dir }).toString().trim();
  const steps = JSON.stringify([{ service: 'app', run: 'echo hello' }]);

  const app = AppModel.create({ name: 'PDPreflight', repo_url: dir, branch, path: dir });
  AppModel.update(String(app.id), { deploy_steps: steps });
  const dep = DeploymentModel.create({ app_id: app.id, status: DeploymentStatus.Pending });

  const execCalls: { service: string; run: string }[] = [];
  const fakeExec = async (service: string, run: string, _w: string, _o: (c: string) => void) => {
    execCalls.push({ service, run });
    return 0;
  };
  const fakeRunner = async (_s: string, _w: string, _o: (c: string) => void) => 0;

  await deployApp(dep.id, { composeRunner: fakeRunner, execStep: fakeExec });

  const updated = DeploymentModel.findById(dep.id);
  // docker unavailable → getContainerStatus() returns [] → service 'app' not in running set
  expect(updated!.status).toBe(DeploymentStatus.Failed);
  expect(updated!.log).toContain('post-deploy: service "app" not running');
  // execStep must NOT be called — pre-flight short-circuits before exec
  expect(execCalls).toHaveLength(0);

  cleanupLocalGitDir(dir);
}, 30000);

test('post-deploy step failure triggers exactly one auto-rollback deployment', async () => {
  // Compose steps pass (fakeRunner returns 0), but getContainerStatus returns [] →
  // pre-flight fails → deploy fails → auto-rollback enqueued to the previous success SHA.
  const dir = makeLocalGitDir('bridge-pd-rollback');
  const branch = execSync('git rev-parse --abbrev-ref HEAD', { cwd: dir }).toString().trim();

  const app = AppModel.create({ name: 'PDRollback', repo_url: dir, branch, path: dir });

  // Seed a prior successful deployment with a known SHA
  const prevDep = DeploymentModel.create({ app_id: app.id, status: DeploymentStatus.Pending });
  DeploymentModel.update(prevDep.id, {
    status: DeploymentStatus.Success,
    commit_sha: 'aabbccddee112233445566778899aabbccddee11',
  });

  // Write bridge.yml so there are steps to trigger pre-flight
  writeFileSync(join(dir, 'bridge.yml'), 'post_deploy:\n  - service: app\n    run: migrate\n', 'utf-8');

  const dep = DeploymentModel.create({ app_id: app.id, status: DeploymentStatus.Pending });
  const fakeRunner = async (_s: string, _w: string, _o: (c: string) => void) => 0;
  const fakeExec = async (_svc: string, _run: string, _w: string, onOutput: (c: string) => void) => {
    onOutput('migration error\n');
    return 1;
  };

  await deployApp(dep.id, { composeRunner: fakeRunner, execStep: fakeExec });

  const updated = DeploymentModel.findById(dep.id);
  expect(updated!.status).toBe(DeploymentStatus.Failed);

  // Exactly one rollback deployment should have been created
  const allDeps = DeploymentModel.listForApp(app.id);
  const rollbackDeps = allDeps.filter(d => d.rollback_sha !== null);
  expect(rollbackDeps).toHaveLength(1);
  expect(rollbackDeps[0].rollback_sha).toBe('aabbccddee112233445566778899aabbccddee11');
  expect(rollbackDeps[0].status).toBe(DeploymentStatus.Pending);
  expect(rollbackDeps[0].id).not.toBe(dep.id);

  cleanupLocalGitDir(dir);
}, 30000);

test('deploy with rollback_sha does NOT enqueue another auto-rollback (loop guard)', async () => {
  // When dep.rollback_sha is set, the catch block must NOT create another rollback.
  const dir = makeLocalGitDir('bridge-pd-loopguard');
  const branch = execSync('git rev-parse --abbrev-ref HEAD', { cwd: dir }).toString().trim();

  const app = AppModel.create({ name: 'LoopGuard', repo_url: dir, branch, path: dir });

  // This deployment is itself a rollback
  const dep = DeploymentModel.create({
    app_id: app.id,
    status: DeploymentStatus.Pending,
    rollback_sha: 'aabbccddee112233445566778899aabbccddee11',
  });

  // Runner fails to force the catch block
  const failRunner = async (_s: string, _w: string, _o: (c: string) => void) => 1;

  await deployApp(dep.id, { composeRunner: failRunner });

  const updated = DeploymentModel.findById(dep.id);
  expect(updated!.status).toBe(DeploymentStatus.Failed);

  // No new rollback deployment should have been created
  const allDeps = DeploymentModel.listForApp(app.id);
  expect(allDeps).toHaveLength(1);

  cleanupLocalGitDir(dir);
}, 30000);

test('no prior successful deployment means no auto-rollback enqueued', async () => {
  const dir = makeLocalGitDir('bridge-pd-noprior');
  const branch = execSync('git rev-parse --abbrev-ref HEAD', { cwd: dir }).toString().trim();

  const app = AppModel.create({ name: 'NoPrior', repo_url: dir, branch, path: dir });
  const dep = DeploymentModel.create({ app_id: app.id, status: DeploymentStatus.Pending });

  const failRunner = async (_s: string, _w: string, _o: (c: string) => void) => 1;

  await deployApp(dep.id, { composeRunner: failRunner });

  const updated = DeploymentModel.findById(dep.id);
  expect(updated!.status).toBe(DeploymentStatus.Failed);
  expect(updated!.log).toContain('No previous successful deployment');

  // Only the one deployment — no rollback was created
  const allDeps = DeploymentModel.listForApp(app.id);
  expect(allDeps).toHaveLength(1);

  cleanupLocalGitDir(dir);
}, 30000);
