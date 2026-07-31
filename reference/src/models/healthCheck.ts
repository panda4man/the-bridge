import { getDb } from '../db.js';
import type { HealthCheckRecord } from '../types.js';

export function record(
  appId: number,
  status: string,
  httpStatusCode: number | null,
  responseTimeMs: number | null,
): void {
  const db = getDb();
  db.prepare(
    'INSERT INTO health_checks (app_id, status, http_status_code, response_time_ms) VALUES (?, ?, ?, ?)'
  ).run(appId, status, httpStatusCode, responseTimeMs);
  // prune to 20 most recent
  db.prepare(
    'DELETE FROM health_checks WHERE app_id = ? AND id NOT IN (SELECT id FROM health_checks WHERE app_id = ? ORDER BY id DESC LIMIT 20)'
  ).run(appId, appId);
}

export function findLatest(appId: number): HealthCheckRecord | null {
  return (getDb().prepare(
    'SELECT * FROM health_checks WHERE app_id = ? ORDER BY id DESC LIMIT 1'
  ).get(appId) as HealthCheckRecord | undefined) ?? null;
}

export function listRecent(appId: number): HealthCheckRecord[] {
  return getDb().prepare(
    'SELECT * FROM health_checks WHERE app_id = ? ORDER BY id DESC LIMIT 20'
  ).all(appId) as HealthCheckRecord[];
}

export function consecutiveFailures(appId: number): number {
  const rows = getDb().prepare(
    "SELECT status FROM health_checks WHERE app_id = ? ORDER BY id DESC LIMIT 20"
  ).all(appId) as { status: string }[];
  let count = 0;
  for (const row of rows) {
    if (row.status !== 'up') count++;
    else break;
  }
  return count;
}
