import Database from 'better-sqlite3';

let _db: Database.Database | null = null;

export function openDb(path?: string): Database.Database {
  const dbPath = path || process.env.DB_PATH || '/data/bridge.db';
  _db = new Database(dbPath);
  _db.pragma('journal_mode = WAL');
  _db.pragma('foreign_keys = ON');
  bootstrapSchema(_db);
  return _db;
}

export function getDb(): Database.Database {
  if (!_db) openDb();
  return _db!;
}

function bootstrapSchema(db: Database.Database): void {
  db.exec(`
    CREATE TABLE IF NOT EXISTS apps (
      id         INTEGER PRIMARY KEY AUTOINCREMENT,
      name       TEXT NOT NULL,
      repo_url   TEXT NOT NULL,
      branch     TEXT NOT NULL DEFAULT 'main',
      path       TEXT NOT NULL,
      status     TEXT NOT NULL DEFAULT 'idle',
      created_at TEXT NOT NULL DEFAULT (datetime('now')),
      updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS deployments (
      id          INTEGER PRIMARY KEY AUTOINCREMENT,
      app_id      INTEGER NOT NULL REFERENCES apps(id) ON DELETE CASCADE,
      status      TEXT NOT NULL DEFAULT 'pending',
      log         TEXT,
      started_at  TEXT,
      finished_at TEXT,
      created_at  TEXT NOT NULL DEFAULT (datetime('now')),
      updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS jobs (
      id           INTEGER PRIMARY KEY AUTOINCREMENT,
      queue        TEXT    NOT NULL DEFAULT 'default',
      payload      TEXT    NOT NULL,
      attempts     INTEGER NOT NULL DEFAULT 0,
      reserved_at  INTEGER,
      available_at INTEGER NOT NULL DEFAULT (unixepoch()),
      created_at   INTEGER NOT NULL DEFAULT (unixepoch())
    );

    CREATE INDEX IF NOT EXISTS jobs_queue_idx ON jobs(queue);

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
  `);

  const migrations: string[] = [
    'ALTER TABLE apps ADD COLUMN health_url TEXT',
    'ALTER TABLE apps ADD COLUMN health_check_interval INTEGER NOT NULL DEFAULT 60',
    'ALTER TABLE apps ADD COLUMN webhook_secret TEXT',
    'ALTER TABLE deployments ADD COLUMN commit_sha TEXT',
    'ALTER TABLE deployments ADD COLUMN commit_message TEXT',
    'ALTER TABLE deployments ADD COLUMN rollback_sha TEXT',
    'ALTER TABLE apps ADD COLUMN deploy_steps TEXT',
  ];
  for (const sql of migrations) {
    try { db.exec(sql); } catch { /* column already exists */ }
  }
}

export function resetStuckDeployments(db: Database.Database): number {
  const stuck = db.prepare(
    "SELECT id FROM deployments WHERE status IN ('running', 'pending')"
  ).all() as { id: number }[];

  const updateDeployment = db.prepare(
    "UPDATE deployments SET status = 'failed', finished_at = datetime('now'), " +
    "log = COALESCE(log, '') || '\n[Bridge] Container restarted — deployment interrupted.\n' " +
    "WHERE id = ?"
  );
  for (const row of stuck) updateDeployment.run(row.id);

  db.prepare("UPDATE apps SET status = 'failed' WHERE status = 'deploying'").run();

  return stuck.length;
}

export function resetDb(): void {
  if (_db) {
    _db.close();
    _db = null;
  }
}
