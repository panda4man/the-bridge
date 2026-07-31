import { getDb } from '../db.js';
import { AppStatus } from '../enums.js';
import type { AppRecord } from '../types.js';

export function create(data: Partial<AppRecord>): AppRecord {
  const db = getDb();
  const now = new Date().toISOString();
  const result = db.prepare(
    'INSERT INTO apps (name, repo_url, branch, path, health_url, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
  ).run(data.name, data.repo_url, data.branch, data.path, data.health_url ?? null, data.status ?? AppStatus.Idle, now, now);
  return findById(result.lastInsertRowid as number) as AppRecord;
}

export function findById(id: number | bigint): AppRecord | null {
  return (getDb().prepare('SELECT * FROM apps WHERE id = ?').get(id) as AppRecord | undefined) ?? null;
}

export function list(): AppRecord[] {
  return getDb().prepare('SELECT * FROM apps ORDER BY id DESC').all() as AppRecord[];
}

export function update(id: number | string, data: Partial<AppRecord>): AppRecord | null {
  const db = getDb();
  const now = new Date().toISOString();
  const fields = Object.keys(data).filter(k => k !== 'id') as (keyof AppRecord)[];
  if (fields.length === 0) return findById(Number(id));
  const sets = fields.map(k => `${k} = ?`).join(', ');
  const values = fields.map(k => data[k] ?? null);
  db.prepare(`UPDATE apps SET ${sets}, updated_at = ? WHERE id = ?`)
    .run(...values, now, id);
  return findById(Number(id));
}

export function updateStatus(id: number | string, status: string): void {
  getDb().prepare('UPDATE apps SET status = ?, updated_at = ? WHERE id = ?')
    .run(status, new Date().toISOString(), id);
}

export function remove(id: number | string): void {
  getDb().prepare('DELETE FROM apps WHERE id = ?').run(id);
}
