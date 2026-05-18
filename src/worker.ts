import { openDb } from './db.js';
import { deployApp } from './jobs/deployApp.js';
import type { JobRecord } from './types.js';

const db = openDb();

console.log(`Queue worker started (PID ${process.pid}). CTRL+C or kill ${process.pid} to stop.`);

let running = true;

process.on('SIGTERM', () => {
  running = false;
  process.exit(0);
});

async function processJob(job: JobRecord): Promise<void> {
  const payload = JSON.parse(job.payload) as { type: string; deploymentId: number };
  if (payload.type === 'deploy') {
    await deployApp(payload.deploymentId);
  }
}

async function poll(): Promise<void> {
  while (running) {
    const job = db.prepare(
      'SELECT * FROM jobs WHERE reserved_at IS NULL AND available_at <= ? ORDER BY available_at LIMIT 1'
    ).get(Date.now()) as JobRecord | undefined;

    if (job) {
      db.prepare('UPDATE jobs SET reserved_at = ? WHERE id = ?').run(Date.now(), job.id);
      try {
        await processJob(job);
        db.prepare('DELETE FROM jobs WHERE id = ?').run(job.id);
      } catch (err) {
        console.error(`Job ${job.id} failed:`, err instanceof Error ? err.message : String(err));
        db.prepare('UPDATE jobs SET reserved_at = NULL, attempts = attempts + 1 WHERE id = ?').run(job.id);
      }
    }

    await new Promise(r => setTimeout(r, 1000));
  }
}

poll().catch(console.error);
