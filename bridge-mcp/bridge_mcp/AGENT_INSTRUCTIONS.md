## Deploying with The Bridge (bridge-api-guide MCP)

This project is deployed via **The Bridge**. A `bridge-api-guide` MCP server is
available (registered globally or in this repo) with tools that call The
Bridge deployment API directly — `list_branches`, `list_apps`, `deploy_app`,
`get_deployment`, `get_deployment_log`.

**Before any deploy or deployment-status task:**

1. Read the `bridge://api/overview` resource (base URL, bearer-token auth, error
   model) if you need the contract details.
2. Read the `bridge://api/actions/<slug>` resource for background on a
   specific action — slugs: `list-branches`, `list-apps`, `deploy-app`,
   `get-deployment`, `get-deployment-log`.
3. For multi-step jobs, invoke a prompt instead of improvising:
   - `deploy_and_watch(app_id)` — deploy and tail to completion
   - `find_and_deploy_branch(repo_url)` — resolve the app from a repo URL, deploy, watch
   - `check_deploy_status(deployment_id)` — report current status + log
4. Use the `list_apps` / `deploy_app` / `get_deployment` / `get_deployment_log`
   tools directly — no need to hand-roll HTTP calls. **Never assume success
   from `deploy_app`'s immediate response** — poll `get_deployment` /
   `get_deployment_log` until the deployment is terminal.

Example user prompts that should route through this server:
- "Deploy app 3 and watch it to completion."
- "Deploy the app for https://github.com/acme/widgets.git."
- "What's the status of deployment 42?"
