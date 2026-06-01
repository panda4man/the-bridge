import { existsSync } from 'fs';
import simpleGit from 'simple-git';

export default class GitService {
  private _sshKeyPath: string;

  constructor(sshKeyPath: string | null = null) {
    this._sshKeyPath = sshKeyPath ?? process.env.BRIDGE_SSH_KEY_PATH ?? '/data/ssh/id_rsa';
  }

  private _sshEnv(): Record<string, string> {
    if (this._sshKeyPath && existsSync(this._sshKeyPath)) {
      return { GIT_SSH_COMMAND: `ssh -i ${this._sshKeyPath} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null` };
    }
    return {};
  }

  async clone(repoUrl: string, targetPath: string, branch: string): Promise<string> {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const git = simpleGit({ env: { ...process.env, ...this._sshEnv() } } as any);
    try {
      await git.clone(repoUrl, targetPath, ['--branch', branch, '-c', 'safe.directory=*']);
      return `Cloned ${repoUrl} into ${targetPath}`;
    } catch (err) {
      throw new Error(err instanceof Error ? err.message : String(err));
    }
  }

  async lsRemote(repoUrl: string): Promise<string[]> {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const git = simpleGit({ env: { ...process.env, ...this._sshEnv() } } as any);
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
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const git = simpleGit({ baseDir: repoPath, env: { ...process.env, ...this._sshEnv() } } as any);
    await git.raw(['config', 'safe.directory', '*']).catch(() => {});
    try {
      const output = await git.raw(['pull', 'origin', branch]);
      return output.trim() || `Pulled ${branch} in ${repoPath}`;
    } catch (err) {
      throw new Error(err instanceof Error ? err.message : String(err));
    }
  }
}
