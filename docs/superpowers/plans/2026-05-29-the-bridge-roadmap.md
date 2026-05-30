# The Bridge — Feature Roadmap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement 6 features — container status, health checks, rollback, auto-deploy webhooks, Slack notifications, and LCARS UI — each independently shippable.

**Architecture:** Schema changes via additive `ALTER TABLE ADD COLUMN` (wrapped in try-catch for idempotency) plus `CREATE TABLE IF NOT EXISTS` additions for new tables. Worker extended with a health-check poller loop. Express routes extended with new endpoints. LCARS theming via `public/lcars.css` layered over Tailwind layout utilities.

**Tech Stack:** TypeScript, Express, better-sqlite3, EJS, Alpine.js, Tailwind CDN (layout utilities only), CSS custom properties for LCARS theming.

---

## File Map

| File | Action | Purpose |
|------|--------|---------|
| `src/db.ts` | Modify | Add all schema changes (ALTER TABLE + new tables) |
| `src/types.ts` | Modify | Add `ContainerInfo`, `HealthCheckRecord`, extend `AppRecord` + `DeploymentRecord` |
| `src/enums.ts` | Modify | Add `HealthStatus` enum |
| `src/models/healthCheck.ts` | Create | CRUD for `health_checks` table |
| `src/models/settings.ts` | Create | Key/value settings model |
| `src/services/containerStatus.ts` | Create | Run `docker-compose ps --format json`, parse output |
| `src/services/slackNotifier.ts` | Create | POST deployment result to Slack webhook |
| `src/routes/apps.ts` | Modify | Add containers, webhook, rollback endpoints |
| `src/routes/settings.ts` | Create | GET/POST `/settings` |
| `src/app.ts` | Modify | Register settings route, add static middleware |
| `src/worker.ts` | Modify | Add health-check poller loop |
| `src/jobs/deployApp.ts` | Modify | Capture commit SHA; support `rollback_sha`; call Slack notifier |
| `src/views/layouts/header.ejs` | Modify | Link `lcars.css`, theme toggle, LCARS nav |
| `src/views/layouts/footer.ejs` | Modify | Link `theme.js` |
| `src/views/apps/index.ejs` | Modify | LCARS classes + health badge |
| `src/views/apps/show.ejs` | Modify | LCARS classes + containers section + rollback buttons |
| `src/views/apps/create.ejs` | Modify | LCARS classes |
| `src/views/apps/edit.ejs` | Modify | LCARS classes + webhook section + health_url field |
| `src/views/deployments/show.ejs` | Modify | LCARS classes |
| `src/views/settings/index.ejs` | Create | Slack webhook URL form |
| `public/lcars.css` | Create | CSS custom properties + LCARS component classes |
| `public/theme.js` | Create | Read/write `localStorage` theme preference |

---

## Task 1: Container Status Dashboard

**Files:**
- Create: `src/services/containerStatus.ts`
- Create: `tests/Feature/containerStatus.test.ts`
- Modify: `src/types.ts`
- Modify: `src/routes/apps.ts`
- Modify: `src/views/apps/show.ejs`

- [ ] **Step 1: Add `ContainerInfo` type**

In `src/types.ts`, add after `PortBinding`:

```typescript
export interface ContainerInfo {
  name: string;
  service: string;
  state: string;
  status: string;
  ports: string;
}
```

- [ ] **Step 2: Create `src/services/containerStatus.ts`**

```typescript
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
```

- [ ] **Step 3: Write failing test**

Create `tests/Feature/containerStatus.test.ts`:

```typescript
import request from 'supertest';
import { vi } from 'vitest';
import * as AppModel from '../../src/models/app.js';

vi.mock('../../src/services/containerStatus.js', () => ({
  getContainerStatus: vi.fn().mockReturnValue([
    { name: 'myapp-web-1', service: 'web', state: 'running', status: 'Up 2 hours', ports: '0.0.0.0:3000->3000/tcp' },
  ]),
}));

vi.mock('../../src/services/gitService.js', () => ({
  default: vi.fn().mockImplementation(() => ({
    clone: vi.fn().mockResolvedValue(''),
    pull: vi.fn().mockResolvedValue(''),
  })),
}));

test('GET /apps/:id/containers returns container list', async () => {
  const app = await import('../../src/app.js').then(m => m.default);
  const rec = AppModel.create({ name: 'C', repo_url: 'r', branch: 'main', path: '/c' });
  const res = await request(app).get(`/apps/${rec.id}/containers`);
  expect(res.status).toBe(200);
  expect(res.body.containers).toHaveLength(1);
  expect(res.body.containers[0].state).toBe('running');
});

test('GET /apps/:id/containers returns 404 for unknown app', async () => {
  const app = await import('../../src/app.js').then(m => m.default);
  const res = await request(app).get('/apps/99999/containers');
  expect(res.status).toBe(404);
});
```

- [ ] **Step 4: Run test — expect FAIL**

```bash
npx vitest run tests/Feature/containerStatus.test.ts
```

Expected: FAIL — `GET /apps/:id/containers` route does not exist.

- [ ] **Step 5: Add `GET /apps/:id/containers` to `src/routes/apps.ts`**

Add this import at top of `src/routes/apps.ts`:

```typescript
import { getContainerStatus } from '../services/containerStatus.js';
```

Add this route before `export default router`:

```typescript
router.get('/apps/:id/containers', (req: Request, res: Response) => {
  const app = AppModel.findById(Number(req.params.id));
  if (!app) { res.status(404).json({ error: 'Not found' }); return; }
  res.json({ containers: getContainerStatus(app.path) });
});
```

- [ ] **Step 6: Run test — expect PASS**

```bash
npx vitest run tests/Feature/containerStatus.test.ts
```

Expected: PASS

- [ ] **Step 7: Add containers section to `src/views/apps/show.ejs`**

Add after the closing `</div>` of the app info card and before the Deploy History heading:

```html
<h2 class="font-semibold mb-3 mt-6">Containers</h2>
<div x-data="{ containers: [], loaded: false, async load() { const r = await fetch('/apps/<%= app.id %>/containers'); const d = await r.json(); this.containers = d.containers || []; this.loaded = true; } }" x-init="load()">
  <div x-show="!loaded" class="text-sm text-gray-400">Loading…</div>
  <div x-show="loaded && containers.length === 0" class="text-sm text-gray-400">No containers found (app may not be running).</div>
  <template x-for="c in containers" :key="c.name">
    <div class="bg-white rounded shadow p-3 mb-2 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <span class="font-mono text-sm" x-text="c.service"></span>
        <span class="text-xs px-2 py-1 rounded"
          :class="{
            'bg-blue-100 text-blue-800':   c.state === 'running',
            'bg-red-100 text-red-800':     c.state === 'exited',
            'bg-yellow-100 text-yellow-800': c.state === 'restarting',
            'bg-gray-100 text-gray-600':   !['running','exited','restarting'].includes(c.state)
          }"
          x-text="c.state"></span>
        <span class="text-xs text-gray-400 font-mono" x-text="c.status"></span>
      </div>
      <span class="text-xs text-gray-400 font-mono" x-text="c.ports"></span>
    </div>
  </template>
</div>
```

- [ ] **Step 8: Commit**

```bash
git add src/types.ts src/services/containerStatus.ts src/routes/apps.ts src/views/apps/show.ejs tests/Feature/containerStatus.test.ts
git commit -m "feat: add container status dashboard to app detail page"
```

---

## Task 2: Health Checks — Schema, Types, Model

**Files:**
- Modify: `src/db.ts`
- Modify: `src/types.ts`
- Modify: `src/enums.ts`
- Create: `src/models/healthCheck.ts`
- Create: `tests/Unit/healthCheck.test.ts`

- [ ] **Step 1: Write failing model test**

Create `tests/Unit/healthCheck.test.ts`:

```typescript
import * as AppModel from '../../src/models/app.js';
import * as HealthCheckModel from '../../src/models/healthCheck.js';

test('create and findLatest for app', () => {
  const app = AppModel.create({ name: 'HC', repo_url: 'r', branch: 'main', path: '/hc' });
  HealthCheckModel.record(app.id, 'up', 200, 120);
  const latest = HealthCheckModel.findLatest(app.id);
  expect(latest).not.toBeNull();
  expect(latest!.status).toBe('up');
  expect(latest!.http_status_code).toBe(200);
  expect(latest!.response_time_ms).toBe(120);
});

test('listRecent returns up to 20 results newest-first', () => {
  const app = AppModel.create({ name: 'HC2', repo_url: 'r', branch: 'main', path: '/hc2' });
  for (let i = 0; i < 25; i++) HealthCheckModel.record(app.id, 'up', 200, i * 10);
  const results = HealthCheckModel.listRecent(app.id);
  expect(results.length).toBe(20);
});

test('consecutiveFailures counts only trailing failures', () => {
  const app = AppModel.create({ name: 'HC3', repo_url: 'r', branch: 'main', path: '/hc3' });
  HealthCheckModel.record(app.id, 'up', 200, 50);
  HealthCheckModel.record(app.id, 'down', 0, 0);
  HealthCheckModel.record(app.id, 'down', 0, 0);
  expect(HealthCheckModel.consecutiveFailures(app.id)).toBe(2);
});
```

- [ ] **Step 2: Run test — expect FAIL**

```bash
npx vitest run tests/Unit/healthCheck.test.ts
```

Expected: FAIL — module not found.

- [ ] **Step 3: Add schema changes to `src/db.ts`**

In the `bootstrapSchema` function, add inside the `db.exec(...)` call after the `jobs` table:

```sql
CREATE TABLE IF NOT EXISTS health_checks (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  app_id           INTEGER NOT NULL REFERENCES apps(id) ON DELETE CASCADE,
  status           TEXT NOT NULL DEFAULT 'unknown',
  http_status_code INTEGER,
  response_time_ms INTEGER,
  checked_at       TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS health_checks_app_id_idx ON health_checks(app_id);

CREATE TABLE IF NOT EXISTS settings (
  key   TEXT PRIMARY KEY,
  value TEXT
);
```

Also add after the `db.exec(...)` call, to handle existing databases:

