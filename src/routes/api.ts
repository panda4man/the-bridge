import { Router, Request, Response } from 'express';
import GitService from '../services/gitService.js';

const router = Router();

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

export default router;
