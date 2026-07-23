# bridge-mcp

An MCP server for [The Bridge](../) deployment API.

It exposes real **tools** that call the `/api` endpoints over HTTP, plus
authored *resources* and *prompts* that explain the contract and recommended
workflow. Think of it as a live, workflow-aware companion to the OpenAPI
schema at `/api/openapi.json`.

## Quickstart

1. Prereq: [`uv`](https://docs.astral.sh/uv/) installed. That's it — no
   `pip install`, no cloning this repo.
2. Register the server, once, user-scope (available in every project on this
   machine). Pulling the values from an existing `.env` instead of typing the
   token? See [step 1 below](#1-register-it-for-every-project-user-scope):
   ```bash
   claude mcp add bridge-api-guide --scope user \
     -e BRIDGE_API_BASE_URL=http://localhost:3000/api \
     -e BRIDGE_API_TOKEN=<token> \
     -- uvx --from git+https://github.com/panda4man/the-bridge.git#subdirectory=bridge-mcp bridge-mcp
   ```
3. Verify: `claude mcp list` should show `bridge-api-guide`.
4. In each app repo whose agents should deploy through it, add the steer so
   they know it's there:
   ```bash
   uvx --from git+https://github.com/panda4man/the-bridge.git#subdirectory=bridge-mcp \
     bridge-mcp-init --instructions-only --repo /path/to/that-app
   ```
   Commit the resulting `AGENTS.md` block.
5. Done. Any agent in that repo now knows to reach for `list_apps` /
   `deploy_app` / `get_deployment` / `get_deployment_log` when asked to deploy.

Need a single repo to own its own config instead of user-scope (e.g. to share
via a committed `.mcp.json`)? See [Per-project alternative](#per-project-alternative-share-with-teammates)
below.

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

No install step — `uvx` fetches and caches `bridge-mcp` straight from GitHub on
first launch. Nothing to put on PATH, nothing to upgrade by hand.

### 1. Register it for every project (user scope)

`claude mcp add --scope user` has no live env-var expansion — `-e` only takes
literal values, baked in at add-time. To pull from an existing `.env` instead
of hand-typing the token, export it into the shell first and let the shell
fill in the literal:

```bash
set -a; source .env; set +a   # exports BRIDGE_API_BASE_URL / BRIDGE_API_TOKEN
claude mcp add bridge-api-guide --scope user \
  -e BRIDGE_API_BASE_URL="$BRIDGE_API_BASE_URL" \
  -e BRIDGE_API_TOKEN="$BRIDGE_API_TOKEN" \
  -- uvx --from git+https://github.com/panda4man/the-bridge.git#subdirectory=bridge-mcp bridge-mcp
```

Re-run `claude mcp add ... --scope user` (it overwrites) whenever the token
rotates. User scope = available in **every** project on this machine. Verify
with `claude mcp list`. (No per-repo `.mcp.json` needed.)

Want the value to actually live-resolve from the environment on every launch
instead of being baked in once? That's only supported for project-scope
`.mcp.json` — see [Per-project alternative](#per-project-alternative-share-with-teammates).

### 2. Tell each app's agents to use it

In each app repo, drop the deploy-instruction block (idempotent, replaceable).
This needs the `bridge-mcp` package importable — either `uvx --from git+... bridge-mcp-init`,
or from a local checkout of this repo:

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

The emitted `.mcp.json` entry launches via `uvx --from git+...`, so any
teammate with `uv` installed can use it as-is — no local checkout, no global
install.

Claude Code expands `${VAR}` / `${VAR:-default}` inside `.mcp.json` values,
resolved from Claude Code's own environment each time it starts — so use
placeholders instead of literal secrets. No token ever touches the committed
file; each teammate just needs `BRIDGE_API_BASE_URL` / `BRIDGE_API_TOKEN`
exported (e.g. `set -a; source .env; set +a`) before launching `claude`:

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

This is the version to commit. (`.mcp.json.local` / literal values still work
if you'd rather not rely on shell export.)

By default this tracks the default branch. Pin a tag/commit for
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