```typescript
  const migrations: string[] = [
    'ALTER TABLE apps ADD COLUMN health_url TEXT',
    'ALTER TABLE apps ADD COLUMN health_check_interval INTEGER NOT NULL DEFAULT 60',
    'ALTER TABLE apps ADD COLUMN webhook_secret TEXT',
    'ALTER TABLE deployments ADD COLUMN commit_sha TEXT',
    'ALTER TABLE deployments ADD COLUMN commit_message TEXT',
    'ALTER TABLE deployments ADD COLUMN rollback_sha TEXT',
  ];
  for (const sql of migrations) {
    try { db.exec(sql); } catch { /* column already exists */ }
  }
```

- [ ] **Step 4: Add types to `src/types.ts`**

Extend `AppRecord`:

```typescript
export interface AppRecord {
  id: number;
  name: string;
  path: string;
  repo_url: string;
  branch: string;
  status: string;
  health_url: string | null;
  health_check_interval: number;
  webhook_secret: string | null;
  created_at: string;
  updated_at: string;
}
```

Extend `DeploymentRecord`:

```typescript
export interface DeploymentRecord {
  id: number;
  app_id: number;
  status: string;
  log: string | null;
  started_at: string | null;
  finished_at: string | null;
  commit_sha: string | null;
  commit_message: string | null;
  rollback_sha: string | null;
  created_at: string;
  updated_at: string;
  app_name?: string;
  app_path?: string;
  app_branch?: string;
  app_repo_url?: string;
  app_status?: string;
}
```

Add new type after `JobRecord`:

```typescript
export interface HealthCheckRecord {
  id: number;
  app_id: number;
  status: string;
  http_status_code: number | null;
  response_time_ms: number | null;
  checked_at: string;
}

export interface SettingRecord {
  key: string;
  value: string;
}
```

- [ ] **Step 5: Add `HealthStatus` enum to `src/enums.ts`**

```typescript
export const HealthStatus = Object.freeze({
  Up: 'up',
  Down: 'down',
  Unknown: 'unknown',
} as const);

export type HealthStatusValue = typeof HealthStatus[keyof typeof HealthStatus];
```

- [ ] **Step 6: Create `src/models/healthCheck.ts`**

```typescript
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
```

- [ ] **Step 7: Run test — expect PASS**

```bash
npx vitest run tests/Unit/healthCheck.test.ts
```

Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add src/db.ts src/types.ts src/enums.ts src/models/healthCheck.ts tests/Unit/healthCheck.test.ts
git commit -m "feat: add health checks schema, types, and model"
```

---

## Task 3: Health Checks — Worker Poller + Edit Form + UI Badges

**Files:**
- Create: `src/services/healthPoller.ts`
- Modify: `src/worker.ts`
- Modify: `src/routes/apps.ts` (update `PUT /apps/:id` to save `health_url`)
- Modify: `src/views/apps/edit.ejs`
- Modify: `src/views/apps/index.ejs`
- Modify: `src/views/apps/show.ejs`

- [ ] **Step 1: Create `src/services/healthPoller.ts`**

```typescript
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
```

- [ ] **Step 2: Write failing test for health poller**

Create `tests/Unit/healthPoller.test.ts`:

```typescript
import { vi } from 'vitest';
import * as AppModel from '../../src/models/app.js';
import * as HealthCheckModel from '../../src/models/healthCheck.js';

global.fetch = vi.fn();

