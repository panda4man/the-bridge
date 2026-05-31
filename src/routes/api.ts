import { Router, Request, Response } from 'express';

const router = Router();

function parseRepoUrl(repoUrl: string): { host: string; owner: string; repo: string } | null {
  const httpsMatch = repoUrl.match(/https?:\/\/([^/]+)\/([^/]+)\/([^/]+?)(?:\.git)?\/?$/);
  if (httpsMatch) {
    return { host: httpsMatch[1], owner: httpsMatch[2], repo: httpsMatch[3] };
  }
  const sshMatch = repoUrl.match(/git@([^:]+):([^/]+)\/([^/]+?)(?:\.git)?$/);
  if (sshMatch) {
    return { host: sshMatch[1], owner: sshMatch[2], repo: sshMatch[3] };
  }
  return null;
}

router.get('/branches', async (req: Request, res: Response) => {
  const repoUrl = ((req.query['repo_url'] as string) || '').trim();
  if (!repoUrl) return res.json({ branches: [] });

  const parsed = parseRepoUrl(repoUrl);
  if (!parsed) return res.json({ branches: [], error: 'Could not parse repo URL' });

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 10000);

  try {
    let apiUrl: string;
    const headers: Record<string, string> = { 'User-Agent': 'the-bridge/1.0' };

    if (parsed.host === 'github.com') {
      apiUrl = `https://api.github.com/repos/${parsed.owner}/${parsed.repo}/branches?per_page=100`;
      headers['Accept'] = 'application/vnd.github+json';
    } else if (parsed.host === 'gitlab.com') {
      const encodedPath = encodeURIComponent(`${parsed.owner}/${parsed.repo}`);
      apiUrl = `https://gitlab.com/api/v4/projects/${encodedPath}/repository/branches?per_page=100`;
    } else {
      return res.json({ branches: [] });
    }

    const response = await fetch(apiUrl, { headers, signal: controller.signal });
    if (!response.ok) return res.json({ branches: [], error: `Provider returned ${response.status}` });

    const data = (await response.json()) as Array<{ name: string }>;
    let branches = data.map((b) => b.name).sort();
    const priority = ['main', 'master'].filter((b) => branches.includes(b));
    branches = [...priority, ...branches.filter((b) => !priority.includes(b))];

    return res.json({ branches });
  } catch {
    return res.json({ branches: [], error: 'Failed to fetch branches' });
  } finally {
    clearTimeout(timeout);
  }
});

export default router;
