import { getDb } from './db.js';

export function enqueueDeployJob(deploymentId: number): void {
  getDb().prepare(
    `INSERT INTO jobs (queue, payload, attempts, available_at, created_at)
     VALUES ('default', ?, 0, ?, ?)`
  ).run(
    JSON.stringify({ type: 'deploy', deploymentId }),
    Date.now(),
    Date.now()
  );
}
