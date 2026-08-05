---
description: Deploy and monitor an app managed by the-bridge (Docker deployment manager on Unraid), using the bridge-api-guide MCP tools. Use when the user asks to deploy, redeploy, ship, or push an app the-bridge manages. Generic mechanics only — a consuming repo supplies its own environment table.
name: the-bridge-deploy
---

# the-bridge deploy

Mechanics for triggering and following a deployment. This skill is environment-agnostic: it does not know which app you mean. A consuming repo must supply its own environment table (see "What a consuming repo must provide").

## Rule: MCP tools only

**Never call the-bridge REST API directly.** No `curl`, no HTTP client, no shell one-liner — including for read-only polling, status checks, or listing apps. Every operation below has an MCP tool. There is no case where reaching for the API is the right move; if a tool appears to be missing, stop and say so rather than falling back to HTTP.

The `bridge-api-guide` MCP server holds the base URL and bearer token itself (`BRIDGE_API_BASE_URL` / `BRIDGE_API_TOKEN` in its own environment). You never handle the token, never print it, and never `source .env` to get it.

| tool | signature | returns |
|---|---|---|
| `mcp__bridge-api-guide__list_apps` | `()` | `[{id, name, branch, status, repo_url}]` |
| `mcp__bridge-api-guide__list_branches` | `(repo_url)` | `{branches: [...]}` — no auth |
| `mcp__bridge-api-guide__deploy_app` | `(app_id: int)` | `{deployment_id, app_id, status}` |
| `mcp__bridge-api-guide__get_deployment` | `(deployment_id: int)` | `{id, app_id, app_name, status, commit_sha, commit_message, started_at, finished_at, log_length}` |
| `mcp__bridge-api-guide__get_deployment_log` | `(deployment_id: int, offset: int = 0)` | `{text, log_offset, deploy_status, deploy_done}` |

`GET /api/openapi.json` is the full machine-readable contract — read it if you need to understand the API's shape, but it is not an invitation to call the API.

If anything here looks stale (server name, tool set, host), re-resolve via `mcp__homelab-kb-http__query_service("the-bridge")` before proceeding.

## Resolving which app

`deploy_app` takes a literal integer id. Do not guess it, and do not read it out of a `.env` file — resolve it through `list_apps()` and confirm the match:

1. Call `list_apps()`.
2. Match on `repo_url` **and** `branch`. Repos with more than one environment register the same `repo_url` under different branches — **branch is the only discriminator**, so matching on name or repo alone will eventually deploy the wrong environment.
3. Echo back the resolved `{id, name, branch}` before triggering. If exactly one candidate does not fall out, ask rather than pick.

Safety rules — these govern every deploy:

1. **An unqualified "deploy" means the non-production environment.** Never resolve an unqualified request to production.
2. **Production requires an explicit production word** — prod, production, live, "ship it" — in the *user's own* message. Confirm once, then proceed.
3. the-bridge deploys **the branch registered on the app**, not your working tree. Push to that branch first, and say which commit you expect to land.
4. **Refuse a production deploy while the working tree is on a non-production branch**, unless the user overrides explicitly.

## Flow

1. **Resolve** as above. State the environment out loud before firing.

2. **Trigger:** `deploy_app(app_id)` → `{deployment_id, app_id, status: "pending"}`. This is fire-and-forget — the deployment is *queued*, not finished. Never report success off this response.

3. **Follow the log.** Poll `get_deployment_log(deployment_id, offset)`:
   - Start at `offset=0`.
   - Print `text` when non-empty.
   - Set the next `offset` to the returned `log_offset`.
   - Stop when `deploy_done` is `true` (a real bool — no string comparison).
   - Space calls a couple of seconds apart. Don't tail in a tight loop.

4. **Confirm what shipped:** `get_deployment(deployment_id)` for the terminal record. Check `status` for `success` vs `failed`, and **verify `commit_sha` is the commit you meant to ship on the branch you meant to ship it from.** This is the last point at which a wrong-environment deploy is still catchable.

## Gotchas

- **`deploy_done` does not mean success.** It means a terminal state was reached. Read `deploy_status` (or `get_deployment().status`) for `success` vs `failed`.
- **A failed deploy triggers the-bridge's auto-rollback**, which enqueues a *second* deployment of the previous successful commit. Expect two deployment records; the second one is not someone else deploying.
- **Don't deploy two environments of the same repo at once.** Each deploy is a `compose down` / `up --build`, so concurrent builds of the same Dockerfile contend on the shared build cache.
- **`list_branches` never raises on failure** — it answers 200 with `{"branches": [], "error": "..."}`. If `branches` is empty, check for an `error` key instead of treating it as "no branches".
- **A 503 is not a 401.** 503 means the-bridge server has no API token configured; 401 means the token you sent is wrong. Other failures surface as `{"error": "..."}`.

## What a consuming repo must provide

This skill deliberately holds no environment facts. A repo deploying through the-bridge needs its own skill or `CLAUDE.md` section supplying:

- the environment table — which environments exist, which branch each deploys, where each is published
- any branch-promotion rules (which branch merges into which, and which never do)
- repo-specific deploy-time validation (entrypoint env vars, required build args)
- any `.claude/settings.json` permission entries, written against **MCP tool names** (`mcp__bridge-api-guide__deploy_app`), never Bash command prefixes — a curl-prefix rule silently stops matching once the flow is MCP.
