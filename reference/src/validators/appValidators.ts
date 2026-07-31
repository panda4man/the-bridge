import { body, validationResult } from 'express-validator';
import { existsSync } from 'fs';
import { join } from 'path';
import type { Request, Response, NextFunction, RequestHandler } from 'express';
import { getDb } from '../db.js';
import { resolveDeploySteps, serializeUiSteps } from '../services/deploySteps.js';

function reposPath(): string {
  return (process.env.REPOS_PATH || '/repos').replace(/\/$/, '');
}

export function fullPath(relative: string): string {
  return join(reposPath(), relative);
}

export const storeRules: RequestHandler[] = [
  body('name').trim().notEmpty().withMessage('Name is required').isLength({ max: 255 }),
  body('repo_url').trim().notEmpty().withMessage('Repo URL is required').isLength({ max: 500 }),
  body('branch').trim().notEmpty().withMessage('Branch is required').isLength({ max: 255 }),
  body('path')
    .trim()
    .notEmpty()
    .withMessage('Path is required')
    .isLength({ max: 255 })
    .not().matches(/\.\./)
    .withMessage('Path must not contain ..'),
];

export function validateStore(req: Request, res: Response, next: NextFunction): void {
  const errors = validationResult(req);
  if (!errors.isEmpty()) {
    res.status(422).render('apps/create', {
      errors: errors.mapped(),
      old: req.body,
      reposPath: reposPath(),
    });
    return;
  }

  const rel = req.body.path as string;
  const full = fullPath(rel);
  const skip = req.body.skip_clone === '1' || req.body.skip_clone === 'true';

  const existing = getDb().prepare('SELECT id FROM apps WHERE path = ?').get(full);
  if (existing) {
    res.status(422).render('apps/create', {
      errors: { path: { msg: 'An app already uses this path.' } },
      old: req.body,
      reposPath: reposPath(),
    });
    return;
  }

  if (skip) {
    if (!existsSync(full)) {
      res.status(422).render('apps/create', {
        errors: { path: { msg: 'Directory does not exist at this path.' } },
        old: req.body,
        reposPath: reposPath(),
      });
      return;
    }
    if (!existsSync(join(full, '.git'))) {
      res.status(422).render('apps/create', {
        errors: { path: { msg: 'Directory exists but is not a git repository.' } },
        old: req.body,
        reposPath: reposPath(),
      });
      return;
    }
  } else {
    if (existsSync(full)) {
      res.status(422).render('apps/create', {
        errors: { path: { msg: 'Directory already exists on disk.' } },
        old: req.body,
        reposPath: reposPath(),
      });
      return;
    }
  }

  req.fullPath = full;
  req.skipClone = skip;
  next();
}

export const updateRules: RequestHandler[] = [
  body('name').trim().notEmpty().withMessage('Name is required').isLength({ max: 255 }),
  body('repo_url').trim().notEmpty().withMessage('Repo URL is required').isLength({ max: 500 }),
  body('branch').trim().notEmpty().withMessage('Branch is required').isLength({ max: 255 }),
  body('path').trim().notEmpty().withMessage('Path is required').isLength({ max: 500 }),
];

export function validateUpdate(req: Request, res: Response, next: NextFunction): void {
  const errors = validationResult(req);
  if (!errors.isEmpty()) {
    const app = req.app_record!;
    const sourceExists = existsSync(app.path);
    let repoStepsActive = false;
    let resolvedSteps: import('../types.js').DeployStep[] = [];
    try {
      const resolved = resolveDeploySteps(app);
      repoStepsActive = resolved.source === 'repo';
      resolvedSteps = resolved.steps;
    } catch { /* ignore */ }
    const uiStepsText = repoStepsActive
      ? serializeUiSteps(resolvedSteps)
      : (app.deploy_steps ? serializeUiSteps(JSON.parse(app.deploy_steps)) : '');
    res.status(422).render('apps/edit', {
      errors: errors.mapped(),
      app,
      sourceExists,
      repoStepsActive,
      resolvedSteps,
      uiStepsText,
    });
    return;
  }
  next();
}
