"""Static, authored guidance for The Bridge deployment API.

Nothing here performs network I/O. This module is the single source of truth
for what the MCP server tells agents. Keep it in sync with the live contract in
the main app's ``src/openapi.ts`` and ``src/routes/api.ts``.
"""

API_BASE = "/api"

OVERVIEW = """\
# The Bridge Deployment API — Agent Guide

This MCP server exposes real **tools** (`list_branches`, `list_apps`,
`deploy_app`, `get_deployment`, `get_deployment_log`) that call The Bridge
over HTTP for you — plus these resources and prompts, which explain the
contract and the recommended workflow so you use the tools correctly.

## Base URL
The tools call `{base}` on the host where The Bridge runs, configured via the
`BRIDGE_API_BASE_URL` environment variable set for this MCP server
(e.g. `http://localhost:3000{base}`). The OpenAPI 3.0 schema is served at
`{base}/openapi.json` and interactive docs at `{base}/docs`.

## Authentication
Protected endpoints require a bearer token:

    Authorization: Bearer <token>

The tools read it from the `BRIDGE_API_TOKEN` environment variable set for
this MCP server — it is not passed as a tool argument. On the Bridge side, the
token is configured via the `BRIDGE_API_TOKEN` env var or the `api_token`
settings field.

## Conventions & error model
- Responses are JSON unless noted (the deployment log endpoint returns
  `text/plain`).
- `401 Unauthorized` — missing or invalid bearer token.
- `404 Not Found` — `{{"error": "Not found"}}` for unknown app/deployment ids.
- `503 Service Unavailable` — the server has no API token configured at all.
- Deployments are asynchronous: triggering one returns `202` immediately with a
  `deployment_id`; you then poll for status and tail the log.

## Available action guides
Read `bridge://api/actions/<slug>` for any of:
- `list-branches`
- `list-apps`
- `deploy-app`
- `get-deployment`
- `get-deployment-log`
""".format(base=API_BASE)


# One entry per action slug. Keep keys stable — resources and prompts key off them.
ENDPOINTS: dict[str, dict] = {
    "list-branches": {
        "title": "List remote git branches",
        "method": "GET",
        "path": "/branches",
        "auth": "none",
        "params": "Query: `repo_url` (string, required) — the remote git URL.",
        "response": (
            "200 `{\"branches\": string[]}`. `main`/`master` are sorted first. "
            "On failure the call still returns 200 with `{\"branches\": [], "
            "\"error\": \"Failed to fetch branches\"}`; an empty/missing "
            "`repo_url` yields `{\"branches\": []}`."
        ),
        "curl_example": (
            "curl -G '{base}/branches' "
            "--data-urlencode 'repo_url=https://github.com/acme/widgets.git'"
        ),
        "notes": "No auth. Use this before deploying to confirm a branch exists.",
    },
    "list-apps": {
        "title": "List all apps",
        "method": "GET",
        "path": "/apps",
        "auth": "bearer",
        "params": "None.",
        "response": (
            "200 array of `{id, name, branch, status, repo_url}`. `status` is one "
            "of idle/deploying/success/failed."
        ),
        "curl_example": "curl '{base}/apps' -H 'Authorization: Bearer $TOKEN'",
        "notes": "Use this to resolve an app name to its numeric `id` before deploying.",
    },
    "deploy-app": {
        "title": "Trigger a deployment for an app",
        "method": "POST",
        "path": "/apps/{id}/deploy",
        "auth": "bearer",
        "params": "Path: `id` (integer, required) — the app id. No request body.",
        "response": (
            "202 `{deployment_id, app_id, status}` — the deployment is QUEUED, not "
            "finished. Save `deployment_id` and poll it. 404 `{\"error\": \"Not "
            "found\"}` if the app id is unknown."
        ),
        "curl_example": (
            "curl -X POST '{base}/apps/3/deploy' -H 'Authorization: Bearer $TOKEN'"
        ),
        "notes": (
            "Asynchronous. Always follow with get-deployment / get-deployment-log "
            "polling — do not assume success from the 202."
        ),
    },
    "get-deployment": {
        "title": "Get deployment details",
        "method": "GET",
        "path": "/deployments/{id}",
        "auth": "bearer",
        "params": "Path: `id` (integer, required) — the deployment id.",
        "response": (
            "200 `{id, app_id, app_name, status, commit_sha, commit_message, "
            "started_at, finished_at, log_length}`. `status` is one of "
            "pending/running/success/failed; success and failed are terminal."
        ),
        "curl_example": "curl '{base}/deployments/42' -H 'Authorization: Bearer $TOKEN'",
        "notes": "Poll until `status` is `success` or `failed`.",
    },
    "get-deployment-log": {
        "title": "Stream deployment log text",
        "method": "GET",
        "path": "/deployments/{id}/log",
        "auth": "bearer",
        "params": (
            "Path: `id` (integer, required). Query: `offset` (integer, optional, "
            "default 0) — byte offset to read from for incremental polling."
        ),
        "response": (
            "200 `text/plain` chunk starting at `offset`. Response headers: "
            "`X-Log-Offset` (total bytes written so far — pass this as the next "
            "`offset`), `X-Deploy-Status` (current status), `X-Deploy-Done` "
            "(`true`/`false`, whether the deployment reached a terminal state)."
        ),
        "curl_example": (
            "curl -D - '{base}/deployments/42/log?offset=0' "
            "-H 'Authorization: Bearer $TOKEN'"
        ),
        "notes": (
            "Tail loop: start at offset 0, append each chunk, set the next "
            "`offset` to the returned `X-Log-Offset`, and stop when "
            "`X-Deploy-Done: true`."
        ),
    },
}


