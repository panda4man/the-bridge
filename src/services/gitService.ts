import { existsSync } from 'fs';
import simpleGit, { SimpleGit } from 'simple-git';

export default class GitService {
  private _sshKeyPath: string;

  constructor(sshKeyPath: string | null = null) {
    this._sshKeyPath = sshKeyPath ?? process.env.BRIDGE_SSH_KEY_PATH ?? '/data/ssh/id_rsa';
  }

  // simple-git ignores `env` passed to the factory, so the SSH command must be
  // applied via .env() — which is guarded behind allowUnsafeSshCommand. The
  // single-arg .env() form merges with process.env, preserving PATH etc.
  private _git(baseDir?: string): SimpleGit {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const opts: any = { unsafe: { allowUnsafeSshCommand: true } };
    if (baseDir) opts.baseDir = baseDir;
    const git = simpleGit(opts);
    if (this._sshKeyPath && existsSync(this._sshKeyPath)) {
      git.env(
        'GIT_SSH_COMMAND',
        `ssh -i ${this._sshKeyPath} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null`
      );
    }
    return git;
  }

  async clone(repoUrl: string, targetPath: string, branch: string): Promise<string> {
    const git = this._git();
    try {
      await git.clone(repoUrl, targetPath, ['--branch', branch, '-c', 'safe.directory=*']);
      return `Cloned ${repoUrl} into ${targetPath}`;
    } catch (err) {
      throw new Error(err instanceof Error ? err.message : String(err));
    }
  }

  async lsRemote(repoUrl: string): Promise<string[]> {
    const git = this._git();
    const output = await git.listRemote(['--heads', repoUrl]);
    return output
      .split('\n')
      .map((line) => line.trim())
      .filter(Boolean)
      .map((line) => line.split('\t')[1] || '')
      .filter((ref) => ref.startsWith('refs/heads/'))
      .map((ref) => ref.slice('refs/heads/'.length));
  }

  async pull(repoPath: string, branch: string): Promise<string> {
    const git = this._git(repoPath);
    await git.raw(['config', 'safe.directory', '*']).catch(() => {});
    const log: string[] = [];
    try {
      const fetchOut = await git.raw(['fetch', 'origin', branch]);
      if (fetchOut.trim()) log.push(fetchOut.trim());

      const currentBranch = (await git.raw(['rev-parse', '--abbrev-ref', 'HEAD'])).trim();
      if (currentBranch !== branch) {
        const localBranches = await git.raw(['branch', '--list', branch]);
        const checkoutOut = localBranches.trim()
          ? await git.raw(['checkout', branch])
          : await git.raw(['checkout', '-b', branch, '--track', `origin/${branch}`]);
        if (checkoutOut.trim()) log.push(checkoutOut.trim());
      }

      const pullOut = await git.raw(['pull', 'origin', branch]);
      log.push(pullOut.trim() || `Pulled ${branch} in ${repoPath}`);
      return log.join('\n');
    } catch (err) {
      throw new Error(err instanceof Error ? err.message : String(err));
    }
  }
}
