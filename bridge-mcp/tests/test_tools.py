import asyncio
import json

import httpx
import pytest
import respx

from bridge_mcp import server

BASE = "http://bridge.test/api"


@pytest.fixture(autouse=True)
def bridge_env(monkeypatch):
    monkeypatch.setenv("BRIDGE_API_BASE_URL", BASE)
    monkeypatch.setenv("BRIDGE_API_TOKEN", "secret-token")


def _run(coro):
    return asyncio.run(coro)


def _call_tool(name: str, arguments: dict):
    return _run(server.mcp.call_tool(name, arguments))


def _tool_json(result):
    """Extract parsed JSON data from a call_tool result. Dict-returning tools
    come back as a single TextContent block; list-returning tools come back
    as a (blocks, structured_content) tuple with the list under "result"."""
    if isinstance(result, tuple):
        _blocks, structured = result
        return structured["result"] if isinstance(structured, dict) and "result" in structured else structured
    return json.loads(result[0].text)


def test_new_tools_are_registered():
    tools = _run(server.mcp.list_tools())
    names = {t.name for t in tools}
    assert {"list_branches", "list_apps", "deploy_app", "get_deployment", "get_deployment_log"} <= names


@respx.mock
def test_list_apps_tool_round_trips():
    apps = [{"id": 1, "name": "widgets", "branch": "main", "status": "idle", "repo_url": "https://x.git"}]
    respx.get(f"{BASE}/apps").mock(return_value=httpx.Response(200, json=apps))
    result = _call_tool("list_apps", {})
    assert _tool_json(result) == apps


@respx.mock
def test_list_branches_tool_passes_through_embedded_error():
    respx.get(f"{BASE}/branches", params={"repo_url": "https://bad.git"}).mock(
        return_value=httpx.Response(200, json={"branches": [], "error": "Failed to fetch branches"})
    )
    result = _call_tool("list_branches", {"repo_url": "https://bad.git"})
    assert _tool_json(result) == {"branches": [], "error": "Failed to fetch branches"}


@respx.mock
def test_deploy_app_tool_round_trips():
    respx.post(f"{BASE}/apps/3/deploy").mock(
        return_value=httpx.Response(202, json={"deployment_id": 42, "app_id": 3, "status": "pending"})
    )
    result = _call_tool("deploy_app", {"app_id": 3})
    assert _tool_json(result) == {"deployment_id": 42, "app_id": 3, "status": "pending"}


@respx.mock
def test_deploy_app_tool_404_surfaces_as_tool_error():
    respx.post(f"{BASE}/apps/999/deploy").mock(return_value=httpx.Response(404, json={"error": "Not found"}))
    from mcp.server.fastmcp.exceptions import ToolError

    with pytest.raises(ToolError) as exc_info:
        _call_tool("deploy_app", {"app_id": 999})
    assert "Not found" in str(exc_info.value)


@respx.mock
def test_list_apps_tool_401_surfaces_as_tool_error():
    respx.get(f"{BASE}/apps").mock(return_value=httpx.Response(401, json={"error": "Unauthorized"}))
    from mcp.server.fastmcp.exceptions import ToolError

    with pytest.raises(ToolError) as exc_info:
        _call_tool("list_apps", {})
    assert "Unauthorized" in str(exc_info.value)


@respx.mock
def test_list_apps_tool_503_surfaces_as_tool_error_distinct_from_401():
    respx.get(f"{BASE}/apps").mock(return_value=httpx.Response(503, json={"error": "API token not configured"}))
    from mcp.server.fastmcp.exceptions import ToolError

    with pytest.raises(ToolError) as exc_info:
        _call_tool("list_apps", {})
    assert "API token not configured" in str(exc_info.value)


@respx.mock
def test_get_deployment_log_tool_round_trips():
    respx.get(f"{BASE}/deployments/42/log", params={"offset": 0}).mock(
        return_value=httpx.Response(
            200,
            text="log chunk",
            headers={"X-Log-Offset": "9", "X-Deploy-Status": "running", "X-Deploy-Done": "false"},
        )
    )
    result = _call_tool("get_deployment_log", {"deployment_id": 42})
    assert _tool_json(result) == {
        "text": "log chunk",
        "log_offset": 9,
        "deploy_status": "running",
        "deploy_done": False,
    }
