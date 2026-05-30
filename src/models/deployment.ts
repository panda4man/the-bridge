import { getDb } from '../db.js';
import { DeploymentStatus } from '../enums.js';
import type { DeploymentRecord } from '../types.js';

function stripAnsi(text: string): string {
  return text.replace(/\x1b\[[0-9;]*[A-Za-z]|\r/g, '');
}

export function create(data: Partial<DeploymentRecord>): DeploymentRecord {
  const db = getDb();
  const now = new Date().toISOString();
  const result = db.prepare(
    'INSERT INTO deployments (app_id, status, log, started_at, finished_at, commit_sha, commit_message, rollback_sha, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
  ).run(
    data.app_id,
    data.status ?? DeploymentStatus.Pending,
    data.log ?? null,
    data.started_at ?? null,
    data.finished_at ?? null,
    data.commit_sha ?? null,
    data.commit_message ?? null,
    data.rollback_sha ?? null,
    now,
    now
  );
  return findById(result.lastInsertRowid as number) as DeploymentRecord;
}

export function findById(id: number | bigint): DeploymentRecord | null {
  return (getDb().prepare(`
    SELECT d.*, a.name AS app_name, a.path AS app_path, a.branch AS app_branch,
           a.repo_url AS app_repo_url, a.status AS app_status
    FROM deployments d
    JOIN apps a ON a.id = d.app_id
    WHERE d.id = ?
  `).get(id) as DeploymentRecord | undefined) ?? null;
}

export function listForApp(appId: number | string): DeploymentRecord[] {
  return getDb().prepare(
    'SELECT * FROM deployments WHERE app_id = ? ORDER BY id DESC'
  ).all(appId) as DeploymentRecord[];
}

export function update(id: number | string, data: Partial<DeploymentRecord>): DeploymentRecord | null {
  const db = getDb();
  const now = new Date().toISOString();
  const fields = Object.keys(data).filter(k => k !== 'id') as (keyof DeploymentRecord)[];
  const sets = fields.map(k => `${k} = ?`).join(', ');
  const values = fields.map(k => data[k]);
  db.prepare(`UPDATE deployments SET ${sets}, updated_at = ? WHERE id = ?`)
    .run(...values, now, id);
  return findById(Number(id));
}

export function appendLog(id: number | string, chunk: string): void {
  getDb().prepare(
    "UPDATE deployments SET log = COALESCE(log, '') || ?, updated_at = ? WHERE id = ?"
  ).run(stripAnsi(chunk), new Date().toISOString(), id);
}