test('runHealthChecks records "up" for 200 response', async () => {
  (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce({ ok: true, status: 200 });
  AppModel.create({ name: 'P', repo_url: 'r', branch: 'main', path: '/p', health_url: 'http://localhost:9999' } as any);
  const { runHealthChecks } = await import('../../src/services/healthPoller.js');
  await runHealthChecks();
  const apps = AppModel.list();
  const latest = HealthCheckModel.findLatest(apps[0].id);
  expect(latest?.status).toBe('up');
});

test('runHealthChecks records "down" when fetch throws', async () => {
  (global.fetch as ReturnType<typeof vi.fn>).mockRejectedValueOnce(new Error('ECONNREFUSED'));
  const app = AppModel.create({ name: 'P2', repo_url: 'r', branch: 'main', path: '/p2', health_url: 'http://localhost:9998' } as any);
  const { runHealthChecks } = await import('../../src/services/healthPoller.js');
  await runHealthChecks();
  const latest = HealthCheckModel.findLatest(app.id);
  expect(latest?.status).toBe('down');
});

test('runHealthChecks skips apps without health_url', async () => {
  (global.fetch as ReturnType<typeof vi.fn>).mockClear();
  AppModel.create({ name: 'Skip', repo_url: 'r', branch: 'main', path: '/skip' });
  const { runHealthChecks } = await import('../../src/services/healthPoller.js');
  await runHealthChecks();
  expect(global.fetch).not.toHaveBeenCalled();
});
```

- [ ] **Step 3: Run test — expect PASS**

```bash
npx vitest run tests/Unit/healthPoller.test.ts
```

Expected: PASS

- [ ] **Step 4: Add health check loop to `src/worker.ts`**

Add import at top:

```typescript
import { runHealthChecks } from './services/healthPoller.js';
```

Add after the `poll()` function:

```typescript
async function pollHealth(): Promise<void> {
  while (running) {
    try {
      await runHealthChecks();
    } catch (err) {
      console.error('Health check error:', err instanceof Error ? err.message : String(err));
    }
    await new Promise(r => setTimeout(r, 60000));
  }
}
```

Add at the bottom alongside `poll()`:

```typescript
poll().catch(console.error);
pollHealth().catch(console.error);
```

- [ ] **Step 5: Update `PUT /apps/:id` in `src/routes/apps.ts` to save `health_url`**

In the PUT handler's final callback, change the `AppModel.update` call:

```typescript
const { name, repo_url, branch, path, health_url } = req.body as {
  name: string; repo_url: string; branch: string; path: string; health_url?: string;
};
AppModel.update(String(req.params.id), { name, repo_url, branch, path, health_url: health_url || null });
```

Also update `src/models/app.ts` — the `update` function needs to persist `health_url`. Change the SQL:

```typescript
export function update(id: number | string, data: Partial<AppRecord>): AppRecord | null {
  const now = new Date().toISOString();
  getDb().prepare(
    'UPDATE apps SET name = ?, repo_url = ?, branch = ?, path = ?, health_url = ?, updated_at = ? WHERE id = ?'
  ).run(data.name, data.repo_url, data.branch, data.path, data.health_url ?? null, now, id);
  return findById(Number(id));
}
```

- [ ] **Step 6: Add `health_url` field to `src/views/apps/edit.ejs`**

Add before the `<div class="flex gap-3">` buttons div:

```html
<div>
    <label class="block text-sm font-medium mb-1">Health Check URL <span class="text-gray-400 font-normal">(optional)</span></label>
    <input name="health_url" value="<%= app.health_url || '' %>"
        placeholder="https://myapp.example.com/health"
        class="w-full border rounded px-3 py-2">
    <p class="text-xs text-gray-400 mt-1">Pinged every 60s. Must return 2xx to be considered healthy.</p>
</div>
```

- [ ] **Step 7: Add health badge to `src/views/apps/index.ejs`**

In the `apps.forEach` loop, add a `healthBadge` helper after the existing `badge` variable:

```html
<%
    const badge = app.status === 'success' ? 'bg-green-100 text-green-800'
                : app.status === 'failed'  ? 'bg-red-100 text-red-800'
                : app.status === 'deploying' ? 'bg-yellow-100 text-yellow-800'
                : 'bg-gray-100 text-gray-600';
    const healthBadge = !app.health_url ? null
                      : app.last_health_status === 'up'   ? 'bg-blue-100 text-blue-800'
                      : app.last_health_status === 'down' ? 'bg-red-100 text-red-800'
                      : 'bg-gray-100 text-gray-500';
    const healthLabel = !app.health_url ? null
                      : app.last_health_status === 'up'   ? '● healthy'
                      : app.last_health_status === 'down' ? '● down'
                      : '○ checking';
%>
```

Then in the badges div, add after the status badge:

```html
<% if (healthBadge) { %>
<span class="text-xs px-2 py-1 rounded <%= healthBadge %>"><%= healthLabel %></span>
<% } %>
```

- [ ] **Step 8: Update `GET /` route in `src/routes/apps.ts` to join health status**

Change the index route to attach last health status:

```typescript
router.get('/', (req: Request, res: Response) => {
  const apps = AppModel.list();
  const appsWithHealth = apps.map(app => {
    const latest = HealthCheckModel.findLatest(app.id);
    return { ...app, last_health_status: latest?.status ?? null };
  });
  res.render('apps/index', { apps: appsWithHealth });
});
```

Add the import at top of `src/routes/apps.ts`:

```typescript
import * as HealthCheckModel from '../models/healthCheck.js';
```

- [ ] **Step 9: Run all tests**

```bash
npx vitest run
```

Expected: all pass.

- [ ] **Step 10: Commit**

```bash
git add src/services/healthPoller.ts src/worker.ts src/routes/apps.ts src/models/app.ts src/views/apps/edit.ejs src/views/apps/index.ejs tests/Unit/healthPoller.test.ts
git commit -m "feat: add health check poller, badges, and health_url config"
```

---

## Task 4: Rollback — Schema + Deploy Job

**Files:**
- Modify: `src/jobs/deployApp.ts`
- Modify: `tests/Unit/deployApp.test.ts`

Schema columns (`commit_sha`, `commit_message`, `rollback_sha`) were already added in Task 2 via `ALTER TABLE` migrations and `types.ts`.

- [ ] **Step 1: Write failing test for commit SHA capture**

Add to `tests/Unit/deployApp.test.ts`:

```typescript
test('deployApp stores commit_sha after successful deploy', async () => {
  const dir = makeTestDir('bridge-sha');
  const app = AppModel.create({ name: 'SHA', repo_url: TEST_REPO, branch: TEST_BRANCH, path: dir });
  const dep = DeploymentModel.create({ app_id: app.id, status: DeploymentStatus.Pending });

  const mockRunner = async (_s: string, _w: string, _o: (c: string) => void) => 0;
  await deployApp(dep.id, { composeRunner: mockRunner });

  const updated = DeploymentModel.findById(dep.id);
  expect(updated!.commit_sha).toMatch(/^[0-9a-f]{40}$/);
  expect(updated!.commit_message).toBeTruthy();

  rmSync(dir, { recursive: true, force: true });
}, 120000);

test('deployApp with rollback_sha checks out that SHA instead of pulling', async () => {
  const dir = makeTestDir('bridge-rollback');
  // get current SHA to rollback to
  const sha = execSync('git rev-parse HEAD', { cwd: dir }).toString().trim();

  const app = AppModel.create({ name: 'RB', repo_url: TEST_REPO, branch: TEST_BRANCH, path: dir });
  const dep = DeploymentModel.create({ app_id: app.id, status: DeploymentStatus.Pending, rollback_sha: sha });

  const mockRunner = async (_s: string, _w: string, _o: (c: string) => void) => 0;
  await deployApp(dep.id, { composeRunner: mockRunner });

  const updated = DeploymentModel.findById(dep.id);
  expect(updated!.status).toBe(DeploymentStatus.Success);
  expect(updated!.commit_sha).toBe(sha);

  rmSync(dir, { recursive: true, force: true });
}, 120000);
```

- [ ] **Step 2: Run test — expect FAIL**

```bash
npx vitest run tests/Unit/deployApp.test.ts
```

Expected: new tests FAIL — `commit_sha` is null.

- [ ] **Step 3: Update `src/jobs/deployApp.ts` to capture SHA and support rollback**

Replace the git pull section (lines 72–75) with:

```typescript
    const git = new GitService(sshKeyPath);

    if (dep.rollback_sha) {
      DeploymentModel.appendLog(deploymentId, `=== git checkout ${dep.rollback_sha} ===\n`);
      const fetchOut = await git.pull(app.path, app.branch); // fetch latest refs
      DeploymentModel.appendLog(deploymentId, fetchOut + '\n');
      // checkout specific SHA
      const { execSync } = await import('child_process');
      execSync(`git checkout ${dep.rollback_sha}`, { cwd: app.path });
      DeploymentModel.appendLog(deploymentId, `Checked out ${dep.rollback_sha}\n`);
    } else {
      DeploymentModel.appendLog(deploymentId, '=== git pull ===\n');
      const pullOutput = await git.pull(app.path, app.branch);
      DeploymentModel.appendLog(deploymentId, pullOutput + '\n');
    }

    // capture commit SHA and message
    const { execSync: exec } = await import('child_process');
    const commitSha = exec('git rev-parse HEAD', { cwd: app.path }).toString().trim();
    const commitMsg = exec('git log -1 --format=%s', { cwd: app.path }).toString().trim();
    DeploymentModel.update(deploymentId, { commit_sha: commitSha, commit_message: commitMsg });
```

Add `import { execSync } from 'child_process';` at the top of `src/jobs/deployApp.ts` (if not already there — it isn't, add it).

- [ ] **Step 4: Run test — expect PASS**

```bash
npx vitest run tests/Unit/deployApp.test.ts
```

Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add src/jobs/deployApp.ts tests/Unit/deployApp.test.ts
git commit -m "feat: capture commit SHA on deploy and support rollback_sha"
```

---

## Task 5: Rollback — Route + UI

**Files:**
- Modify: `src/routes/apps.ts`
- Modify: `src/views/apps/show.ejs`
- Create: `tests/Feature/rollback.test.ts`

- [ ] **Step 1: Write failing test**

Create `tests/Feature/rollback.test.ts`:

```typescript
import request from 'supertest';
import { vi } from 'vitest';
import * as AppModel from '../../src/models/app.js';
import * as DeploymentModel from '../../src/models/deployment.js';
import { DeploymentStatus } from '../../src/enums.js';

vi.mock('../../src/services/gitService.js', () => ({
  default: vi.fn().mockImplementation(() => ({
    clone: vi.fn().mockResolvedValue(''),
    pull: vi.fn().mockResolvedValue(''),
  })),
}));

vi.mock('../../src/queue.js', () => ({
  enqueueDeployJob: vi.fn(),
}));

test('POST /apps/:id/rollback creates deployment with rollback_sha and redirects', async () => {
  const app = await import('../../src/app.js').then(m => m.default);
  const rec = AppModel.create({ name: 'RB', repo_url: 'r', branch: 'main', path: '/rb' });
  const dep = DeploymentModel.create({
    app_id: rec.id,
    status: DeploymentStatus.Success,
    commit_sha: 'abc123def456abc123def456abc123def456abc1',
  });

  const res = await request(app)
    .post(`/apps/${rec.id}/rollback`)
    .type('form')
    .send({ deployment_id: dep.id });

  expect(res.status).toBe(302);
  const deployments = DeploymentModel.listForApp(rec.id);
  const rollback = deployments.find(d => d.rollback_sha === 'abc123def456abc123def456abc123def456abc1');
  expect(rollback).toBeTruthy();
});

test('POST /apps/:id/rollback returns 400 when deployment has no commit_sha', async () => {
  const app = await import('../../src/app.js').then(m => m.default);
  const rec = AppModel.create({ name: 'RB2', repo_url: 'r', branch: 'main', path: '/rb2' });
  const dep = DeploymentModel.create({ app_id: rec.id, status: DeploymentStatus.Success });

  const res = await request(app)
    .post(`/apps/${rec.id}/rollback`)
    .type('form')
    .send({ deployment_id: dep.id });

  expect(res.status).toBe(400);
});
```

- [ ] **Step 2: Run test — expect FAIL**

```bash
npx vitest run tests/Feature/rollback.test.ts
```

Expected: FAIL — route does not exist.

- [ ] **Step 3: Add rollback route to `src/routes/apps.ts`**

Add before `export default router`:

```typescript
router.post('/apps/:id/rollback', (req: Request, res: Response) => {
  const app = AppModel.findById(Number(req.params.id));
  if (!app) { res.status(404).send('Not found'); return; }

  const { deployment_id } = req.body as { deployment_id: string };
  const source = DeploymentModel.findById(Number(deployment_id));
  if (!source || source.app_id !== app.id) { res.status(404).send('Not found'); return; }
  if (!source.commit_sha) { res.status(400).send('Deployment has no commit SHA — cannot rollback.'); return; }

  const dep = DeploymentModel.create({
    app_id: app.id,
    status: DeploymentStatus.Pending,
    rollback_sha: source.commit_sha,
  });
  enqueueDeployJob(dep.id);
  res.redirect(`/deployments/${dep.id}`);
});
```

- [ ] **Step 4: Run test — expect PASS**

```bash
npx vitest run tests/Feature/rollback.test.ts
```

Expected: PASS

- [ ] **Step 5: Add rollback buttons to `src/views/apps/show.ejs`**

In the `deployments.forEach` loop, replace the deployment row with:

```html
<div class="bg-white rounded shadow p-3 mb-2 flex items-center justify-between">
    <div class="flex items-center gap-3">
        <span class="text-xs px-2 py-1 rounded <%= badge %>"><%= dep.status %></span>
        <span class="text-xs text-gray-400"><%= durationStr %></span>
        <% if (dep.commit_sha) { %>
        <span class="text-xs text-gray-400 font-mono"><%= dep.commit_sha.slice(0, 7) %></span>
        <% } %>
        <% if (dep.commit_message) { %>
        <span class="text-xs text-gray-500 truncate max-w-xs"><%= dep.commit_message %></span>
        <% } %>
        <span class="text-sm text-gray-500"><%= dep.created_at %></span>
    </div>
    <div class="flex items-center gap-2">
        <a href="/deployments/<%= dep.id %>" class="text-sm text-blue-600 hover:underline">View Log</a>
        <% if (dep.status === 'success' && dep.commit_sha) { %>
        <form method="POST" action="/apps/<%= app.id %>/rollback" onsubmit="return confirm('Rollback to <%= dep.commit_sha.slice(0,7) %>?')">
            <input type="hidden" name="deployment_id" value="<%= dep.id %>">
            <button type="submit" class="text-xs px-2 py-1 rounded bg-orange-100 text-orange-700 hover:bg-orange-200">
                Rollback
            </button>
        </form>
        <% } %>
    </div>
</div>
```

- [ ] **Step 6: Commit**

```bash
git add src/routes/apps.ts src/views/apps/show.ejs tests/Feature/rollback.test.ts
git commit -m "feat: add rollback route and rollback button on deployment history"
```

---

## Task 6: Auto-Deploy Webhooks — Endpoint

**Files:**
- Modify: `src/routes/apps.ts`
- Create: `tests/Feature/webhooks.test.ts`

`webhook_secret` column was already added to `apps` table in Task 2. `AppRecord.webhook_secret` type was added in Task 2.

- [ ] **Step 1: Write failing webhook tests**

Create `tests/Feature/webhooks.test.ts`:

```typescript
import request from 'supertest';
import { createHmac, randomBytes } from 'crypto';
import { vi } from 'vitest';
import * as AppModel from '../../src/models/app.js';
import { getDb } from '../../src/db.js';

vi.mock('../../src/services/gitService.js', () => ({
  default: vi.fn().mockImplementation(() => ({
    clone: vi.fn().mockResolvedValue(''),
    pull: vi.fn().mockResolvedValue(''),
  })),
}));

vi.mock('../../src/queue.js', () => ({
  enqueueDeployJob: vi.fn(),
}));

function signPayload(secret: string, body: string): string {
  return 'sha256=' + createHmac('sha256', secret).update(body).digest('hex');
}

test('POST /apps/:id/webhook with valid signature queues deploy and returns 204', async () => {
  const app = await import('../../src/app.js').then(m => m.default);
  const secret = randomBytes(20).toString('hex');
  const rec = AppModel.create({ name: 'WH', repo_url: 'r', branch: 'main', path: '/wh' });
  getDb().prepare('UPDATE apps SET webhook_secret = ? WHERE id = ?').run(secret, rec.id);

  const body = JSON.stringify({ ref: 'refs/heads/main' });
  const sig = signPayload(secret, body);

  const res = await request(app)
    .post(`/apps/${rec.id}/webhook`)
    .set('Content-Type', 'application/json')
    .set('X-Hub-Signature-256', sig)
    .send(body);

  expect(res.status).toBe(204);
});

test('POST /apps/:id/webhook with invalid signature returns 401', async () => {
  const app = await import('../../src/app.js').then(m => m.default);
  const secret = randomBytes(20).toString('hex');
  const rec = AppModel.create({ name: 'WH2', repo_url: 'r', branch: 'main', path: '/wh2' });
  getDb().prepare('UPDATE apps SET webhook_secret = ? WHERE id = ?').run(secret, rec.id);

  const res = await request(app)
    .post(`/apps/${rec.id}/webhook`)
    .set('Content-Type', 'application/json')
    .set('X-Hub-Signature-256', 'sha256=badsignature')
    .send(JSON.stringify({ ref: 'refs/heads/main' }));

  expect(res.status).toBe(401);
});

test('POST /apps/:id/webhook with branch mismatch returns 200 no-op', async () => {
  const app = await import('../../src/app.js').then(m => m.default);
  const secret = randomBytes(20).toString('hex');
  const rec = AppModel.create({ name: 'WH3', repo_url: 'r', branch: 'main', path: '/wh3' });
  getDb().prepare('UPDATE apps SET webhook_secret = ? WHERE id = ?').run(secret, rec.id);

  const body = JSON.stringify({ ref: 'refs/heads/other-branch' });
  const sig = signPayload(secret, body);

  const res = await request(app)
    .post(`/apps/${rec.id}/webhook`)
    .set('Content-Type', 'application/json')
    .set('X-Hub-Signature-256', sig)
    .send(body);

  expect(res.status).toBe(200);
  expect(res.body.skipped).toBe(true);
});

test('POST /apps/:id/webhook returns 400 when no secret configured', async () => {
  const app = await import('../../src/app.js').then(m => m.default);
  const rec = AppModel.create({ name: 'WH4', repo_url: 'r', branch: 'main', path: '/wh4' });

  const res = await request(app)
    .post(`/apps/${rec.id}/webhook`)
    .set('Content-Type', 'application/json')
    .send(JSON.stringify({ ref: 'refs/heads/main' }));

  expect(res.status).toBe(400);
});
```

- [ ] **Step 2: Run test — expect FAIL**

```bash
npx vitest run tests/Feature/webhooks.test.ts
```

Expected: FAIL — route does not exist.

- [ ] **Step 3: Add `express.raw()` middleware to `src/app.ts`**

In `src/app.ts`, add **before** `app.use(express.json())` so the raw body is captured before the JSON parser consumes it:

```typescript
app.use('/apps/:id/webhook', express.raw({ type: 'application/json' }));
app.use(express.json());   // existing line — keep, just move raw() above it
```

- [ ] **Step 4: Add webhook endpoint and secret-regeneration to `src/routes/apps.ts`**

Add at top of `src/routes/apps.ts`:

```typescript
import { createHmac, timingSafeEqual, randomBytes } from 'crypto';
```

Add before `export default router`:

```typescript
router.post('/apps/:id/webhook', (req: Request, res: Response) => {
  const app = AppModel.findById(Number(req.params.id));
  if (!app) { res.status(404).json({ error: 'Not found' }); return; }
  if (!app.webhook_secret) { res.status(400).json({ error: 'No webhook secret configured.' }); return; }

  const rawBody = Buffer.isBuffer(req.body) ? req.body : Buffer.from(JSON.stringify(req.body));
  const sig = req.headers['x-hub-signature-256'] as string | undefined;
  if (!sig) { res.status(401).json({ error: 'Missing signature' }); return; }

  const expected = 'sha256=' + createHmac('sha256', app.webhook_secret).update(rawBody).digest('hex');
  const expectedBuf = Buffer.from(expected);
  const receivedBuf = Buffer.from(sig);
  const valid = expectedBuf.length === receivedBuf.length &&
    timingSafeEqual(expectedBuf, receivedBuf);
  if (!valid) { res.status(401).json({ error: 'Invalid signature' }); return; }

  let payload: { ref?: string } = {};
  try { payload = JSON.parse(rawBody.toString()); } catch { /* ignore */ }
  const pushedBranch = payload.ref?.replace('refs/heads/', '');
  if (pushedBranch && pushedBranch !== app.branch) {
    res.json({ skipped: true, reason: `Push was to ${pushedBranch}, app tracks ${app.branch}` });
    return;
  }

  const dep = DeploymentModel.create({ app_id: app.id, status: DeploymentStatus.Pending });
  enqueueDeployJob(dep.id);
  res.status(204).send();
});

router.post('/apps/:id/webhook-secret', (req: Request, res: Response) => {
  const app = AppModel.findById(Number(req.params.id));
  if (!app) { res.status(404).json({ error: 'Not found' }); return; }
  const secret = randomBytes(32).toString('hex');
  getDb().prepare('UPDATE apps SET webhook_secret = ?, updated_at = ? WHERE id = ?')
    .run(secret, new Date().toISOString(), app.id);
  req.flash('success', 'Webhook secret regenerated.');
  res.redirect(`/apps/${app.id}/edit`);
});
```

Add `import { getDb } from '../db.js';` to `src/routes/apps.ts` (if not already present — it is not, add it).

- [ ] **Step 5: Run test — expect PASS**

```bash
npx vitest run tests/Feature/webhooks.test.ts
```

Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/routes/apps.ts src/app.ts tests/Feature/webhooks.test.ts
git commit -m "feat: add auto-deploy webhook endpoint with HMAC validation"
```

---

## Task 7: Webhooks — Edit Page UI

**Files:**
- Modify: `src/views/apps/edit.ejs`

- [ ] **Step 1: Add webhook section to `src/views/apps/edit.ejs`**

Add after the closing `</form>` of the edit form and before the delete form:

```html
<div class="mt-8 bg-white rounded shadow p-6 max-w-xl">
    <h2 class="font-semibold mb-4">Auto-Deploy Webhook</h2>
    <% if (app.webhook_secret) { %>
    <div class="mb-4">
        <label class="block text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Webhook URL</label>
        <div class="flex items-center gap-2">
            <input readonly
                value="<%= `${process.env.APP_URL || 'http://localhost:3000'}/apps/${app.id}/webhook` %>"
                class="w-full font-mono text-sm border rounded px-3 py-2 bg-gray-50 text-gray-700">
            <button type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)"
                class="text-xs px-3 py-2 rounded bg-gray-100 hover:bg-gray-200 shrink-0">Copy</button>
        </div>
    </div>
    <div class="mb-4">
        <label class="block text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Secret</label>
        <p class="text-sm text-gray-500 font-mono"><%= app.webhook_secret.slice(0,8) %>••••••••••••••••••••••••</p>
    </div>
    <form method="POST" action="/apps/<%= app.id %>/webhook-secret"
          onsubmit="return confirm('Regenerate secret? Existing GitHub webhooks using the old secret will stop working.')">
        <button type="submit" class="text-sm px-3 py-1.5 rounded bg-yellow-100 text-yellow-800 hover:bg-yellow-200">
            Regenerate Secret
        </button>
    </form>
    <% } else { %>
    <p class="text-sm text-gray-500 mb-3">No webhook secret yet. Generate one to enable auto-deploy on git push.</p>
    <form method="POST" action="/apps/<%= app.id %>/webhook-secret">
        <button type="submit" class="text-sm px-3 py-1.5 rounded bg-blue-600 text-white hover:bg-blue-700">
            Generate Webhook Secret
        </button>
    </form>
    <% } %>
    <p class="text-xs text-gray-400 mt-4">Add this URL as a GitHub webhook (push events, content type: application/json).</p>
