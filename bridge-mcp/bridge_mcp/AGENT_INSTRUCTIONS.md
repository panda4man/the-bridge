## Deploying with The Bridge (bridge-api-guide MCP)

This project is deployed via **The Bridge**. A `bridge-api-guide` MCP server is
available (registered globally or in this repo). It is **instruction-only**: it
tells you how to call The Bridge deployment API; it does not call it for you.

**Before any deploy or deployment-status task:**

1. Read the `bridge://api/overview` resource (base URL, bearer-token auth, error
   model).
2. Read the `bridge://api/actions/<slug>` resource for the action you need —
   slugs: `list-branches`, `list-apps`, `deploy-app`, `get-deployment`,
   `get-deployment-log`.
3. For multi-step jobs, invoke a prompt instead of improvising:
   - `deploy_and_watch(app_id)` — deploy and tail to completion
   - `find_and_deploy_branch(repo_url)` — resolve the app from a repo URL, deploy, watch
   - `check_deploy_status(deployment_id)` — report current status + log
4. Make the HTTP calls yourself with the operator-provided bearer token. **Never
   guess endpoints or assume success from the `202` queue response** — poll
   `get-deployment` / `get-deployment-log` until the deployment is terminal.

Example user prompts that should route through this server:
- "Deploy app 3 and watch it to completion."
- "Deploy the app for https://github.com/acme/widgets.git."
- "What's the status of deployment 42?"
