# the-bridge

Docker deployment manager. Register Git repos, trigger deploys via the web UI, stream live logs over SSE.

## Stack

- **Node.js 20+** / TypeScript / Express
- **SQLite** (better-sqlite3) — single file, no external DB
- **SSE** for real-time deploy log streaming
- **supervisord** — runs web server + queue worker in one Docker container

## Local development

```bash
nvm use 20
npm install
```

Two processes required — run each in a separate terminal:

```bash
# Terminal 1 — web server
npm start
# → http://localhost:3000

# Terminal 2 — queue worker (processes deploy jobs)
npm run worker
```

Both scripts load `.env` automatically. Copy `.env.example` to `.env` to customise.

### Environment variables

| Variable | Default | Description |
|---|---|---|
| `DB_PATH` | `/data/bridge.db` | SQLite database path — override locally |
| `REPOS_PATH` | `/repos` | Directory where app repos are cloned |
| `PORT` | `3000` | Web server port |
| `SESSION_SECRET` | `bridge-secret` | Express session secret |
| `BRIDGE_SSH_KEY_PATH` | `/data/ssh/id_rsa` | SSH key for private repo access |

## Docker

```bash
docker build -f docker/Dockerfile -t the-bridge .

docker run -d \
  -p 3000:3000 \
  -v /var/run/docker.sock:/var/run/docker.sock \
  -v bridge-data:/data \
  -v /path/to/repos:/repos \
  the-bridge
```

The container runs both the web server and queue worker via supervisord. The `/data` volume holds the SQLite database and SSH keys.

### SSH key for private repos

```bash
# Place key in the /data/ssh volume before first deploy
docker cp ~/.ssh/id_rsa <container>:/data/ssh/id_rsa
```

## For AI agents

Use **Sonnet** (`claude-sonnet-4-6`) for implementation — editing files, writing code, running tests.
Use **Opus** (`claude-opus-4-8`) for planning — architecture decisions, task decomposition, design review.

See `AGENTS.md` for code navigation policy and jCodemunch tool usage.

## Tests

```bash
npm test              # run all 35 tests
npm run test:watch    # watch mode
npm run typecheck     # tsc --noEmit
```