</div>
```

- [ ] **Step 2: Run the app and manually verify**

```bash
npm run dev
```

Visit `http://localhost:3000`, create or open an app, navigate to Edit. Confirm the webhook section shows. Click "Generate Webhook Secret", confirm secret appears masked. Click "Regenerate Secret", confirm flash message.

- [ ] **Step 3: Commit**

```bash
git add src/views/apps/edit.ejs
git commit -m "feat: add webhook configuration UI to app edit page"
```

---

## Task 8: Slack Notifications — Model, Notifier, Deploy Hook

**Files:**
- Create: `src/models/settings.ts`
- Create: `src/services/slackNotifier.ts`
- Modify: `src/jobs/deployApp.ts`
- Create: `tests/Unit/slackNotifier.test.ts`

- [ ] **Step 1: Create `src/models/settings.ts`**

```typescript
import { getDb } from '../db.js';

export function get(key: string): string | null {
  const row = getDb().prepare('SELECT value FROM settings WHERE key = ?').get(key) as
    { value: string } | undefined;
  return row?.value ?? null;
}

export function set(key: string, value: string): void {
  getDb().prepare(
    'INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value'
  ).run(key, value);
}
```

- [ ] **Step 2: Create `src/services/slackNotifier.ts`**

```typescript
import * as SettingsModel from '../models/settings.js';
import type { DeploymentRecord } from '../types.js';

export async function notifyDeployment(dep: DeploymentRecord): Promise<void> {
  const url = SettingsModel.get('slack_webhook_url');
  if (!url) return;

  const isSuccess = dep.status === 'success';
  const emoji = isSuccess ? ':white_check_mark:' : ':x:';
  const color = isSuccess ? '#2eb886' : '#cc0000';

  let durationText = '';
  if (dep.started_at && dep.finished_at) {
    const secs = Math.round(
      (new Date(dep.finished_at).getTime() - new Date(dep.started_at).getTime()) / 1000
    );
    durationText = secs >= 60
      ? `${Math.floor(secs / 60)}m ${secs % 60}s`
      : `${secs}s`;
  }

  const logTail = dep.log
    ? dep.log.split('\n').slice(-20).join('\n').trim()
    : '';

  const blocks: unknown[] = [
    {
      type: 'section',
      text: {
        type: 'mrkdwn',
        text: `${emoji} *${dep.app_name ?? 'App'}* deployment *${dep.status}*${durationText ? ` (${durationText})` : ''}`,
      },
    },
  ];

  if (!isSuccess && logTail) {
    blocks.push({
      type: 'section',
      text: { type: 'mrkdwn', text: `\`\`\`${logTail}\`\`\`` },
    });
  }

  const baseUrl = process.env.APP_URL ?? 'http://localhost:3000';
  blocks.push({
    type: 'context',
    elements: [{ type: 'mrkdwn', text: `<${baseUrl}/deployments/${dep.id}|View deployment log>` }],
  });

  await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ attachments: [{ color, blocks }] }),
  });
}
```

- [ ] **Step 3: Write failing test**

Create `tests/Unit/slackNotifier.test.ts`:

```typescript
import { vi } from 'vitest';
import * as SettingsModel from '../../src/models/settings.js';

global.fetch = vi.fn();

