import { Router, Request, Response, NextFunction } from 'express';
import { copyFileSync, existsSync, readFileSync, writeFileSync } from 'fs';
import { rm } from 'fs/promises';
import { join } from 'path';
import { spawn } from 'child_process';
import { createHmac, timingSafeEqual, randomBytes } from 'crypto';
import GitService from '../services/gitService.js';
import { readPortBindings } from '../services/portBindings.js';
import { resolveDeploySteps, parseUiSteps, serializeUiSteps } from '../services/deploySteps.js';
import { getContainerStatus } from '../services/containerStatus.js';
import * as AppModel from '../models/app.js';
import * as DeploymentModel from '../models/deployment.js';
import * as HealthCheckModel from '../models/healthCheck.js';
import { DeploymentStatus } from '../enums.js';
import { storeRules, validateStore, updateRules, validateUpdate } from '../validators/appValidators.js';
import { enqueueDeployJob } from '../queue.js';
import { getDb } from '../db.js';

const router = Router();

function runComposeDown(workDir: string, composeFile: string): Promise<void> {
  return new Promise((resolve) => {
    const proc = spawn('docker', ['compose', '-f', composeFile, 'down'], { cwd: workDir });
    const timer = setTimeout(() => { proc.kill('SIGKILL'); resolve(); }, 60000);
    proc.on('close', () => { clearTimeout(timer); resolve(); });
    proc.on('error', () => { clearTimeout(timer); resolve(); });
  });
}

function reposPath(): string {
  return (process.env.REPOS_PATH || '/repos').replace(/\/$/, '');
}

router.get('/', (req: Request, res: Response) => {
  const apps = AppModel.list();
  const appsWithHealth = apps.map(app => {
    const latest = HealthCheckModel.findLatest(app.id);
    return { ...app, last_health_status: latest?.status ?? null };
  });
  res.render('apps/index', { apps: appsWithHealth });
});

router.get('/apps/create', (req: Request, res: Response) => {
  res.render('apps/create', { errors: {}, old: {}, reposPath: reposPath() });
});

router.post('/apps', storeRules, validateStore, async (req: Request, res: Response) => {
  const { name, repo_url, branch } = req.body as { name: string; repo_url: string; branch: string };
  const full = req.fullPath as string;
  const skip = req.skipClone;

  if (!skip) {
    const git = new GitService();
    try {
      await git.clone(repo_url, full, branch);
    } catch (err) {
      res.status(422).render('apps/create', {
        errors: { repo_url: { msg: `Clone failed: ${err instanceof Error ? err.message : String(err)}` } },
        old: req.body,
        reposPath: reposPath(),
      });
      return;
    }
  }

  const envExample = join(full, '.env.example');
  const envFile = join(full, '.env');
  if (existsSync(envExample) && !existsSync(envFile)) {
    copyFileSync(envExample, envFile);
  }

  AppModel.create({ name, repo_url, branch, path: full });
  req.flash('success', skip ? 'App imported.' : 'App created and cloned.');
  res.redirect('/');
});

router.get('/apps/:id', (req: Request, res: Response) => {
  const app = AppModel.findById(Number(req.params.id));
  if (!app) { res.status(404).send('Not found'); return; }
  const deployments = DeploymentModel.listForApp(app.id);
  const ports = readPortBindings(app.path);
  const latestHealth = HealthCheckModel.findLatest(app.id);
  res.render('apps/show', { app, deployments, ports, latestHealth });
});

router.get('/apps/:id/edit', (req: Request, res: Response) => {
  const app = AppModel.findById(Number(req.params.id));
  if (!app) { res.status(404).send('Not found'); return; }
  const sourceExists = existsSync(app.path);
  let repoStepsActive = false;
  let resolvedSteps: import('../types.js').DeployStep[] = [];
  try {
    const resolved = resolveDeploySteps(app);
    repoStepsActive = resolved.source === 'repo';
    resolvedSteps = resolved.steps;
  } catch { /* ignore resolution errors on edit page */ }
  const uiStepsText = repoStepsActive
    ? serializeUiSteps(resolvedSteps)
    : (app.deploy_steps ? serializeUiSteps(JSON.parse(app.deploy_steps)) : '');
  res.render('apps/edit', { app, errors: {}, sourceExists, repoStepsActive, resolvedSteps, uiStepsText });
});

router.put('/apps/:id',
  (req: Request, res: Response, next: NextFunction) => {
    const app = AppModel.findById(Number(req.params.id));
    if (!app) { res.status(404).send('Not found'); return; }
    req.app_record = app;
    next();
  },
  updateRules,
  validateUpdate,
  (req: Request, res: Response) => {
    const { name, repo_url, branch, path, health_url, deploy_steps_text } = req.body as {
      name: string; repo_url: string; branch: string; path: string; health_url?: string; deploy_steps_text?: string;
    };
    const previousBranch = req.app_record!.branch;
    const parsedSteps = parseUiSteps(deploy_steps_text ?? '');
    const deployStepsJson = parsedSteps.length > 0 ? JSON.stringify(parsedSteps) : null;
    AppModel.update(String(req.params.id), { name, repo_url, branch, path, health_url: health_url || null, deploy_steps: deployStepsJson });

    if (branch !== previousBranch) {
      const dep = DeploymentModel.create({ app_id: Number(req.params.id), status: DeploymentStatus.Pending });
      enqueueDeployJob(dep.id);
      req.flash('success', `App updated. Deploying branch "${branch}"...`);
      res.redirect(`/deployments/${dep.id}`);
      return;
    }

    req.flash('success', 'App updated.');
    res.redirect(`/apps/${req.params.id}`);
  }
);

