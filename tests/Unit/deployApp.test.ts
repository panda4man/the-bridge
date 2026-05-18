import { mkdirSync, rmSync } from 'fs';
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
