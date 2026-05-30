import { execSync } from 'child_process';
import type { ContainerInfo } from '../types.js';

export function getContainerStatus(workDir: string): ContainerInfo[] {
  try {
    const out = execSync('docker-compose ps --format json', {
      cwd: workDir,
      timeout: 8000,
    }).toString().trim();
    if (!out) return [];
    const parsed = JSON.parse(out);
    const items: Record<string, string>[] = Array.isArray(parsed) ? parsed : [parsed];
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
