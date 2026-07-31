import { spawn, execSync, execFileSync } from 'child_process';
import { existsSync } from 'fs';
import { join } from 'path';
import * as AppModel from '../models/app.js';
import * as DeploymentModel from '../models/deployment.js';
import GitService from '../services/gitService.js';
import { AppStatus, DeploymentStatus } from '../enums.js';
import { notifyDeployment } from '../services/slackNotifier.js';
import { resolveDeploySteps } from '../services/deploySteps.js';
import { getContainerStatus } from '../services/containerStatus.js';
import { enqueueDeployJob } from '../queue.js';

type ComposeRunner = (subCmd: string, workDir: string, onOutput: (chunk: string) => void) => Promise<number>;

export interface DeployAppOptions {
  sshKeyPath?: string;
  composeRunner?: ComposeRunner;
  execStep?: (service: string, run: string, workDir: string, onOutput: (chunk: string) => void) => Promise<number>;
}

function spawnWithStall(
  argv: string[],
  workDir: string,
  env: NodeJS.ProcessEnv,
  stallTimeout: number,
  onOutput: (chunk: string) => void
): Promise<number> {
  return new Promise((resolve) => {
    const [cmd, ...args] = argv;
    const proc = spawn(cmd, args, { cwd: workDir, env });
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
}

function defaultComposeRunner(sshKeyPath: string): ComposeRunner {
  return async function (subCmd, workDir, onOutput) {
    const composeFile = join(workDir, 'docker-compose.yml');
    const args = ['docker', 'compose', '-f', composeFile, ...subCmd.split(' ')];
    const env: NodeJS.ProcessEnv = { ...process.env, DOCKER_PROGRESS: 'plain' };

    if (sshKeyPath && existsSync(sshKeyPath)) {
      env.GIT_SSH_COMMAND = `ssh -i ${sshKeyPath} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null`;
    }

    const stallTimeout = subCmd.startsWith('pull') ? 60000 : 300000;
    return spawnWithStall(args, workDir, env, stallTimeout, onOutput);
  };
}

function defaultExecStep(sshKeyPath: string) {
  return async function (service: string, run: string, workDir: string, onOutput: (chunk: string) => void): Promise<number> {
    const composeFile = join(workDir, 'docker-compose.yml');
    const argv = ['docker', 'compose', '-f', composeFile, 'exec', '-T', service, 'sh', '-c', run];
    const env: NodeJS.ProcessEnv = { ...process.env };

    if (sshKeyPath && existsSync(sshKeyPath)) {
      env.GIT_SSH_COMMAND = `ssh -i ${sshKeyPath} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null`;
    }

    return spawnWithStall(argv, workDir, env, 60000, onOutput);
  };
}

export async function deployApp(deploymentId: number, options: DeployAppOptions = {}): Promise<void> {
  const sshKeyPath = options.sshKeyPath ?? process.env.BRIDGE_SSH_KEY_PATH ?? '/data/ssh/id_rsa';
  const composeRunner = options.composeRunner ?? defaultComposeRunner(sshKeyPath);
  const execStep = options.execStep ?? defaultExecStep(sshKeyPath);

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

    // Post-deploy steps
    const { steps, source } = resolveDeploySteps(app);
    if (steps.length > 0) {
      DeploymentModel.appendLog(deploymentId, `=== post-deploy (${steps.length} step${steps.length === 1 ? '' : 's'}, source: ${source}) ===\n`);

      // Pre-flight: verify each service is actually running
      const containers = getContainerStatus(app.path);
      const runningServices = new Set(containers.filter(c => c.state === 'running').map(c => c.service));

      for (const step of steps) {
        if (!runningServices.has(step.service)) {
          throw new Error(`post-deploy: service "${step.service}" not running — check service name in bridge.yml or deploy_steps`);
        }
      }

      // Execute each step
      for (const step of steps) {
        DeploymentModel.appendLog(deploymentId, `--- exec ${step.service}: ${step.run} ---\n`);
        const exit = await execStep(step.service, step.run, app.path, (chunk) => DeploymentModel.appendLog(deploymentId, chunk));
        if (exit !== 0) {
          throw new Error(`post-deploy step failed: ${step.service}: ${step.run}`);
        }
      }
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

    // Auto-rollback: only when this deploy is NOT itself a rollback (loop guard)
    if (!dep.rollback_sha) {
      const prev = DeploymentModel.findLastSuccessful(dep.app_id, dep.id);
      if (prev && prev.commit_sha) {
        DeploymentModel.appendLog(deploymentId, `Auto-rolling back to ${prev.commit_sha}\n`);
        const rollbackDep = DeploymentModel.create({
          app_id: dep.app_id,
          status: DeploymentStatus.Pending,
          rollback_sha: prev.commit_sha,
        });
        enqueueDeployJob(rollbackDep.id);
      } else {
        DeploymentModel.appendLog(deploymentId, 'No previous successful deployment — skipping auto-rollback\n');
      }
    }
  }
}
