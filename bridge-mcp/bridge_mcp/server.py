"""FastMCP server for The Bridge deployment API.

Exposes both instruction resources/prompts (the contract and workflow guides)
and real tools that call The Bridge over HTTP, configured via the
BRIDGE_API_BASE_URL / BRIDGE_API_TOKEN environment variables (see client.py).
"""

from mcp.server.fastmcp import FastMCP

from . import client, content

mcp = FastMCP("bridge-api-guide")


# ---- Resources -------------------------------------------------------------

@mcp.resource("bridge://api/overview")
def overview() -> str:
    """Base URL, auth model, error conventions, and the list of action guides."""
    return content.OVERVIEW


@mcp.resource("bridge://api/actions")
def actions_index() -> str:
    """Index of every documented Bridge API action."""
    return content.render_index()


@mcp.resource("bridge://api/actions/{action}")
def action_guide(action: str) -> str:
    """Full guide for a single action (method, path, auth, params, example)."""
    return content.render_action(action)


# ---- Prompts (guided workflows) --------------------------------------------

@mcp.prompt()
def deploy_and_watch(app_id: str) -> str:
    """Deploy an app by id and follow it to a terminal status."""
    return content.WORKFLOW_DEPLOY_AND_WATCH.format(
        base=content.API_BASE, app_id=app_id
    )


@mcp.prompt()
def find_and_deploy_branch(repo_url: str) -> str:
    """Find the app for a repo URL, then deploy and watch it."""
    return content.WORKFLOW_FIND_AND_DEPLOY_BRANCH.format(
        base=content.API_BASE, repo_url=repo_url
    )


@mcp.prompt()
def check_deploy_status(deployment_id: str) -> str:
    """Report a deployment's current status and recent log without waiting."""
    return content.WORKFLOW_CHECK_DEPLOY_STATUS.format(
        base=content.API_BASE, deployment_id=deployment_id
    )


# ---- Tools (real HTTP calls) -----------------------------------------------

@mcp.tool()
def list_branches(repo_url: str) -> dict:
    """List remote git branches for a repo URL. No auth required. Never
    raises on failure — check for an "error" key if branches is empty."""
    return client.list_branches(repo_url)


@mcp.tool()
def list_apps() -> list[dict]:
    """List all apps registered in The Bridge."""
    return client.list_apps()


@mcp.tool()
def deploy_app(app_id: int) -> dict:
    """Trigger a deployment for an app. Returns immediately once queued — poll
    get_deployment / get_deployment_log for progress, don't assume success."""
    return client.deploy_app(app_id)


@mcp.tool()
def get_deployment(deployment_id: int) -> dict:
    """Get a deployment's current status, commit info, and log length."""
    return client.get_deployment(deployment_id)


@mcp.tool()
def get_deployment_log(deployment_id: int, offset: int = 0) -> dict:
    """Read a chunk of a deployment's log starting at offset. Tail by calling
    again with offset=log_offset from the previous response, until deploy_done."""
    return client.get_deployment_log(deployment_id, offset)


def main() -> None:
    """Console entry point: run the server over stdio."""
    mcp.run()


if __name__ == "__main__":
    main()
