"""FastMCP server exposing instruction-only resources and prompts for The Bridge.

No tools are registered: this server never performs HTTP calls. It hands the
agent the contract and the workflow, and the agent makes the calls itself.
"""

from mcp.server.fastmcp import FastMCP

from . import content

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


def main() -> None:
    """Console entry point: run the server over stdio."""
    mcp.run()


if __name__ == "__main__":
    main()