router.delete('/apps/:id', async (req: Request, res: Response) => {
  const app = AppModel.findById(Number(req.params.id));
  if (!app) { res.status(404).send('Not found'); return; }

  if (existsSync(app.path)) {
    const composeFile = join(app.path, 'docker-compose.yml');
    if (existsSync(composeFile)) {
      await runComposeDown(app.path, composeFile);
    }
    await rm(app.path, { recursive: true, force: true });
  }

  AppModel.remove(String(app.id));
  req.flash('success', 'App removed.');
  res.redirect('/');
});

router.get('/apps/:id/env', (req: Request, res: Response) => {
  const app = AppModel.findById(Number(req.params.id));
  if (!app) { res.status(404).json({ error: 'Not found' }); return; }
  const envPath = join(app.path, '.env');
  try {
    const content = existsSync(envPath) ? readFileSync(envPath, 'utf-8') : '';
    res.json({ content });
  } catch {
    res.status(500).json({ error: 'Could not read .env file' });
  }
});

router.post('/apps/:id/env', (req: Request, res: Response) => {
  const app = AppModel.findById(Number(req.params.id));
  if (!app) { res.status(404).json({ error: 'Not found' }); return; }
  const { content } = req.body as { content?: unknown };
  if (typeof content !== 'string' || content.trim().length === 0) {
    res.status(400).json({ error: 'Content must be a non-empty string' });
    return;
  }
  const envPath = join(app.path, '.env');
  try {
    writeFileSync(envPath, content, 'utf-8');
    res.json({ ok: true });
  } catch {
    res.status(500).json({ error: 'Could not write .env file' });
  }
});

router.post('/apps/:id/deploy', (req: Request, res: Response) => {
  const app = AppModel.findById(Number(req.params.id));
  if (!app) { res.status(404).send('Not found'); return; }
  const dep = DeploymentModel.create({ app_id: app.id, status: DeploymentStatus.Pending });
  enqueueDeployJob(dep.id);
  res.redirect(`/deployments/${dep.id}`);
});

router.get('/apps/:id/containers', (req: Request, res: Response) => {
  const app = AppModel.findById(Number(req.params.id));
  if (!app) { res.status(404).json({ error: 'Not found' }); return; }
  res.json({ containers: getContainerStatus(app.path) });
});

router.post('/apps/:id/rollback', (req: Request, res: Response) => {
  const app = AppModel.findById(Number(req.params.id));
  if (!app) { res.status(404).send('Not found'); return; }

  const { deployment_id } = req.body as { deployment_id: string };
  const source = DeploymentModel.findById(Number(deployment_id));
  if (!source || source.app_id !== app.id) { res.status(404).send('Not found'); return; }
  if (!source.commit_sha) { res.status(400).send('Deployment has no commit SHA — cannot rollback.'); return; }

  const dep = DeploymentModel.create({
    app_id: app.id,
    status: DeploymentStatus.Pending,
    rollback_sha: source.commit_sha,
  });
  enqueueDeployJob(dep.id);
  res.redirect(`/deployments/${dep.id}`);
});

router.post('/apps/:id/webhook', (req: Request, res: Response) => {
  const app = AppModel.findById(Number(req.params.id));
  if (!app) { res.status(404).json({ error: 'Not found' }); return; }
  if (!app.webhook_secret) { res.status(400).json({ error: 'No webhook secret configured.' }); return; }

  const rawBody = Buffer.isBuffer(req.body) ? req.body : Buffer.from(JSON.stringify(req.body));
  const sig = req.headers['x-hub-signature-256'] as string | undefined;
  if (!sig) { res.status(401).json({ error: 'Missing signature' }); return; }

  const expected = 'sha256=' + createHmac('sha256', app.webhook_secret).update(rawBody).digest('hex');
  const expectedBuf = Buffer.from(expected);
  const receivedBuf = Buffer.from(sig);
  const valid = expectedBuf.length === receivedBuf.length &&
    timingSafeEqual(expectedBuf, receivedBuf);
  if (!valid) { res.status(401).json({ error: 'Invalid signature' }); return; }

  let payload: { ref?: string } = {};
  try { payload = JSON.parse(rawBody.toString()); } catch { /* ignore */ }
  const pushedBranch = payload.ref?.replace('refs/heads/', '');
  if (pushedBranch && pushedBranch !== app.branch) {
    res.json({ skipped: true, reason: `Push was to ${pushedBranch}, app tracks ${app.branch}` });
    return;
  }

  const dep = DeploymentModel.create({ app_id: app.id, status: DeploymentStatus.Pending });
  enqueueDeployJob(dep.id);
  res.status(204).send();
});

router.post('/apps/:id/webhook-secret', (req: Request, res: Response) => {
  const app = AppModel.findById(Number(req.params.id));
  if (!app) { res.status(404).json({ error: 'Not found' }); return; }
  const secret = randomBytes(32).toString('hex');
  getDb().prepare('UPDATE apps SET webhook_secret = ?, updated_at = ? WHERE id = ?')
    .run(secret, new Date().toISOString(), app.id);
  req.flash('success', 'Webhook secret regenerated.');
  res.redirect(`/apps/${app.id}/edit`);
});

export default router;