test('notifyDeployment does nothing when no slack_webhook_url set', async () => {
  (global.fetch as ReturnType<typeof vi.fn>).mockClear();
  const { notifyDeployment } = await import('../../src/services/slackNotifier.js');
  await notifyDeployment({ id: 1, app_id: 1, status: 'success', log: null, started_at: null, finished_at: null, commit_sha: null, commit_message: null, rollback_sha: null, created_at: '', updated_at: '', app_name: 'Test' } as any);
  expect(global.fetch).not.toHaveBeenCalled();
});

test('notifyDeployment POSTs to Slack when url is configured', async () => {
  (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce({ ok: true });
  SettingsModel.set('slack_webhook_url', 'https://hooks.slack.com/test');
  const { notifyDeployment } = await import('../../src/services/slackNotifier.js');
  await notifyDeployment({ id: 2, app_id: 1, status: 'failed', log: 'error line\n', started_at: '2026-01-01T00:00:00Z', finished_at: '2026-01-01T00:01:00Z', commit_sha: null, commit_message: null, rollback_sha: null, created_at: '', updated_at: '', app_name: 'MyApp' } as any);
  expect(global.fetch).toHaveBeenCalledWith('https://hooks.slack.com/test', expect.objectContaining({ method: 'POST' }));
});
```

- [ ] **Step 4: Run test — expect PASS**

```bash
npx vitest run tests/Unit/slackNotifier.test.ts
```

Expected: PASS

- [ ] **Step 5: Call `notifyDeployment` in `src/jobs/deployApp.ts`**

Add import at top:

```typescript
import { notifyDeployment } from '../services/slackNotifier.js';
```

In the `try` block, change the final `DeploymentModel.update` for success to:

```typescript
    DeploymentModel.update(deploymentId, { status: DeploymentStatus.Success, finished_at: new Date().toISOString() });
    AppModel.updateStatus(app.id, AppStatus.Success);
    const successDep = DeploymentModel.findById(deploymentId)!;
    notifyDeployment(successDep).catch(console.error);
```

In the `catch` block, after the failed update:

```typescript
    DeploymentModel.update(deploymentId, { status: DeploymentStatus.Failed, finished_at: new Date().toISOString() });
    AppModel.updateStatus(app.id, AppStatus.Failed);
    const failedDep = DeploymentModel.findById(deploymentId)!;
    notifyDeployment(failedDep).catch(console.error);
```

- [ ] **Step 6: Run all tests**

```bash
npx vitest run
```

Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add src/models/settings.ts src/services/slackNotifier.ts src/jobs/deployApp.ts tests/Unit/slackNotifier.test.ts
git commit -m "feat: add Slack deployment notifications"
```

---

## Task 9: Slack Settings — Route + View + Nav

**Files:**
- Create: `src/routes/settings.ts`
- Create: `src/views/settings/index.ejs`
- Modify: `src/app.ts`
- Modify: `src/views/layouts/header.ejs`

- [ ] **Step 1: Create `src/routes/settings.ts`**

```typescript
import { Router, Request, Response } from 'express';
import * as SettingsModel from '../models/settings.js';

const router = Router();

router.get('/settings', (req: Request, res: Response) => {
  const slackWebhookUrl = SettingsModel.get('slack_webhook_url') ?? '';
  res.render('settings/index', { slackWebhookUrl });
});

router.post('/settings', async (req: Request, res: Response) => {
  const { slack_webhook_url, test_slack } = req.body as {
    slack_webhook_url?: string;
    test_slack?: string;
  };

  if (slack_webhook_url !== undefined) {
    const url = slack_webhook_url.trim();
    if (url) {
      SettingsModel.set('slack_webhook_url', url);
    } else {
      SettingsModel.set('slack_webhook_url', '');
    }
  }

  if (test_slack) {
    const url = SettingsModel.get('slack_webhook_url');
    if (url) {
      try {
        await fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ text: ':satellite: The Bridge test notification — connection OK.' }),
        });
        req.flash('success', 'Test notification sent to Slack.');
      } catch {
        req.flash('success', 'Failed to reach Slack webhook URL.');
      }
    }
  } else {
    req.flash('success', 'Settings saved.');
  }

  res.redirect('/settings');
});

export default router;
```

- [ ] **Step 2: Create `src/views/settings/` directory and `index.ejs`**

```bash
mkdir -p src/views/settings
```

Create `src/views/settings/index.ejs`:

```html
<%- include('../layouts/header') %>
<h1 class="text-2xl font-bold mb-6">Settings</h1>

<form method="POST" action="/settings" class="bg-white rounded shadow p-6 space-y-4 max-w-xl">
    <div>
        <label class="block text-sm font-medium mb-1">Slack Webhook URL</label>
        <input name="slack_webhook_url"
            value="<%= slackWebhookUrl %>"
            placeholder="https://hooks.slack.com/services/…"
            class="w-full border rounded px-3 py-2 font-mono text-sm">
        <p class="text-xs text-gray-400 mt-1">
            Receives a message when any deployment succeeds or fails.
            <a href="https://api.slack.com/messaging/webhooks" class="underline" target="_blank">How to create one →</a>
        </p>
    </div>
    <div class="flex gap-3">
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Save</button>
        <% if (slackWebhookUrl) { %>
        <button type="submit" name="test_slack" value="1"
            class="bg-gray-100 text-gray-700 px-4 py-2 rounded hover:bg-gray-200">
            Send Test
        </button>
        <% } %>
    </div>
</form>
<%- include('../layouts/footer') %>
```

- [ ] **Step 3: Register settings route in `src/app.ts`**

Add import:

```typescript
import settingsRouter from './routes/settings.js';
```

Add after `app.use('/', deploymentsRouter)`:

```typescript
app.use('/', settingsRouter);
```

- [ ] **Step 4: Add Settings link to `src/views/layouts/header.ejs`**

In the `<nav>` element, add after the `+ New App` link:

```html
<a href="/settings" class="text-sm text-gray-400 hover:text-white ml-auto">Settings</a>
```

- [ ] **Step 5: Write feature test**

Create `tests/Feature/settings.test.ts`:

```typescript
import request from 'supertest';
import { vi } from 'vitest';
import * as SettingsModel from '../../src/models/settings.js';

vi.mock('../../src/services/gitService.js', () => ({
  default: vi.fn().mockImplementation(() => ({ clone: vi.fn(), pull: vi.fn() })),
}));

test('GET /settings returns 200', async () => {
  const app = await import('../../src/app.js').then(m => m.default);
  const res = await request(app).get('/settings');
  expect(res.status).toBe(200);
  expect(res.text).toContain('Slack');
});

test('POST /settings saves slack_webhook_url and redirects', async () => {
  const app = await import('../../src/app.js').then(m => m.default);
  const res = await request(app)
    .post('/settings')
    .type('form')
    .send({ slack_webhook_url: 'https://hooks.slack.com/test123' });
  expect(res.status).toBe(302);
  expect(SettingsModel.get('slack_webhook_url')).toBe('https://hooks.slack.com/test123');
});
```

- [ ] **Step 6: Run all tests**

```bash
npx vitest run
```

Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add src/routes/settings.ts src/views/settings/index.ejs src/app.ts src/views/layouts/header.ejs tests/Feature/settings.test.ts
git commit -m "feat: add settings page with Slack webhook URL configuration"
```

---

## Task 10: LCARS UI — CSS + Static Serving

**Files:**
- Modify: `src/app.ts`
- Create: `public/lcars.css`
- Create: `public/theme.js`
- Modify: `src/views/layouts/header.ejs`
- Modify: `src/views/layouts/footer.ejs`

- [ ] **Step 1: Add static file serving to `src/app.ts`**

Add after the `import { dirname, join }` line:

```typescript
app.use(express.static(join(__dirname, '..', 'public')));
```

(Place this before any route registrations.)

- [ ] **Step 2: Create `public/theme.js`**

```javascript
(function () {
  var saved = localStorage.getItem('bridge-theme');
  if (saved) document.documentElement.setAttribute('data-theme', saved);
})();

function toggleTheme() {
  var current = document.documentElement.getAttribute('data-theme');
  var isDark = current === 'dark' ||
    (!current && window.matchMedia('(prefers-color-scheme: dark)').matches);
  var next = isDark ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('bridge-theme', next);
}
```

- [ ] **Step 3: Create `public/lcars.css`**

```css
/* ==========================================================
   THE BRIDGE — LCARS Theme (Star Trek TNG)
   ========================================================== */

:root {
  --color-brand:    #FF9900;
  --color-command:  #CC0000;
  --color-sciences: #3399FF;
  --color-ops:      #FFCC00;

  --color-bg:          #F4F4F4;
  --color-surface:     #FFFFFF;
  --color-surface-alt: #EFEFEF;
  --color-text:        #1A1A1A;
  --color-text-muted:  #666666;
  --color-border:      #DDDDDD;
  --color-log-bg:      #0A0A0A;
  --color-log-text:    #33FF33;
}

[data-theme="dark"] {
  --color-bg:          #0D0D0D;
  --color-surface:     #1A1A1A;
  --color-surface-alt: #242424;
  --color-text:        #F0E6D0;
  --color-text-muted:  #888888;
  --color-border:      #333333;
}

@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --color-bg:          #0D0D0D;
    --color-surface:     #1A1A1A;
    --color-surface-alt: #242424;
    --color-text:        #F0E6D0;
    --color-text-muted:  #888888;
    --color-border:      #333333;
  }
}

/* --- Base --- */
body {
  background-color: var(--color-bg);
  color: var(--color-text);
}

/* --- Nav --- */
.lcars-nav {
  background-color: #000;
  border-bottom: 3px solid var(--color-brand);
  padding: 0.5rem 1.5rem;
  display: flex;
  align-items: center;
  gap: 1rem;
}

.lcars-nav__brand {
  font-size: 1.1rem;
  font-weight: 900;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: var(--color-brand);
  text-decoration: none;
}

.lcars-nav__link {
  font-size: 0.8rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #888;
  text-decoration: none;
  padding: 0.25rem 0.75rem;
  border-radius: 9999px;
  transition: background 0.15s, color 0.15s;
}

.lcars-nav__link:hover {
  background: var(--color-brand);
  color: #000;
}

.lcars-nav__spacer { flex: 1; }

