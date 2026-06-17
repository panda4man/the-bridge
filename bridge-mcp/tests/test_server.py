import asyncio

from bridge_mcp import server


def _run(coro):
    return asyncio.run(coro)


def _as_text(result) -> str:
    """Flatten whatever FastMCP returns (resource contents / prompt messages) to text."""
    return str(result)


def test_resources_and_prompts_are_registered():
    resources = _run(server.mcp.list_resources())
    templates = _run(server.mcp.list_resource_templates())
    prompts = _run(server.mcp.list_prompts())

    static_uris = {str(r.uri) for r in resources}
    assert "bridge://api/overview" in static_uris
    assert "bridge://api/actions" in static_uris

    template_uris = {t.uriTemplate for t in templates}
    assert any("{action}" in u for u in template_uris)

    prompt_names = {p.name for p in prompts}
    assert {"deploy_and_watch", "find_and_deploy_branch", "check_deploy_status"} <= prompt_names


def test_overview_resource_renders():
    text = _as_text(_run(server.mcp.read_resource("bridge://api/overview")))
    assert "Authorization: Bearer" in text


def test_action_template_renders_for_concrete_uri():
    text = _as_text(_run(server.mcp.read_resource("bridge://api/actions/deploy-app")))
    assert "/api/apps/{id}/deploy" in text
    assert "{base}" not in text


def test_deploy_and_watch_prompt_renders():
    text = _as_text(_run(server.mcp.get_prompt("deploy_and_watch", {"app_id": "3"})))
    assert "/apps/3/deploy" in text
    assert "X-Deploy-Done" in text
