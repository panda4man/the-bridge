import { spawn, execSync, execFileSync } from 'child_process';
import { existsSync } from 'fs';
import { join } from 'path';
import * as AppModel from '../models/app.js';
import * as DeploymentModel from '../models/deployment.js';
import GitService from '../services/gitService.js';
import { AppStatus, DeploymentStatus } from '../enums.js';
import { notifyDeployment } from '../services/slackNotifier.js';

type ComposeRunner = (subCmd: string, workDir: string, onOutput: (chunk: string) => void) => Promise<number>;

export interface DeployAppOptions {
  sshKeyPath?: string;
  composeRunner?: ComposeRunner;
}

function defaultComposeRunner(sshKeyPath: string): ComposeRunner {
  return async function (subCmd, workDir, onOutput) {
    const composeFile = join(workDir, 'docker-compose.yml');
    const args = ['-f', composeFile, ...subCmd.split(' ')];
    const env: NodeJS.ProcessEnv = { ...process.env, DOCKER_PROGRESS: 'plain' };

    if (sshKeyPath && existsSync(sshKeyPath)) {
      env.GIT_SSH_COMMAND = `ssh -i ${sshKeyPath} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null`;
    }

    const stallTimeout = subCmd.startsWith('pull') ? 60000 : 300000;

    return new Promise((resolve) => {
      const proc = spawn('docker', ['compose', ...args], { cwd: workDir, env });
      let timer = setTimeout(() => {
        onOutput(`\nERROR: process stalled — no output for ${stallTimeout / 1000}s, killed.\n`);
        proc.kill('SIGKILL');
        resolve(1);
      }, stallTimeout);

      const resetTimer = () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
          onOutput(`\nERROR: process stalled — no output for ${stallTimeout / 1000}s, killed.\n`);
          proc.kill('SIGKILL');
          resolve(1);
        }, stallTimeout);
      };

      proc.stdout.on('data', (chunk: Buffer) => { resetTimer(); onOutput(chunk.toString()); });
      proc.stderr.on('data', (chunk: Buffer) => { resetTimer(); onOutput(chunk.toString()); });
      proc.on('close', (code: number | null) => { clearTimeout(timer); resolve(code ?? 1); });
      proc.on('error', (err: Error) => { clearTimeout(timer); onOutput(`\nERROR: ${err.message}\n`); resolve(1); });
    });
  };
}

export async function deployApp(deploymentId: number, options: DeployAppOptions = {}): Promise<void> {
  const sshKeyPath = options.sshKeyPath ?? process.env.BRIDGE_SSH_KEY_PATH ?? '/data/ssh/id_rsa';
  const composeRunner = options.composeRunner ?? defaultComposeRunner(sshKeyPath);

  const dep = DeploymentModel.findById(deploymentId);
  if (!dep) throw new Error(`Deployment ${deploymentId} not found`);

  const app = AppModel.findById(dep.app_id);
  if (!app) throw new Error(`App ${dep.app_id} not found`);

  DeploymentModel.update(deploymentId, { status: DeploymentStatus.Running, started_at: new Date().toISOString() });
  AppModel.updateStatus(app.id, AppStatus.Deploying);

  try {
    const git = new GitService(sshKeyPath);

    if (dep.rollback_sha) {
      DeploymentModel.appendLog(deploymentId, `=== git checkout ${dep.rollback_sha} ===\n`);
      const fetchOut = await git.pull(app.path, app.branch);
      DeploymentModel.appendLog(deploymentId, fetchOut + '\n');
      execFileSync('git', ['checkout', dep.rollback_sha], { cwd: app.path });
      DeploymentModel.appendLog(deploymentId, `Checked out ${dep.rollback_sha}\n`);
    } else {
      DeploymentModel.appendLog(deploymentId, '=== git pull ===\n');
      const pullOutput = await git.pull(app.path, app.branch);
      DeploymentModel.appendLog(deploymentId, pullOutput + '\n');
    }

    // capture commit SHA and message
    const commitSha = execSync('git rev-parse HEAD', { cwd: app.path }).toString().trim();
    const commitMsg = execSync('git log -1 --format=%s', { cwd: app.path }).toString().trim();
    DeploymentModel.update(deploymentId, { commit_sha: commitSha, commit_message: commitMsg });

    for (const subCmd of ['pull', 'down', 'up -d --build --remove-orphans']) {
      let success = false;
      for (let attempt = 1; attempt <= 3; attempt++) {
        const label = attempt > 1 ? ` (attempt ${attempt})` : '';
        DeploymentModel.appendLog(deploymentId, `=== docker compose ${subCmd}${label} ===\n`);
        const exit = await composeRunner(subCmd, app.path, (chunk) => DeploymentModel.appendLog(deploymentId, chunk));
        if (exit === 0) { success = true; break; }
        if (attempt < 3) {
          DeploymentModel.appendLog(deploymentId, 'Retrying in 5s...\n');
          await new Promise(r => setTimeout(r, 5000));
        }
      }
      if (!success) throw new Error(`docker compose ${subCmd} failed after 3 attempts`);
    }

    DeploymentModel.update(deploymentId, { status: DeploymentStatus.Success, finished_at: new Date().toISOString() });
    AppModel.updateStatus(app.id, AppStatus.Success);
    const successDep = DeploymentModel.findById(deploymentId)!;
    notifyDeployment(successDep).catch(console.error);
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    DeploymentModel.appendLog(deploymentId, `\nERROR: ${message}\n`);
    DeploymentModel.update(deploymentId, { status: DeploymentStatus.Failed, finished_at: new Date().toISOString() });
    AppModel.updateStatus(app.id, AppStatus.Failed);
    const failedDep = DeploymentModel.findById(deploymentId)!;
    notifyDeployment(failedDep).catch(console.error);
  }
}
