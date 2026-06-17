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

## Install

```bash
cd bridge-mcp
uv sync           # or: pip install -e .[dev]
```

## Run (stdio)

```bash
uv run bridge-mcp            # or: python -m bridge_mcp.server
```

Inspect interactively with the MCP Inspector:

```bash
uv run mcp dev bridge_mcp/server.py
```

## Wire it into a repo

```bash
uv run bridge-mcp-init                 # auto-detects repo root
uv run bridge-mcp-init --dry-run       # preview changes
uv run bridge-mcp-init --force         # overwrite an existing .mcp.json entry
```

This (1) adds a `bridge-api-guide` stdio entry to the repo's `.mcp.json`
(backing up the old file to `.mcp.json.bak`, leaving other servers untouched),
and (2) installs an agent-instruction block into `AGENTS.md` (idempotent — the
fenced block is replaced in place on re-runs, never duplicated).

Registered manually, the `.mcp.json` entry is:

```json
{
  "mcpServers": {
    "bridge-api-guide": {
      "command": "uv",
      "args": ["run", "--directory", "bridge-mcp", "bridge-mcp"]
    }
  }
}
```

(For non-uv setups, use `"command": "python", "args": ["-m", "bridge_mcp.server"]`
with the package installed.)

## Test

```bash
uv run pytest
```
