import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mkdtempSync, writeFileSync, rmSync } from 'fs';
import { tmpdir } from 'os';
import { join } from 'path';
import { resolveDeploySteps, parseUiSteps, serializeUiSteps } from './deploySteps.js';
import type { AppRecord } from '../types.js';

let workDir: string;

function makeApp(overrides: Partial<AppRecord> = {}): AppRecord {
  return {
    id: 1,
    name: 'test-app',
    path: workDir,
    repo_url: 'https://example.com/repo.git',
    branch: 'main',
    status: 'idle',
    health_url: null,
    health_check_interval: 60,
    webhook_secret: null,
    deploy_steps: null,
    created_at: new Date().toISOString(),
    updated_at: new Date().toISOString(),
    ...overrides,
  };
}

beforeEach(() => {
  workDir = mkdtempSync(join(tmpdir(), 'deploy-steps-'));
});

afterEach(() => {
  rmSync(workDir, { recursive: true, force: true });
});

describe('resolveDeploySteps', () => {
  it('returns none when no bridge.yml and no deploy_steps', () => {
    const result = resolveDeploySteps(makeApp());
    expect(result).toEqual({ steps: [], source: 'none' });
  });

  it('repo file takes precedence over UI steps', () => {
    const bridgeYml = `
post_deploy:
  - service: app
    run: php artisan migrate --force
`;
    writeFileSync(join(workDir, 'bridge.yml'), bridgeYml, 'utf-8');

    const uiSteps = JSON.stringify([{ service: 'worker', run: 'restart' }]);
    const app = makeApp({ deploy_steps: uiSteps });

    const result = resolveDeploySteps(app);
    expect(result.source).toBe('repo');
    expect(result.steps).toEqual([{ service: 'app', run: 'php artisan migrate --force' }]);
  });

  it('falls through to UI steps when bridge.yml is absent', () => {
    const uiSteps = JSON.stringify([{ service: 'worker', run: 'queue:restart' }]);
    const app = makeApp({ deploy_steps: uiSteps });

    const result = resolveDeploySteps(app);
    expect(result.source).toBe('ui');
    expect(result.steps).toEqual([{ service: 'worker', run: 'queue:restart' }]);
  });

  it('returns none when UI steps is empty JSON array', () => {
    const app = makeApp({ deploy_steps: '[]' });
    const result = resolveDeploySteps(app);
    expect(result).toEqual({ steps: [], source: 'none' });
  });

  it('parses bridge.yml with multiple steps', () => {
    const bridgeYml = `
post_deploy:
  - service: app
    run: php artisan migrate --force
  - service: app
    run: php artisan config:cache
`;
    writeFileSync(join(workDir, 'bridge.yml'), bridgeYml, 'utf-8');

    const result = resolveDeploySteps(makeApp());
    expect(result.source).toBe('repo');
    expect(result.steps).toHaveLength(2);
    expect(result.steps[0]).toEqual({ service: 'app', run: 'php artisan migrate --force' });
    expect(result.steps[1]).toEqual({ service: 'app', run: 'php artisan config:cache' });
  });

  it('throws on malformed bridge.yml (invalid YAML)', () => {
    writeFileSync(join(workDir, 'bridge.yml'), 'post_deploy: [:::\n', 'utf-8');
    expect(() => resolveDeploySteps(makeApp())).toThrow('bridge.yml parse error');
  });

  it('throws on bridge.yml with post_deploy not an array', () => {
    writeFileSync(join(workDir, 'bridge.yml'), 'post_deploy: "not an array"\n', 'utf-8');
    expect(() => resolveDeploySteps(makeApp())).toThrow('post_deploy array');
  });

  it('throws on bridge.yml entry with empty service', () => {
    const bridgeYml = `
post_deploy:
  - service: ""
    run: do something
`;
    writeFileSync(join(workDir, 'bridge.yml'), bridgeYml, 'utf-8');
    expect(() => resolveDeploySteps(makeApp())).toThrow('service must be a non-empty string');
  });

  it('throws on bridge.yml entry with empty run', () => {
    const bridgeYml = `
post_deploy:
  - service: app
    run: ""
`;
    writeFileSync(join(workDir, 'bridge.yml'), bridgeYml, 'utf-8');
    expect(() => resolveDeploySteps(makeApp())).toThrow('run must be a non-empty string');
  });

  it('throws on malformed JSON in deploy_steps', () => {
    const app = makeApp({ deploy_steps: 'not-json' });
    expect(() => resolveDeploySteps(app)).toThrow('deploy_steps JSON parse error');
  });

  it('throws on deploy_steps entry with missing service', () => {
    const app = makeApp({ deploy_steps: JSON.stringify([{ run: 'migrate' }]) });
    expect(() => resolveDeploySteps(app)).toThrow('service must be a non-empty string');
  });

  it('throws on deploy_steps entry with missing run', () => {
    const app = makeApp({ deploy_steps: JSON.stringify([{ service: 'app' }]) });
    expect(() => resolveDeploySteps(app)).toThrow('run must be a non-empty string');
  });
});

describe('parseUiSteps', () => {
  it('parses a simple service:command line', () => {
    expect(parseUiSteps('app: php artisan migrate')).toEqual([
      { service: 'app', run: 'php artisan migrate' },
    ]);
  });

  it('splits on first colon only — commands keep their colons', () => {
    expect(parseUiSteps('app: php artisan cache:clear')).toEqual([
      { service: 'app', run: 'php artisan cache:clear' },
    ]);
  });

  it('handles rails db:migrate', () => {
    expect(parseUiSteps('web: rails db:migrate')).toEqual([
      { service: 'web', run: 'rails db:migrate' },
    ]);
  });

  it('skips blank lines', () => {
    const text = 'app: migrate\n\nworker: restart\n';
    expect(parseUiSteps(text)).toEqual([
      { service: 'app', run: 'migrate' },
      { service: 'worker', run: 'restart' },
    ]);
  });

  it('skips lines without a colon', () => {
    const text = 'no colon here\napp: migrate';
    expect(parseUiSteps(text)).toEqual([{ service: 'app', run: 'migrate' }]);
  });

  it('skips lines with empty run after colon', () => {
    expect(parseUiSteps('app:')).toEqual([]);
  });

  it('returns empty array for blank input', () => {
    expect(parseUiSteps('')).toEqual([]);
    expect(parseUiSteps('   \n  \n')).toEqual([]);
  });

  it('trims whitespace from service and run', () => {
    expect(parseUiSteps('  app  :  php artisan migrate  ')).toEqual([
      { service: 'app', run: 'php artisan migrate' },
    ]);
  });
});

describe('serializeUiSteps / parseUiSteps round-trip', () => {
  it('round-trips a single step', () => {
    const steps = [{ service: 'app', run: 'php artisan migrate --force' }];
    expect(parseUiSteps(serializeUiSteps(steps))).toEqual(steps);
  });

  it('round-trips steps with colons in commands', () => {
    const steps = [
      { service: 'app', run: 'php artisan cache:clear' },
      { service: 'web', run: 'rails db:migrate' },
    ];
    expect(parseUiSteps(serializeUiSteps(steps))).toEqual(steps);
  });

  it('round-trips multiple steps', () => {
    const steps = [
      { service: 'app', run: 'php artisan migrate --force' },
      { service: 'app', run: 'php artisan config:cache' },
      { service: 'worker', run: 'queue:restart' },
    ];
    expect(parseUiSteps(serializeUiSteps(steps))).toEqual(steps);
  });

  it('round-trips empty array', () => {
    expect(parseUiSteps(serializeUiSteps([]))).toEqual([]);
  });
});
