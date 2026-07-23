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

## Use it across ALL your projects (recommended)

The goal: agents in any of your apps know how to deploy that app to The Bridge,
without hand-editing each repo's MCP config.

### 1. Install the server once, globally

```bash
uv tool install --editable /abs/path/to/the-bridge/bridge-mcp
# or, from inside this dir:  uv tool install --editable .
```

This puts `bridge-mcp` and `bridge-mcp-init` on your PATH, independent of where
the-bridge is checked out. `--editable` makes source edits take effect without
reinstalling. (Drop `--editable` for a pinned snapshot; then pick up changes
with `uv tool upgrade bridge-mcp`.)

### 2. Register it for every project (user scope)

```bash
claude mcp add bridge-api-guide --scope user \
  -e BRIDGE_API_BASE_URL=http://localhost:3000/api \
  -e BRIDGE_API_TOKEN=<token> \
  -- bridge-mcp
```

User scope = available in **every** project on this machine. Verify with
`claude mcp list`. (No per-repo `.mcp.json` needed.)

### 3. Tell each app's agents to use it

In each app repo, drop the deploy-instruction block (idempotent, replaceable):

```bash
bridge-mcp-init --instructions-only --repo /path/to/that-app
# or just run inside the repo:  bridge-mcp-init --instructions-only
```

This writes only the `AGENTS.md` block (skips `.mcp.json`, since registration is
global). Commit it so the steer travels with the repo. Re-running replaces the
block in place.

Each agent still needs the per-app runtime inputs to actually deploy: The Bridge
base URL, a bearer token, and the app's id — these are operator-supplied, not
baked into the server.

## Per-project alternative (share with teammates)

To commit the server into a single repo instead of global registration:

```bash
bridge-mcp-init                 # both: .mcp.json entry + AGENTS.md block
bridge-mcp-init --dry-run       # preview
bridge-mcp-init --mcp-only      # only the .mcp.json entry
bridge-mcp-init --force         # overwrite an existing entry
```

The emitted `.mcp.json` entry uses the bare on-PATH command, so teammates must
also `uv tool install` the package. Add the required env vars to it before
committing (or override them locally with `.mcp.json.local` / your client's
env support):

```json
{
  "mcpServers": {
    "bridge-api-guide": {
      "command": "bridge-mcp",
      "env": {
        "BRIDGE_API_BASE_URL": "http://localhost:3000/api",
        "BRIDGE_API_TOKEN": "<token>"
      }
    }
  }
}
```

## Local development

```bash
cd bridge-mcp
uv sync                          # install deps incl. dev
uv run bridge-mcp                # run server over stdio
uv run mcp dev bridge_mcp/server.py   # MCP Inspector
uv run pytest                    # 41 tests
```

Run the server against a real Bridge instance by setting
`BRIDGE_API_BASE_URL` / `BRIDGE_API_TOKEN` before `uv run bridge-mcp` or
`uv run mcp dev`.
