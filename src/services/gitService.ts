import { existsSync } from 'fs';
import simpleGit from 'simple-git';

export default class GitService {
  private _sshKeyPath: string;

  constructor(sshKeyPath: string | null = null) {
    this._sshKeyPath = sshKeyPath ?? process.env.BRIDGE_SSH_KEY_PATH ?? '/data/ssh/id_rsa';
  }

  private _sshEnv(): Record<string, string> {
    if (this._sshKeyPath && existsSync(this._sshKeyPath)) {
      return { GIT_SSH_COMMAND: `ssh -i ${this._sshKeyPath} -o StrictHostKeyChecking=no` };
    }
    return {};
  }

  async clone(repoUrl: string, targetPath: string, branch: string): Promise<string> {
    const git = simpleGit({ env: { ...process.env, ...this._sshEnv() } as NodeJS.ProcessEnv });
    try {
      await git.clone(repoUrl, targetPath, ['--branch', branch, '-c', 'safe.directory=*']);
      return `Cloned ${repoUrl} into ${targetPath}`;
    } catch (err) {
      throw new Error(err instanceof Error ? err.message : String(err));
    }
  }

  async pull(repoPath: string, branch: string): Promise<string> {
    const git = simpleGit({
      baseDir: repoPath,
      env: { ...process.env, ...this._sshEnv() } as NodeJS.ProcessEnv,
    });
    await git.raw(['config', 'safe.directory', '*']).catch(() => {});
    try {
      await git.pull('origin', branch);
      return `Pulled ${branch} in ${repoPath}`;
    } catch (err) {
      throw new Error(err instanceof Error ? err.message : String(err));
    }
  }
}