def action_slugs() -> list[str]:
    """Stable, ordered list of valid action slugs."""
    return list(ENDPOINTS.keys())


def render_action(slug: str) -> str:
    """Format a single action guide as markdown, or a helpful error for bad slugs."""
    spec = ENDPOINTS.get(slug)
    if spec is None:
        valid = ", ".join(action_slugs())
        return (
            f"Unknown action '{slug}'. Valid actions are: {valid}.\n"
            "Read bridge://api/actions for the index."
        )
    curl = spec["curl_example"].format(base=API_BASE)
    return f"""\
# {spec['title']}

`{spec['method']} {API_BASE}{spec['path']}`  — auth: **{spec['auth']}**

**Parameters:** {spec['params']}

**Response:** {spec['response']}

**Example:**

    {curl}

**Notes:** {spec['notes']}
"""


def render_index() -> str:
    """Markdown index of every action with a one-line summary."""
    lines = ["# The Bridge API — actions", ""]
    for slug, spec in ENDPOINTS.items():
        lines.append(
            f"- `{slug}` — {spec['method']} {API_BASE}{spec['path']} "
            f"({spec['auth']}): {spec['title']}"
        )
    lines.append("")
    lines.append("Read `bridge://api/actions/<slug>` for full detail.")
    return "\n".join(lines)


# ---- Workflow narratives (reused by prompts) -------------------------------

WORKFLOW_DEPLOY_AND_WATCH = """\
Goal: deploy app **{app_id}** and follow it to completion.

1. POST `{base}/apps/{app_id}/deploy` with `Authorization: Bearer <token>`.
   Read the `deployment_id` from the 202 response. (404 means the app id is wrong
   — use list-apps to find the right one.)
2. Tail the log: GET `{base}/deployments/<deployment_id>/log?offset=0`. Print the
   text chunk. Remember the `X-Log-Offset` response header.
3. Repeat step 2 using `offset=<last X-Log-Offset>`, appending each new chunk,
   until the `X-Deploy-Done` header is `true`.
4. Confirm the outcome with GET `{base}/deployments/<deployment_id>` and report
   the final `status` (`success` or `failed`) plus `commit_sha`/`commit_message`.

Do not declare success from the 202 in step 1 — only from a terminal status.
"""

WORKFLOW_FIND_AND_DEPLOY_BRANCH = """\
Goal: deploy the app tracking repo **{repo_url}**.

1. (Optional) GET `{base}/branches?repo_url={repo_url}` to confirm available
   branches. No auth needed.
2. GET `{base}/apps` (bearer auth) and find the app whose `repo_url` matches
   {repo_url}; note its `id` and current `branch`/`status`.
3. POST `{base}/apps/<id>/deploy` (bearer auth) and capture `deployment_id`.
4. Follow the deploy to completion as in the deploy_and_watch workflow
   (tail the log by `offset`/`X-Log-Offset` until `X-Deploy-Done: true`, then
   read the final status).
"""

WORKFLOW_CHECK_DEPLOY_STATUS = """\
Goal: report the current state of deployment **{deployment_id}** without waiting.

1. GET `{base}/deployments/{deployment_id}` (bearer auth) for `status`,
   `commit_sha`, `commit_message`, `started_at`, `finished_at`, `log_length`.
2. GET `{base}/deployments/{deployment_id}/log?offset=0` once to show the log so
   far. The `X-Deploy-Done` header tells you whether it is still running.
3. Summarize: is it pending/running or terminal (success/failed), and the latest
   log tail.
"""
