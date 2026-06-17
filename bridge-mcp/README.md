# bridge-mcp

An **instruction-only** MCP server for [The Bridge](../) deployment API.

It does **not** call The Bridge. It serves authored guidance — MCP *resources*
and *prompts* — that tells an AI agent exactly how to call the `/api` endpoints
itself (with the agent's own bearer token). Think of it as a machine-readable,
workflow-aware companion to the OpenAPI schema at `/api/openapi.json`.

## What it exposes

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

No tools are registered — by design, the server never performs HTTP requests.

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
claude mcp add bridge-api-guide --scope user -- bridge-mcp
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
also `uv tool install` the package:

```json
{ "mcpServers": { "bridge-api-guide": { "command": "bridge-mcp" } } }
```

## Local development

```bash
cd bridge-mcp
uv sync                          # install deps incl. dev
uv run bridge-mcp                # run server over stdio
uv run mcp dev bridge_mcp/server.py   # MCP Inspector
uv run pytest                    # 22 tests
```
