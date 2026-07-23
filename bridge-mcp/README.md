# bridge-mcp

An MCP server for [The Bridge](../) deployment API.

It exposes real **tools** that call the `/api` endpoints over HTTP, plus
authored *resources* and *prompts* that explain the contract and recommended
workflow. Think of it as a live, workflow-aware companion to the OpenAPI
schema at `/api/openapi.json`.

## What it exposes

**Tools** (see [Configuration](#configuration) for required env vars)
- `list_branches(repo_url)` — no auth.
- `list_apps()` — bearer auth.
- `deploy_app(app_id)` — bearer auth. Queues a deployment; returns immediately.
- `get_deployment(deployment_id)` — bearer auth.
- `get_deployment_log(deployment_id, offset=0)` — bearer auth. Call again with
  the returned `log_offset` to tail incrementally, until `deploy_done`.

**Resources**
- `bridge://api/overview` — base URL, bearer-token auth, error model.
- `bridge://api/actions` — index of documented actions.
- `bridge://api/actions/{action}` — full guide for one action. Slugs:
  `list-branches`, `list-apps`, `deploy-app`, `get-deployment`,
  `get-deployment-log`.

**Prompts (guided workflows)**
- `deploy_and_watch(app_id)` — deploy and tail the log to a terminal status.
- `find_and_deploy_branch(repo_url)` — resolve the app from a repo URL, deploy, watch.
- `check_deploy_status(deployment_id)` — report current status + recent log.

## Configuration

The tools read these environment variables at call time (set them in the
MCP client config that launches this server):

- `BRIDGE_API_BASE_URL` — required. The Bridge's API base, e.g.
  `http://localhost:3000/api`.
- `BRIDGE_API_TOKEN` — required for every tool except `list_branches`. Must
  match the token The Bridge is configured with (`BRIDGE_API_TOKEN` env var or
  the `api_token` settings field on that server).

## Installing

### Prerequisite

[`uv`](https://docs.astral.sh/uv/) installed. That's it — `uvx` fetches and
caches `bridge-mcp` straight from GitHub on first launch. No `pip install`,
no cloning this repo, nothing to put on PATH.

### Choose a scope

Two ways to register the server — pick one:

- **Global / user scope** (recommended default): register once per machine,
  credentials baked in at registration time, available in every project
  automatically. No per-repo config file, no re-exporting env vars. Best if
  you're the only one who needs it, or each teammate is fine registering it
  themselves.
- **Per-project**: the server registration itself is committed to a repo's
  `.mcp.json` so any teammate who clones it gets it automatically. Trades
  that convenience for needing `BRIDGE_API_BASE_URL` / `BRIDGE_API_TOKEN` to
  be present in the environment every time `claude` launches.

Either way, every app repo whose agents should deploy through it also needs
the `AGENTS.md` steer — that part is scope-independent (see step 3 of Option
A below).

### Option A — Global / user scope

1. Register the server, once, for this machine:
   ```bash
   claude mcp add bridge-api-guide --scope user \
     -e BRIDGE_API_BASE_URL=http://localhost:3000/api \
     -e BRIDGE_API_TOKEN=<token> \
     -- uvx --from git+https://github.com/panda4man/the-bridge.git#subdirectory=bridge-mcp bridge-mcp
   ```
   Pulling the values from an existing `.env` instead of hand-typing the
   token — `claude mcp add --scope user` only takes literal `-e` values, so
   export into the shell first and let it fill in the literal:
   ```bash
   set -a; source .env; set +a   # exports BRIDGE_API_BASE_URL / BRIDGE_API_TOKEN
   claude mcp add bridge-api-guide --scope user \
     -e BRIDGE_API_BASE_URL="$BRIDGE_API_BASE_URL" \
     -e BRIDGE_API_TOKEN="$BRIDGE_API_TOKEN" \
     -- uvx --from git+https://github.com/panda4man/the-bridge.git#subdirectory=bridge-mcp bridge-mcp
   ```
   The values are written into `~/.claude.json` at add-time — nothing to
   export on later launches. Re-run the same command (it overwrites)
   whenever the token rotates.
2. Verify: `claude mcp list` should show `bridge-api-guide`.
3. In each app repo whose agents should deploy through it, add the steer so
   they know it's there. Registration is already global, so only the
   `AGENTS.md` block is needed — skip `.mcp.json` with `--instructions-only`:
   ```bash
   uvx --from git+https://github.com/panda4man/the-bridge.git#subdirectory=bridge-mcp \
     bridge-mcp-init --instructions-only --repo /path/to/that-app
   # or just run inside the repo:  bridge-mcp-init --instructions-only
   ```
   Commit the resulting `AGENTS.md` block.
4. Done. Any agent in that repo now knows to reach for `list_apps` /
   `deploy_app` / `get_deployment` / `get_deployment_log` when asked to
   deploy.

### Option B — Per-project (commit into one repo, share with teammates)

1. From inside the target repo, run `bridge-mcp-init`. This needs the
   `bridge-mcp` package importable — either `uvx --from git+...`, or from a
   local checkout of this repo:
   ```bash
   uvx --from git+https://github.com/panda4man/the-bridge.git#subdirectory=bridge-mcp bridge-mcp-init
   bridge-mcp-init --dry-run       # preview only, write nothing
   bridge-mcp-init --mcp-only      # only the .mcp.json entry, skip AGENTS.md
   bridge-mcp-init --force         # overwrite an existing .mcp.json entry
   ```
   With no flags this writes both `.mcp.json` and the `AGENTS.md` block.
   Re-running is idempotent — an existing entry/block is left in place
   unless `--force` is given.

2. The `.mcp.json` entry it writes includes `env` with `${VAR}` placeholders
   — Claude Code expands these from its own environment each time it
   starts, so no secret ever touches the committed file:
   ```json
   {
     "mcpServers": {
       "bridge-api-guide": {
         "command": "uvx",
         "args": [
           "--from",
           "git+https://github.com/panda4man/the-bridge.git#subdirectory=bridge-mcp",
           "bridge-mcp"
         ],
         "env": {
           "BRIDGE_API_BASE_URL": "${BRIDGE_API_BASE_URL:-http://localhost:3000/api}",
           "BRIDGE_API_TOKEN": "${BRIDGE_API_TOKEN}"
         }
       }
     }
   }
   ```

3. Commit `.mcp.json` and the `AGENTS.md` block.

4. Each teammate needs `BRIDGE_API_BASE_URL` / `BRIDGE_API_TOKEN` exported
   before launching `claude`:
   ```bash
   set -a; source .env; set +a
   claude
   ```
   Claude Code has no untracked local-override file for `.mcp.json` (there
   is no such thing as `.mcp.json.local`) — that env has to come from
   somewhere at launch time. To avoid re-exporting every session, put the
   `export` lines in your shell profile (`~/.zshrc` / `~/.bashrc`) once, or
   use Option A (global scope) instead, which bakes the value in at
   registration time and needs no export at all.

By default the entry tracks the default branch. Pin a tag/commit for
reproducibility by appending `@<ref>` before the `#subdirectory` fragment,
e.g. `git+https://github.com/panda4man/the-bridge.git@v0.1.0#subdirectory=bridge-mcp`.

## Local development

```bash
cd bridge-mcp
uv sync                          # install deps incl. dev
uv run bridge-mcp                # run server over stdio
uv run mcp dev bridge_mcp/server.py   # MCP Inspector
uv run pytest                    # 44 tests
```

Run the server against a real Bridge instance by setting
`BRIDGE_API_BASE_URL` / `BRIDGE_API_TOKEN` before `uv run bridge-mcp` or
`uv run mcp dev`.
