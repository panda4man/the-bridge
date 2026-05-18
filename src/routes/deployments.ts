import { Router, Request, Response } from 'express';
import * as DeploymentModel from '../models/deployment.js';
import * as AppModel from '../models/app.js';
import { AppStatus, DeploymentStatus } from '../enums.js';

const router = Router();
const TERMINAL: string[] = [DeploymentStatus.Success, DeploymentStatus.Failed];

router.get('/deployments/:id', (req: Request, res: Response) => {
  const deployment = DeploymentModel.findById(Number(req.params.id));
  if (!deployment) { res.status(404).send('Not found'); return; }
  res.render('deployments/show', { deployment });
});

router.get('/deployments/:id/stream', (req: Request, res: Response) => {
  const initial = DeploymentModel.findById(Number(req.params.id));
  if (!initial) { res.status(404).end(); return; }

  res.setHeader('Content-Type', 'text/event-stream');
  res.setHeader('Cache-Control', 'no-cache');
  res.setHeader('X-Accel-Buffering', 'no');
  res.flushHeaders();

  const lastEventId = req.headers['last-event-id'];
  let offset = parseInt(Array.isArray(lastEventId) ? lastEventId[0] : (lastEventId ?? '0'), 10);
  let lastHeartbeat = Date.now();

  const interval = setInterval(() => {
    const dep = DeploymentModel.findById(Number(req.params.id));
    if (!dep) { clearInterval(interval); res.end(); return; }

    const log = dep.log ?? '';
    const chunk = log.slice(offset);
    if (chunk) {
      offset = log.length;
      res.write(`id: ${offset}\ndata: ${JSON.stringify({ text: chunk })}\n\n`);
      lastHeartbeat = Date.now();
    }

    if (TERMINAL.includes(dep.status)) {
      res.write(`data: ${JSON.stringify({ done: true, status: dep.status })}\n\n`);
      clearInterval(interval);
      res.end();
      return;
    }

    if (Date.now() - lastHeartbeat >= 5000) {
      res.write(': heartbeat\n\n');
      lastHeartbeat = Date.now();
    }
  }, 500);

  req.on('close', () => clearInterval(interval));
});

router.post('/deployments/:id/reset', (req: Request, res: Response) => {
  const dep = DeploymentModel.findById(Number(req.params.id));
  if (!dep) { res.status(404).send('Not found'); return; }

  const resetable: string[] = [DeploymentStatus.Running, DeploymentStatus.Pending];
  if (resetable.includes(dep.status)) {
    DeploymentModel.update(dep.id, { status: DeploymentStatus.Failed, finished_at: new Date().toISOString() });
    AppModel.updateStatus(dep.app_id, AppStatus.Failed);
  }

  req.flash('success', 'Deployment reset to failed.');
  res.redirect(`/deployments/${dep.id}`);
});

export default router;
