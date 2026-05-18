import { Router, Request, Response, NextFunction } from 'express';
import { copyFileSync, existsSync, readFileSync, writeFileSync } from 'fs';
import { join } from 'path';
import GitService from '../services/gitService.js';
import * as AppModel from '../models/app.js';
import * as DeploymentModel from '../models/deployment.js';
import { DeploymentStatus } from '../enums.js';
import { storeRules, validateStore, updateRules, validateUpdate } from '../validators/appValidators.js';
import { enqueueDeployJob } from '../queue.js';

const router = Router();

function reposPath(): string {
  return (process.env.REPOS_PATH || '/repos').replace(/\/$/, '');
}

router.get('/', (req: Request, res: Response) => {
  const apps = AppModel.list();
  res.render('apps/index', { apps });
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
  res.render('apps/show', { app, deployments });
});

router.get('/apps/:id/edit', (req: Request, res: Response) => {
  const app = AppModel.findById(Number(req.params.id));
  if (!app) { res.status(404).send('Not found'); return; }
  res.render('apps/edit', { app, errors: {} });
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
    const { name, repo_url, branch, path } = req.body as { name: string; repo_url: string; branch: string; path: string };
    AppModel.update(String(req.params.id), { name, repo_url, branch, path });
    req.flash('success', 'App updated.');
    res.redirect(`/apps/${req.params.id}`);
  }
);

router.delete('/apps/:id', (req: Request, res: Response) => {
  AppModel.remove(String(req.params.id));
  req.flash('success', 'App deleted.');
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

export default router;
