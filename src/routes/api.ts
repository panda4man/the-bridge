import { Router, Request, Response } from 'express';
import GitService from '../services/gitService.js';
import { requireApiToken } from '../middleware/apiAuth.js';
import * as AppModel from '../models/app.js';
import * as DeploymentModel from '../models/deployment.js';
import { enqueueDeployJob } from '../queue.js';
import { DeploymentStatus } from '../enums.js';

const router = Router();

const TERMINAL: string[] = [DeploymentStatus.Success, DeploymentStatus.Failed];

router.get('/branches', async (req: Request, res: Response) => {
  const repoUrl = ((req.query['repo_url'] as string) || '').trim();
  if (!repoUrl) return res.json({ branches: [] });

  try {
    const git = new GitService();
    let branches = (await git.lsRemote(repoUrl)).sort();
    const priority = ['main', 'master'].filter((b) => branches.includes(b));
    branches = [...priority, ...branches.filter((b) => !priority.includes(b))];

    return res.json({ branches });
  } catch {
    return res.json({ branches: [], error: 'Failed to fetch branches' });
  }
});

router.get('/apps', requireApiToken, (req: Request, res: Response) => {
  const apps = AppModel.list().map((a) => ({
    id: a.id,
    name: a.name,
    branch: a.branch,
    status: a.status,
    repo_url: a.repo_url,
  }));
  res.json(apps);
});

router.post('/apps/:id/deploy', requireApiToken, (req: Request, res: Response) => {
  const app = AppModel.findById(Number(req.params.id));
  if (!app) { res.status(404).json({ error: 'Not found' }); return; }

  const dep = DeploymentModel.create({ app_id: app.id, status: DeploymentStatus.Pending });
  enqueueDeployJob(dep.id);
  res.status(202).json({ deployment_id: dep.id, app_id: app.id, status: dep.status });
});

router.get('/deployments/:id', requireApiToken, (req: Request, res: Response) => {
  const dep = DeploymentModel.findById(Number(req.params.id));
  if (!dep) { res.status(404).json({ error: 'Not found' }); return; }

  res.json({
    id: dep.id,
    app_id: dep.app_id,
    app_name: dep.app_name,
    status: dep.status,
    commit_sha: dep.commit_sha,
    commit_message: dep.commit_message,
    started_at: dep.started_at,
    finished_at: dep.finished_at,
    log_length: (dep.log ?? '').length,
  });
});

router.get('/deployments/:id/log', requireApiToken, (req: Request, res: Response) => {
  const dep = DeploymentModel.findById(Number(req.params.id));
  if (!dep) { res.status(404).json({ error: 'Not found' }); return; }

  const log = dep.log ?? '';
  const offset = Math.max(0, parseInt((req.query['offset'] as string) ?? '0', 10) || 0);
  const chunk = log.slice(offset);

  res.setHeader('Content-Type', 'text/plain; charset=utf-8');
  res.setHeader('X-Log-Offset', String(log.length));
  res.setHeader('X-Deploy-Status', dep.status);
  res.setHeader('X-Deploy-Done', TERMINAL.includes(dep.status) ? 'true' : 'false');
  res.send(chunk);
});

export default router;
