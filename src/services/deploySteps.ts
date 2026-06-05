import { existsSync, readFileSync } from 'fs';
import { join } from 'path';
import yaml from 'js-yaml';
import type { AppRecord, DeployStep } from '../types.js';

export interface ResolvedSteps {
  steps: DeployStep[];
  source: 'repo' | 'ui' | 'none';
}

/**
 * Resolves which post-deploy steps to run for an app.
 *
 * Priority: repo file (bridge.yml) > UI/DB field > none.
 *
 * Rollback edge case (intentional): after `git checkout <rollback_sha>` to a SHA
 * that predates bridge.yml, the repo file is absent, so resolution falls through to
 * UI steps. This is correct — repo file wins when present, UI when absent.
 */
export function resolveDeploySteps(app: AppRecord): ResolvedSteps {
  const bridgeYmlPath = join(app.path, 'bridge.yml');

  if (existsSync(bridgeYmlPath)) {
    let raw: string;
    try {
      raw = readFileSync(bridgeYmlPath, 'utf-8');
    } catch (err) {
      throw new Error(`post-deploy: failed to read bridge.yml: ${err instanceof Error ? err.message : String(err)}`);
    }

    let doc: unknown;
    try {
      doc = yaml.load(raw);
    } catch (err) {
      throw new Error(`post-deploy: bridge.yml parse error: ${err instanceof Error ? err.message : String(err)}`);
    }

    if (!doc || typeof doc !== 'object') {
      throw new Error('post-deploy: bridge.yml must be a YAML mapping');
    }

    const postDeploy = (doc as Record<string, unknown>).post_deploy;
    if (!Array.isArray(postDeploy)) {
      throw new Error('post-deploy: bridge.yml must have a post_deploy array');
    }

    const steps = validateSteps(postDeploy, 'bridge.yml');
    return { steps, source: 'repo' };
  }

  if (app.deploy_steps) {
    let parsed: unknown;
    try {
      parsed = JSON.parse(app.deploy_steps);
    } catch (err) {
      throw new Error(`post-deploy: deploy_steps JSON parse error: ${err instanceof Error ? err.message : String(err)}`);
    }

    if (!Array.isArray(parsed) || parsed.length === 0) {
      return { steps: [], source: 'none' };
    }

    const steps = validateSteps(parsed, 'deploy_steps');
    return { steps, source: 'ui' };
  }

  return { steps: [], source: 'none' };
}

function validateSteps(entries: unknown[], source: string): DeployStep[] {
  return entries.map((entry, i) => {
    if (!entry || typeof entry !== 'object') {
      throw new Error(`post-deploy: ${source}[${i}] must be an object with service and run`);
    }
    const e = entry as Record<string, unknown>;
    if (typeof e.service !== 'string' || e.service.trim() === '') {
      throw new Error(`post-deploy: ${source}[${i}].service must be a non-empty string`);
    }
    if (typeof e.run !== 'string' || e.run.trim() === '') {
      throw new Error(`post-deploy: ${source}[${i}].run must be a non-empty string`);
    }
    return { service: e.service.trim(), run: e.run.trim() };
  });
}

/**
 * Serialize DeployStep[] to the plain-text textarea format (one step per line).
 * Format: `service: command`
 */
export function serializeUiSteps(steps: DeployStep[]): string {
  return steps.map(s => `${s.service}: ${s.run}`).join('\n');
}

/**
 * Parse the edit-form textarea to DeployStep[].
 * Splits on the FIRST colon only so commands keep theirs
 * (e.g. `php artisan cache:clear`, `rails db:migrate`).
 * Blank lines and lines without a colon are skipped.
 */
export function parseUiSteps(text: string): DeployStep[] {
  const steps: DeployStep[] = [];
  for (const line of text.split('\n')) {
    const trimmed = line.trim();
    if (!trimmed) continue;
    const i = trimmed.indexOf(':');
    if (i < 0) continue;
    const service = trimmed.slice(0, i).trim();
    const run = trimmed.slice(i + 1).trim();
    if (!service || !run) continue;
    steps.push({ service, run });
  }
  return steps;
}