.lcars-nav__theme-btn {
  background: none;
  border: 1px solid #444;
  color: #888;
  font-size: 0.75rem;
  padding: 0.2rem 0.6rem;
  border-radius: 9999px;
  cursor: pointer;
  letter-spacing: 0.05em;
  transition: border-color 0.15s, color 0.15s;
}

.lcars-nav__theme-btn:hover {
  border-color: var(--color-brand);
  color: var(--color-brand);
}

/* --- Cards / Panels --- */
.lcars-card {
  background: var(--color-surface);
  border-radius: 0.375rem;
  border-left: 4px solid var(--color-brand);
  box-shadow: 0 1px 3px rgba(0,0,0,0.15);
}

.lcars-card--command  { border-left-color: var(--color-command); }
.lcars-card--sciences { border-left-color: var(--color-sciences); }
.lcars-card--ops      { border-left-color: var(--color-ops); }

/* --- Section Headers --- */
.lcars-section-header {
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--color-brand);
  border-left: 3px solid var(--color-brand);
  padding-left: 0.5rem;
  margin-bottom: 0.75rem;
}

/* --- Badges --- */
.lcars-badge {
  display: inline-block;
  padding: 0.15rem 0.6rem;
  border-radius: 9999px;
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.lcars-badge--success  { background: var(--color-sciences); color: #000; }
.lcars-badge--failed   { background: var(--color-command);  color: #fff; }
.lcars-badge--running  { background: var(--color-ops);      color: #000; }
.lcars-badge--pending  { background: #444;                  color: #ccc; }
.lcars-badge--idle     { background: #333;                  color: #aaa; }
.lcars-badge--deploying { background: var(--color-ops);     color: #000; }

.lcars-badge--health-up      { background: var(--color-sciences); color: #000; }
.lcars-badge--health-down    { background: var(--color-command);  color: #fff; }
.lcars-badge--health-unknown { background: #444;                  color: #999; }

.lcars-badge--container-running    { background: var(--color-sciences); color: #000; }
.lcars-badge--container-exited     { background: var(--color-command);  color: #fff; }
.lcars-badge--container-restarting { background: var(--color-ops);      color: #000; }
.lcars-badge--container-unknown    { background: #444;                  color: #999; }

/* --- Buttons --- */
.lcars-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.5rem 1.25rem;
  border-radius: 9999px;
  font-size: 0.8rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  text-decoration: none;
  cursor: pointer;
  border: none;
  transition: opacity 0.15s;
}

.lcars-btn:hover   { opacity: 0.85; }
.lcars-btn:disabled { opacity: 0.4; cursor: not-allowed; }

.lcars-btn--primary   { background: var(--color-brand);    color: #000; }
.lcars-btn--deploy    { background: var(--color-sciences); color: #000; }
.lcars-btn--secondary { background: var(--color-surface-alt); color: var(--color-text); border: 1px solid var(--color-border); }
.lcars-btn--danger    { background: var(--color-command);  color: #fff; }
.lcars-btn--warning   { background: var(--color-ops);      color: #000; }
.lcars-btn--sm { padding: 0.25rem 0.75rem; font-size: 0.7rem; }

/* --- Inputs --- */
.lcars-input {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  color: var(--color-text);
  border-radius: 0.25rem;
  padding: 0.5rem 0.75rem;
  font-size: 0.875rem;
  width: 100%;
  transition: border-color 0.15s;
}

.lcars-input:focus {
  outline: none;
  border-color: var(--color-brand);
  box-shadow: 0 0 0 2px rgba(255, 153, 0, 0.2);
}

.lcars-input--error { border-color: var(--color-command); }

.lcars-input-prefix {
  background: var(--color-surface-alt);
  border: 1px solid var(--color-border);
  border-right: none;
  color: var(--color-text-muted);
  padding: 0.5rem 0.75rem;
  font-size: 0.875rem;
  border-radius: 0.25rem 0 0 0.25rem;
  white-space: nowrap;
}

.lcars-input--prefix-group {
  display: flex;
  border-radius: 0.25rem;
  overflow: hidden;
  border: 1px solid var(--color-border);
}

.lcars-input--prefix-group .lcars-input {
  border: none;
  border-radius: 0;
}

/* --- Labels --- */
.lcars-label {
  display: block;
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--color-text-muted);
  margin-bottom: 0.35rem;
}

/* --- Flash Messages --- */
.lcars-flash--success {
  background: rgba(51, 153, 255, 0.15);
  border-left: 3px solid var(--color-sciences);
  color: var(--color-sciences);
  padding: 0.6rem 1rem;
  border-radius: 0.25rem;
  font-size: 0.875rem;
  margin-bottom: 1rem;
}

/* --- Log output --- */
.lcars-log {
  background: var(--color-log-bg);
  color: var(--color-log-text);
  border-radius: 0.375rem;
  padding: 1rem;
  font-family: ui-monospace, 'Cascadia Code', 'Source Code Pro', monospace;
  font-size: 0.8rem;
  overflow: auto;
  height: 600px;
  white-space: pre-wrap;
}

/* --- Divider --- */
.lcars-divider {
  border: none;
  border-top: 1px solid var(--color-border);
  margin: 1rem 0;
}

/* --- Muted text --- */
.lcars-muted { color: var(--color-text-muted); }
```

- [ ] **Step 4: Update `src/views/layouts/header.ejs`**

Replace entire file content:

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Bridge</title>
    <script src="/theme.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/lcars.css">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body>
<nav class="lcars-nav">
    <a href="/" class="lcars-nav__brand">&#x2721; The Bridge</a>
    <a href="/apps/create" class="lcars-nav__link">+ New App</a>
    <span class="lcars-nav__spacer"></span>
    <a href="/settings" class="lcars-nav__link">Settings</a>
    <button class="lcars-nav__theme-btn" onclick="toggleTheme()">&#9680; Theme</button>
</nav>
<main class="max-w-4xl mx-auto p-6">
    <% if (locals.success && success.length) { %>
        <div class="lcars-flash--success"><%= success[0] %></div>
    <% } %>
```

- [ ] **Step 5: Update `src/views/layouts/footer.ejs`**

Replace entire file content:

```html
</main>
<script src="/theme.js"></script>
</body>
</html>
```

- [ ] **Step 6: Run all tests**

```bash
npx vitest run
```

Expected: all pass (CSS/static changes don't affect server tests).

- [ ] **Step 7: Commit**

```bash
git add public/lcars.css public/theme.js src/app.ts src/views/layouts/header.ejs src/views/layouts/footer.ejs
git commit -m "feat: add LCARS CSS foundation and theme toggle"
```

---

## Task 11: LCARS UI — Update All Views

**Files:**
- Modify: `src/views/apps/index.ejs`
- Modify: `src/views/apps/show.ejs`
- Modify: `src/views/apps/create.ejs`
- Modify: `src/views/apps/edit.ejs`
- Modify: `src/views/deployments/show.ejs`
- Modify: `src/views/settings/index.ejs`

- [ ] **Step 1: Update `src/views/apps/index.ejs`**

Replace entire file:

```html
<%- include('../layouts/header') %>
<div class="flex justify-between items-center mb-6">
    <h1 class="text-xl font-bold tracking-widest uppercase" style="color:var(--color-brand)">Applications</h1>
    <a href="/apps/create" class="lcars-btn lcars-btn--primary lcars-btn--sm">+ New App</a>
</div>
<% if (apps.length === 0) { %>
<p class="lcars-muted text-sm">No apps yet. <a href="/apps/create" style="color:var(--color-sciences)">Create one.</a></p>
<% } else { %>
<% apps.forEach(function(app) {
    const statusClass = app.status === 'success'   ? 'lcars-badge--success'
                      : app.status === 'failed'    ? 'lcars-badge--failed'
                      : app.status === 'deploying' ? 'lcars-badge--deploying'
                      : 'lcars-badge--idle';
    const healthClass = !app.health_url                        ? null
                      : app.last_health_status === 'up'        ? 'lcars-badge--health-up'
                      : app.last_health_status === 'down'      ? 'lcars-badge--health-down'
                      : 'lcars-badge--health-unknown';
    const healthLabel = !app.health_url                        ? null
                      : app.last_health_status === 'up'        ? '● healthy'
                      : app.last_health_status === 'down'      ? '● down'
                      : '○ checking';
%>
<div class="lcars-card p-4 mb-3 flex items-center justify-between">
    <div>
        <a href="/apps/<%= app.id %>" class="font-semibold hover:underline" style="color:var(--color-sciences)"><%= app.name %></a>
        <span class="ml-2 text-xs lcars-muted"><%= app.path %></span>
    </div>
    <div class="flex items-center gap-3">
        <% if (healthClass) { %><span class="lcars-badge <%= healthClass %>"><%= healthLabel %></span><% } %>
        <span class="lcars-badge <%= statusClass %>"><%= app.status %></span>
        <form method="POST" action="/apps/<%= app.id %>/deploy">
            <button class="lcars-btn lcars-btn--deploy lcars-btn--sm">Deploy</button>
        </form>
        <a href="/apps/<%= app.id %>/edit" class="lcars-btn lcars-btn--secondary lcars-btn--sm">Edit</a>
    </div>
</div>
<% }) %>
<% } %>
<%- include('../layouts/footer') %>
```

- [ ] **Step 2: Update `src/views/apps/show.ejs`**

Replace the entire file with the LCARS-styled version. Key changes: replace all Tailwind color classes with LCARS equivalents, replace the containers section state classes, replace the deployment badge logic:

```html
<%- include('../layouts/header') %>
<%
  const statusClass = app.status === 'success'   ? 'lcars-badge--success'
                    : app.status === 'failed'     ? 'lcars-badge--failed'
                    : app.status === 'deploying'  ? 'lcars-badge--deploying'
                    : 'lcars-badge--idle';
  const latestHealth = locals.latestHealth || null;
  const healthClass  = !app.health_url                        ? null
                     : latestHealth && latestHealth.status === 'up'   ? 'lcars-badge--health-up'
                     : latestHealth && latestHealth.status === 'down' ? 'lcars-badge--health-down'
                     : 'lcars-badge--health-unknown';
  const healthLabel  = !app.health_url ? null
                     : latestHealth ? (latestHealth.status === 'up' ? '● healthy' : '● down')
                     : '○ checking';
%>
<div x-data="{
  envOpen: false, envContent: '', envLoading: false, envLoaded: false,
  envSaving: false, envError: '', envSaved: false,
  async loadEnv() {
    this.envLoading = true; this.envLoaded = false; this.envError = ''; this.envSaved = false;
    try {
      const r = await fetch('/apps/<%= app.id %>/env');
      const d = await r.json();
      if (d.error) { this.envError = d.error; } else { this.envContent = d.content; this.envLoaded = true; }
    } catch { this.envError = 'Failed to load'; }
    this.envLoading = false;
  },
  async saveEnv() {
    if (!this.envLoaded || this.envLoading) return;
    this.envSaving = true; this.envError = ''; this.envSaved = false;
    try {
      const r = await fetch('/apps/<%= app.id %>/env', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ content: this.envContent })
      });
      const d = await r.json();
      if (d.error) { this.envError = d.error; } else { this.envSaved = true; }
    } catch { this.envError = 'Failed to save'; }
    this.envSaving = false;
  }
}">

<div class="mb-4">
    <a href="/" class="text-sm lcars-muted hover:underline">← All Applications</a>
</div>

<div class="lcars-card p-6 mb-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold" style="color:var(--color-text)"><%= app.name %></h1>
            <div class="flex items-center gap-2 mt-2">
                <span class="lcars-badge <%= statusClass %>"><%= app.status %></span>
                <% if (healthClass) { %><span class="lcars-badge <%= healthClass %>"><%= healthLabel %></span><% } %>
            </div>
        </div>
        <div class="flex gap-2 shrink-0">
            <form method="POST" action="/apps/<%= app.id %>/deploy">
                <button class="lcars-btn lcars-btn--deploy">Deploy</button>
            </form>
            <button @click="envOpen = true; loadEnv()" class="lcars-btn lcars-btn--secondary">Edit .env</button>
            <a href="/apps/<%= app.id %>/edit" class="lcars-btn lcars-btn--secondary">Edit</a>
        </div>
    </div>

    <hr class="lcars-divider mt-4">

    <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 text-sm mt-4">
        <div>
            <dt class="lcars-label">Repository</dt>
            <dd class="lcars-muted break-all"><%= app.repo_url %></dd>
        </div>
        <div>
            <dt class="lcars-label">Branch</dt>
            <dd class="font-mono" style="color:var(--color-text)"><%= app.branch %></dd>
        </div>
        <div>
            <dt class="lcars-label">Path</dt>
            <dd class="font-mono lcars-muted break-all text-xs"><%= app.path %></dd>
        </div>
        <div>
            <dt class="lcars-label">Ports</dt>
            <dd class="font-mono text-xs lcars-muted space-y-0.5">
                <% if (!ports || ports.length === 0) { %>
                    <span>None</span>
                <% } else { %>
                    <% ports.forEach(function(p) { %>
                    <div><span class="lcars-muted"><%= p.service %>:</span>
                    <%= (p.host_ip ? p.host_ip + ':' : '') + (p.host_port || '—') %> → <%= p.container_port %>/<%= p.protocol %></div>
                    <% }) %>
                <% } %>
            </dd>
        </div>
    </dl>
</div>

<h2 class="lcars-section-header mt-6">Containers</h2>
<div x-data="{ containers: [], loaded: false, async load() { const r = await fetch('/apps/<%= app.id %>/containers'); const d = await r.json(); this.containers = d.containers || []; this.loaded = true; } }" x-init="load()">
  <div x-show="!loaded" class="text-sm lcars-muted">Loading…</div>
  <div x-show="loaded && containers.length === 0" class="text-sm lcars-muted">No containers found.</div>
  <template x-for="c in containers" :key="c.name">
    <div class="lcars-card p-3 mb-2 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <span class="font-mono text-sm" x-text="c.service" style="color:var(--color-text)"></span>
        <span class="lcars-badge"
          :class="{
            'lcars-badge--container-running':    c.state === 'running',
            'lcars-badge--container-exited':     c.state === 'exited',
            'lcars-badge--container-restarting': c.state === 'restarting',
            'lcars-badge--container-unknown':    !['running','exited','restarting'].includes(c.state)
          }"
          x-text="c.state"></span>
        <span class="text-xs lcars-muted font-mono" x-text="c.status"></span>
      </div>
      <span class="text-xs lcars-muted font-mono" x-text="c.ports"></span>
    </div>
  </template>
</div>

<h2 class="lcars-section-header mt-6">Deploy History</h2>
<% if (deployments.length === 0) { %>
<p class="text-sm lcars-muted">No deployments yet.</p>
<% } else { %>
<% deployments.forEach(function(dep) {
    const depClass = dep.status === 'success' ? 'lcars-badge--success'
                   : dep.status === 'failed'  ? 'lcars-badge--failed'
                   : dep.status === 'running' ? 'lcars-badge--running'
                   : 'lcars-badge--pending';
    let durationStr = '—';
    if (dep.started_at && dep.finished_at) {
        const secs = Math.round((new Date(dep.finished_at) - new Date(dep.started_at)) / 1000);
        const m = Math.floor(secs / 60); const s = secs % 60;
        durationStr = m > 0 ? `${m}m ${String(s).padStart(2,'0')}s` : `${s}s`;
    }
%>
<div class="lcars-card lcars-card--command p-3 mb-2 flex items-center justify-between">
    <div class="flex items-center gap-3 flex-wrap">
        <span class="lcars-badge <%= depClass %>"><%= dep.status %></span>
        <span class="text-xs lcars-muted"><%= durationStr %></span>
        <% if (dep.commit_sha) { %><span class="text-xs font-mono lcars-muted"><%= dep.commit_sha.slice(0,7) %></span><% } %>
        <% if (dep.commit_message) { %><span class="text-xs lcars-muted truncate max-w-xs"><%= dep.commit_message %></span><% } %>
        <span class="text-xs lcars-muted"><%= dep.created_at %></span>
    </div>
    <div class="flex items-center gap-2 shrink-0">
        <a href="/deployments/<%= dep.id %>" class="lcars-btn lcars-btn--secondary lcars-btn--sm">Log</a>
        <% if (dep.status === 'success' && dep.commit_sha) { %>
        <form method="POST" action="/apps/<%= app.id %>/rollback" onsubmit="return confirm('Rollback to <%= dep.commit_sha.slice(0,7) %>?')">
            <input type="hidden" name="deployment_id" value="<%= dep.id %>">
            <button type="submit" class="lcars-btn lcars-btn--warning lcars-btn--sm">Rollback</button>
        </form>
        <% } %>
    </div>
</div>
<% }) %>
<% } %>

<!-- .env editor modal -->
<div x-show="envOpen" class="fixed inset-0 z-50 flex items-center justify-center" style="display:none">
    <div class="absolute inset-0 bg-black/70" @click="envOpen = false"></div>
    <div class="relative rounded-lg shadow-xl w-full max-w-2xl mx-4 flex flex-col max-h-[80vh]" style="background:var(--color-surface);border-left:4px solid var(--color-brand)">
        <div class="flex items-center justify-between px-5 py-4" style="border-bottom:1px solid var(--color-border)">
            <h3 class="font-semibold" style="color:var(--color-text)">Edit .env — <%= app.name %></h3>
            <button @click="envOpen = false" class="lcars-muted hover:text-white">✕</button>
        </div>
        <div class="flex-1 overflow-auto px-5 py-4">
            <div x-show="envLoading" class="text-sm lcars-muted">Loading…</div>
            <div x-show="envError" class="text-sm mb-2" style="color:var(--color-command)" x-text="envError"></div>
            <textarea x-show="!envLoading" x-model="envContent" rows="20" spellcheck="false"
                class="lcars-input font-mono text-sm resize-y"
                placeholder="No .env file yet — type to create one"></textarea>
        </div>
        <div class="flex items-center justify-between px-5 py-3" style="border-top:1px solid var(--color-border);background:var(--color-surface-alt);border-radius:0 0 0.375rem 0.375rem">
            <span x-show="envSaved" class="text-sm" style="color:var(--color-sciences)">Saved.</span>
            <span x-show="!envSaved" class="text-sm lcars-muted">Changes are written directly to disk.</span>
            <div class="flex gap-2">
                <button @click="envOpen = false" class="lcars-btn lcars-btn--secondary lcars-btn--sm">Close</button>
                <button @click="saveEnv()" :disabled="envSaving || envLoading || !envLoaded" class="lcars-btn lcars-btn--primary lcars-btn--sm">
                    <span x-text="envSaving ? 'Saving…' : 'Save'">Save</span>
                </button>
            </div>
        </div>
    </div>
</div>

</div>
<%- include('../layouts/footer') %>
```

- [ ] **Step 3: Update `GET /apps/:id` route to pass `latestHealth`**

In `src/routes/apps.ts`, update the show route:

```typescript
router.get('/apps/:id', (req: Request, res: Response) => {
  const app = AppModel.findById(Number(req.params.id));
  if (!app) { res.status(404).send('Not found'); return; }
  const deployments = DeploymentModel.listForApp(app.id);
  const ports = readPortBindings(app.path);
  const latestHealth = HealthCheckModel.findLatest(app.id);
  res.render('apps/show', { app, deployments, ports, latestHealth });
});
```

- [ ] **Step 4: Update `src/views/apps/create.ejs`**

Replace entire file:

```html
<%- include('../layouts/header') %>
<h1 class="text-xl font-bold tracking-widest uppercase mb-6" style="color:var(--color-brand)">New Application</h1>
<form method="POST" action="/apps" class="lcars-card p-6 space-y-4 max-w-xl"
    x-data="{ relative: '', skip: false }"
    x-init="relative = $el.dataset.path; skip = $el.dataset.skip === 'true'"
    data-path="<%= old.path || '' %>"
    data-skip="<%= (old.skip_clone === '1' || old.skip_clone === 'true') ? 'true' : 'false' %>">
    <div>
        <label class="lcars-label">Name</label>
        <input name="name" value="<%= old.name || '' %>" required
            class="lcars-input <%= errors.name ? 'lcars-input--error' : '' %>">
        <% if (errors.name) { %><p class="text-xs mt-1" style="color:var(--color-command)"><%= errors.name.msg %></p><% } %>
    </div>
    <div>
        <label class="lcars-label">Repo URL</label>
        <input name="repo_url" value="<%= old.repo_url || '' %>" required
            placeholder="https://github.com/org/repo.git"
            class="lcars-input <%= errors.repo_url ? 'lcars-input--error' : '' %>">
        <% if (errors.repo_url) { %><p class="text-xs mt-1" style="color:var(--color-command)"><%= errors.repo_url.msg %></p><% } %>
    </div>
    <div>
        <label class="lcars-label">Branch</label>
        <input name="branch" value="<%= old.branch || 'main' %>" required class="lcars-input">
    </div>
    <div>
        <label class="flex items-center gap-2 text-sm cursor-pointer select-none" style="color:var(--color-text)">
            <input type="checkbox" name="skip_clone" value="1" x-model="skip">
            Import existing directory <span class="lcars-muted">(skip clone)</span>
        </label>
        <p class="text-xs mt-1 lcars-muted" x-show="skip">Directory must already exist on disk and be a git repository.</p>
    </div>
    <div>
        <label class="lcars-label">Local Path</label>
        <div class="lcars-input--prefix-group <%= errors.path ? 'border-red-500' : '' %>">
            <span class="lcars-input-prefix"><%= reposPath %>/</span>
            <input name="path" x-model="relative" required placeholder="my-app" class="lcars-input">
        </div>
        <p class="text-xs mt-1 lcars-muted" x-show="relative.trim()">→ <span x-text="'<%= reposPath %>/' + relative.trim()"></span></p>
        <% if (errors.path) { %><p class="text-xs mt-1" style="color:var(--color-command)"><%= errors.path.msg %></p><% } %>
    </div>
    <button type="submit" class="lcars-btn lcars-btn--primary">
        <span x-text="skip ? 'Import' : 'Create &amp; Clone'">Create &amp; Clone</span>
    </button>
</form>
<%- include('../layouts/footer') %>
```

- [ ] **Step 5: Update `src/views/apps/edit.ejs`**

Replace entire file (includes health_url field + LCARS classes + existing webhook section):

```html
<%- include('../layouts/header') %>
<h1 class="text-xl font-bold tracking-widest uppercase mb-6" style="color:var(--color-brand)">Edit <%= app.name %></h1>
<form method="POST" action="/apps/<%= app.id %>" class="lcars-card p-6 space-y-4 max-w-xl">
    <input type="hidden" name="_method" value="PUT">
    <div>
        <label class="lcars-label">Name</label>
        <input name="name" value="<%= app.name %>" required class="lcars-input">
    </div>
    <div>
        <label class="lcars-label">Repo URL</label>
        <input name="repo_url" value="<%= app.repo_url %>" required class="lcars-input">
    </div>
    <div>
        <label class="lcars-label">Branch</label>
        <input name="branch" value="<%= app.branch %>" required class="lcars-input">
    </div>
    <div>
        <label class="lcars-label">Local Path</label>
        <input name="path" value="<%= app.path %>" required class="lcars-input">
    </div>
    <div>
        <label class="lcars-label">Health Check URL <span class="lcars-muted font-normal normal-case">(optional)</span></label>
        <input name="health_url" value="<%= app.health_url || '' %>"
            placeholder="https://myapp.example.com/health" class="lcars-input">
        <p class="text-xs lcars-muted mt-1">Pinged every 60s. Must return 2xx to be considered healthy.</p>
    </div>
    <div class="flex gap-3">
        <button type="submit" class="lcars-btn lcars-btn--primary">Save</button>
        <a href="/apps/<%= app.id %>" class="lcars-btn lcars-btn--secondary">Cancel</a>
    </div>
</form>

<div class="mt-6 lcars-card lcars-card--sciences p-6 max-w-xl">
    <h2 class="lcars-section-header">Auto-Deploy Webhook</h2>
    <% if (app.webhook_secret) { %>
    <div class="mb-4">
        <label class="lcars-label">Webhook URL</label>
        <div class="flex items-center gap-2">
            <input readonly value="<%= `${process.env.APP_URL || 'http://localhost:3000'}/apps/${app.id}/webhook` %>"
                class="lcars-input font-mono text-sm">
            <button type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)"
                class="lcars-btn lcars-btn--secondary lcars-btn--sm shrink-0">Copy</button>
        </div>
    </div>
    <div class="mb-4">
        <label class="lcars-label">Secret</label>
        <p class="font-mono text-sm lcars-muted"><%= app.webhook_secret.slice(0,8) %>••••••••••••••••••••••••</p>
    </div>
    <form method="POST" action="/apps/<%= app.id %>/webhook-secret"
          onsubmit="return confirm('Regenerate secret? Existing GitHub webhooks using the old secret will stop working.')">
        <button type="submit" class="lcars-btn lcars-btn--warning lcars-btn--sm">Regenerate Secret</button>
    </form>
    <% } else { %>
    <p class="text-sm lcars-muted mb-3">No webhook secret yet. Generate one to enable auto-deploy on git push.</p>
    <form method="POST" action="/apps/<%= app.id %>/webhook-secret">
        <button type="submit" class="lcars-btn lcars-btn--primary">Generate Webhook Secret</button>
    </form>
    <% } %>
    <p class="text-xs lcars-muted mt-4">Add this URL as a GitHub webhook (push events, content type: application/json).</p>
</div>

<% const _confirmMsg = sourceExists
  ? `This will:\n1. Stop and remove Docker containers\n2. Delete source folder: ${app.path}\n3. Remove app from database\n\nContinue?`
  : `Source folder not found at ${app.path}.\nThis will remove the app from the database only.\n\nContinue?` %>
<form method="POST" action="/apps/<%= app.id %>" class="mt-6 max-w-xl">
    <input type="hidden" name="_method" value="DELETE">
    <button type="submit" onclick="return confirm(<%- JSON.stringify(_confirmMsg) %>)"
        class="lcars-btn lcars-btn--danger lcars-btn--sm">Delete App</button>
</form>
<%- include('../layouts/footer') %>
```

- [ ] **Step 6: Update `src/views/deployments/show.ejs`**

Replace entire file:

```html
<%- include('../layouts/header') %>
<div x-data="{ log: <%= JSON.stringify(deployment.log || '') %>, status: <%= JSON.stringify(deployment.status) %>, done: <%= ['success', 'failed'].includes(deployment.status) %>, init() { if (this.done) return; const es = new EventSource('/deployments/<%= deployment.id %>/stream'); es.onmessage = (e) => { const data = JSON.parse(e.data); if (data.text) { this.log += data.text; this.$nextTick(() => { const el = this.$refs.logbox; el.scrollTop = el.scrollHeight; }); } if (data.done) { this.status = data.status; this.done = true; es.close(); } }; } }">
    <div class="flex items-center gap-3 mb-4 flex-wrap">
        <a href="/apps/<%= deployment.app_id %>" class="lcars-muted text-sm hover:underline">← <%= deployment.app_name %></a>
        <span
            class="lcars-badge"
            :class="{
                'lcars-badge--success': status === 'success',
                'lcars-badge--failed':  status === 'failed',
                'lcars-badge--running': status === 'running',
                'lcars-badge--pending': status === 'pending'
            }"
            x-text="status"
        ></span>
        <% if (['running', 'pending'].includes(deployment.status)) { %>
        <form method="POST" action="/deployments/<%= deployment.id %>/reset" x-show="!done">
            <button type="submit" class="lcars-btn lcars-btn--danger lcars-btn--sm"
                onclick="return confirm('Mark this deployment as failed?')">
                Reset
            </button>
        </form>
        <% } %>
    </div>

    <pre
        x-ref="logbox"
        x-text="log || 'Waiting for output...'"
        class="lcars-log"
    ></pre>
</div>
<%- include('../layouts/footer') %>
```

- [ ] **Step 7: Update `src/views/settings/index.ejs`**

Replace entire file:

```html
<%- include('../layouts/header') %>
<h1 class="text-xl font-bold tracking-widest uppercase mb-6" style="color:var(--color-brand)">Settings</h1>

<form method="POST" action="/settings" class="lcars-card lcars-card--sciences p-6 space-y-4 max-w-xl">
    <div>
        <label class="lcars-label">Slack Webhook URL</label>
        <input name="slack_webhook_url"
            value="<%= slackWebhookUrl %>"
            placeholder="https://hooks.slack.com/services/…"
            class="lcars-input font-mono text-sm">
        <p class="text-xs lcars-muted mt-1">
            Receives a message when any deployment succeeds or fails.
            <a href="https://api.slack.com/messaging/webhooks" style="color:var(--color-sciences)" target="_blank">How to create one →</a>
        </p>
    </div>
    <div class="flex gap-3">
        <button type="submit" class="lcars-btn lcars-btn--primary">Save</button>
        <% if (slackWebhookUrl) { %>
        <button type="submit" name="test_slack" value="1" class="lcars-btn lcars-btn--secondary">
            Send Test
        </button>
        <% } %>
    </div>
</form>
<%- include('../layouts/footer') %>
```

- [ ] **Step 8: Run all tests**

```bash
npx vitest run
```

Expected: all pass.

- [ ] **Step 9: Start dev server and visually verify all pages**

```bash
npm run dev
```

Check each page:
- `/` — app list with LCARS cards and badges, dark/light toggle works
- `/apps/create` — LCARS form, amber brand color
- `/apps/:id` — detail page with container section, rollback buttons, health badge
- `/apps/:id/edit` — health_url field, webhook section
- `/deployments/:id` — green-on-black log viewer
- `/settings` — Slack URL form

Toggle dark/light mode via the nav button, confirm colors switch correctly on all pages.

- [ ] **Step 10: Final commit**

```bash
git add src/views/apps/index.ejs src/views/apps/show.ejs src/views/apps/create.ejs src/views/apps/edit.ejs src/views/deployments/show.ejs src/views/settings/index.ejs src/routes/apps.ts
git commit -m "feat: apply LCARS UI theme across all views"
```

---

## Verification Checklist

| Feature | Manual check |
|---------|-------------|
| Container status | Deploy app → app detail shows container names + states |
| Health checks | Set `health_url` in Edit → wait 60s → badge appears on list + detail |
| Rollback | Deploy twice → rollback button on older deployment → new deployment created at old SHA |
| Webhooks | Generate secret → add to GitHub webhook → push to branch → deployment auto-triggers |
| Slack | Set URL in Settings → Send Test → confirm message in Slack channel |
| Light/dark mode | Click Theme button → colors switch → survives page reload |
| LCARS theme | All badge colors match: sciences-blue=healthy/running, command-red=failed/down, ops-gold=pending/warning |
