import { execSync } from 'child_process';
import type { ContainerInfo } from '../types.js';

export function getContainerStatus(workDir: string): ContainerInfo[] {
  try {
    const out = execSync('docker compose ps --format json', {
      cwd: workDir,
      timeout: 8000,
    }).toString().trim();
    if (!out) return [];
    // docker compose v2 emits NDJSON (one object per line), not a JSON array.
    const items: Record<string, string>[] = [];
    for (const line of out.split('\n')) {
      const trimmed = line.trim();
      if (!trimmed) continue;
      const parsed = JSON.parse(trimmed);
      if (Array.isArray(parsed)) items.push(...parsed);
      else items.push(parsed);
    }
    return items.map(c => ({
      name: c.Name ?? '',
      service: c.Service ?? '',
      state: (c.State ?? 'unknown').toLowerCase(),
      status: c.Status ?? '',
      ports: c.Ports ?? '',
    }));
  } catch {
    return [];
  }
}
