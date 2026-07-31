import * as AppModel from '../models/app.js';
import * as HealthCheckModel from '../models/healthCheck.js';
import { HealthStatus } from '../enums.js';

export async function runHealthChecks(): Promise<void> {
  const apps = AppModel.list();
  for (const app of apps) {
    if (!app.health_url) continue;
    const start = Date.now();
    try {
      const res = await fetch(app.health_url, { signal: AbortSignal.timeout(10000) });
      const ms = Date.now() - start;
      const status = res.ok ? HealthStatus.Up : HealthStatus.Down;
      HealthCheckModel.record(app.id, status, res.status, ms);
    } catch {
      HealthCheckModel.record(app.id, HealthStatus.Down, null, null);
    }
  }
}
